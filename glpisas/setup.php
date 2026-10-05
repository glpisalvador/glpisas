<?php

/**
 * Plugin GLPI SAS - GLPI 11 e 12 (sucessor do "Controle Administrativo")
 * Limites por perfil (usuários, grupos e perfis criados), por entidade (chamados, mudanças, problemas,
 * projetos) e por grupo (membros, chamados), por período; controle de horas contratadas com valores;
 * painel de uso, registro de criações e auditoria das configurações.
 */

define('PLUGIN_GLPISAS_VERSION', '1.0.0');
define('PLUGIN_GLPISAS_MIN_GLPI', '11.0.0');
define('PLUGIN_GLPISAS_MAX_GLPI', '12.99.99');

function plugin_init_glpisas(): void
{
    global $PLUGIN_HOOKS;

    // Chave literal: a constante Hooks::CSRF_COMPLIANT não existe no GLPI 12
    $PLUGIN_HOOKS['csrf_compliant']['glpisas'] = true;

    $plugin = new Plugin();
    if (!$plugin->isActivated('glpisas')) {
        return;
    }

    Plugin::registerClass('PluginGlpisasMenu');
    Plugin::registerClass('PluginGlpisasEntidade', ['addtabon' => ['Entity']]);
    Plugin::registerClass('PluginGlpisasProfile', ['addtabon' => ['Profile']]);

    $PLUGIN_HOOKS['config_page']['glpisas'] = 'front/config.form.php';
    $PLUGIN_HOOKS['redefine_menus']['glpisas'] = ['PluginGlpisasMenu', 'redefinir'];

    // Limites (antes de criar) e registro das criações (depois)
    $C = 'PluginGlpisasControle';
    $tipos = ['User', 'Group', 'Profile', 'Group_User', 'Ticket', 'Change', 'Problem', 'Project'];
    foreach ($tipos as $tipo) {
        $PLUGIN_HOOKS['pre_item_add']['glpisas'][$tipo] = [$C, 'antesDeCriar'];
        $PLUGIN_HOOKS['item_add']['glpisas'][$tipo] = [$C, 'aoCriar'];
    }
}

function plugin_version_glpisas(): array
{
    return [
        'name'         => 'GLPI SAS',
        'version'      => PLUGIN_GLPISAS_VERSION,
        'author'       => 'GLPI Salvador',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GLPISAS_MIN_GLPI,
                'max' => PLUGIN_GLPISAS_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

function plugin_glpisas_check_prerequisites(): bool
{
    return version_compare(GLPI_VERSION, PLUGIN_GLPISAS_MIN_GLPI, '>=');
}

function plugin_glpisas_check_config($verbose = false): bool
{
    return true;
}
