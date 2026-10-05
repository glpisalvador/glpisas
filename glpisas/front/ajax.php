<?php

/**
 * Plugin GLPI SAS - endpoints AJAX (JSON): ativar/desativar, excluir e detalhes das horas
 */

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();
include('../../../inc/includes.php');
while (ob_get_level() > 0) {
    ob_end_clean();
}

register_shutdown_function(static function (): void {
    $erro = error_get_last();
    if ($erro && in_array($erro['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['success' => false, 'message' => 'Erro interno ao processar a solicitação.']);
    }
});

function glpisas_responder(array $dados): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    $dados['new_token'] = PluginGlpisasConfig::tokenCsrf();
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!Session::getLoginUserID() || !PluginGlpisasConfig::podeVer()) {
    glpisas_responder(['success' => false, 'message' => 'Sem permissão.']);
}

$acao = (string) ($_REQUEST['action'] ?? '');
$id = (int) ($_REQUEST['id'] ?? 0);
$objeto = (string) ($_REQUEST['objeto'] ?? '');
$classe = ['limite' => PluginGlpisasLimite::class, 'horas' => PluginGlpisasHoras::class][$objeto] ?? null;

switch ($acao) {
    case 'alternar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !PluginGlpisasConfig::podeGerenciar() || $classe === null) {
            glpisas_responder(['success' => false, 'message' => 'Sem permissão.']);
        }
        $novo = $classe::alternar($id);
        if ($novo === null) {
            glpisas_responder(['success' => false, 'message' => 'Registro não encontrado.']);
        }
        glpisas_responder(['success' => true, 'ativo' => $novo, 'message' => $novo ? 'Ativado.' : 'Desativado.']);

        // no break
    case 'excluir':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !PluginGlpisasConfig::podeGerenciar() || $classe === null) {
            glpisas_responder(['success' => false, 'message' => 'Sem permissão.']);
        }
        if (!$classe::excluir($id)) {
            glpisas_responder(['success' => false, 'message' => 'Registro não encontrado.']);
        }
        Session::addMessageAfterRedirect($objeto === 'limite' ? 'Limite excluído.' : 'Controle de horas excluído.', false, INFO);
        glpisas_responder(['success' => true]);

        // no break
    case 'detalhes_horas':
        $h = PluginGlpisasHoras::obter($id);
        if (!$h) {
            glpisas_responder(['success' => false, 'message' => 'Controle não encontrado.']);
        }
        $c = PluginGlpisasHoras::consumo($h, true);
        $f = PluginGlpisasHoras::financeiro($h, $c['minutos']);
        $chamados = [];
        foreach ($c['detalhes'] as $d) {
            $chamados[] = [
                'id'       => $d['id'],
                'nome'     => $d['nome'],
                'link'     => Ticket::getFormURLWithID($d['id']),
                'data'     => Html::convDateTime($d['data']),
                'status'   => $d['status'],
                'entidade' => $d['entidade'],
                'tempo'    => PluginGlpisasConfig::tempo($d['minutos']),
            ];
        }
        glpisas_responder([
            'success'  => true,
            'titulo'   => PluginGlpisasHoras::tipos()[$h['tipo_alvo']][0] . ' ' . PluginGlpisasHoras::nomeAlvo($h['tipo_alvo'], (int) $h['alvo_id']),
            'periodo'  => PluginGlpisasHoras::descreverPeriodo($h),
            'fonte'    => PluginGlpisasHoras::fontes()[$h['fonte']] ?? '',
            'resumo'   => [
                'contratado'      => PluginGlpisasConfig::tempo($f['contratado']),
                'consumido'       => PluginGlpisasConfig::tempo($f['consumido']),
                'excedente'       => PluginGlpisasConfig::tempo($f['excedente']),
                'valor_dentro'    => PluginGlpisasConfig::reais($f['valor_dentro']),
                'valor_excedente' => PluginGlpisasConfig::reais($f['valor_excedente']),
                'valor_total'     => PluginGlpisasConfig::reais($f['valor_total']),
            ],
            'chamados' => $chamados,
            'csv'      => PluginGlpisasConfig::url('exportar.php', ['tipo' => 'horas', 'id' => $id]),
        ]);

        // no break
    default:
        glpisas_responder(['success' => false, 'message' => 'Ação desconhecida.']);
}
