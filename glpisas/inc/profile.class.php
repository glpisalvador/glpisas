<?php

/**
 * Plugin GLPI SAS - aba "GLPI SAS" no perfil (direito nativo plugin_glpisas)
 */
class PluginGlpisasProfile extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'GLPI SAS';
    }

    public static function getAllRights(): array
    {
        return [[
            'itemtype' => 'PluginGlpisasMenu',
            'label'    => 'GLPI SAS',
            'field'    => PluginGlpisasConfig::DIREITO,
            'rights'   => [READ => 'Ver', UPDATE => 'Gerenciar limites e horas'],
        ]];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if ($item instanceof Profile && $item->getField('interface') !== 'helpdesk') {
            return self::createTabEntry('GLPI SAS', 0, null, 'ti ti-shield-check');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Profile) {
            return true;
        }
        $perfil = new Profile();
        $perfil->getFromDB($item->getID());
        $pode = Session::haveRight('profile', UPDATE);
        echo '<div class="spaced">';
        if ($pode) {
            echo '<form method="post" action="' . PluginGlpisasConfig::e(Profile::getFormURL()) . '">';
        }
        $perfil->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $pode,
            'default_class' => 'tab_bg_2',
            'title'         => 'GLPI SAS',
        ]);
        if ($pode) {
            echo '<div class="center">' . Html::hidden('id', ['value' => $item->getID()])
                . Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']) . '</div>';
            Html::closeForm();
        }
        echo '<p class="text-muted glpisas-dica"><i class="ti ti-info-circle"></i> "Ver" libera o painel, os limites, as horas e os relatórios; "Gerenciar" permite criar, alterar e excluir limites e controles de horas. Quem administra o GLPI (Configuração: atualizar) sempre tem acesso completo.</p>';
        echo '</div>';
        return true;
    }
}
