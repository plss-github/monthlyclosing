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
            `date_start`   datetime NOT NULL,
            `date_end`     datetime NOT NULL,
            `status`       tinyint NOT NULL DEFAULT '0'
                           COMMENT '0=pendente 1=ativa 2=encerrada 3=cancelada',
            `comment`      text DEFAULT NULL,
            `date_activation` datetime DEFAULT NULL,
            `date_deactivation` datetime DEFAULT NULL,
            `date_creation` datetime DEFAULT NULL,
            `date_mod`     datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `status`       (`status`),
            KEY `date_start`   (`date_start`),
            KEY `date_end`     (`date_end`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
    }

    // ------------------------------------------------------------------
    // Backup de configurações de entidades (autoclose_delay)
    // ------------------------------------------------------------------
    $backupTable = RightsManager::getBackupTable();
    if (!$DB->tableExists($backupTable)) {
        $DB->doQuery("CREATE TABLE `{$backupTable}` (
            `id`              int {$sign} NOT NULL AUTO_INCREMENT,
            `windows_id`      int {$sign} NOT NULL DEFAULT '0',
            `entities_id`     int {$sign} NOT NULL DEFAULT '0',
            `autoclose_delay` int NOT NULL DEFAULT '0',
            `date_creation`   datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `windows_id`  (`windows_id`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC");
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
            `date_mod`              datetime DEFAULT NULL,
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
    $superAdminProfiles = $DB->request([
        'FROM'  => 'glpi_profiles',
        'WHERE' => ['is_super_admin' => 1],
    ]);
    foreach ($superAdminProfiles as $profile) {
        $existing = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => ['profiles_id' => $profile['id'], 'name' => Window::$rightname],
        ])->current();

        if (!$existing) {
            $DB->insert('glpi_profilerights', [
                'profiles_id' => $profile['id'],
                'name'        => Window::$rightname,
                'rights'      => ALLSTANDARDRIGHT,
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
    $activeWindows = $DB->request([
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

    $docDir = GLPI_PLUGIN_DOC_DIR . '/monthlyclosing';
    if (is_dir($docDir)) {
        Toolbox::deleteDir($docDir);
    }

    return true;
}
