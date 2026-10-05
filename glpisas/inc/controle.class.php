<?php

/**
 * Plugin GLPI SAS - aplicação dos limites antes de criar e registro depois de criar
 */
class PluginGlpisasControle
{
    /** Hook pre_item_add: bloqueia ou avisa conforme os limites e o controle de horas */
    public static function antesDeCriar(CommonDBTM $item): void
    {
        if (!is_array($item->input) || !is_numeric(Session::getLoginUserID()) || PluginGlpisasConfig::isento()) {
            return;
        }
        try {
            $ok = match (true) {
                $item instanceof User       => self::verificarPerfil($item, 'usuarios', 'usuários'),
                $item instanceof Group      => self::verificarPerfil($item, 'grupos', 'grupos'),
                $item instanceof Profile    => self::verificarPerfil($item, 'perfis', 'perfis'),
                $item instanceof Group_User => self::verificarMembros($item),
                $item instanceof Ticket     => self::verificarChamado($item),
                $item instanceof Change     => self::verificarEntidade($item, 'mudancas', 'mudanças'),
                $item instanceof Problem    => self::verificarEntidade($item, 'problemas', 'problemas'),
                $item instanceof Project    => self::verificarEntidade($item, 'projetos', 'projetos'),
                default                     => true,
            };
        } catch (Throwable $e) {
            Toolbox::logInFile('php-errors', 'Plugin glpisas: falha ao verificar limites: ' . $e->getMessage() . "\n");
            $ok = true;
        }
        if (!$ok) {
            $item->input = false;
        }
    }

    /** Hook item_add: registra quem criou */
    public static function aoCriar(CommonDBTM $item): void
    {
        PluginGlpisasRegistro::registrar($item);
    }

    // =====================================================================

    /**
     * Avalia um limite com o item que está para ser criado.
     * Retorna false quando deve bloquear; avisos saem como WARNING.
     */
    private static function avaliar(array $l, string $descricao, string $plural): bool
    {
        $uso = PluginGlpisasLimite::uso($l);
        $max = (int) $l['quantidade'];
        $periodo = mb_strtolower(PluginGlpisasLimite::descreverPeriodo($l));
        if ($uso >= $max) {
            if ($l['acao'] === 'bloquear') {
                Session::addMessageAfterRedirect(
                    sprintf('Limite atingido: %s já tem %d de %d %s permitidos (%s). Não foi possível criar.', $descricao, $uso, $max, $plural, $periodo) . PluginGlpisasConfig::complementoBloqueio(),
                    false,
                    ERROR
                );
                return false;
            }
            Session::addMessageAfterRedirect(sprintf('Limite excedido: %s passa a ter %d de %d %s permitidos (%s).', $descricao, $uso + 1, $max, $plural, $periodo), false, WARNING);
            return true;
        }
        if ((int) PluginGlpisasConfig::getConfig('avisar_perto') && $max > 0 && ($uso + 1) / $max * 100 >= PluginGlpisasConfig::inteiro('alerta_pct', 1, 100)) {
            Session::addMessageAfterRedirect(sprintf('Atenção: %s chegou a %d de %d %s permitidos (%s).', $descricao, $uso + 1, $max, $plural, $periodo), false, WARNING);
        }
        return true;
    }

    private static function verificarPerfil(CommonDBTM $item, string $recurso, string $plural): bool
    {
        $perfil = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        foreach (PluginGlpisasLimite::aplicaveis('perfil', [$perfil], $recurso) as $l) {
            if (!self::avaliar($l, 'o perfil "' . PluginGlpisasLimite::nomeAlvo('perfil', $perfil) . '"', $plural)) {
                return false;
            }
        }
        return true;
    }

    private static function verificarMembros(Group_User $item): bool
    {
        $grupo = (int) ($item->input['groups_id'] ?? 0);
        if ($grupo <= 0) {
            return true;
        }
        foreach (PluginGlpisasLimite::aplicaveis('grupo', [$grupo], 'membros') as $l) {
            if (!self::avaliar($l, 'o grupo "' . PluginGlpisasLimite::nomeAlvo('grupo', $grupo) . '"', 'membros')) {
                return false;
            }
        }
        return true;
    }

    private static function entidadeDoItem(CommonDBTM $item): int
    {
        if (isset($item->input['entities_id']) && is_numeric($item->input['entities_id'])) {
            return (int) $item->input['entities_id'];
        }
        return (int) ($_SESSION['glpiactive_entity'] ?? 0);
    }

