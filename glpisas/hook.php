<?php

/**
 * Plugin GLPI SAS - instalação, atualização e desinstalação
 * Migra dados do antigo plugin "controleadministrativo" quando as tabelas dele existirem.
 * A desinstalação nunca remove as tabelas.
 */

function plugin_glpisas_install(): bool
{
    global $DB;

    $opcoes = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC';

    if (!$DB->tableExists('glpi_plugin_glpisas_configs', false)) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpisas_configs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(255) NOT NULL,
            `value` longtext,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `name` (`name`)
        ) $opcoes");
    }

    // Limites: perfil (usuarios, grupos, perfis), entidade (chamados, mudancas, problemas, projetos), grupo (membros, chamados)
    if (!$DB->tableExists('glpi_plugin_glpisas_limites', false)) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpisas_limites` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tipo_alvo` varchar(20) NOT NULL DEFAULT 'entidade',
            `alvo_id` int unsigned NOT NULL DEFAULT 0,
            `recurso` varchar(30) NOT NULL DEFAULT 'chamados',
            `quantidade` int unsigned NOT NULL DEFAULT 0,
            `periodo` varchar(10) NOT NULL DEFAULT 'mes',
            `data_inicio` date NULL DEFAULT NULL,
            `incluir_filhas` tinyint(1) NOT NULL DEFAULT 0,
            `acao` varchar(10) NOT NULL DEFAULT 'bloquear',
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `comment` text,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `alvo_recurso` (`tipo_alvo`, `alvo_id`, `recurso`),
            KEY `is_active` (`is_active`)
        ) $opcoes");
    }

    // Controle de horas contratadas: entidade, grupo ou usuário
    if (!$DB->tableExists('glpi_plugin_glpisas_horas', false)) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpisas_horas` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `tipo_alvo` varchar(20) NOT NULL DEFAULT 'entidade',
            `alvo_id` int unsigned NOT NULL DEFAULT 0,
            `minutos_contratados` int unsigned NOT NULL DEFAULT 0,
            `periodo` varchar(10) NOT NULL DEFAULT 'mes',
            `data_inicio` date NULL DEFAULT NULL,
            `data_fim` date NULL DEFAULT NULL,
            `valor_hora` decimal(12,2) NOT NULL DEFAULT 0.00,
            `valor_hora_excedente` decimal(12,2) NOT NULL DEFAULT 0.00,
            `fonte` varchar(15) NOT NULL DEFAULT 'tarefas',
            `incluir_filhas` tinyint(1) NOT NULL DEFAULT 0,
            `ao_exceder` varchar(10) NOT NULL DEFAULT 'avisar',
            `is_active` tinyint(1) NOT NULL DEFAULT 1,
            `comment` text,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            `date_mod` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `alvo` (`tipo_alvo`, `alvo_id`),
            KEY `is_active` (`is_active`)
        ) $opcoes");
    }

    // Registro de criações (quem criou, com qual perfil, em qual entidade)
    if (!$DB->tableExists('glpi_plugin_glpisas_registros', false)) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpisas_registros` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `itemtype` varchar(100) NOT NULL DEFAULT '',
            `items_id` int unsigned NOT NULL DEFAULT 0,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `groups_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `profiles_id` int unsigned NOT NULL DEFAULT 0,
            `detalhe` varchar(255) NOT NULL DEFAULT '',
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `item` (`itemtype`, `items_id`),
            KEY `perfil` (`profiles_id`, `itemtype`),
            KEY `users_id` (`users_id`),
            KEY `entities_id` (`entities_id`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    // Auditoria das alterações de configuração
    if (!$DB->tableExists('glpi_plugin_glpisas_auditoria', false)) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_glpisas_auditoria` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `acao` varchar(20) NOT NULL DEFAULT '',
            `objeto` varchar(20) NOT NULL DEFAULT '',
            `objeto_id` int unsigned NOT NULL DEFAULT 0,
            `descricao` varchar(255) NOT NULL DEFAULT '',
            `valor_antigo` text,
            `valor_novo` text,
            `date_creation` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `users_id` (`users_id`),
            KEY `objeto` (`objeto`, `objeto_id`),
            KEY `date_creation` (`date_creation`)
        ) $opcoes");
    }

    $padroes = [
        'alerta_pct'      => '80',
        'avisar_perto'    => '1',
        'perfis_isentos'  => '[]',
        'msg_bloqueio'    => '',
        'por_pagina'      => '50',
    ];
    foreach ($padroes as $nome => $valor) {
        if (count($DB->request(['FROM' => 'glpi_plugin_glpisas_configs', 'WHERE' => ['name' => $nome]])) === 0) {
            $DB->insert('glpi_plugin_glpisas_configs', ['name' => $nome, 'value' => $valor]);
        }
    }

    $perfisAntigos = plugin_glpisas_migrar_antigo();

    // Direito nativo: perfis que administram o GLPI recebem Ver + Gerenciar; perfis do plugin antigo também
    $total = READ | UPDATE;
    $perfis = [];
    foreach ($DB->request(['SELECT' => ['profiles_id', 'rights'], 'FROM' => 'glpi_profilerights', 'WHERE' => ['name' => 'config']]) as $r) {
        if (((int) $r['rights'] & UPDATE) === UPDATE) {
            $perfis[(int) $r['profiles_id']] = $total;
        }
    }
    foreach ($perfisAntigos as $pid => $direitos) {
        $perfis[$pid] = ($perfis[$pid] ?? 0) | $direitos;
    }
    foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $p) {
        $pid = (int) $p['id'];
        $existe = $DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => $pid, 'name' => 'plugin_glpisas'], 'LIMIT' => 1]);
        if (count($existe) === 0) {
            $DB->insert('glpi_profilerights', ['profiles_id' => $pid, 'name' => 'plugin_glpisas', 'rights' => $perfis[$pid] ?? 0]);
        }
    }
    if (isset($_SESSION['glpiactiveprofile']['id'])) {
        $_SESSION['glpiactiveprofile']['plugin_glpisas'] = $perfis[(int) $_SESSION['glpiactiveprofile']['id']] ?? ($_SESSION['glpiactiveprofile']['plugin_glpisas'] ?? 0);
    }

    return true;
}

