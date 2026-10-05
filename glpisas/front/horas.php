<?php

/**
 * Plugin GLPI SAS - controle de horas contratadas
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginGlpisasConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$e = [PluginGlpisasConfig::class, 'e'];
$gerencia = PluginGlpisasConfig::podeGerenciar();

$formulario = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_action'] ?? '') === 'salvar_horas') {
    if (!$gerencia) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    [$ok, $msg] = PluginGlpisasHoras::salvar($_POST);
    Session::addMessageAfterRedirect($msg, false, $ok ? INFO : ERROR);
    if (!$ok) {
        $formulario = $_POST;
        $tipo = (string) ($_POST['tipo_alvo'] ?? 'entidade');
        $formulario['alvo_id'] = (int) ($_POST['alvo_id_' . $tipo] ?? 0);
        $formulario['minutos_contratados'] = 0;
        $formulario['valor_hora'] = PluginGlpisasConfig::lerValor($_POST['valor_hora'] ?? 0);
        $formulario['valor_hora_excedente'] = PluginGlpisasConfig::lerValor($_POST['valor_hora_excedente'] ?? 0);
    }
}
if ($formulario === null && $gerencia) {
    if (!empty($_GET['editar'])) {
        $formulario = PluginGlpisasHoras::obter((int) $_GET['editar']);
    } elseif (!empty($_GET['novo'])) {
        $formulario = ['tipo_alvo' => 'entidade', 'alvo_id' => (int) ($_GET['alvo'] ?? 0)];
    }
}

// Filtro por tipo de alvo
$filtroTipo = array_key_exists((string) ($_GET['tipo'] ?? ''), PluginGlpisasHoras::tipos()) ? (string) $_GET['tipo'] : null;

PluginGlpisasConfig::cabecalho('horas', 'Controle de horas');
echo '<div class="glpisas">';

if ($formulario !== null) {
    PluginGlpisasHoras::formulario($formulario);
}

$controles = PluginGlpisasHoras::listar(false, $filtroTipo);
echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-clock-dollar"></i> Controles de horas</h5><div class="glpisas-acoes">';
echo '<div class="btn-group btn-group-sm" role="group">';
echo '<a class="btn btn-outline-secondary' . ($filtroTipo === null ? ' active' : '') . '" href="' . $e(PluginGlpisasConfig::url('horas.php')) . '">Todos</a>';
foreach (PluginGlpisasHoras::tipos() as $k => [$rotulo, $icone]) {
    echo '<a class="btn btn-outline-secondary' . ($filtroTipo === $k ? ' active' : '') . '" href="' . $e(PluginGlpisasConfig::url('horas.php', ['tipo' => $k])) . '"><i class="' . $icone . '"></i> ' . $e($rotulo) . '</a>';
}
echo '</div>';
if ($gerencia && $formulario === null) {
    echo '<a class="btn btn-sm btn-primary glpisas-btn-salvar" href="' . $e(PluginGlpisasConfig::url('horas.php', ['novo' => 1])) . '"><i class="ti ti-plus"></i> Novo controle</a>';
}
echo '</div></div><div class="card-body p-0">';
echo '<p class="text-muted glpisas-dica m-2"><i class="ti ti-info-circle"></i> O consumo é apurado no período de cada controle. Até o limite contratado vale o valor da hora; o que passar disso é cobrado pelo valor da hora excedente.</p>';
PluginGlpisasHoras::tabela($controles, $gerencia);
echo '</div></div>';
echo '</div>';
echo PluginGlpisasEntidade::modalDetalhes();
Html::footer();
