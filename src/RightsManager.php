<?php

namespace GlpiPlugin\Monthlyclosing;

/**
 * Gerencia a aplicação e restauração das restrições de fechamento:
 *
 *  - Desativa autoclose_delay em todas as entidades (salva backup por janela)
 *  - Restaura os valores originais ao encerrar a janela
 *
 * O bloqueio ativo de status "Fechado" nos ITIL é feito via hook em setup.php
 * (plugin_monthlyclosing_pre_item_update_ticket), sem alterar glpi_profilerights.
 */
class RightsManager
{
    private const BACKUP_TABLE  = 'glpi_plugin_monthlyclosing_entitybackups';
    private const CONFIG_TABLE  = 'glpi_plugin_monthlyclosing_configs';

    /** Nome da tabela de backup — usado em hook.php para criação. */
    public static function getBackupTable(): string
    {
        return self::BACKUP_TABLE;
    }

    // ------------------------------------------------------------------
    // Ativação
    // ------------------------------------------------------------------

    /**
     * Desativa o autoclose de todas as entidades e salva o estado original.
     */
    public static function apply(Window $window): void
    {
        global $DB;

        $entities = $DB->request(['FROM' => 'glpi_entities']);
        $now      = date('Y-m-d H:i:s');

        foreach ($entities as $entity) {
            // Salva backup
            $DB->insert(self::BACKUP_TABLE, [
                'windows_id'      => $window->fields['id'],
                'entities_id'     => $entity['id'],
                'autoclose_delay' => (int) $entity['autoclose_delay'],
                'date_creation'   => $now,
            ]);

            // Desativa autoclose (0 = nunca fechar automaticamente)
            $DB->update('glpi_entities', [
                'autoclose_delay' => 0,
            ], ['id' => $entity['id']]);
        }

        // Também desativa na configuração global se existir
        self::setGlobalAutoclose(0);
    }

    // ------------------------------------------------------------------
    // Restauração
    // ------------------------------------------------------------------

    /**
     * Restaura o autoclose de todas as entidades a partir do backup da janela.
     */
    public static function restore(Window $window): void
    {
        global $DB;

        $backups = $DB->request([
            'FROM'  => self::BACKUP_TABLE,
            'WHERE' => ['windows_id' => $window->fields['id']],
        ]);

        foreach ($backups as $backup) {
            $DB->update('glpi_entities', [
                'autoclose_delay' => $backup['autoclose_delay'],
            ], ['id' => $backup['entities_id']]);
        }

        // Remove backups desta janela
        $DB->delete(self::BACKUP_TABLE, ['windows_id' => $window->fields['id']]);

        // Restaura config global: pega valor do registro da entidade raiz (id=0) se existir
        $rootBackup = $DB->request([
            'FROM'  => self::BACKUP_TABLE,
            'WHERE' => ['windows_id' => $window->fields['id'], 'entities_id' => 0],
        ])->current();

        if ($rootBackup) {
            self::setGlobalAutoclose($rootBackup['autoclose_delay']);
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Altera o autoclose_delay na tabela glpi_configs (configuração global do GLPI).
     * O campo pode não existir em todas as versões; a função é tolerante a falhas.
     */
    private static function setGlobalAutoclose(int $value): void
    {
        global $DB;

        try {
            // GLPI 11 armazena configurações globais em glpi_configs com context/name
            $exists = $DB->request([
                'FROM'  => 'glpi_configs',
                'WHERE' => ['context' => 'core', 'name' => 'autoclose_delay'],
                'LIMIT' => 1,
            ])->current();

            if ($exists) {
                $DB->update('glpi_configs', ['value' => $value], [
                    'context' => 'core',
                    'name'    => 'autoclose_delay',
                ]);
            }
        } catch (\Throwable) {
            // Campo ausente na versão atual do GLPI — ignora silenciosamente
        }
    }

    // ------------------------------------------------------------------
    // Perfis autorizados a configurar o plugin
    // ------------------------------------------------------------------

    /**
     * Retorna os IDs dos perfis que podem configurar o plugin.
     */
    public static function getConfigProfileIds(): array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::CONFIG_TABLE,
            'WHERE' => ['id' => 1],
            'LIMIT' => 1,
        ])->current();

        if (!$row || empty($row['config_profiles_ids'])) {
            return [];
        }

        return json_decode($row['config_profiles_ids'], true) ?? [];
    }

    /**
     * Retorna os IDs dos perfis que têm o fechamento bloqueado.
     * Lista vazia = todos os perfis.
     */
    public static function getTargetProfileIds(): array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::CONFIG_TABLE,
            'WHERE' => ['id' => 1],
            'LIMIT' => 1,
        ])->current();

        if (!$row || empty($row['target_profiles_ids'])) {
            return [];
        }

        return json_decode($row['target_profiles_ids'], true) ?? [];
    }

    /**
     * Verifica se o usuário atual deve ter o fechamento bloqueado.
     * Retorna true quando há janela ativa E o perfil está na lista de alvo (ou lista vazia = todos).
     */
    public static function currentUserIsBlocked(): bool
    {
        if (!\GlpiPlugin\Monthlyclosing\Window::hasActiveWindow()) {
            return false;
        }

        $targetIds = static::getTargetProfileIds();

        // Lista vazia = todos os perfis são bloqueados
        if (empty($targetIds)) {
            return true;
        }

        $currentProfileId = (int) \Session::getLoginUserID(true); // retorna o profile_id
        // Na verdade Session::getLoginUserID não retorna profile; usa $_SESSION
        $currentProfileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);

        return in_array($currentProfileId, $targetIds, true);
    }
}
