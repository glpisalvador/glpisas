<?php

/**
 * Plugin GLPI SAS - relatórios: registro de criações e auditoria das configurações
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginGlpisasConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$e = [PluginGlpisasConfig::class, 'e'];
$aba = ($_GET['aba'] ?? '') === 'auditoria' ? 'auditoria' : 'criacoes';
$f = PluginGlpisasRegistro::lerFiltros($_GET, $aba);
$porPagina = PluginGlpisasConfig::inteiro('por_pagina', 10, 500);

PluginGlpisasConfig::cabecalho('relatorios', 'Relatórios');
echo '<div class="glpisas">';

echo '<ul class="nav nav-pills glpisas-subabas">';
echo '<li class="nav-item"><a class="nav-link' . ($aba === 'criacoes' ? ' active' : '') . '" href="' . $e(PluginGlpisasConfig::url('relatorios.php')) . '"><i class="ti ti-history"></i> Registro de criações</a></li>';
echo '<li class="nav-item"><a class="nav-link' . ($aba === 'auditoria' ? ' active' : '') . '" href="' . $e(PluginGlpisasConfig::url('relatorios.php', ['aba' => 'auditoria'])) . '"><i class="ti ti-shield"></i> Auditoria das configurações</a></li>';
echo '</ul>';

// Filtros (GET)
echo '<form method="get" action="' . $e(PluginGlpisasConfig::url('relatorios.php')) . '" class="card glpisas-card glpisas-filtros"><input type="hidden" name="aba" value="' . $aba . '">';
echo '<div class="card-body glpisas-linha-filtros">';
if ($aba === 'criacoes') {
    echo '<div class="glpisas-filtro"><label>Tipo</label>';
    Dropdown::showFromArray('itemtype', ['' => 'Todos'] + array_map(static fn($t) => $t[0], PluginGlpisasRegistro::tipos()), ['value' => $f['itemtype'], 'width' => '170px']);
    echo '</div><div class="glpisas-filtro"><label>Perfil de quem criou</label>';
    Profile::dropdown(['name' => 'profiles_id', 'value' => $f['profiles_id'], 'width' => '200px']);
    echo '</div><div class="glpisas-filtro"><label>Entidade</label>';
    Entity::dropdown(['name' => 'entities_id', 'value' => $f['entities_id'] >= 0 ? $f['entities_id'] : '', 'entity' => $_SESSION['glpiactiveentities'] ?? [], 'display_emptychoice' => true, 'width' => '220px']);
    echo '</div>';
} else {
    echo '<div class="glpisas-filtro"><label>Objeto</label>';
    Dropdown::showFromArray('objeto', ['' => 'Todos'] + PluginGlpisasRegistro::objetosAuditoria(), ['value' => $f['objeto'], 'width' => '200px']);
    echo '</div><div class="glpisas-filtro"><label>Ação</label>';
    Dropdown::showFromArray('acao', ['' => 'Todas'] + PluginGlpisasRegistro::acoesAuditoria(), ['value' => $f['acao'], 'width' => '150px']);
    echo '</div>';
}
echo '<div class="glpisas-filtro"><label>Usuário</label>';
User::dropdown(['name' => 'users_id', 'value' => $f['users_id'], 'right' => 'all', 'width' => '200px']);
echo '</div><div class="glpisas-filtro"><label>De</label>';
Html::showDateField('de', ['value' => $f['de'], 'maybeempty' => true]);
echo '</div><div class="glpisas-filtro"><label>Até</label>';
Html::showDateField('ate', ['value' => $f['ate'], 'maybeempty' => true]);
echo '</div><div class="glpisas-filtro glpisas-filtro-botoes">';
echo '<button type="submit" class="btn btn-sm btn-primary glpisas-btn-salvar"><i class="ti ti-filter"></i> Filtrar</button>';
echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('relatorios.php', ['aba' => $aba])) . '"><i class="ti ti-x"></i> Limpar</a>';
$paramsCsv = array_filter($_GET, static fn($v, $k) => $k !== 'pagina' && $v !== '', ARRAY_FILTER_USE_BOTH);
echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('exportar.php', ['tipo' => $aba] + $paramsCsv)) . '"><i class="ti ti-file-spreadsheet"></i> CSV</a>';
echo '</div></div></form>';

if ($aba === 'criacoes') {
    [$linhas, $total] = PluginGlpisasRegistro::criacoes($f, $porPagina);
    $tipos = PluginGlpisasRegistro::tipos();
    echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-history"></i> Criações registradas</h5><span class="glpisas-contador">' . $total . '</span></div><div class="card-body p-0">';
    if ($linhas) {
        echo '<div class="table-responsive"><table class="table table-hover table-sm glpisas-lista"><thead><tr><th>Data</th><th>Tipo</th><th>Item</th><th>Criado por</th><th>Perfil</th><th>Entidade</th></tr></thead><tbody>';
        foreach ($linhas as $r) {
            $item = PluginGlpisasRegistro::itemCriado($r);
            $t = $tipos[$r['itemtype']] ?? [$r['itemtype'], 'ti ti-file'];
            echo '<tr><td class="text-nowrap">' . $e(Html::convDateTime($r['date_creation'])) . '</td><td><span class="glpisas-etiqueta"><i class="' . $t[1] . '"></i> ' . $e($t[0]) . '</span></td>';
            echo '<td>' . ($item['link'] ? '<a href="' . $e($item['link']) . '">' . $e($item['nome']) . '</a>' : $e($item['nome'])) . (!$item['existe'] ? ' <span class="glpisas-etiqueta" title="Excluído ou na lixeira">excluído</span>' : '') . '</td>';
            echo '<td>' . $e((int) $r['users_id'] ? getUserName((int) $r['users_id']) : 'Automático') . '</td>';
            echo '<td>' . $e((int) $r['profiles_id'] ? html_entity_decode(Dropdown::getDropdownName('glpi_profiles', (int) $r['profiles_id']), ENT_QUOTES, 'UTF-8') : '-') . '</td>';
            echo '<td>' . $e($r['itemtype'] === 'Group_User' ? '-' : html_entity_decode(Dropdown::getDropdownName('glpi_entities', (int) $r['entities_id']), ENT_QUOTES, 'UTF-8')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<div class="glpisas-vazio"><i class="ti ti-mood-empty"></i> Nenhum registro com esses filtros.</div>';
    }
    echo '</div></div>';
} else {
    [$linhas, $total] = PluginGlpisasRegistro::auditoria($f, $porPagina);
    $acoes = PluginGlpisasRegistro::acoesAuditoria();
    $objetos = PluginGlpisasRegistro::objetosAuditoria();
    $valor = static function (?string $json) use ($e): string {
        if ($json === null || $json === '') {
            return '<span class="text-muted">-</span>';
        }
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return $e(mb_strimwidth($json, 0, 300, '…'));
        }
        $partes = [];
        foreach ($d as $k => $v) {
            $partes[] = '<span class="glpisas-par"><b>' . $e($k) . ':</b> ' . $e(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) . '</span>';
        }
        return implode(' ', $partes);
    };
    echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-shield"></i> Alterações nas configurações</h5><span class="glpisas-contador">' . $total . '</span></div><div class="card-body p-0">';
    if ($linhas) {
        echo '<div class="table-responsive"><table class="table table-hover table-sm glpisas-lista"><thead><tr><th>Data</th><th>Usuário</th><th>Ação</th><th>Objeto</th><th>Descrição</th><th>Antes</th><th>Depois</th></tr></thead><tbody>';
        foreach ($linhas as $r) {
            $classe = ['criar' => 'glpisas-pill-ok', 'alterar' => 'glpisas-pill-avisar', 'excluir' => 'glpisas-pill-bloquear'][$r['acao']] ?? 'glpisas-pill-neutro';
            echo '<tr><td class="text-nowrap">' . $e(Html::convDateTime($r['date_creation'])) . '</td><td>' . $e((int) $r['users_id'] ? getUserName((int) $r['users_id']) : '-') . '</td>';
            echo '<td><span class="glpisas-pill ' . $classe . '">' . $e($acoes[$r['acao']] ?? $r['acao']) . '</span></td><td>' . $e($objetos[$r['objeto']] ?? $r['objeto']) . '</td>';
            echo '<td>' . $e($r['descricao']) . '</td><td class="glpisas-valor">' . $valor($r['valor_antigo']) . '</td><td class="glpisas-valor">' . $valor($r['valor_novo']) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<div class="glpisas-vazio"><i class="ti ti-mood-empty"></i> Nenhuma alteração com esses filtros.</div>';
    }
    echo '</div></div>';
}

// Paginação
$paginas = (int) ceil($total / $porPagina);
if ($paginas > 1) {
    $atual = min($f['pagina'], $paginas);
    $link = static fn(int $p) => PluginGlpisasConfig::url('relatorios.php', ['pagina' => $p] + array_filter($_GET, static fn($v, $k) => $k !== 'pagina' && $v !== '', ARRAY_FILTER_USE_BOTH));
    echo '<nav class="glpisas-paginacao"><ul class="pagination pagination-sm">';
    echo '<li class="page-item' . ($atual <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . $e($link(max(1, $atual - 1))) . '"><i class="ti ti-chevron-left"></i></a></li>';
    $ultimo = 0;
    for ($p = 1; $p <= $paginas; $p++) {
        if ($p === 1 || $p === $paginas || abs($p - $atual) <= 2) {
            if ($ultimo && $p - $ultimo > 1) {
                echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            echo '<li class="page-item' . ($p === $atual ? ' active' : '') . '"><a class="page-link" href="' . $e($link($p)) . '">' . $p . '</a></li>';
            $ultimo = $p;
        }
    }
    echo '<li class="page-item' . ($atual >= $paginas ? ' disabled' : '') . '"><a class="page-link" href="' . $e($link(min($paginas, $atual + 1))) . '"><i class="ti ti-chevron-right"></i></a></li>';
    echo '</ul><span class="glpisas-ajuda">' . $total . ' registro(s) · página ' . $atual . ' de ' . $paginas . '</span></nav>';
}

echo '</div>';
Html::footer();
