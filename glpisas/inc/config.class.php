<?php

/**
 * Plugin GLPI SAS - configurações (chave/valor), direito nativo e utilitários comuns
 */
class PluginGlpisasConfig extends CommonDBTM
{
    // $rightname não é redeclarada: é tipada (string) no GLPI 12 e sem tipo no 11.

    public const TABELA = 'glpi_plugin_glpisas_configs';
    public const DIREITO = 'plugin_glpisas';

    public static function getTypeName($nb = 0): string
    {
        return 'GLPI SAS';
    }

    public static function getTable($classname = null)
    {
        return self::TABELA;
    }

    public static function canView(): bool
    {
        return self::ehAdmin();
    }

    public static function canCreate(): bool
    {
        return self::ehAdmin();
    }

    public static function canUpdate(): bool
    {
        return self::ehAdmin();
    }

    public static function canDelete(): bool
    {
        return self::ehAdmin();
    }

    public static function canPurge(): bool
    {
        return self::ehAdmin();
    }

    public static function ehAdmin(): bool
    {
        return Session::getLoginUserID() && Session::haveRight('config', UPDATE);
    }

    /** Ver painel, limites, horas e relatórios */
    public static function podeVer(): bool
    {
        return Session::getLoginUserID() && (self::ehAdmin() || Session::haveRight(self::DIREITO, READ));
    }

    /** Criar, alterar e excluir limites e controles de horas */
    public static function podeGerenciar(): bool
    {
        return Session::getLoginUserID() && (self::ehAdmin() || Session::haveRight(self::DIREITO, UPDATE));
    }

    // =====================================================================
    // Chave/valor
    // =====================================================================

    public static function padroes(): array
    {
        return [
            'alerta_pct'     => '80',
            'avisar_perto'   => '1',
            'perfis_isentos' => '[]',
            'msg_bloqueio'   => '',
            'por_pagina'     => '50',
        ];
    }

    /** Perfil ativo está nos perfis isentos (limites e bloqueios de horas não se aplicam) */
    public static function isento(): bool
    {
        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        return $perfil > 0 && in_array($perfil, array_map('intval', self::getArrayConfig('perfis_isentos')), true);
    }

