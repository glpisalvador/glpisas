<?php

use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QuerySubQuery;

/**
 * Plugin GLPI SAS - controle de horas contratadas (entidade, grupo ou usuário)
 */
class PluginGlpisasHoras extends CommonGLPI
{
    public const TABELA = 'glpi_plugin_glpisas_horas';
    /** Pausas de SLA do plugin botoesadicionais (comunicação direta, usada só se existir) */
    public const PAUSAS = 'glpi_plugin_botoesadicionais_sla_armazenamento';

    public static function getTypeName($nb = 0): string
    {
        return 'Controle de horas';
    }

    public static function tipos(): array
    {
        return [
            'entidade' => ['Entidade', 'ti ti-building'],
            'grupo'    => ['Grupo', 'ti ti-users'],
            'usuario'  => ['Usuário', 'ti ti-user'],
        ];
    }

    public static function periodos(): array
    {
        return ['mes' => 'Mensal (renova todo mês)', 'contrato' => 'Período do contrato', 'total' => 'Total'];
    }

    public static function fontes(): array
    {
        $fontes = ['tarefas' => 'Duração das tarefas'];
        $fontes['atendimento'] = 'Tempo de atendimento (início do atendimento até a solução)';
        return $fontes;
    }

    public static function aoExceder(): array
    {
        return ['avisar' => 'Avisar ao abrir chamado', 'bloquear' => 'Bloquear novos chamados', 'nada' => 'Só acompanhar'];
    }

