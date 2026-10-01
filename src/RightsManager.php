<?php

namespace GlpiPlugin\Monthlyclosing;

/**
 * Gerencia a aplicação e restauração das restrições de fechamento por entidade.
 *
 * Ao ativar uma janela:
 *   - Salva autoclose_delay e autopurge_delay de cada entidade
 *   - Define ambos como Entity::CONFIG_NEVER (-10 = "Nunca")
 *
 * Ao encerrar a janela:
 *   - Restaura os valores originais
 *
 * O bloqueio de status "Fechado" é feito via hook pre_item_update (setup.php).
 */
class RightsManager
{
    private const BACKUP_TABLE = 'glpi_plugin_monthlyclosing_entitybackups';
    private const CONFIG_TABLE = 'glpi_plugin_monthlyclosing_configs';

    public static function getBackupTable(): string
    {
        return self::BACKUP_TABLE;
    }

    // ------------------------------------------------------------------
    // Ativação
    // ------------------------------------------------------------------

    public static function apply(Window $window): void
    {
        global $DB;

        // Outra janela ativa já desativou — não duplica backups
        if (self::getOtherActiveWindowId($window) !== null) {
            return;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($DB->request(['FROM' => 'glpi_entities']) as $entity) {
            $DB->insert(self::BACKUP_TABLE, [
                'windows_id'       => $window->fields['id'],
                'entities_id'      => $entity['id'],
                'autoclose_delay'  => isset($entity['autoclose_delay'])  ? (int) $entity['autoclose_delay']  : \Entity::CONFIG_PARENT,
                'autopurge_delay'  => isset($entity['autopurge_delay'])  ? (int) $entity['autopurge_delay']  : \Entity::CONFIG_PARENT,
                'date_creation'    => $now,
            ]);

            // Entity::CONFIG_NEVER = -10 = "Nunca" na interface do GLPI
            $DB->update('glpi_entities', [
                'autoclose_delay' => \Entity::CONFIG_NEVER,
                'autopurge_delay' => \Entity::CONFIG_NEVER,
            ], ['id' => $entity['id']]);
        }
    }

    // ------------------------------------------------------------------
    // Restauração
    // ------------------------------------------------------------------

    public static function restore(Window $window): void
    {
        global $DB;

        // Outra janela ainda ativa: repassa os backups para ela
        $other = self::getOtherActiveWindowId($window);
        if ($other !== null) {
            $DB->update(
                self::BACKUP_TABLE,
                ['windows_id' => $other],
                ['windows_id' => $window->fields['id']]
            );
            return;
        }

        // Materializa antes de deletar (evita ler de resultado vazio)
        $backups = iterator_to_array(
            $DB->request([
                'FROM'  => self::BACKUP_TABLE,
                'WHERE' => ['windows_id' => $window->fields['id']],
            ])
        );

        foreach ($backups as $backup) {
            $DB->update('glpi_entities', [
                'autoclose_delay' => (int) $backup['autoclose_delay'],
                'autopurge_delay' => (int) $backup['autopurge_delay'],
            ], ['id' => (int) $backup['entities_id']]);
        }

        $DB->delete(self::BACKUP_TABLE, ['windows_id' => $window->fields['id']]);
    }

    // ------------------------------------------------------------------
    // Helpers privados
    // ------------------------------------------------------------------

    private static function getOtherActiveWindowId(Window $window): ?int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Window::getTable(),
            'WHERE'  => [
                'status' => Window::STATUS_ACTIVE,
                ['NOT'   => ['id' => $window->fields['id']]],
            ],
            'LIMIT' => 1,
        ])->current();

        return $row ? (int) $row['id'] : null;
    }

    // ------------------------------------------------------------------
    // Configuração
    // ------------------------------------------------------------------

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
}