    /** Texto complementar das mensagens de bloqueio (campo rico salvo na configuração) */
    public static function complementoBloqueio(): string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br />'], ' ', (string) self::getConfig('msg_bloqueio'))), ENT_QUOTES, 'UTF-8')));
        return $texto !== '' ? ' ' . $texto : '';
    }

    private static ?array $glpisasCache = null;

    public static function getConfig(string $name, $default = null)
    {
        global $DB;
        if (self::$glpisasCache === null) {
            self::$glpisasCache = [];
            if ($DB->tableExists(self::TABELA)) {
                foreach ($DB->request(['SELECT' => ['name', 'value'], 'FROM' => self::TABELA]) as $row) {
                    self::$glpisasCache[$row['name']] = $row['value'];
                }
            }
        }
        if (array_key_exists($name, self::$glpisasCache)) {
            return self::$glpisasCache[$name];
        }
        if ($default === null) {
            $padrao = self::padroes()[$name] ?? null;
            return is_array($padrao) ? json_encode($padrao) : $padrao;
        }
        return $default;
    }

    public static function setConfig(string $name, $value): bool
    {
        global $DB;
        $antes = self::getConfig($name);
        if (count($DB->request(['FROM' => self::TABELA, 'WHERE' => ['name' => $name], 'LIMIT' => 1])) > 0) {
            $ok = (bool) $DB->update(self::TABELA, ['value' => $value], ['name' => $name]);
        } else {
            $ok = (bool) $DB->insert(self::TABELA, ['name' => $name, 'value' => $value]);
        }
        self::$glpisasCache = null;
        if ($ok && (string) $antes !== (string) $value) {
            PluginGlpisasRegistro::auditar('alterar', 'config', 0, 'Configuração "' . $name . '"', (string) $antes, (string) $value);
        }
        return $ok;
    }

    public static function getAllConfigs(): array
    {
        global $DB;
        $todas = [];
        foreach ($DB->request(['FROM' => self::TABELA]) as $row) {
            $todas[$row['name']] = $row['value'];
        }
        return $todas;
    }

    public static function getArrayConfig(string $name): array
    {
        $lista = json_decode((string) self::getConfig($name), true);
        return is_array($lista) ? $lista : [];
    }

    public static function setArrayConfig(string $name, array $value): bool
    {
        return self::setConfig($name, json_encode(array_values($value)));
    }

    public static function inteiro(string $name, int $min, int $max): int
    {
        return max($min, min($max, (int) self::getConfig($name)));
    }

    // =====================================================================
    // Utilitários
    // =====================================================================

    public static function e($texto): string
    {
        return htmlspecialchars((string) $texto, ENT_QUOTES, 'UTF-8');
    }

    public static function url(string $arquivo, array $params = []): string
    {
        global $CFG_GLPI;
        return $CFG_GLPI['root_doc'] . '/plugins/glpisas/front/' . $arquivo . ($params ? '?' . http_build_query($params) : '');
    }

    /** URL de arquivo de public/ (servido em /plugins/<nome>/ no GLPI 11/12), com versão e data do arquivo */
    public static function urlAsset(string $caminho): string
    {
        global $CFG_GLPI;
        $arquivo = dirname(__DIR__) . '/public/' . $caminho;
        return $CFG_GLPI['root_doc'] . '/plugins/glpisas/' . $caminho . '?v=' . PLUGIN_GLPISAS_VERSION . '-' . (is_file($arquivo) ? filemtime($arquivo) : 0);
    }

    public static function assets(): string
    {
        static $feito = false;
        if ($feito) {
            return '';
        }
        $feito = true;
        global $DB;
        $ocultas = [];
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_entities', 'WHERE' => ['entities_id' => ['>', 0]]]) as $r) {
            $ocultas[] = (int) $r['id'];
        }
        return '<link rel="stylesheet" href="' . self::e(self::urlAsset('css/glpisas.css')) . '">'
            . '<script>window.glpisasEntidadesOcultas = ' . json_encode($ocultas) . ';</script>'
            . '<script src="' . self::e(self::urlAsset('js/glpisas.js')) . '"></script>';
    }

    /** GLPI 11 exige token CSRF nos POST; no 12 a proteção é por cabeçalho e o token foi removido */
    public static function tokenCsrf(): string
    {
        return version_compare(GLPI_VERSION, '12.0.0-dev', '<') ? Session::getNewCSRFToken() : '';
    }

    /** Minutos em "12h05" */
    public static function tempo(int $minutos): string
    {
        $sinal = $minutos < 0 ? '-' : '';
        $minutos = abs($minutos);
        return $sinal . intdiv($minutos, 60) . 'h' . str_pad((string) ($minutos % 60), 2, '0', STR_PAD_LEFT);
    }

    public static function reais(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    /** "1.234,56" ou "1234.56" em número */
    public static function lerValor($texto): float
    {
        $t = trim((string) $texto);
        if (str_contains($t, ',')) {
            $t = str_replace(['.', ','], ['', '.'], $t);
        }
        return max(0, round((float) preg_replace('/[^0-9.]/', '', $t), 2));
    }

    /** Barra de uso com cor por faixa (abaixo do alerta, perto, estourado) */
    public static function barra(float $usado, float $limite, string $texto = ''): string
    {
        $pct = $limite > 0 ? $usado / $limite * 100 : 0;
        $alerta = self::inteiro('alerta_pct', 1, 100);
        $faixa = $pct >= 100 ? 'estourado' : ($pct >= $alerta ? 'perto' : 'ok');
        return '<div class="glpisas-uso glpisas-uso-' . $faixa . '" title="' . self::e(number_format($pct, 1, ',', '.') . '%') . '"><div class="glpisas-uso-barra"><div style="width:' . min(100, round($pct, 1)) . '%"></div></div>'
            . '<span>' . self::e($texto !== '' ? $texto : number_format($usado, 0, ',', '.') . ' / ' . number_format($limite, 0, ',', '.')) . ' · ' . number_format($pct, 0, ',', '.') . '%</span></div>';
    }

    public static function faixa(float $usado, float $limite): string
    {
        $pct = $limite > 0 ? $usado / $limite * 100 : 0;
        return $pct >= 100 ? 'estourado' : ($pct >= self::inteiro('alerta_pct', 1, 100) ? 'perto' : 'ok');
    }

    /** Cabeçalho nativo com breadcrumb e abas das páginas do plugin */
    public static function cabecalho(string $chave, string $titulo): void
    {
        Html::header($titulo, $_SERVER['PHP_SELF'] ?? '', 'glpisas', $chave);
        echo self::assets();
        $abas = [
            'painel'     => ['ti ti-gauge', 'Painel', 'painel.php'],
            'limites'    => ['ti ti-adjustments-horizontal', 'Limites', 'limites.php'],
            'horas'      => ['ti ti-clock-dollar', 'Controle de horas', 'horas.php'],
            'relatorios' => ['ti ti-report', 'Relatórios', 'relatorios.php'],
        ];
        if (self::ehAdmin()) {
            $abas['config'] = ['ti ti-settings', 'Configuração', 'config.form.php'];
        }
        echo '<ul class="nav nav-tabs glpisas-modulos">';
        foreach ($abas as $k => [$icone, $rotulo, $pagina]) {
            echo '<li class="nav-item"><a class="nav-link' . ($k === $chave ? ' active' : '') . '" href="' . self::e(self::url($pagina)) . '"><i class="' . $icone . '"></i> ' . self::e($rotulo) . '</a></li>';
        }
        echo '</ul>';
    }

    /** Entidades acima (pais) de uma entidade, incluindo ela */
    public static function ancestrais(int $entidade): array
    {
        return array_values(array_unique(array_merge([$entidade], array_map('intval', getAncestorsOf('glpi_entities', $entidade)))));
    }
}
