<?php

/**
 * Plugin GLPI SAS - menu próprio no topo do GLPI
 */
class PluginGlpisasMenu extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'GLPI SAS';
    }

    public static function getMenuName(): string
    {
        return 'GLPI SAS';
    }

    public static function canView(): bool
    {
        return PluginGlpisasConfig::podeVer();
    }

    public static function paginas(): array
    {
        $paginas = [
            'painel'     => ['Painel', 'ti ti-gauge', 'painel.php'],
            'limites'    => ['Limites', 'ti ti-adjustments-horizontal', 'limites.php'],
            'horas'      => ['Controle de horas', 'ti ti-clock-dollar', 'horas.php'],
            'relatorios' => ['Relatórios', 'ti ti-report', 'relatorios.php'],
        ];
        if (PluginGlpisasConfig::ehAdmin()) {
            $paginas['config'] = ['Configuração', 'ti ti-settings', 'config.form.php'];
        }
        return $paginas;
    }

    public static function redefinir($menus)
    {
        if (!is_array($menus) || !PluginGlpisasConfig::podeVer()) {
            return $menus;
        }
        $base = '/plugins/glpisas/front/';
        $conteudo = [];
        foreach (self::paginas() as $chave => [$titulo, $icone, $pagina]) {
            $conteudo[$chave] = ['title' => $titulo, 'icon' => $icone, 'page' => $base . $pagina];
        }
        $menus['glpisas'] = [
            'title'   => 'GLPI SAS',
            'icon'    => 'ti ti-shield-check',
            'types'   => [],
            'default' => $base . 'painel.php',
            'content' => $conteudo,
        ];
        return $menus;
    }
}
