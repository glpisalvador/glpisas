<?php

/**
 * Plugin GLPI SAS - registro de criações e auditoria das configurações
 */
class PluginGlpisasRegistro extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_glpisas_registros';
    public const AUDITORIA = 'glpi_plugin_glpisas_auditoria';

    public static function getTypeName($nb = 0): string
    {
        return 'Registro de criações';
    }

    /** Tipos registrados, com rótulo, ícone e tabela nativa */
    public static function tipos(): array
    {
        return [
            'User'       => ['Usuário', 'ti ti-user', 'glpi_users'],
            'Group'      => ['Grupo', 'ti ti-users', 'glpi_groups'],
            'Profile'    => ['Perfil', 'ti ti-id-badge', 'glpi_profiles'],
            'Group_User' => ['Membro de grupo', 'ti ti-user-plus', 'glpi_users'],
            'Ticket'     => ['Chamado', 'ti ti-ticket', 'glpi_tickets'],
            'Change'     => ['Mudança', 'ti ti-exchange', 'glpi_changes'],
            'Problem'    => ['Problema', 'ti ti-alert-triangle', 'glpi_problems'],
            'Project'    => ['Projeto', 'ti ti-briefcase', 'glpi_projects'],
        ];
    }

    public static function acoesAuditoria(): array
    {
        return ['criar' => 'Criação', 'alterar' => 'Alteração', 'excluir' => 'Exclusão'];
    }

    public static function objetosAuditoria(): array
    {
        return ['limite' => 'Limite', 'horas' => 'Controle de horas', 'config' => 'Configuração', 'antigo' => 'Controle Administrativo (migrado)'];
    }

    public static function registrar(CommonDBTM $item): void
    {
        global $DB;
        $tipo = $item::getType();
        $usuario = Session::getLoginUserID();
        $dados = [
            'itemtype'    => $tipo,
            'items_id'    => (int) $item->getID(),
            'entities_id' => (int) ($item->fields['entities_id'] ?? 0),
            'users_id'    => is_numeric($usuario) ? (int) $usuario : 0,
            'profiles_id' => (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0),
            'detalhe'     => mb_substr((string) ($item->fields['name'] ?? ''), 0, 255),
        ];
        if ($item instanceof Group_User) {
            $dados['items_id'] = (int) $item->fields['users_id'];
            $dados['groups_id'] = (int) $item->fields['groups_id'];
            $dados['detalhe'] = mb_substr(getUserName((int) $item->fields['users_id']), 0, 255);
        }
        try {
            $DB->insert(self::TABELA, $dados);
        } catch (Throwable $e) {
            Toolbox::logInFile('php-errors', 'Plugin glpisas: falha ao registrar criação: ' . $e->getMessage() . "\n");
        }
    }

    public static function auditar(string $acao, string $objeto, int $objetoId, string $descricao, ?string $antigo = null, ?string $novo = null): void
    {
        global $DB;
        $usuario = Session::getLoginUserID();
        $DB->insert(self::AUDITORIA, [
            'users_id'     => is_numeric($usuario) ? (int) $usuario : 0,
            'acao'         => $acao,
            'objeto'       => $objeto,
            'objeto_id'    => $objetoId,
            'descricao'    => mb_substr($descricao, 0, 255),
            'valor_antigo' => $antigo,
            'valor_novo'   => $novo,
        ]);
    }

    // =====================================================================
    // Consultas com filtros (tela e CSV)
    // =====================================================================

    public static function lerFiltros(array $origem, string $tipo): array
    {
        $f = [
            'de'  => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($origem['de'] ?? '')) ? substr($origem['de'], 0, 10) : '',
            'ate' => preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($origem['ate'] ?? '')) ? substr($origem['ate'], 0, 10) : '',
            'users_id' => max(0, (int) ($origem['users_id'] ?? 0)),
            'pagina'   => max(1, (int) ($origem['pagina'] ?? 1)),
        ];
        if ($tipo === 'criacoes') {
            $f['itemtype'] = array_key_exists((string) ($origem['itemtype'] ?? ''), self::tipos()) ? $origem['itemtype'] : '';
            $f['profiles_id'] = max(0, (int) ($origem['profiles_id'] ?? 0));
            $ent = (string) ($origem['entities_id'] ?? '');
            $f['entities_id'] = ($ent === '' || (int) $ent < 0) ? -1 : (int) $ent;
        } else {
            $f['objeto'] = array_key_exists((string) ($origem['objeto'] ?? ''), self::objetosAuditoria()) ? $origem['objeto'] : '';
            $f['acao'] = array_key_exists((string) ($origem['acao'] ?? ''), self::acoesAuditoria()) ? $origem['acao'] : '';
        }
        return $f;
    }

    private static function whereComum(array $f, string $tabela): array
    {
        $where = [];
        if ($f['de'] !== '') {
            $where[] = [$tabela . '.date_creation' => ['>=', $f['de'] . ' 00:00:00']];
        }
        if ($f['ate'] !== '') {
            $where[] = [$tabela . '.date_creation' => ['<=', $f['ate'] . ' 23:59:59']];
        }
        if ($f['users_id'] > 0) {
            $where[$tabela . '.users_id'] = $f['users_id'];
        }
        return $where;
    }

    /** @return array{0: array, 1: int} linhas da página e total */
    public static function criacoes(array $f, int $porPagina = 50, bool $tudo = false): array
    {
        global $DB;
        $t = self::TABELA;
        $where = self::whereComum($f, $t);
        if ($f['itemtype'] !== '') {
            $where[$t . '.itemtype'] = $f['itemtype'];
        }
        if ($f['profiles_id'] > 0) {
            $where[$t . '.profiles_id'] = $f['profiles_id'];
        }
        if ($f['entities_id'] >= 0) {
            $where[$t . '.entities_id'] = $f['entities_id'];
        }
        $total = (int) ($DB->request(['COUNT' => 'n', 'FROM' => $t, 'WHERE' => $where])->current()['n'] ?? 0);
        $consulta = ['FROM' => $t, 'WHERE' => $where, 'ORDER' => [$t . '.id DESC']];
        if (!$tudo) {
            $consulta['LIMIT'] = $porPagina;
            $consulta['START'] = ($f['pagina'] - 1) * $porPagina;
        } else {
            $consulta['LIMIT'] = 50000;
        }
        return [iterator_to_array($DB->request($consulta), false), $total];
    }

    /** @return array{0: array, 1: int} */
    public static function auditoria(array $f, int $porPagina = 50, bool $tudo = false): array
    {
        global $DB;
        $t = self::AUDITORIA;
        $where = self::whereComum($f, $t);
        if ($f['objeto'] !== '') {
            $where[$t . '.objeto'] = $f['objeto'];
        }
        if ($f['acao'] !== '') {
            $where[$t . '.acao'] = $f['acao'];
        }
        $total = (int) ($DB->request(['COUNT' => 'n', 'FROM' => $t, 'WHERE' => $where])->current()['n'] ?? 0);
        $consulta = ['FROM' => $t, 'WHERE' => $where, 'ORDER' => [$t . '.id DESC']];
        if (!$tudo) {
            $consulta['LIMIT'] = $porPagina;
            $consulta['START'] = ($f['pagina'] - 1) * $porPagina;
        } else {
            $consulta['LIMIT'] = 50000;
        }
        return [iterator_to_array($DB->request($consulta), false), $total];
    }

    /** Nome atual do item criado (ou o nome gravado, se ele não existir mais) e link */
    public static function itemCriado(array $r): array
    {
        $tipo = (string) $r['itemtype'];
        $classe = $tipo === 'Group_User' ? 'User' : $tipo;
        $nome = (string) $r['detalhe'];
        $link = '';
        $existe = false;
        if (class_exists($classe) && is_a($classe, CommonDBTM::class, true)) {
            $obj = new $classe();
            if ($obj->getFromDB((int) $r['items_id'])) {
                $existe = !((int) ($obj->fields['is_deleted'] ?? 0));
                $nome = $classe === 'User' ? getUserName((int) $r['items_id']) : (string) $obj->getName();
                $link = $classe::getFormURLWithID((int) $r['items_id']);
            }
        }
        if ($tipo === 'Group_User' && (int) $r['groups_id'] > 0) {
            $nome .= ' → ' . Dropdown::getDropdownName('glpi_groups', (int) $r['groups_id']);
        }
        return ['nome' => $nome !== '' ? $nome : '#' . (int) $r['items_id'], 'link' => $link, 'existe' => $existe];
    }
}