    private static function verificarEntidade(CommonDBTM $item, string $recurso, string $plural): bool
    {
        $entidade = self::entidadeDoItem($item);
        foreach (PluginGlpisasLimite::aplicaveisEntidade($entidade, $recurso) as $l) {
            if (!self::avaliar($l, 'a entidade "' . PluginGlpisasLimite::nomeAlvo('entidade', (int) $l['alvo_id']) . '"', $plural)) {
                return false;
            }
        }
        return true;
    }

    /** Atores do formulário (GLPI 11/12 usam _actors; a API e integrações usam _users_id_requester etc.) */
    public static function atores(array $input, string $papel, string $tipo): array
    {
        $ids = [];
        foreach ((array) ($input['_actors'][$papel] ?? []) as $ator) {
            if (is_array($ator) && ($ator['itemtype'] ?? '') === $tipo && (int) ($ator['items_id'] ?? 0) > 0) {
                $ids[] = (int) $ator['items_id'];
            }
        }
        $campo = $tipo === 'User' ? '_users_id_' . $papel : '_groups_id_' . $papel;
        foreach ((array) ($input[$campo] ?? []) as $id) {
            if ((int) $id > 0) {
                $ids[] = (int) $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function verificarChamado(Ticket $item): bool
    {
        $input = $item->input;
        $usuarioSessao = (int) Session::getLoginUserID();
        $entidade = self::entidadeDoItem($item);

        // Limite de chamados por entidade
        if (!self::verificarEntidade($item, 'chamados', 'chamados')) {
            return false;
        }

        // Limite de chamados por grupo: grupos de quem abre e grupos requerentes
        $grupos = self::atores($input, 'requester', 'Group');
        foreach (Group_User::getUserGroups($usuarioSessao) as $g) {
            $grupos[] = (int) $g['id'];
        }
        $grupos = array_values(array_unique($grupos));
        foreach (PluginGlpisasLimite::aplicaveis('grupo', $grupos, 'chamados') as $l) {
            if (!self::avaliar($l, 'o grupo "' . PluginGlpisasLimite::nomeAlvo('grupo', (int) $l['alvo_id']) . '"', 'chamados')) {
                return false;
            }
        }

        // Controle de horas: entidade, requerentes (ou quem abre) e os grupos deles
        $requerentes = self::atores($input, 'requester', 'User') ?: [$usuarioSessao];
        $gruposHoras = self::atores($input, 'requester', 'Group');
        foreach ($requerentes as $u) {
            foreach (Group_User::getUserGroups($u) as $g) {
                $gruposHoras[] = (int) $g['id'];
            }
        }
        foreach (PluginGlpisasHoras::aplicaveisChamado($entidade, $requerentes, array_values(array_unique($gruposHoras))) as $h) {
            if ($h['ao_exceder'] === 'nada') {
                continue;
            }
            $c = PluginGlpisasHoras::consumo($h);
            $f = PluginGlpisasHoras::financeiro($h, $c['minutos']);
            $nome = mb_strtolower(PluginGlpisasHoras::tipos()[$h['tipo_alvo']][0]) . ' "' . PluginGlpisasHoras::nomeAlvo($h['tipo_alvo'], (int) $h['alvo_id']) . '"';
            $T = [PluginGlpisasConfig::class, 'tempo'];
            if ($f['contratado'] > 0 && $f['consumido'] >= $f['contratado']) {
                if ($h['ao_exceder'] === 'bloquear') {
                    Session::addMessageAfterRedirect(sprintf('Horas contratadas esgotadas: %s consumiu %s de %s (%s). Não foi possível abrir o chamado.', $nome, $T($f['consumido']), $T($f['contratado']), mb_strtolower(PluginGlpisasHoras::descreverPeriodo($h))) . PluginGlpisasConfig::complementoBloqueio(), false, ERROR);
                    return false;
                }
                Session::addMessageAfterRedirect(sprintf('Horas contratadas excedidas: %s consumiu %s de %s (%s). Excedente de %s, cobrado como hora excedente.', $nome, $T($f['consumido']), $T($f['contratado']), mb_strtolower(PluginGlpisasHoras::descreverPeriodo($h)), $T($f['excedente'])), false, WARNING);
            } elseif ((int) PluginGlpisasConfig::getConfig('avisar_perto') && $f['contratado'] > 0 && $f['consumido'] / $f['contratado'] * 100 >= PluginGlpisasConfig::inteiro('alerta_pct', 1, 100)) {
                Session::addMessageAfterRedirect(sprintf('Atenção: %s já consumiu %s de %s horas contratadas (%s).', $nome, $T($f['consumido']), $T($f['contratado']), mb_strtolower(PluginGlpisasHoras::descreverPeriodo($h))), false, WARNING);
            }
        }
        return true;
    }
}