    public static function obter(int $id): ?array
    {
        global $DB;
        $linha = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['id' => $id], 'LIMIT' => 1])->current();
        return $linha ?: null;
    }

    public static function listar(bool $somenteAtivos = false, ?string $tipo = null, ?int $alvo = null): array
    {
        global $DB;
        $where = [];
        if ($somenteAtivos) {
            $where['is_active'] = 1;
        }
        if ($tipo !== null) {
            $where['tipo_alvo'] = $tipo;
        }
        if ($alvo !== null) {
            $where['alvo_id'] = $alvo;
        }
        $linhas = iterator_to_array($DB->request(['FROM' => self::TABELA, 'WHERE' => $where]), false);
        foreach ($linhas as &$h) {
            $h['_nome'] = self::nomeAlvo((string) $h['tipo_alvo'], (int) $h['alvo_id']);
        }
        unset($h);
        usort($linhas, static fn($a, $b) => [$a['tipo_alvo'], mb_strtolower($a['_nome'])] <=> [$b['tipo_alvo'], mb_strtolower($b['_nome'])]);
        return $linhas;
    }

    public static function nomeAlvo(string $tipo, int $id): string
    {
        if ($tipo === 'usuario') {
            $nome = getUserName($id);
            return $nome !== '' ? $nome : '#' . $id;
        }
        $tabela = $tipo === 'grupo' ? 'glpi_groups' : 'glpi_entities';
        $nome = Dropdown::getDropdownName($tabela, $id);
        return ($nome === '' || $nome === '&nbsp;') ? '#' . $id . ' (não encontrado)' : html_entity_decode($nome, ENT_QUOTES, 'UTF-8');
    }

    /** Janela de apuração [início, fim] em 'Y-m-d H:i:s' (null = aberto) */
    public static function janela(array $h): array
    {
        if ($h['periodo'] === 'mes') {
            return [date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')];
        }
        $inicio = !empty($h['data_inicio']) ? $h['data_inicio'] . ' 00:00:00' : null;
        $fim = ($h['periodo'] === 'contrato' && !empty($h['data_fim'])) ? $h['data_fim'] . ' 23:59:59' : null;
        return [$inicio, $fim];
    }

    public static function descreverPeriodo(array $h): string
    {
        if ($h['periodo'] === 'mes') {
            return 'Mensal · ' . date('m/Y');
        }
        [$i, $f] = [$h['data_inicio'] ?? null, $h['data_fim'] ?? null];
        if ($h['periodo'] === 'contrato') {
            return 'Contrato ' . ($i ? Html::convDate($i) : '…') . ' a ' . ($f ? Html::convDate($f) : '…');
        }
        return $i ? 'Total desde ' . Html::convDate($i) : 'Total';
    }

    /** Condição sobre glpi_tickets que seleciona os chamados do alvo */
    public static function condicaoChamados(array $h): array
    {
        $alvo = (int) $h['alvo_id'];
        $where = ['glpi_tickets.is_deleted' => 0];
        if ($h['tipo_alvo'] === 'entidade') {
            $where['glpi_tickets.entities_id'] = (int) $h['incluir_filhas'] ? array_map('intval', array_values(getSonsOf('glpi_entities', $alvo))) : $alvo;
        } elseif ($h['tipo_alvo'] === 'usuario') {
            $where['glpi_tickets.id'] = new QuerySubQuery(['SELECT' => 'tickets_id', 'FROM' => 'glpi_tickets_users', 'WHERE' => ['users_id' => $alvo, 'type' => CommonITILActor::REQUESTER]]);
        } else {
            $where['OR'] = [
                ['glpi_tickets.id' => new QuerySubQuery([
                    'SELECT' => 'tickets_id',
                    'FROM'   => 'glpi_tickets_users',
                    'WHERE'  => ['type' => CommonITILActor::REQUESTER, 'users_id' => new QuerySubQuery(['SELECT' => 'users_id', 'FROM' => 'glpi_groups_users', 'WHERE' => ['groups_id' => $alvo]])],
                ])],
                ['glpi_tickets.id' => new QuerySubQuery(['SELECT' => 'tickets_id', 'FROM' => 'glpi_groups_tickets', 'WHERE' => ['groups_id' => $alvo, 'type' => CommonITILActor::REQUESTER]])],
            ];
        }
        return $where;
    }

    /**
     * Consumo no período: minutos totais, chamados e (opcional) o detalhe por chamado
     * @return array{minutos: int, chamados: int, detalhes: array}
     */
    public static function consumo(array $h, bool $comDetalhes = false): array
    {
        global $DB;
        [$inicio, $fim] = self::janela($h);
        $porChamado = [];

        if ($h['fonte'] === 'atendimento') {
            $where = self::condicaoChamados($h);
            $where[] = ['NOT' => ['glpi_tickets.takeintoaccountdate' => null]];
            if ($inicio) {
                $where[] = ['glpi_tickets.date' => ['>=', $inicio]];
            }
            if ($fim) {
                $where[] = ['glpi_tickets.date' => ['<=', $fim]];
            }
            $pausas = $DB->tableExists(self::PAUSAS);
            foreach ($DB->request(['SELECT' => ['glpi_tickets.id', 'glpi_tickets.name', 'glpi_tickets.date', 'glpi_tickets.status', 'glpi_tickets.entities_id', 'glpi_tickets.takeintoaccountdate', 'glpi_tickets.solvedate'], 'FROM' => 'glpi_tickets', 'WHERE' => $where]) as $t) {
                $min = self::minutosAtendimento($t, $pausas);
                if ($min > 0) {
                    $porChamado[(int) $t['id']] = ['minutos' => $min, 'ticket' => $t];
                }
            }
        } else {
            $where = self::condicaoChamados($h);
            $where[] = ['glpi_tickettasks.actiontime' => ['>', 0]];
            if ($inicio) {
                $where[] = ['glpi_tickettasks.date' => ['>=', $inicio]];
            }
            if ($fim) {
                $where[] = ['glpi_tickettasks.date' => ['<=', $fim]];
            }
            $iterador = $DB->request([
                'SELECT'     => ['glpi_tickets.id', 'glpi_tickets.name', 'glpi_tickets.date', 'glpi_tickets.status', 'glpi_tickets.entities_id', new QueryExpression('SUM(' . $DB->quoteName('glpi_tickettasks.actiontime') . ') AS ' . $DB->quoteName('segundos'))],
                'FROM'       => 'glpi_tickettasks',
                'INNER JOIN' => ['glpi_tickets' => ['ON' => ['glpi_tickettasks' => 'tickets_id', 'glpi_tickets' => 'id']]],
                'WHERE'      => $where,
                'GROUPBY'    => ['glpi_tickets.id', 'glpi_tickets.name', 'glpi_tickets.date', 'glpi_tickets.status', 'glpi_tickets.entities_id'],
            ]);
            foreach ($iterador as $t) {
                $min = (int) round(((int) $t['segundos']) / 60);
                if ($min > 0) {
                    $porChamado[(int) $t['id']] = ['minutos' => $min, 'ticket' => $t];
                }
            }
        }

        $total = array_sum(array_column($porChamado, 'minutos'));
        $detalhes = [];
        if ($comDetalhes) {
            uasort($porChamado, static fn($a, $b) => strcmp((string) $b['ticket']['date'], (string) $a['ticket']['date']));
            foreach ($porChamado as $id => $p) {
                $detalhes[] = [
                    'id'       => $id,
                    'nome'     => (string) $p['ticket']['name'],
                    'data'     => (string) $p['ticket']['date'],
                    'status'   => Ticket::getStatus((int) $p['ticket']['status']),
                    'entidade' => html_entity_decode(Dropdown::getDropdownName('glpi_entities', (int) $p['ticket']['entities_id']), ENT_QUOTES, 'UTF-8'),
                    'minutos'  => $p['minutos'],
                ];
            }
        }
        return ['minutos' => (int) $total, 'chamados' => count($porChamado), 'detalhes' => $detalhes];
    }

    /** Minutos de atendimento de um chamado descontando as pausas registradas pelo botoesadicionais */
    public static function minutosAtendimento(array $t, bool $comPausas): int
    {
        global $DB;
        if (empty($t['takeintoaccountdate'])) {
            return 0;
        }
        $inicio = strtotime((string) $t['takeintoaccountdate']);
        $id = (int) $t['id'];
        if (!empty($t['solvedate'])) {
            $fim = strtotime((string) $t['solvedate']);
        } elseif (in_array((int) $t['status'], [CommonITILObject::SOLVED, CommonITILObject::CLOSED], true)) {
            return 0;
        } else {
            $fim = time();
            if ($comPausas) {
                $ativa = $DB->request(['SELECT' => ['sla_pausado'], 'FROM' => self::PAUSAS, 'WHERE' => ['tickets_id' => $id, 'ativo' => 1], 'LIMIT' => 1])->current();
                if ($ativa && !empty($ativa['sla_pausado'])) {
                    $fim = strtotime((string) $ativa['sla_pausado']);
                }
            }
        }
        $pausado = 0;
        if ($comPausas) {
            $ultima = $DB->request(['SELECT' => ['tempo_pausado_acumulado'], 'FROM' => self::PAUSAS, 'WHERE' => ['tickets_id' => $id, 'ativo' => 0], 'ORDER' => 'id DESC', 'LIMIT' => 1])->current();
            $pausado = (int) ($ultima['tempo_pausado_acumulado'] ?? 0);
        }
        return (int) floor(max(0, $fim - $inicio - $pausado) / 60);
    }

    /** Valores do período: dentro do contratado, excedente e totais */
    public static function financeiro(array $h, int $consumido): array
    {
        $contratado = (int) $h['minutos_contratados'];
        $dentro = $contratado > 0 ? min($consumido, $contratado) : $consumido;
        $excedente = $contratado > 0 ? max(0, $consumido - $contratado) : 0;
        $valorDentro = round($dentro / 60 * (float) $h['valor_hora'], 2);
        $valorExcedente = round($excedente / 60 * (float) $h['valor_hora_excedente'], 2);
        return [
            'contratado'      => $contratado,
            'consumido'       => $consumido,
            'dentro'          => $dentro,
            'excedente'       => $excedente,
            'saldo'           => $contratado - $consumido,
            'valor_dentro'    => $valorDentro,
            'valor_excedente' => $valorExcedente,
            'valor_total'     => round($valorDentro + $valorExcedente, 2),
        ];
    }

    /** Controles ativos que valem para um chamado novo (entidade, requerentes e grupos deles) */
    public static function aplicaveisChamado(int $entidade, array $usuarios, array $grupos): array
    {
        $validos = [];
        foreach (self::listar(true, 'entidade') as $h) {
            $a = (int) $h['alvo_id'];
            if ($a === $entidade || ((int) $h['incluir_filhas'] && in_array($a, PluginGlpisasConfig::ancestrais($entidade), true))) {
                $validos[] = $h;
            }
        }
        if ($usuarios) {
            foreach (self::listar(true, 'usuario') as $h) {
                if (in_array((int) $h['alvo_id'], $usuarios, true)) {
                    $validos[] = $h;
                }
            }
        }
        if ($grupos) {
            foreach (self::listar(true, 'grupo') as $h) {
                if (in_array((int) $h['alvo_id'], $grupos, true)) {
                    $validos[] = $h;
                }
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
        $id = (int) ($in['id'] ?? 0);
        $tipo = (string) ($in['tipo_alvo'] ?? '');
        if (!isset(self::tipos()[$tipo])) {
            return [false, 'Escolha o tipo do controle.', $id];
        }
        $alvo = (int) ($in['alvo_id_' . $tipo] ?? ($in['alvo_id'] ?? 0));
        $horas = trim((string) ($in['horas_contratadas'] ?? '0'));
        if (preg_match('/^(\d+):(\d{1,2})$/', $horas, $m)) {
            $minutos = (int) $m[1] * 60 + (int) $m[2];
        } else {
            $minutos = (int) round(PluginGlpisasConfig::lerValor($horas) * 60);
        }
        $dados = [
            'tipo_alvo'            => $tipo,
            'alvo_id'              => $alvo,
            'minutos_contratados'  => max(0, $minutos),
            'periodo'              => array_key_exists((string) ($in['periodo'] ?? ''), self::periodos()) ? $in['periodo'] : 'mes',
            'data_inicio'          => preg_match('/^\d{4}-\d{2}-\d{2}$/', substr((string) ($in['data_inicio'] ?? ''), 0, 10)) ? substr($in['data_inicio'], 0, 10) : null,
            'data_fim'             => preg_match('/^\d{4}-\d{2}-\d{2}$/', substr((string) ($in['data_fim'] ?? ''), 0, 10)) ? substr($in['data_fim'], 0, 10) : null,
            'valor_hora'           => PluginGlpisasConfig::lerValor($in['valor_hora'] ?? 0),
            'valor_hora_excedente' => PluginGlpisasConfig::lerValor($in['valor_hora_excedente'] ?? 0),
            'fonte'                => array_key_exists((string) ($in['fonte'] ?? ''), self::fontes()) ? $in['fonte'] : 'tarefas',
            'incluir_filhas'       => $tipo === 'entidade' && !empty($in['incluir_filhas']) ? 1 : 0,
            'ao_exceder'           => array_key_exists((string) ($in['ao_exceder'] ?? ''), self::aoExceder()) ? $in['ao_exceder'] : 'avisar',
            'is_active'            => !empty($in['is_active']) ? 1 : 0,
            'comment'              => (string) ($in['comment'] ?? ''),
        ];
        if ($alvo <= 0 && $tipo !== 'entidade') {
            return [false, 'Escolha o ' . mb_strtolower(self::tipos()[$tipo][0]) . '.', $id];
        }
        if ($alvo < 0) {
            return [false, 'Escolha a entidade.', $id];
        }
        if ($dados['minutos_contratados'] <= 0) {
            return [false, 'Informe as horas contratadas (ex.: 40 ou 40:30).', $id];
        }
        if ($dados['periodo'] === 'mes') {
            $dados['data_inicio'] = null;
            $dados['data_fim'] = null;
        } elseif ($dados['periodo'] === 'total') {
            $dados['data_fim'] = null;
        } elseif (!$dados['data_inicio'] || !$dados['data_fim']) {
            return [false, 'Informe o início e o fim do contrato.', $id];
        } elseif ($dados['data_fim'] < $dados['data_inicio']) {
            return [false, 'O fim do contrato é anterior ao início.', $id];
        }
        $dup = $DB->request(['FROM' => self::TABELA, 'WHERE' => ['tipo_alvo' => $tipo, 'alvo_id' => $alvo, 'id' => ['<>', $id]], 'LIMIT' => 1])->current();
        if ($dup) {
            return [false, 'Já existe um controle de horas para ' . self::nomeAlvo($tipo, $alvo) . '. Edite o existente.', $id];
        }
        $descricao = self::tipos()[$tipo][0] . ' ' . self::nomeAlvo($tipo, $alvo);
        if ($id > 0) {
            $antes = self::obter($id);
            if (!$antes) {
                return [false, 'Controle não encontrado.', 0];
            }
            $DB->update(self::TABELA, $dados, ['id' => $id]);
            $a = self::resumo($antes);
            $d = self::resumo($dados);
            if ((int) $antes['alvo_id'] !== $alvo || $antes['tipo_alvo'] !== $tipo) {
                $a['alvo'] = self::nomeAlvo($antes['tipo_alvo'], (int) $antes['alvo_id']);
                $d['alvo'] = self::nomeAlvo($tipo, $alvo);
            }
            $va = array_diff_assoc($a, $d);
            $vd = array_diff_assoc($d, $a);
            if ($va || $vd) {
                PluginGlpisasRegistro::auditar('alterar', 'horas', $id, $descricao, json_encode($va, JSON_UNESCAPED_UNICODE), json_encode($vd, JSON_UNESCAPED_UNICODE));
            }
            return [true, 'Controle de horas atualizado.', $id];
        }
        $dados['users_id'] = (int) Session::getLoginUserID();
        $DB->insert(self::TABELA, $dados);
        $id = (int) $DB->insertId();
        PluginGlpisasRegistro::auditar('criar', 'horas', $id, $descricao, null, json_encode(self::resumo($dados), JSON_UNESCAPED_UNICODE));
        return [true, 'Controle de horas criado.', $id];
    }

    public static function excluir(int $id): bool
    {
        global $DB;
        $h = self::obter($id);
        if (!$h) {
            return false;
        }
        $DB->delete(self::TABELA, ['id' => $id]);
        PluginGlpisasRegistro::auditar('excluir', 'horas', $id, self::tipos()[$h['tipo_alvo']][0] . ' ' . self::nomeAlvo($h['tipo_alvo'], (int) $h['alvo_id']), json_encode(self::resumo($h), JSON_UNESCAPED_UNICODE), null);
        return true;
    }

    public static function alternar(int $id): ?int
    {
        global $DB;
        $h = self::obter($id);
        if (!$h) {
            return null;
        }
        $novo = (int) $h['is_active'] ? 0 : 1;
        $DB->update(self::TABELA, ['is_active' => $novo], ['id' => $id]);
        PluginGlpisasRegistro::auditar('alterar', 'horas', $id, self::nomeAlvo($h['tipo_alvo'], (int) $h['alvo_id']), json_encode(['ativo' => $novo ? 'Não' : 'Sim'], JSON_UNESCAPED_UNICODE), json_encode(['ativo' => $novo ? 'Sim' : 'Não'], JSON_UNESCAPED_UNICODE));
        return $novo;
    }

    private static function resumo(array $h): array
    {
        return [
            'contratado'  => PluginGlpisasConfig::tempo((int) $h['minutos_contratados']),
            'periodo'     => self::descreverPeriodo($h),
            'valor_hora'  => PluginGlpisasConfig::reais((float) $h['valor_hora']),
            'excedente'   => PluginGlpisasConfig::reais((float) $h['valor_hora_excedente']),
            'fonte'       => self::fontes()[$h['fonte']] ?? $h['fonte'],
            'filhas'      => (int) ($h['incluir_filhas'] ?? 0) ? 'Sim' : 'Não',
            'ao_exceder'  => self::aoExceder()[$h['ao_exceder']] ?? $h['ao_exceder'],
            'ativo'       => (int) $h['is_active'] ? 'Sim' : 'Não',
            'observação'  => trim(strip_tags((string) ($h['comment'] ?? ''))),
        ];
    }

    // =====================================================================
    // Telas
    // =====================================================================

    public static function formulario(array $h): void
    {
        $e = [PluginGlpisasConfig::class, 'e'];
        $id = (int) ($h['id'] ?? 0);
        $tipo = (string) ($h['tipo_alvo'] ?? 'entidade');
        $min = (int) ($h['minutos_contratados'] ?? 0);
        $horasTexto = isset($h['horas_contratadas']) ? (string) $h['horas_contratadas'] : ($min > 0 ? (intdiv($min, 60) . ($min % 60 ? ':' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT) : '')) : '');
        $moeda = static fn($v) => $v !== null && $v !== '' ? number_format((float) $v, 2, ',', '.') : '';

        echo '<form method="post" action="' . $e(PluginGlpisasConfig::url('horas.php')) . '" class="glpisas-form">';
        echo '<input type="hidden" name="save_action" value="salvar_horas"><input type="hidden" name="id" value="' . $id . '">';
        echo '<table class="tab_cadre_fixe glpisas-tabela-form">';
        echo '<tr class="tab_bg_2"><th colspan="4"><i class="ti ti-clock-dollar"></i> ' . ($id ? 'Editar controle de horas' : 'Novo controle de horas') . '</th></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Controlar por</td><td>';
        Dropdown::showFromArray('tipo_alvo', array_map(static fn($t) => $t[0], self::tipos()), ['value' => $tipo, 'width' => '100%', 'rand' => 'glpisas_tipo_horas']);
        echo '</td><td class="glpisas-rotulo">Alvo <span class="required">*</span></td><td>';
        $alvo = (int) ($h['alvo_id'] ?? 0);
        echo '<div class="glpisas-alvo" data-tipo="entidade">';
        Entity::dropdown(['name' => 'alvo_id_entidade', 'value' => $tipo === 'entidade' ? $alvo : 0, 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
        echo '</div><div class="glpisas-alvo" data-tipo="grupo">';
        Group::dropdown(['name' => 'alvo_id_grupo', 'value' => $tipo === 'grupo' ? $alvo : 0, 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
        echo '</div><div class="glpisas-alvo" data-tipo="usuario">';
        User::dropdown(['name' => 'alvo_id_usuario', 'value' => $tipo === 'usuario' ? $alvo : 0, 'right' => 'all', 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'width' => '100%']);
        echo '</div></td></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Horas contratadas <span class="required">*</span></td><td><input type="text" class="form-control" name="horas_contratadas" value="' . $e($horasTexto) . '" placeholder="Ex.: 40 ou 40:30" required></td>';
        echo '<td class="glpisas-rotulo">Período</td><td>';
        Dropdown::showFromArray('periodo', self::periodos(), ['value' => $h['periodo'] ?? 'mes', 'width' => '100%', 'rand' => 'glpisas_periodo_horas']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1 glpisas-so-datas"><td class="glpisas-rotulo">Início</td><td>';
        Html::showDateField('data_inicio', ['value' => $h['data_inicio'] ?? '', 'maybeempty' => true]);
        echo '</td><td class="glpisas-rotulo glpisas-so-contrato">Fim</td><td class="glpisas-so-contrato">';
        Html::showDateField('data_fim', ['value' => $h['data_fim'] ?? '', 'maybeempty' => true]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Valor da hora (R$)</td><td><input type="text" inputmode="decimal" class="form-control glpisas-moeda" name="valor_hora" value="' . $e($moeda($h['valor_hora'] ?? '')) . '" placeholder="0,00"></td>';
        echo '<td class="glpisas-rotulo">Valor da hora excedente (R$)</td><td><input type="text" inputmode="decimal" class="form-control glpisas-moeda" name="valor_hora_excedente" value="' . $e($moeda($h['valor_hora_excedente'] ?? '')) . '" placeholder="0,00"></td></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Tempo contado</td><td>';
        Dropdown::showFromArray('fonte', self::fontes(), ['value' => $h['fonte'] ?? 'tarefas', 'width' => '100%']);
        echo '<div class="glpisas-ajuda">As tarefas somam a duração lançada pelos técnicos. O atendimento mede do início do atendimento até a solução e desconta as pausas do plugin Botões Adicionais, quando ele estiver instalado.</div>';
        echo '</td><td class="glpisas-rotulo">Ao exceder</td><td>';
        Dropdown::showFromArray('ao_exceder', self::aoExceder(), ['value' => $h['ao_exceder'] ?? 'avisar', 'width' => '100%']);
        echo '</td></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Ativo</td><td>' . PluginGlpisasLimite::interruptor('is_active', (int) ($h['is_active'] ?? 1)) . '</td>';
        echo '<td class="glpisas-rotulo glpisas-so-entidade">Incluir entidades filhas</td><td class="glpisas-so-entidade">' . PluginGlpisasLimite::interruptor('incluir_filhas', (int) ($h['incluir_filhas'] ?? 0)) . '</td></tr>';

        echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Observação</td><td colspan="3">';
        Html::textarea(['name' => 'comment', 'value' => $h['comment'] ?? '', 'enable_richtext' => true, 'cols' => 100, 'rows' => 4]);
        echo '</td></tr>';

        echo '<tr class="tab_bg_2"><td colspan="4" class="center"><div class="glpisas-acoes-form">';
        echo '<button type="submit" class="btn btn-primary glpisas-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button>';
        echo '<a class="btn btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('horas.php')) . '"><i class="ti ti-x"></i> Cancelar</a>';
        echo '</div></td></tr></table>';
        Html::closeForm();
    }

    /** Tabela de controles com consumo e valores */
    public static function tabela(array $controles, bool $comAcoes): void
    {
        $e = [PluginGlpisasConfig::class, 'e'];
        if (!$controles) {
            echo '<div class="glpisas-vazio"><i class="ti ti-mood-empty"></i> Nenhum controle de horas cadastrado.</div>';
            return;
        }
        echo '<div class="table-responsive"><table class="table table-hover table-sm glpisas-lista">';
        echo '<thead><tr><th>Alvo</th><th>Período</th><th class="glpisas-col-uso">Consumido / contratado</th><th class="text-end">Saldo</th><th class="text-end">Dentro do contrato</th><th class="text-end">Excedente</th><th class="text-end">Total</th><th>Ao exceder</th>' . ($comAcoes ? '<th class="text-center">Ativo</th>' : '') . '<th class="text-end">Ações</th></tr></thead><tbody>';
        foreach ($controles as $h) {
            $c = self::consumo($h);
            $f = self::financeiro($h, $c['minutos']);
            $t = self::tipos()[$h['tipo_alvo']] ?? ['', ''];
            $ativo = (int) $h['is_active'];
            echo '<tr class="' . ($ativo ? '' : 'glpisas-inativo') . '" data-id="' . (int) $h['id'] . '">';
            echo '<td><span class="glpisas-etiqueta"><i class="' . $t[1] . '"></i> ' . $e($t[0]) . '</span> <strong>' . $e($h['_nome'] ?? self::nomeAlvo($h['tipo_alvo'], (int) $h['alvo_id'])) . '</strong>'
                . ((int) $h['incluir_filhas'] ? ' <span class="glpisas-etiqueta" title="Inclui as entidades filhas"><i class="ti ti-sitemap"></i> com filhas</span>' : '')
                . '<div class="glpisas-ajuda">' . $e(self::fontes()[$h['fonte']] ?? '') . ' · ' . (int) $c['chamados'] . ' chamado(s)</div></td>';
            echo '<td>' . $e(self::descreverPeriodo($h)) . '</td>';
            echo '<td>' . PluginGlpisasConfig::barra($f['consumido'], $f['contratado'], PluginGlpisasConfig::tempo($f['consumido']) . ' / ' . PluginGlpisasConfig::tempo($f['contratado'])) . '</td>';
            echo '<td class="text-end ' . ($f['saldo'] < 0 ? 'glpisas-negativo' : '') . '">' . $e(PluginGlpisasConfig::tempo($f['saldo'])) . '</td>';
            echo '<td class="text-end">' . $e(PluginGlpisasConfig::reais($f['valor_dentro'])) . '</td>';
            echo '<td class="text-end ' . ($f['excedente'] > 0 ? 'glpisas-negativo' : '') . '">' . $e(PluginGlpisasConfig::tempo($f['excedente'])) . '<div class="glpisas-ajuda">' . $e(PluginGlpisasConfig::reais($f['valor_excedente'])) . '</div></td>';
            echo '<td class="text-end"><strong>' . $e(PluginGlpisasConfig::reais($f['valor_total'])) . '</strong></td>';
            $pill = ['avisar' => 'avisar', 'bloquear' => 'bloquear', 'nada' => 'neutro'][$h['ao_exceder']] ?? 'neutro';
            echo '<td><span class="glpisas-pill glpisas-pill-' . $pill . '">' . $e(['avisar' => 'Avisar', 'bloquear' => 'Bloquear', 'nada' => 'Acompanhar'][$h['ao_exceder']] ?? '') . '</span></td>';
            if ($comAcoes) {
                echo '<td class="text-center"><div class="form-check form-switch glpisas-switch d-inline-block"><input class="form-check-input" type="checkbox" role="switch" data-glpisas-alternar="horas" data-id="' . (int) $h['id'] . '"' . ($ativo ? ' checked' : '') . ' title="Ativar ou desativar"></div></td>';
            }
            echo '<td class="text-end"><div class="glpisas-acoes">';
            echo '<button type="button" class="btn btn-sm btn-outline-secondary" data-glpisas-detalhes="' . (int) $h['id'] . '" title="Chamados que consumiram horas"><i class="ti ti-list"></i></button>';
            echo '<a class="btn btn-sm btn-outline-secondary" title="Exportar CSV" href="' . $e(PluginGlpisasConfig::url('exportar.php', ['tipo' => 'horas', 'id' => (int) $h['id']])) . '"><i class="ti ti-file-spreadsheet"></i></a>';
            if ($comAcoes) {
                echo '<a class="btn btn-sm btn-outline-secondary" title="Editar" href="' . $e(PluginGlpisasConfig::url('horas.php', ['editar' => (int) $h['id']])) . '"><i class="ti ti-edit"></i></a>';
                echo '<button type="button" class="btn btn-sm btn-outline-danger" data-glpisas-excluir="horas" data-id="' . (int) $h['id'] . '" title="Excluir"><i class="ti ti-trash"></i></button>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
