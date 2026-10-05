<?php

/**
 * Plugin GLPI SAS - atalho da configuração (marketplace)
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Html::redirect(PluginGlpisasConfig::url('config.form.php'));