/**
 * Copia limites, horas, registros e auditoria do plugin "controleadministrativo" (uma única vez).
 * Retorna os direitos a conceder por perfil, a partir das listas de perfis antigas.
 */
function plugin_glpisas_migrar_antigo(): array
{
    global $DB;

    $antigo = 'glpi_plugin_controleadministrativo_';
    $direitos = [];
    $jaMigrado = count($DB->request(['FROM' => 'glpi_plugin_glpisas_configs', 'WHERE' => ['name' => 'migrado_controleadministrativo']])) > 0;
    if ($jaMigrado || !$DB->tableExists($antigo . 'configs', false)) {
        return $direitos;
    }

    foreach ($DB->request(['FROM' => $antigo . 'configs']) as $c) {
        $lista = json_decode((string) $c['value'], true);
        if (!is_array($lista) || !str_starts_with((string) $c['name'], 'profiles_can_')) {
            continue;
        }
        $bit = $c['name'] === 'profiles_can_configure' ? (READ | UPDATE) : READ;
        foreach ($lista as $pid) {
            $direitos[(int) $pid] = ($direitos[(int) $pid] ?? 0) | $bit;
        }
    }

    $limite = static function (string $tipo, int $alvo, string $recurso, int $qtd) use ($DB): void {
        if ($qtd <= 0 || $alvo < 0) {
            return;
        }
        $existe = $DB->request(['FROM' => 'glpi_plugin_glpisas_limites', 'WHERE' => ['tipo_alvo' => $tipo, 'alvo_id' => $alvo, 'recurso' => $recurso]]);
        if (count($existe) === 0) {
            $DB->insert('glpi_plugin_glpisas_limites', [
                'tipo_alvo' => $tipo, 'alvo_id' => $alvo, 'recurso' => $recurso, 'quantidade' => $qtd,
                'periodo' => 'total', 'acao' => 'bloquear', 'is_active' => 1,
                'comment' => 'Migrado do Controle Administrativo',
            ]);
        }
    };
    if ($DB->tableExists($antigo . 'perfil_config', false)) {
        foreach ($DB->request(['FROM' => $antigo . 'perfil_config']) as $r) {
            $limite('perfil', (int) $r['profiles_id'], 'usuarios', (int) $r['limite_usuario']);
            $limite('perfil', (int) $r['profiles_id'], 'grupos', (int) $r['limite_grupo']);
            $limite('perfil', (int) $r['profiles_id'], 'perfis', (int) $r['limite_perfil']);
        }
    }
    if ($DB->tableExists($antigo . 'entidade_config', false)) {
        foreach ($DB->request(['FROM' => $antigo . 'entidade_config']) as $r) {
            $limite('entidade', (int) $r['entities_id'], 'chamados', (int) $r['limite_chamado']);
            $limite('entidade', (int) $r['entities_id'], 'mudancas', (int) $r['limite_mudanca']);
            $limite('entidade', (int) $r['entities_id'], 'problemas', (int) $r['limite_problema']);
            $limite('entidade', (int) $r['entities_id'], 'projetos', (int) $r['limite_projeto']);
        }
    }
    if ($DB->tableExists($antigo . 'grupo_config', false)) {
        foreach ($DB->request(['FROM' => $antigo . 'grupo_config']) as $r) {
            $limite('grupo', (int) $r['groups_id'], 'membros', (int) $r['limite_membros']);
            $limite('grupo', (int) $r['groups_id'], 'chamados', (int) $r['limite_chamados_grupo']);
        }
    }
    if ($DB->tableExists($antigo . 'horas_config', false)) {
        $tipos = ['entity' => 'entidade', 'group' => 'grupo', 'user' => 'usuario'];
        foreach ($DB->request(['FROM' => $antigo . 'horas_config']) as $r) {
            $tipo = $tipos[$r['tipo_alvo']] ?? null;
            if ($tipo === null || count($DB->request(['FROM' => 'glpi_plugin_glpisas_horas', 'WHERE' => ['tipo_alvo' => $tipo, 'alvo_id' => (int) $r['alvo_id']]])) > 0) {
                continue;
            }
            $DB->insert('glpi_plugin_glpisas_horas', [
                'tipo_alvo' => $tipo, 'alvo_id' => (int) $r['alvo_id'], 'minutos_contratados' => (int) $r['limite_horas'],
                'periodo' => 'total', 'valor_hora' => (float) $r['valor_hora_positiva'], 'valor_hora_excedente' => (float) $r['valor_hora_negativa'],
                'fonte' => 'atendimento', 'ao_exceder' => 'avisar', 'is_active' => 1, 'comment' => 'Migrado do Controle Administrativo',
            ]);
        }
    }

    $registros = [
        'usuario_logs'  => ['User', 'created_user_id', 'creator_user_id', 'creator_profile_id'],
        'grupo_logs'    => ['Group', 'created_group_id', 'creator_user_id', 'creator_profile_id'],
        'perfil_logs'   => ['Profile', 'created_profile_id', 'creator_user_id', 'creator_profile_id'],
        'entidade_ticket_logs'  => ['Ticket', 'ticket_id', 'user_id', null],
        'entidade_change_logs'  => ['Change', 'change_id', 'user_id', null],
        'entidade_problem_logs' => ['Problem', 'problem_id', 'user_id', null],
        'entidade_project_logs' => ['Project', 'project_id', 'user_id', null],
    ];
    foreach ($registros as $tabela => [$tipo, $campoItem, $campoUsuario, $campoPerfil]) {
        if (!$DB->tableExists($antigo . $tabela, false)) {
            continue;
        }
        foreach ($DB->request(['FROM' => $antigo . $tabela]) as $r) {
            $DB->insert('glpi_plugin_glpisas_registros', [
                'itemtype' => $tipo, 'items_id' => (int) $r[$campoItem], 'entities_id' => (int) ($r['entities_id'] ?? 0),
                'users_id' => (int) $r[$campoUsuario], 'profiles_id' => $campoPerfil ? (int) $r[$campoPerfil] : 0,
                'date_creation' => $r['date_creation'],
            ]);
        }
    }
    if ($DB->tableExists($antigo . 'grupo_member_logs', false)) {
        foreach ($DB->request(['FROM' => $antigo . 'grupo_member_logs']) as $r) {
            $DB->insert('glpi_plugin_glpisas_registros', [
                'itemtype' => 'Group_User', 'items_id' => (int) $r['users_id'], 'groups_id' => (int) $r['groups_id'],
                'users_id' => (int) $r['added_by_user_id'], 'date_creation' => $r['date_creation'],
            ]);
        }
    }
    if ($DB->tableExists($antigo . 'config_logs', false)) {
        foreach ($DB->request(['FROM' => $antigo . 'config_logs']) as $r) {
            $DB->insert('glpi_plugin_glpisas_auditoria', [
                'users_id' => (int) $r['user_id'], 'acao' => mb_substr((string) $r['action'], 0, 20), 'objeto' => 'antigo',
                'objeto_id' => (int) $r['item_id'], 'descricao' => mb_substr(trim($r['item_type'] . ' ' . $r['field_name']), 0, 255),
                'valor_antigo' => $r['old_value'], 'valor_novo' => $r['new_value'], 'date_creation' => $r['date_creation'],
            ]);
        }
    }

    $DB->insert('glpi_plugin_glpisas_configs', ['name' => 'migrado_controleadministrativo', 'value' => date('Y-m-d H:i:s')]);
    return $direitos;
}

/** Desinstalar mantém todas as tabelas e direitos (os dados ficam para uma reinstalação) */
function plugin_glpisas_uninstall(): bool
{
    return true;
}
