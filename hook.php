<?php

use GlpiPlugin\Monthlyclosing\Window;
use GlpiPlugin\Monthlyclosing\RightsManager;

/**
 * Instalação (também roda em upgrade — sempre testa existência antes de criar/alterar).
 */
function plugin_monthlyclosing_install(): bool
{
    global $DB;

    $migration = new Migration(PLUGIN_MONTHLYCLOSING_VERSION);
    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // ------------------------------------------------------------------
    // Janelas de fechamento
    // ------------------------------------------------------------------
    $windowTable = Window::getTable();
    if (!$DB->tableExists($windowTable)) {
        $DB->doQuery("CREATE TABLE `{$windowTable}` (
            `id`           int {$sign} NOT NULL AUTO_INCREMENT,
            `name`         varchar(255) DEFAULT NULL,
            `date_start`   timestamp NULL DEFAULT NULL,
            `date_end`     timestamp NULL DEFAULT NULL,
            `status`       tinyint NOT NULL DEFAULT '0'
                           COMMENT '0=pendente 1=ativa 2=encerrada 3=cancelada',
            `comment`      text DEFAULT NULL,
            `date_activation` timestamp NULL DEFAULT NULL,
            `date_deactivation` timestamp NULL DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod`     timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `status`       (`status`),
            KEY `date_start`   (`date_start`),
            KEY `date_end`     (`date_end`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // ------------------------------------------------------------------
    // Backup de configurações de entidades (autoclose_delay + autopurge_delay)
    // ------------------------------------------------------------------
    $backupTable = RightsManager::getBackupTable();
    if (!$DB->tableExists($backupTable)) {
        $DB->doQuery("CREATE TABLE `{$backupTable}` (
            `id`              int {$sign} NOT NULL AUTO_INCREMENT,
            `windows_id`      int {$sign} NOT NULL DEFAULT '0',
            `entities_id`     int {$sign} NOT NULL DEFAULT '0',
            `autoclose_delay` int NOT NULL DEFAULT '0',
            `autopurge_delay` int NOT NULL DEFAULT '0',
            `date_creation`   timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `windows_id`  (`windows_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    } else {
        // Upgrade: adiciona coluna autopurge_delay se ainda não existir
        $migration->addField($backupTable, 'autopurge_delay', 'integer', ['value' => 0, 'after' => 'autoclose_delay']);
    }

    // ------------------------------------------------------------------
    // Configuração do plugin
    // ------------------------------------------------------------------
    $configTable = 'glpi_plugin_monthlyclosing_configs';
    if (!$DB->tableExists($configTable)) {
        $DB->doQuery("CREATE TABLE `{$configTable}` (
            `id`                    int {$sign} NOT NULL AUTO_INCREMENT,
            `config_profiles_ids`   text DEFAULT NULL
                                    COMMENT 'JSON: IDs dos perfis que podem configurar o plugin',
            `target_profiles_ids`   text DEFAULT NULL
                                    COMMENT 'JSON: IDs dos perfis bloqueados (NULL/vazio = todos)',
            `date_mod`              timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");

        // Registro padrão (super-admin pode sempre configurar)
        $DB->insert($configTable, [
            'id'                  => 1,
            'config_profiles_ids' => json_encode([]),
            'target_profiles_ids' => json_encode([]),
            'date_mod'            => date('Y-m-d H:i:s'),
        ]);
    }

    // ------------------------------------------------------------------
    // Direitos do plugin: concede ao(s) perfil(is) super-admin
    // ------------------------------------------------------------------
    foreach (Profile::getSuperAdminProfilesId() as $profileId) {
        $existing = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['profiles_id' => $profileId, 'name' => Window::$rightname],
        ])->current();

        if (!$existing) {
            $DB->insert('glpi_profilerights', [
                'profiles_id' => $profileId,
                'name'        => Window::$rightname,
                'rights'      => ALLSTANDARDRIGHT,
            ]);
        } elseif ((int) $existing['rights'] === 0) {
            $DB->update('glpi_profilerights', ['rights' => ALLSTANDARDRIGHT], ['id' => $existing['id']]);
        }
    }

    // Atualiza os direitos da sessão atual (evita precisar relogar após instalar)
    if (Session::getLoginUserID() && isset($_SESSION['glpiactiveprofile']['id'])) {
        $_SESSION['glpiactiveprofile'][Window::$rightname] = (int) (ProfileRight::getProfileRights(
            $_SESSION['glpiactiveprofile']['id'],
            [Window::$rightname]
        )[Window::$rightname] ?? 0);
    }

    // ------------------------------------------------------------------
    // Colunas padrão da listagem (Início, Fim, Status)
    // ------------------------------------------------------------------
    $hasPrefs = $DB->request([
        'FROM'  => 'glpi_displaypreferences',
        'WHERE' => ['itemtype' => Window::class, 'users_id' => 0],
        'LIMIT' => 1,
    ])->count() > 0;
    if (!$hasPrefs) {
        foreach ([11, 12, 13] as $rank => $num) {
            $DB->insert('glpi_displaypreferences', [
                'itemtype' => Window::class,
                'num'      => $num,
                'rank'     => $rank + 1,
                'users_id' => 0,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Cron: verifica janelas a cada minuto
    // ------------------------------------------------------------------
    CronTask::register(
        Window::class,
        'MonthlyClosing',
        MINUTE_TIMESTAMP,
        [
            'comment' => 'Ativa/desativa janelas de fechamento mensal e ajusta permissões',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    // register() não atualiza tarefas já existentes — forçamos a frequência correta
    $DB->update('glpi_crontasks', ['frequency' => MINUTE_TIMESTAMP], [
        'itemtype' => Window::class,
        'name'     => 'MonthlyClosing',
    ]);

    $migration->executeMigration();

    // Diretório de dados do plugin
    $docDir = GLPI_PLUGIN_DOC_DIR . '/monthlyclosing';
    if (!is_dir($docDir)) {
        mkdir($docDir, 0755, true);
    }

    return true;
}

/**
 * Desinstalação: restaura configurações e remove todas as tabelas do plugin.
 */
function plugin_monthlyclosing_uninstall(): bool
{
    global $DB;

    // Garante que qualquer janela ativa seja desativada antes de remover
    $activeWindows = !$DB->tableExists(Window::getTable()) ? [] : $DB->request([
        'FROM'  => Window::getTable(),
        'WHERE' => ['status' => Window::STATUS_ACTIVE],
    ]);
    foreach ($activeWindows as $row) {
        $window = new Window();
        $window->getFromDB($row['id']);
        RightsManager::restore($window);
    }

    $tables = [
        Window::getTable(),
        RightsManager::getBackupTable(),
        'glpi_plugin_monthlyclosing_configs',
    ];

    foreach ($tables as $table) {
        if ($DB->tableExists($table)) {
            $DB->dropTable($table);
        }
    }

    $DB->delete('glpi_profilerights', ['name' => Window::$rightname]);
    $DB->delete('glpi_displaypreferences', ['itemtype' => Window::class]);
    unset($_SESSION['glpiactiveprofile'][Window::$rightname]);

    $docDir = GLPI_PLUGIN_DOC_DIR . '/monthlyclosing';
    if (is_dir($docDir)) {
        Toolbox::deleteDir($docDir);
    }

    return true;
}
