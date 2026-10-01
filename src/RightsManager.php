<?php

namespace GlpiPlugin\Monthlyclosing;

/**
 * Gerencia a aplicação e restauração das restrições de fechamento.
 *
 * Ao ativar uma janela:
 *   - Salva o valor atual de autoclose_delay de cada entidade
 *   - Zera autoclose_delay em todas as entidades (desativa fechamento automático)
 *
 * Ao encerrar a janela:
 *   - Restaura os valores originais de cada entidade
 *
 * O bloqueio de status "Fechado" nos objetos ITIL é feito via hook pre_item_update
 * em setup.php — bloqueia os perfis configurados (vazio = todos) e o próprio cron do GLPI.
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
    // Ativação: desativa autoclose em todas as entidades
    // ------------------------------------------------------------------

    public static function apply(Window $window): void
    {
        global $DB;

        $now = date('Y-m-d H:i:s');

        // Outra janela já está ativa: o autoclose já foi desativado e os valores
        // originais estão no backup dela (repassado a esta janela no restore()).
        $other = self::getOtherActiveWindowId($window);
        if ($other !== null) {
            return;
        }

        // Lê todas as entidades e salva backup antes de alterar
        $entities = $DB->request(['FROM' => 'glpi_entities']);

        foreach ($entities as $entity) {
            $originalDelay = isset($entity['autoclose_delay'])
                ? (int) $entity['autoclose_delay']
                : \Entity::CONFIG_PARENT; // herda do pai (valor padrão seguro para restaurar)

            $DB->insert(self::BACKUP_TABLE, [
                'windows_id'      => $window->fields['id'],
                'entities_id'     => $entity['id'],
                'autoclose_delay' => $originalDelay,
                'date_creation'   => $now,
            ]);

            // CONFIG_NEVER (-10) = nunca fechar automaticamente (0 significa "imediatamente")
            $DB->update('glpi_entities', ['autoclose_delay' => \Entity::CONFIG_NEVER], ['id' => $entity['id']]);
        }
    }

    // ------------------------------------------------------------------
    // Restauração: devolve autoclose original de cada entidade
    // ------------------------------------------------------------------

    public static function restore(Window $window): void
    {
        global $DB;

        // Se outra janela continua ativa, não restaura: repassa os backups para ela
        $other = self::getOtherActiveWindowId($window);
        if ($other !== null) {
            $DB->update(
                self::BACKUP_TABLE,
                ['windows_id' => $other],
                ['windows_id' => $window->fields['id']]
            );
            return;
        }

        // Lê TODOS os backups ANTES de deletar qualquer registro
        $backups = iterator_to_array(
            $DB->request([
                'FROM'  => self::BACKUP_TABLE,
                'WHERE' => ['windows_id' => $window->fields['id']],
            ])
        );

        // Restaura cada entidade
        foreach ($backups as $backup) {
            $DB->update('glpi_entities', [
                'autoclose_delay' => (int) $backup['autoclose_delay'],
            ], ['id' => (int) $backup['entities_id']]);
        }

        // Remove backups somente após restaurar tudo
        $DB->delete(self::BACKUP_TABLE, ['windows_id' => $window->fields['id']]);
    }

    /**
     * Retorna o ID de outra janela ativa (diferente da informada), ou null.
     */
    private static function getOtherActiveWindowId(Window $window): ?int
    {
        global $DB;

        $row = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => Window::getTable(),
            'WHERE'  => [
                'status' => Window::STATUS_ACTIVE,
                ['NOT' => ['id' => $window->fields['id']]],
            ],
            'LIMIT'  => 1,
        ])->current();

        return $row ? (int) $row['id'] : null;
    }

    // ------------------------------------------------------------------
    // Configuração: quais perfis podem configurar o plugin
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
