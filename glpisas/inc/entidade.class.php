<?php

/**
 * Plugin GLPI SAS - aba "GLPI SAS" na entidade: limites e horas que valem para ela
 */
class PluginGlpisasEntidade extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'GLPI SAS';
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof Entity || !PluginGlpisasConfig::podeVer()) {
            return '';
        }
        $n = 0;
        if ($_SESSION['glpishow_count_on_tabs'] ?? false) {
            $n = count(self::limitesDa((int) $item->getID())) + count(self::horasDa((int) $item->getID()));
        }
        return self::createTabEntry('GLPI SAS', $n, null, 'ti ti-shield-check');
    }

    /** Limites de entidade da própria entidade e das mães que incluem as filhas */
    public static function limitesDa(int $entidade): array
    {
        $ancestrais = PluginGlpisasConfig::ancestrais($entidade);
        $lista = [];
        foreach (PluginGlpisasLimite::listar('entidade') as $l) {
            $a = (int) $l['alvo_id'];
            if ($a === $entidade || ((int) $l['incluir_filhas'] && in_array($a, $ancestrais, true))) {
                $lista[] = $l;
            }
        }
        return $lista;
    }

    public static function horasDa(int $entidade): array
    {
        $ancestrais = PluginGlpisasConfig::ancestrais($entidade);
        $lista = [];
        foreach (PluginGlpisasHoras::listar(false, 'entidade') as $h) {
            $a = (int) $h['alvo_id'];
            if ($a === $entidade || ((int) $h['incluir_filhas'] && in_array($a, $ancestrais, true))) {
                $lista[] = $h;
            }
        }
        return $lista;
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof Entity || !PluginGlpisasConfig::podeVer()) {
            return true;
        }
        $id = (int) $item->getID();
        $e = [PluginGlpisasConfig::class, 'e'];
        $gerencia = PluginGlpisasConfig::podeGerenciar();
        echo PluginGlpisasConfig::assets();
        echo '<div class="glpisas glpisas-aba">';

        echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-adjustments-horizontal"></i> Limites</h5>';
        if ($gerencia) {
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('limites.php', ['aba' => 'entidade', 'novo' => 1, 'alvo' => $id])) . '"><i class="ti ti-plus"></i> Novo limite</a>';
        }
        echo '</div><div class="card-body p-0">';
        PluginGlpisasLimite::tabela(self::limitesDa($id), false);
        echo '</div></div>';

        echo '<div class="card glpisas-card"><div class="card-header glpisas-card-header"><h5><i class="ti ti-clock-dollar"></i> Controle de horas</h5>';
        if ($gerencia) {
            echo '<a class="btn btn-sm btn-outline-secondary" href="' . $e(PluginGlpisasConfig::url('horas.php', ['novo' => 1, 'alvo' => $id])) . '"><i class="ti ti-plus"></i> Novo controle</a>';
        }
        echo '</div><div class="card-body p-0">';
        PluginGlpisasHoras::tabela(self::horasDa($id), false);
        echo '</div></div>';
        echo '<p class="text-muted glpisas-dica"><i class="ti ti-info-circle"></i> Aparecem aqui os limites e controles da própria entidade e os das entidades acima dela marcados para incluir as filhas.</p>';
        echo '</div>';
        echo self::modalDetalhes();
        return true;
    }

    /** Modal usado pelo botão "Chamados que consumiram horas" */
    public static function modalDetalhes(): string
    {
        return '<div class="modal fade" id="glpisas-modal-detalhes" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">'
            . '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-list"></i> Chamados que consumiram horas</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button></div>'
            . '<div class="modal-body glpisas"><div class="glpisas-carregando"><i class="ti ti-loader"></i> Carregando…</div></div>'
            . '<div class="modal-footer"><a class="btn btn-outline-secondary glpisas-modal-csv" href="#"><i class="ti ti-file-spreadsheet"></i> Exportar CSV</a><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button></div>'
            . '</div></div></div>';
    }
}
