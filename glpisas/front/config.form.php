<?php

/**
 * Plugin GLPI SAS - configuração geral
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
if (!PluginGlpisasConfig::ehAdmin()) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_action'])) {
    switch ($_POST['save_action']) {
        case 'salvar_geral':
            PluginGlpisasConfig::setConfig('alerta_pct', (string) max(1, min(100, (int) ($_POST['alerta_pct'] ?? 80))));
            PluginGlpisasConfig::setConfig('avisar_perto', !empty($_POST['avisar_perto']) ? '1' : '0');
            PluginGlpisasConfig::setConfig('por_pagina', (string) max(10, min(500, (int) ($_POST['por_pagina'] ?? 50))));
            $msg = (string) ($_POST['msg_bloqueio'] ?? '');
            PluginGlpisasConfig::setConfig('msg_bloqueio', empty(trim(strip_tags($msg))) ? '' : $msg);
            Session::addMessageAfterRedirect('Configuração salva.', false, INFO);
            break;
        case 'salvar_isentos':
            PluginGlpisasConfig::setArrayConfig('perfis_isentos', array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['perfis_isentos'] ?? []))))));
            Session::addMessageAfterRedirect('Perfis isentos salvos.', false, INFO);
            break;
    }
}

global $DB;
$e = [PluginGlpisasConfig::class, 'e'];

PluginGlpisasConfig::cabecalho('config', 'Configuração');
echo '<div class="glpisas glpisas-config">';

// Geral
echo '<form method="post" action="' . $e(PluginGlpisasConfig::url('config.form.php')) . '">';
echo '<input type="hidden" name="save_action" value="salvar_geral">';
echo '<table class="tab_cadre_fixe glpisas-tabela-form">';
echo '<tr class="tab_bg_2"><th colspan="4"><i class="ti ti-settings"></i> Geral</th></tr>';
echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Alerta a partir de (%)</td><td><input type="number" min="1" max="100" class="form-control" name="alerta_pct" value="' . PluginGlpisasConfig::inteiro('alerta_pct', 1, 100) . '">';
echo '<div class="glpisas-ajuda">Uso a partir do qual o limite aparece como "perto" no painel e nas barras.</div></td>';
echo '<td class="glpisas-rotulo">Avisar quem cria ao chegar no alerta</td><td>' . PluginGlpisasLimite::interruptor('avisar_perto', (int) PluginGlpisasConfig::getConfig('avisar_perto')) . '</td></tr>';
echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Registros por página nos relatórios</td><td><input type="number" min="10" max="500" class="form-control" name="por_pagina" value="' . PluginGlpisasConfig::inteiro('por_pagina', 10, 500) . '"></td><td></td><td></td></tr>';
echo '<tr class="tab_bg_1"><td class="glpisas-rotulo">Complemento das mensagens de bloqueio</td><td colspan="3">';
Html::textarea(['name' => 'msg_bloqueio', 'value' => (string) PluginGlpisasConfig::getConfig('msg_bloqueio'), 'enable_richtext' => true, 'cols' => 100, 'rows' => 4]);
echo '<div class="glpisas-ajuda">Texto acrescentado ao final das mensagens de limite atingido e de horas esgotadas (por exemplo, a quem pedir ampliação). Aparece como texto simples.</div></td></tr>';
echo '<tr class="tab_bg_2"><td colspan="4" class="center"><button type="submit" class="btn btn-primary glpisas-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button></td></tr>';
echo '</table>';
Html::closeForm();

// Perfis isentos
$isentos = array_map('intval', PluginGlpisasConfig::getArrayConfig('perfis_isentos'));
$perfis = [];
foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_profiles', 'ORDER' => 'name']) as $p) {
    $perfis[(int) $p['id']] = (string) $p['name'];
}
$sel = array_intersect_key($perfis, array_flip($isentos));
$nao = array_diff_key($perfis, $sel);
asort($sel);
asort($nao);
echo '<form method="post" action="' . $e(PluginGlpisasConfig::url('config.form.php')) . '">';
echo '<input type="hidden" name="save_action" value="salvar_isentos">';
echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-shield-lock"></i> Perfis isentos</h5></div><div class="card-body">';
echo '<p class="text-muted glpisas-dica"><i class="ti ti-info-circle"></i> Quem estiver com um destes perfis ativo não é bloqueado nem avisado pelos limites e pelo controle de horas. As criações continuam registradas.</p>';
echo '<div class="glpisas-multi" data-glpisas-multi>';
echo '<input type="search" class="form-control form-control-sm glpisas-multi-busca" placeholder="Pesquisar perfil…">';
echo '<div class="glpisas-multi-barra"><label><input type="checkbox" class="glpisas-check glpisas-multi-todos"> Marcar/desmarcar todos</label></div>';
echo '<div class="glpisas-multi-lista">';
foreach ($sel + $nao as $id => $nome) {
    $marcado = isset($sel[$id]);
    echo '<label class="glpisas-multi-item' . ($marcado ? ' selected' : '') . '" data-label="' . $e(mb_strtolower($nome)) . '"><input type="checkbox" class="glpisas-check" name="perfis_isentos[]" value="' . $id . '"' . ($marcado ? ' checked' : '') . '><span>' . $e($nome) . '</span></label>';
}
echo '</div><div class="glpisas-multi-contador"></div></div>';
echo '<div class="center mt-2"><button type="submit" class="btn btn-primary glpisas-btn-salvar"><i class="ti ti-device-floppy"></i> Salvar</button></div>';
echo '</div></div>';
Html::closeForm();

echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-lock"></i> Acesso</h5></div><div class="card-body">';
echo '<p class="text-muted glpisas-dica mb-0"><i class="ti ti-info-circle"></i> O acesso ao GLPI SAS é dado pelo direito nativo "GLPI SAS", na aba de mesmo nome em Administração › Perfis. "Ver" mostra o painel, os limites, as horas e os relatórios; "Gerenciar limites e horas" permite alterá-los. Esta página de configuração fica com quem administra o GLPI.</p>';
echo '</div></div>';

echo '</div>';
Html::footer();
