<?php

/**
 * Plugin GLPI SAS - limites por perfil, entidade e grupo
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginGlpisasConfig::podeVer()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$e = [PluginGlpisasConfig::class, 'e'];
$tipos = PluginGlpisasLimite::tipos();
$aba = array_key_exists((string) ($_GET['aba'] ?? $_POST['tipo_alvo'] ?? ''), $tipos) ? (string) ($_GET['aba'] ?? $_POST['tipo_alvo']) : 'entidade';
$gerencia = PluginGlpisasConfig::podeGerenciar();

// Gravação: processa e segue para a página (sem redirecionar)
$formulario = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['save_action'] ?? '') === 'salvar_limite') {
    if (!$gerencia) {
        throw new \Glpi\Exception\Http\AccessDeniedHttpException();
    }
    [$ok, $msg] = PluginGlpisasLimite::salvar($_POST);
    Session::addMessageAfterRedirect($msg, false, $ok ? INFO : ERROR);
    if (!$ok) {
        $formulario = $_POST;
    }
}
if ($formulario === null && $gerencia) {
    if (!empty($_GET['editar'])) {
        $formulario = PluginGlpisasLimite::obter((int) $_GET['editar']);
        if ($formulario) {
            $aba = $formulario['tipo_alvo'];
        }
    } elseif (!empty($_GET['novo'])) {
        $formulario = ['tipo_alvo' => $aba, 'alvo_id' => (int) ($_GET['alvo'] ?? 0)];
    }
}

PluginGlpisasConfig::cabecalho('limites', 'Limites');
echo '<div class="glpisas">';

echo '<ul class="nav nav-pills glpisas-subabas">';
foreach ($tipos as $chave => $t) {
    $n = count(PluginGlpisasLimite::listar($chave));
    echo '<li class="nav-item"><a class="nav-link' . ($chave === $aba ? ' active' : '') . '" href="' . $e(PluginGlpisasConfig::url('limites.php', ['aba' => $chave])) . '"><i class="' . $t['icone'] . '"></i> ' . $e($t['rotulo']) . ' <span class="glpisas-contador">' . $n . '</span></a></li>';
}
echo '</ul>';

if ($formulario !== null) {
    PluginGlpisasLimite::formulario($aba, $formulario);
}

$t = $tipos[$aba];
echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="' . $t['icone'] . '"></i> Limites por ' . $e(mb_strtolower($t['singular'])) . '</h5>';
if ($gerencia && $formulario === null) {
    echo '<a class="btn btn-sm btn-primary glpisas-btn-salvar" href="' . $e(PluginGlpisasConfig::url('limites.php', ['aba' => $aba, 'novo' => 1])) . '"><i class="ti ti-plus"></i> Novo limite</a>';
}
echo '</div><div class="card-body p-0">';
echo '<p class="text-muted glpisas-dica m-2"><i class="ti ti-info-circle"></i> ' . $e($t['dica']) . '</p>';
PluginGlpisasLimite::tabela(PluginGlpisasLimite::listar($aba), $gerencia);
echo '</div></div>';
echo '<div class="glpisas-rodape-acoes"><a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('exportar.php', ['tipo' => 'limites'])) . '"><i class="ti ti-file-spreadsheet"></i> Exportar todos os limites (CSV)</a></div>';
echo '</div>';
Html::footer();
