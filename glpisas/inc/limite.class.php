<?php

use Glpi\DBAL\QuerySubQuery;

/**
 * Plugin GLPI SAS - limites por perfil, entidade e grupo
 */
class PluginGlpisasLimite extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_glpisas_limites';

    public static function getTypeName($nb = 0): string
    {
        return $nb > 1 ? 'Limites' : 'Limite';
    }

    /** Alvos e os recursos que cada um pode limitar */
    public static function tipos(): array
    {
        return [
            'perfil' => [
                'rotulo'   => 'Perfis',
                'singular' => 'Perfil',
                'icone'    => 'ti ti-id-badge',
                'dica'     => 'Limita quantos usuários, grupos e perfis quem trabalha com o perfil pode criar. A contagem usa o registro de criações do plugin e ignora itens que já foram excluídos.',
                'recursos' => ['usuarios' => 'Usuários criados', 'grupos' => 'Grupos criados', 'perfis' => 'Perfis criados'],
            ],
            'entidade' => [
                'rotulo'   => 'Entidades',
                'singular' => 'Entidade',
                'icone'    => 'ti ti-building',
                'dica'     => 'Limita quantos chamados, mudanças, problemas e projetos podem ser abertos na entidade. A contagem é feita nos próprios itens do GLPI (itens na lixeira não contam).',
                'recursos' => ['chamados' => 'Chamados', 'mudancas' => 'Mudanças', 'problemas' => 'Problemas', 'projetos' => 'Projetos'],
            ],
            'grupo' => [
                'rotulo'   => 'Grupos',
                'singular' => 'Grupo',
                'icone'    => 'ti ti-users',
                'dica'     => 'Limita o número de membros do grupo e quantos chamados podem ser abertos pelo grupo (chamados abertos por membros dele ou com o grupo como requerente).',
                'recursos' => ['membros' => 'Membros', 'chamados' => 'Chamados abertos'],
            ],
        ];
    }

    public static function periodos(): array
    {
        return ['mes' => 'Por mês', 'ano' => 'Por ano', 'total' => 'Total'];
    }

    public static function acoes(): array
    {
        return ['bloquear' => 'Bloquear a criação', 'avisar' => 'Apenas avisar'];
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        $linha = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return $linha ?: null;
    }

    public static function listar(?string $tipo = null, bool $somenteAtivos = false): array
    {
        global $DB;
        $where = [];
        if ($tipo !== null) {
            $where['tipo_alvo'] = $tipo;
        }
        if ($somenteAtivos) {
            $where['is_active'] = 1;
        }
        $linhas = iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => $where, 'ORDER' => ['tipo_alvo', 'alvo_id', 'recurso']]), false);
        foreach ($linhas as &$l) {
            $l['_nome'] = self::nomeAlvo((string) $l['tipo_alvo'], (int) $l['alvo_id']);
        }
        unset($l);
        usort($linhas, static fn($a, $b) => [$a['tipo_alvo'], mb_strtolower($a['_nome']), $a['recurso']] <=> [$b['tipo_alvo'], mb_strtolower($b['_nome']), $b['recurso']]);
        return $linhas;
    }

    public static function nomeAlvo(string $tipo, int $id): string
    {
        $tabela = ['perfil' => 'glpi_profiles', 'entidade' => 'glpi_entities', 'grupo' => 'glpi_groups'][$tipo] ?? '';
        if ($tabela === '') {
            return '-';
        }
        $nome = Dropdown::getDropdownName($tabela, $id);
        return ($nome === '' || $nome === '&nbsp;') ? '#' . $id . ' (não encontrado)' : html_entity_decode($nome, ENT_QUOTES, 'UTF-8');
    }

    public static function rotuloRecurso(string $tipo, string $recurso): string
    {
        return self::tipos()[$tipo]['recursos'][$recurso] ?? $recurso;
    }

    /** Início da janela de contagem (null = sem início) */
    public static function inicioPeriodo(array $l): ?string
    {
        if ($l['recurso'] === 'membros') {
            return null;
        }
        return match ($l['periodo']) {
            'mes'   => date('Y-m-01 00:00:00'),
            'ano'   => date('Y-01-01 00:00:00'),
            default => !empty($l['data_inicio']) ? $l['data_inicio'] . ' 00:00:00' : null,
        };
    }

    public static function descreverPeriodo(array $l): string
    {
        if ($l['recurso'] === 'membros') {
            return 'Atual';
        }
        if ($l['periodo'] === 'total') {
            return !empty($l['data_inicio']) ? 'Total desde ' . Html::convDate($l['data_inicio']) : 'Total';
        }
        return self::periodos()[$l['periodo']] ?? $l['periodo'];
    }

    /** Entidades contadas para um limite de entidade */
    public static function entidadesDe(array $l): array
    {
        $id = (int) $l['alvo_id'];
        return (int) $l['incluir_filhas'] ? array_map('intval', array_values(getSonsOf('glpi_entities', $id))) : [$id];
    }

    /** Uso atual do limite, dentro do período */
    public static function uso(array $l): int
    {
        global $DB;
        $inicio = self::inicioPeriodo($l);
        $tipo = (string) $l['tipo_alvo'];
        $recurso = (string) $l['recurso'];
        $alvo = (int) $l['alvo_id'];

        if ($tipo === 'perfil') {
            $mapa = ['usuarios' => ['User', 'glpi_users'], 'grupos' => ['Group', 'glpi_groups'], 'perfis' => ['Profile', 'glpi_profiles']];
            if (!isset($mapa[$recurso])) {
                return 0;
            }
            [$itemtype, $tabela] = $mapa[$recurso];
            $r = PluginGlpisasRegistro::TABELA;
            $where = [$r . '.itemtype' => $itemtype, $r . '.profiles_id' => $alvo];
            if ($tabela === 'glpi_users') {
                $where[$tabela . '.is_deleted'] = 0;
            }
            if ($inicio !== null) {
                $where[] = [$r . '.date_creation' => ['>=', $inicio]];
            }
            return (int) ($DB->request([
                'COUNT'      => 'n',
                'FROM'       => $r,
                'INNER JOIN' => [$tabela => ['ON' => [$tabela => 'id', $r => 'items_id']]],
                'WHERE'      => $where,
            ])->current()['n'] ?? 0);
        }

        if ($tipo === 'grupo' && $recurso === 'membros') {
            return (int) countElementsInTable('glpi_groups_users', ['groups_id' => $alvo]);
        }

        if ($tipo === 'grupo' && $recurso === 'chamados') {
            $where = [
                'glpi_tickets.is_deleted' => 0,
                'OR' => [
                    'glpi_tickets.users_id_recipient' => new QuerySubQuery(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $alvo]]),
                    'glpi_tickets.id' => new QuerySubQuery(['SELECT' => 'tickets_id', 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['groups_id' => $alvo, 'type' => CommonITILActor::REQUESTER]]),
                ],
            ];
            if ($inicio !== null) {
                $where[] = ['glpi_tickets.date_creation' => ['>=', $inicio]];
            }
            return (int) ($DB->request(['COUNT' => 'n', 'FROM' => 'glpi_tickets', 'WHERE' => $where])->current()['n'] ?? 0);
        }

        if ($tipo === 'entidade') {
            $tabela = ['chamados' => 'glpi_tickets', 'mudancas' => 'glpi_changes', 'problemas' => 'glpi_problems', 'projetos' => 'glpi_projects'][$recurso] ?? '';
            if ($tabela === '') {
                return 0;
            }
            $where = ['entities_id' => self::entidadesDe($l), 'is_deleted' => 0];
            if ($inicio !== null) {
                $where[] = ['date_creation' => ['>=', $inicio]];
            }
            return (int) ($DB->request(['COUNT' => 'n', 'FROM' => $tabela, 'WHERE' => $where])->current()['n'] ?? 0);
        }
        return 0;
    }

    /** Limites ativos de um recurso que valem para os alvos informados */
    public static function aplicaveis(string $tipo, array $alvos, string $recurso): array
    {
        global $DB;
        $alvos = array_values(array_unique(array_map('intval', $alvos)));
        if (!$alvos) {
            return [];
        }
        return iterator_to_array($DB->request([
            'FROM'  => self::TABELA,
            'WHERE' => ['tipo_alvo' => $tipo, 'recurso' => $recurso, 'is_active' => 1, 'alvo_id' => $alvos, 'quantidade' => ['>', 0]],
        ]), false);
    }

    /** Limites de entidade que valem para um item criado na entidade (ela mesma e as mães que incluem filhas) */
    public static function aplicaveisEntidade(int $entidade, string $recurso): array
    {
        $validos = [];
        foreach (self::aplicaveis('entidade', PluginGlpisasConfig::ancestrais($entidade), $recurso) as $l) {
            if ((int) $l['alvo_id'] === $entidade || (int) $l['incluir_filhas']) {
                $validos[] = $l;
            }
        }
        return $validos;
    }

    // =====================================================================
    // Gravação
    // =====================================================================

    /** @return array{0: bool, 1: string, 2: int} */
    public static function salvar(array $in): array
    {
        global $DB;
        $tipos = self::tipos();
        $tipo = (string) ($in['tipo_alvo'] ?? '');
        if (!isset($tipos[$tipo])) {
            return [false, 'Tipo de limite inválido.', 0];
        }
        $id = (int) ($in['id'] ?? 0);
        $recurso = (string) ($in['recurso'] ?? '');
        $dados = [
            'tipo_alvo'      => $tipo,
            'alvo_id'        => (int) ($in['alvo_id'] ?? 0),
            'recurso'        => $recurso,
            'quantidade'     => max(0, (int) ($in['quantidade'] ?? 0)),
            'periodo'        => array_key_exists((string) ($in['periodo'] ?? ''), self::periodos()) ? $in['periodo'] : 'mes',
            'data_inicio'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', substr((string) ($in['data_inicio'] ?? ''), 0, 10)) ? substr($in['data_inicio'], 0, 10) : null,
            'incluir_filhas' => $tipo === 'entidade' && !empty($in['incluir_filhas']) ? 1 : 0,
            'acao'           => array_key_exists((string) ($in['acao'] ?? ''), self::acoes()) ? $in['acao'] : 'bloquear',
            'is_active'      => !empty($in['is_active']) ? 1 : 0,
            'comment'        => (string) ($in['comment'] ?? ''),
        ];
        if (!isset($tipos[$tipo]['recursos'][$recurso])) {
            return [false, 'Escolha o que será limitado.', $id];
        }
        if ($dados['alvo_id'] <= 0 && $tipo !== 'entidade') {
            return [false, 'Escolha o ' . mb_strtolower($tipos[$tipo]['singular']) . '.', $id];
        }
        if ($tipo === 'entidade' && $dados['alvo_id'] < 0) {
            return [false, 'Escolha a entidade.', $id];
        }
        if ($dados['quantidade'] <= 0) {
            return [false, 'Informe uma quantidade maior que zero.', $id];
        }
        if ($recurso === 'membros') {
            $dados['periodo'] = 'total';
            $dados['data_inicio'] = null;
        }
        if ($dados['periodo'] !== 'total') {
            $dados['data_inicio'] = null;
        }
        $duplicado = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['tipo_alvo' => $tipo, 'alvo_id' => $dados['alvo_id'], 'recurso' => $recurso, 'id' => ['<>', $id]], 'LIMIT' => 1])->current();
        if ($duplicado) {
            return [false, 'Já existe um limite de "' . self::rotuloRecurso($tipo, $recurso) . '" para ' . self::nomeAlvo($tipo, $dados['alvo_id']) . '. Edite o existente.', $id];
        }

        $descricao = $tipos[$tipo]['singular'] . ' ' . self::nomeAlvo($tipo, $dados['alvo_id']) . ' · ' . self::rotuloRecurso($tipo, $recurso);
        if ($id > 0) {
            $antes = self::obter($id);
            if (!$antes) {
                return [false, 'Limite não encontrado.', 0];
            }
            $DB->update(self::TABELA, $dados, ['id' => $id]);
            $mudou = self::diferencas($antes, $dados);
            if ($mudou) {
                PluginGlpisasRegistro::auditar('alterar', 'limite', $id, $descricao, json_encode($mudou[0], JSON_UNESCAPED_UNICODE), json_encode($mudou[1], JSON_UNESCAPED_UNICODE));
            }
            return [true, 'Limite atualizado.', $id];
        }
        $dados['users_id'] = (int) Session::getLoginUserID();
        $DB->insert(self::TABELA, $dados);
        $id = (int) $DB->insertId();
        PluginGlpisasRegistro::auditar('criar', 'limite', $id, $descricao, null, json_encode(self::resumo($dados), JSON_UNESCAPED_UNICODE));
        return [true, 'Limite criado.', $id];
    }

    public static function excluir(int $id): bool
    {
        global $DB;
        $l = self::obter($id);
        if (!$l) {
            return false;
        }
        $DB->delete(self::TABELA, ['id' => $id]);
        PluginGlpisasRegistro::auditar('excluir', 'limite', $id, self::tipos()[$l['tipo_alvo']]['singular'] . ' ' . self::nomeAlvo($l['tipo_alvo'], (int) $l['alvo_id']) . ' · ' . self::rotuloRecurso($l['tipo_alvo'], $l['recurso']), json_encode(self::resumo($l), JSON_UNESCAPED_UNICODE), null);
        return true;
    }

    public static function alternar(int $id): ?int
    {
        global $DB;
        $l = self::obter($id);
        if (!$l) {
            return null;
        }
        $novo = (int) $l['is_active'] ? 0 : 1;
        $DB->update(self::TABELA, ['is_active' => $novo], ['id' => $id]);
        PluginGlpisasRegistro::auditar('alterar', 'limite', $id, self::nomeAlvo($l['tipo_alvo'], (int) $l['alvo_id']) . ' · ' . self::rotuloRecurso($l['tipo_alvo'], $l['recurso']), json_encode(['ativo' => (int) $l['is_active'] ? 'Sim' : 'Não'], JSON_UNESCAPED_UNICODE), json_encode(['ativo' => $novo ? 'Sim' : 'Não'], JSON_UNESCAPED_UNICODE));
        return $novo;
    }

    private static function resumo(array $d): array
    {
        return [
            'quantidade' => (int) $d['quantidade'],
            'periodo'    => self::descreverPeriodo($d),
            'filhas'     => (int) ($d['incluir_filhas'] ?? 0) ? 'Sim' : 'Não',
            'acao'       => self::acoes()[$d['acao']] ?? $d['acao'],
            'ativo'      => (int) $d['is_active'] ? 'Sim' : 'Não',
        ];
    }

    private static function diferencas(array $antes, array $depois): ?array
    {
        $a = self::resumo($antes);
        $d = self::resumo($depois);
        if (strip_tags((string) $antes['comment']) !== strip_tags((string) $depois['comment'])) {
            $a['observação'] = trim(strip_tags((string) $antes['comment']));
            $d['observação'] = trim(strip_tags((string) $depois['comment']));
        }
        if ((int) $antes['alvo_id'] !== (int) $depois['alvo_id'] || $antes['recurso'] !== $depois['recurso']) {
            $a['alvo'] = self::nomeAlvo($antes['tipo_alvo'], (int) $antes['alvo_id']) . ' · ' . self::rotuloRecurso($antes['tipo_alvo'], $antes['recurso']);
            $d['alvo'] = self::nomeAlvo($depois['tipo_alvo'], (int) $depois['alvo_id']) . ' · ' . self::rotuloRecurso($depois['tipo_alvo'], $depois['recurso']);
        }
        $va = array_diff_assoc($a, $d);
        $vd = array_diff_assoc($d, $a);
        return $va || $vd ? [$va, $vd] : null;
    }

    // =====================================================================
    // Telas
    // =====================================================================

    /** Formulário nativo de criação/edição (POST para limites.php) */
    public static function formulario(string $tipo, array $l): void
    {
        $t = self::tipos()[$tipo];
        $id = (int) ($l['id'] ?? 0);
        $e = [PluginGlpisasConfig::class, 'e'];
        echo '<form method="post" action="' . $e(PluginGlpisasConfig::url('limites.php', ['aba' => $tipo])) . '" class="glpisas-form">';
        echo '<input type="hidden" name="save_action" value="salvar_limite">';
        echo '<input type="hidden" name="tipo_alvo" value="' . $e($tipo) . '">';
        echo '<input type="hidden" name="id" value="' . $id . '">';
        echo '<table class="tab_cadre_fixe glpisas-tabela-form">';
        echo '<tr class="tab_bg_2"><th colspan="4"><i class="' . $t['icone'] . '"></i> ' . ($id ? 'Editar limite' : 'Novo limite') . ' · ' . $e($t['singular']) . '</th></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">' . $e($t['singular']) . ' <span class="required">*</span></td><td>';
        $valor = (int) ($l['alvo_id'] ?? 0);
        if ($tipo === 'perfil') {
            Profile::dropdown(['name' => 'alvo_id', 'value' => $valor, 'width' => '100%']);
        } elseif ($tipo === 'entidade') {
            Entity::dropdown(['name' => 'alvo_id', 'value' => $valor, 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
        } else {
            Group::dropdown(['name' => 'alvo_id', 'value' => $valor, 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
        }
        echo '</td><td class="glpisas-rotulo">Limitar <span class="required">*</span></td><td>';
        Dropdown::showFromArray('recurso', $t['recursos'], ['value' => $l['recurso'] ?? array_key_first($t['recursos']), 'width' => '100%', 'rand' => 'glpisas_recurso']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Quantidade máxima <span class="required">*</span></td><td><input type="number" min="1" step="1" class="form-control" name="quantidade" value="' . $e($l['quantidade'] ?? '') . '" required></td>';
        echo '<td class="glpisas-rotulo glpisas-so-periodo">Período</td><td class="glpisas-so-periodo">';
        Dropdown::showFromArray('periodo', self::periodos(), ['value' => $l['periodo'] ?? 'mes', 'width' => '100%', 'rand' => 'glpisas_periodo']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1 glpisas-so-total"><td class="glpisas-rotulo">Contar a partir de</td><td>';
        Html::showDateField('data_inicio', ['value' => $l['data_inicio'] ?? '', 'maybeempty' => true]);
        echo '<div class="glpisas-ajuda">Vazio conta desde sempre.</div></td><td></td><td></td></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Ao atingir</td><td>';
        Dropdown::showFromArray('acao', self::acoes(), ['value' => $l['acao'] ?? 'bloquear', 'width' => '100%']);
        echo '</td><td class="glpisas-rotulo">Ativo</td><td>' . self::interruptor('is_active', (int) ($l['is_active'] ?? 1)) . '</td></tr>';

        if ($tipo === 'entidade') {
            echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Incluir entidades filhas</td><td colspan="3">' . self::interruptor('incluir_filhas', (int) ($l['incluir_filhas'] ?? 0))
                . '<span class="glpisas-ajuda ms-2">Soma os itens das entidades filhas e aplica o limite a elas também.</span></td></tr>';
        }

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Observação</td><td colspan="3">';
        Html::textarea(['name' => 'comment', 'value' => $l['comment'] ?? '', 'enable_richtext' => true, 'cols' => 100, 'rows' => 4]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_2"><td colspan="4" class="center"><div class="glpisas-acoes-form">';
        echo '<button type="submit" class="btn btn-primary glpisas-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button>';
        echo '<a class="btn btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('limites.php', ['aba' => $tipo])) . '"><i class="ti ti-x"></i> Cancelar</a>';
        echo '</div></td></tr>';
        echo '</table>';
        Html::closeForm();
    }

    public static function interruptor(string $nome, int $marcado): string
    {
        $id = 'glpisas_' . $nome . '_' . mt_rand();
        return '<div class="form-check form-switch glpisas-switch"><input type="hidden" name="' . $nome . '" value="0">'
            . '<input class="form-check-input" type="checkbox" role="switch" id="' . $id . '" name="' . $nome . '" value="1"' . ($marcado ? ' checked' : '') . '>'
            . '<label class="form-check-label" for="' . $id . '">' . ($marcado ? 'Sim' : 'Não') . '</label></div>';
    }

    /** Tabela de limites com uso; $comAcoes inclui editar/ativar/excluir */
    public static function tabela(array $limites, bool $comAcoes, bool $mostrarTipo = false): void
    {
        $e = [PluginGlpisasConfig::class, 'e'];
        $tipos = self::tipos();
        if (!$limites) {
            echo '<div class="glpisas-vazio"><i class="ti ti-mood-empty"></i> Nenhum limite cadastrado.</div>';
            return;
        }
        echo '<div class="table-responsive"><table class="table table-hover table-sm glpisas-lista">';
        echo '<thead><tr>' . ($mostrarTipo ? '<th>Tipo</th>' : '') . '<th>Alvo</th><th>Limitar</th><th>Período</th><th class="glpisas-col-uso">Uso</th><th>Ao atingir</th><th class="text-center">Ativo</th>' . ($comAcoes ? '<th class="text-end">Ações</th>' : '') . '</tr></thead><tbody>';
        foreach ($limites as $l) {
            $uso = self::uso($l);
            $ativo = (int) $l['is_active'];
            $t = $tipos[$l['tipo_alvo']] ?? null;
            $nome = $l['_nome'] ?? self::nomeAlvo($l['tipo_alvo'], (int) $l['alvo_id']);
            echo '<tr class="' . ($ativo ? '' : 'glpisas-inativo') . '" data-id="' . (int) $l['id'] . '">';
            if ($mostrarTipo) {
                echo '<td><span class="glpisas-etiqueta"><i class="' . ($t['icone'] ?? '') . '"></i> ' . $e($t['singular'] ?? $l['tipo_alvo']) . '</span></td>';
            }
            echo '<td><strong>' . $e($nome) . '</strong>' . ((int) $l['incluir_filhas'] ? ' <span class="glpisas-etiqueta" title="Inclui as entidades filhas"><i class="ti ti-sitemap"></i> com filhas</span>' : '');
            $obs = trim(strip_tags((string) $l['comment']));
            if ($obs !== '') {
                echo '<div class="glpisas-ajuda">' . $e(mb_strimwidth(html_entity_decode($obs, ENT_QUOTES, 'UTF-8'), 0, 120, '…')) . '</div>';
            }
            echo '</td>';
            echo '<td>' . $e(self::rotuloRecurso($l['tipo_alvo'], $l['recurso'])) . '</td>';
            echo '<td>' . $e(self::descreverPeriodo($l)) . '</td>';
            echo '<td>' . PluginGlpisasConfig::barra($uso, (int) $l['quantidade']) . '</td>';
            echo '<td>' . ($l['acao'] === 'bloquear' ? '<span class="glpisas-pill glpisas-pill-bloquear">Bloquear</span>' : '<span class="glpisas-pill glpisas-pill-avisar">Avisar</span>') . '</td>';
            if ($comAcoes) {
                echo '<td class="text-center"><div class="form-check form-switch glpisas-switch d-inline-block"><input class="form-check-input" type="checkbox" role="switch" data-glpisas-alternar="limite" data-id="' . (int) $l['id'] . '"' . ($ativo ? ' checked' : '') . ' title="Ativar ou desativar"></div></td>';
                echo '<td class="text-end"><div class="glpisas-acoes">';
                echo '<a class="btn btn-sm btn-outline-secondary" title="Editar" href="' . $e(PluginGlpisasConfig::url('limites.php', ['aba' => $l['tipo_alvo'], 'editar' => (int) $l['id']])) . '"><i class="ti ti-edit"></i></a>';
                echo '<button type="button" class="btn btn-sm btn-outline-danger" data-glpisas-excluir="limite" data-id="' . (int) $l['id'] . '" title="Excluir"><i class="ti ti-trash"></i></button>';
                echo '</div></td>';
            } else {
                echo '<td class="text-center">' . ($ativo ? '<i class="ti ti-check text-success"></i>' : '<i class="ti ti-x text-muted"></i>') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
