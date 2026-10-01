<?php

use Glpi\Plugin\Hooks;
use GlpiPlugin\Monthlyclosing\Window;

define('PLUGIN_MONTHLYCLOSING_VERSION', '1.1.0');
define('PLUGIN_MONTHLYCLOSING_MIN_GLPI', '11.0.0');
define('PLUGIN_MONTHLYCLOSING_MAX_GLPI', '11.0.99');

/**
 * Inicializa os hooks do plugin.
 */
function plugin_init_monthlyclosing(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['monthlyclosing'] = true;

    // Registrado fora do bloco de sessão: dispara também via cron/API/CLI
    $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['monthlyclosing'] = [
        \Ticket::class  => 'plugin_monthlyclosing_pre_item_update_ticket',
        \Problem::class => 'plugin_monthlyclosing_pre_item_update_ticket',
        \Change::class  => 'plugin_monthlyclosing_pre_item_update_ticket',
    ];

    if (!Session::getLoginUserID()) {
        return;
    }

    Plugin::registerClass(Window::class);

    // CONFIG_PAGE sempre registrado; controle de acesso feito no próprio front
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['monthlyclosing'] = 'front/config.form.php';

    if (Session::haveRight(Window::$rightname, READ)) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['monthlyclosing'] = [
            'management' => Window::class,
        ];
    }
}

/**
 * Retorna metadados do plugin.
 */
function plugin_version_monthlyclosing(): array
{
    return [
        'name'         => 'Fechamento Mensal',
        'version'      => PLUGIN_MONTHLYCLOSING_VERSION,
        'author'       => '<a href="https://pellissari.com.br">Pellissari</a>',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/plss-github/monthlyclosing',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_MONTHLYCLOSING_MIN_GLPI,
                'max' => PLUGIN_MONTHLYCLOSING_MAX_GLPI,
            ],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_monthlyclosing_check_config(bool $verbose = false): bool
{
    return true;
}

// ---------------------------------------------------------------------------
// Hook: bloqueia transição para FECHADO durante janela ativa
// ---------------------------------------------------------------------------

/**
 * Intercepta update de Ticket/Problem/Change.
 *
 * Bloqueia a transição para status CLOSED quando há janela ativa, para os
 * perfis configurados (vazio = todos). Execuções sem sessão (cron do GLPI,
 * CLI) são sempre bloqueadas.
 */
function plugin_monthlyclosing_pre_item_update_ticket(\CommonITILObject $item): void
{
    if (!isset($item->input['status'])) {
        return;
    }

    if ((int) $item->input['status'] !== \CommonITILObject::CLOSED) {
        return;
    }

    if (!\GlpiPlugin\Monthlyclosing\Window::hasActiveWindow()) {
        return;
    }

    if (!\GlpiPlugin\Monthlyclosing\Config::isClosingBlockedForCurrentProfile()) {
        return;
    }

    // Remove o status do input — o update continua sem alterar o status
    unset($item->input['status']);

    // Exibe mensagem apenas quando há sessão de usuário (não para cron/CLI)
    if (Session::getLoginUserID()) {
        Session::addMessageAfterRedirect(
            __('Fechamento bloqueado: há uma janela de fechamento mensal ativa. Chamados só podem ser marcados como Solucionados neste período.', 'monthlyclosing'),
            true,
            WARNING
        );
    }
}
