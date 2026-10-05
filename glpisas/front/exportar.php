<?php

/**
 * Plugin GLPI SAS - exportação CSV (limites, horas por chamado, criações, auditoria)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginGlpisasConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$tipo = (string) ($_GET['tipo'] ?? '');
$linhas = [];
$nome = 'glpisas';
$data = static fn($v) => $v ? Html::convDateTime($v) : '';
$txt = static fn($v) => html_entity_decode(trim(strip_tags((string) $v)), ENT_QUOTES, 'UTF-8');

switch ($tipo) {
    case 'limites':
        $nome = 'glpisas_limites';
        $linhas[] = ['Tipo', 'Alvo', 'Limitar', 'Período', 'Uso', 'Limite', 'Uso (%)', 'Ao atingir', 'Inclui filhas', 'Ativo', 'Observação'];
        foreach (PluginGlpisasLimite::listar() as $l) {
            $uso = PluginGlpisasLimite::uso($l);
            $linhas[] = [
                PluginGlpisasLimite::tipos()[$l['tipo_alvo']]['singular'] ?? $l['tipo_alvo'], $l['_nome'],
                PluginGlpisasLimite::rotuloRecurso($l['tipo_alvo'], $l['recurso']), PluginGlpisasLimite::descreverPeriodo($l),
                $uso, (int) $l['quantidade'], number_format((int) $l['quantidade'] > 0 ? $uso / (int) $l['quantidade'] * 100 : 0, 1, ',', ''),
                PluginGlpisasLimite::acoes()[$l['acao']] ?? $l['acao'], (int) $l['incluir_filhas'] ? 'Sim' : 'Não', (int) $l['is_active'] ? 'Sim' : 'Não', $txt($l['comment']),
            ];
        }
        break;

    case 'horas':
        $h = PluginGlpisasHoras::obter((int) ($_GET['id'] ?? 0));
        if (!$h) {
            throw new \Glpi\Exception\Http\NotFoundHttpException();
        }
        $c = PluginGlpisasHoras::consumo($h, true);
        $f = PluginGlpisasHoras::financeiro($h, $c['minutos']);
        $alvo = PluginGlpisasHoras::nomeAlvo($h['tipo_alvo'], (int) $h['alvo_id']);
        $nome = 'glpisas_horas_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $alvo) ?: 'alvo');
        $linhas[] = ['Controle', PluginGlpisasHoras::tipos()[$h['tipo_alvo']][0] . ' ' . $alvo];
        $linhas[] = ['Período', PluginGlpisasHoras::descreverPeriodo($h)];
        $linhas[] = ['Tempo contado', PluginGlpisasHoras::fontes()[$h['fonte']] ?? $h['fonte']];
        $linhas[] = ['Contratado', PluginGlpisasConfig::tempo($f['contratado']), 'Consumido', PluginGlpisasConfig::tempo($f['consumido']), 'Excedente', PluginGlpisasConfig::tempo($f['excedente'])];
        $linhas[] = ['Valor dentro do contrato', PluginGlpisasConfig::reais($f['valor_dentro']), 'Valor excedente', PluginGlpisasConfig::reais($f['valor_excedente']), 'Total', PluginGlpisasConfig::reais($f['valor_total'])];
        $linhas[] = [];
        $linhas[] = ['Chamado', 'Título', 'Abertura', 'Status', 'Entidade', 'Tempo', 'Minutos'];
        foreach ($c['detalhes'] as $d) {
            $linhas[] = [$d['id'], $d['nome'], $data($d['data']), $d['status'], $d['entidade'], PluginGlpisasConfig::tempo($d['minutos']), $d['minutos']];
        }
        break;

    case 'criacoes':
        $nome = 'glpisas_criacoes';
        [$regs] = PluginGlpisasRegistro::criacoes(PluginGlpisasRegistro::lerFiltros($_GET, 'criacoes'), 0, true);
        $tipos = PluginGlpisasRegistro::tipos();
        $linhas[] = ['Data', 'Tipo', 'ID', 'Item', 'Situação', 'Criado por', 'Perfil', 'Entidade'];
        foreach ($regs as $r) {
            $item = PluginGlpisasRegistro::itemCriado($r);
            $linhas[] = [
                $data($r['date_creation']), $tipos[$r['itemtype']][0] ?? $r['itemtype'], (int) $r['items_id'], $item['nome'], $item['existe'] ? 'Ativo' : 'Excluído',
                (int) $r['users_id'] ? getUserName((int) $r['users_id']) : 'Automático',
                (int) $r['profiles_id'] ? $txt(Dropdown::getDropdownName('glpi_profiles', (int) $r['profiles_id'])) : '',
                $r['itemtype'] === 'Group_User' ? '' : $txt(Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id'])),
            ];
        }
        break;

    case 'auditoria':
        $nome = 'glpisas_auditoria';
        [$regs] = PluginGlpisasRegistro::auditoria(PluginGlpisasRegistro::lerFiltros($_GET, 'auditoria'), 0, true);
        $linhas[] = ['Data', 'Usuário', 'Ação', 'Objeto', 'Descrição', 'Antes', 'Depois'];
        foreach ($regs as $r) {
            $linhas[] = [
                $data($r['date_creation']), (int) $r['users_id'] ? getUserName((int) $r['users_id']) : '',
                PluginGlpisasRegistro::acoesAuditoria()[$r['acao']] ?? $r['acao'], PluginGlpisasRegistro::objetosAuditoria()[$r['objeto']] ?? $r['objeto'],
                $r['descricao'], (string) $r['valor_antigo'], (string) $r['valor_novo'],
            ];
        }
        break;

    default:
        throw new \Glpi\Exception\Http\BadRequestHttpException();
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nome . '_' . date('Ymd_His') . '.csv"');
header('Cache-Control: no-store');
$saida = fopen('php://output', 'w');
fwrite($saida, "\xEF\xBB\xBF");
foreach ($linhas as $linha) {
    fwrite($saida, implode(';', array_map(static fn($v) => '"' . str_replace('"', '""', (string) $v) . '"', $linha)) . "\r\n");
}
fclose($saida);
exit;
