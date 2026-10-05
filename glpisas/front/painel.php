<?php

/**
 * Plugin GLPI SAS - painel de uso dos limites e das horas
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginGlpisasConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$e = [PluginGlpisasConfig::class, 'e'];
$limites = PluginGlpisasLimite::listar(null, true);
$horas = PluginGlpisasHoras::listar(true);

// Situação de cada limite e controle
$atencao = [];
$cont = ['limites' => count($limites), 'perto' => 0, 'estourado' => 0, 'horas' => count($horas), 'horas_excedidas' => 0, 'valor_excedente' => 0.0, 'valor_total' => 0.0];
foreach ($limites as $l) {
    $uso = PluginGlpisasLimite::uso($l);
    $faixa = PluginGlpisasConfig::faixa($uso, (int) $l['quantidade']);
    if ($faixa !== 'ok') {
        $cont[$faixa]++;
        $atencao[] = $l + ['_uso' => $uso, '_faixa' => $faixa];
    }
}
$horasLinhas = [];
foreach ($horas as $h) {
    $c = PluginGlpisasHoras::consumo($h);
    $f = PluginGlpisasHoras::financeiro($h, $c['minutos']);
    $cont['valor_excedente'] += $f['valor_excedente'];
    $cont['valor_total'] += $f['valor_total'];
    if ($f['excedente'] > 0) {
        $cont['horas_excedidas']++;
    }
    $horasLinhas[] = $h + ['_f' => $f, '_faixa' => PluginGlpisasConfig::faixa($f['consumido'], $f['contratado'])];
}
usort($atencao, static fn($a, $b) => ($b['_uso'] / max(1, (int) $b['quantidade'])) <=> ($a['_uso'] / max(1, (int) $a['quantidade'])));
usort($horasLinhas, static fn($a, $b) => ($b['_f']['consumido'] / max(1, $b['_f']['contratado'])) <=> ($a['_f']['consumido'] / max(1, $a['_f']['contratado'])));

PluginGlpisasConfig::cabecalho('painel', 'Painel');
echo '<div class="glpisas">';

$cartao = static function (string $icone, string $rotulo, string $valor, string $classe = '') use ($e): string {
    return '<div class="glpisas-indicador ' . $classe . '"><i class="' . $icone . '"></i><div><div class="glpisas-indicador-valor">' . $e($valor) . '</div><div class="glpisas-indicador-rotulo">' . $e($rotulo) . '</div></div></div>';
};
echo '<div class="glpisas-indicadores">';
echo $cartao('ti ti-adjustments-horizontal', 'Limites ativos', (string) $cont['limites']);
echo $cartao('ti ti-alert-triangle', 'Perto do limite (≥ ' . PluginGlpisasConfig::inteiro('alerta_pct', 1, 100) . '%)', (string) $cont['perto'], $cont['perto'] ? 'glpisas-ind-perto' : '');
echo $cartao('ti ti-ban', 'Limites atingidos', (string) $cont['estourado'], $cont['estourado'] ? 'glpisas-ind-estourado' : '');
echo $cartao('ti ti-clock-dollar', 'Controles de horas', (string) $cont['horas']);
echo $cartao('ti ti-clock-exclamation', 'Com horas excedidas', (string) $cont['horas_excedidas'], $cont['horas_excedidas'] ? 'glpisas-ind-estourado' : '');
echo $cartao('ti ti-cash', 'Excedente no período', PluginGlpisasConfig::reais($cont['valor_excedente']));
echo '</div>';

echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-alert-triangle"></i> Limites que pedem atenção</h5><a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('limites.php')) . '"><i class="ti ti-adjustments-horizontal"></i> Ver todos</a></div><div class="card-body p-0">';
if ($atencao) {
    PluginGlpisasLimite::tabela($atencao, false, true);
} else {
    echo '<div class="glpisas-vazio"><i class="ti ti-circle-check"></i> Nenhum limite perto de ser atingido.</div>';
}
echo '</div></div>';

echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-clock-dollar"></i> Horas contratadas</h5><a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('horas.php')) . '"><i class="ti ti-clock-dollar"></i> Gerenciar</a></div><div class="card-body p-0">';
PluginGlpisasHoras::tabela(array_slice($horasLinhas, 0, 15), false);
if (count($horasLinhas) > 15) {
    echo '<div class="glpisas-ajuda p-2">Mostrando os 15 controles com maior consumo. Veja todos em Controle de horas.</div>';
}
echo '</div></div>';

// Últimas criações registradas
[$ultimas] = PluginGlpisasRegistro::criacoes(PluginGlpisasRegistro::lerFiltros([], 'criacoes'), 10);
echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-history"></i> Últimas criações</h5><a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('relatorios.php')) . '"><i class="ti ti-report"></i> Relatórios</a></div><div class="card-body p-0">';
if ($ultimas) {
    $tipos = PluginGlpisasRegistro::tipos();
    echo '<div class="table-responsive"><table class="table table-hover table-sm glpisas-lista"><thead><tr><th>Data</th><th>Tipo</th><th>Item</th><th>Criado por</th><th>Perfil</th><th>Entidade</th></tr></thead><tbody>';
    foreach ($ultimas as $r) {
        $item = PluginGlpisasRegistro::itemCriado($r);
        $t = $tipos[$r['itemtype']] ?? [$r['itemtype'], 'ti ti-file'];
        echo '<tr><td>' . $e(Html::convDateTime($r['date_creation'])) . '</td><td><span class="glpisas-etiqueta"><i class="' . $t[1] . '"></i> ' . $e($t[0]) . '</span></td>';
        echo '<td>' . ($item['link'] ? '<a href="' . $e($item['link']) . '">' . $e($item['nome']) . '</a>' : $e($item['nome'])) . (!$item['existe'] ? ' <span class="glpisas-etiqueta" title="Excluído ou na lixeira">excluído</span>' : '') . '</td>';
        echo '<td>' . $e((int) $r['users_id'] ? getUserName((int) $r['users_id']) : 'Automático') . '</td>';
        echo '<td>' . $e((int) $r['profiles_id'] ? html_entity_decode(Dropdown::getDropdownName('glpi_profiles', (int) $r['profiles_id']), ENT_QUOTES, 'UTF-8') : '-') . '</td>';
        echo '<td>' . $e(in_array($r['itemtype'], ['Ticket', 'Change', 'Problem', 'Project', 'Group', 'Profile', 'User'], true) ? html_entity_decode(Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']), ENT_QUOTES, 'UTF-8') : '-') . '</td></tr>';
    }
    echo '</tbody></table></div>';
} else {
    echo '<div class="glpisas-vazio"><i class="ti ti-mood-empty"></i> Nada registrado ainda.</div>';
}
echo '</div></div>';

echo '</div>';
echo PluginGlpisasEntidade::modalDetalhes();
Html::footer();
