<?php

namespace GlpiPlugin\Monthlyclosing;

use CommonGLPI;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Configuração do plugin Fechamento Mensal.
 *
 * - Quais perfis podem acessar esta página de configuração
 * - Quais perfis podem gerenciar janelas de fechamento (CRUD)
 * - Quais perfis têm o fechamento bloqueado durante uma janela ativa
 *   (vazio = todos; execuções sem sessão, como o cron, são sempre bloqueadas)
 */
class Config extends CommonGLPI
{
    public static $rightname = 'config';

    private const TABLE = 'glpi_plugin_monthlyclosing_configs';

    public static function getTypeName($nb = 0): string
    {
        return __('Fechamento Mensal — Configuração', 'monthlyclosing');
    }

    public static function getIcon()
    {
        return 'ti ti-calendar-cog';
    }

    // ------------------------------------------------------------------
    // Verificação de acesso à configuração
    // ------------------------------------------------------------------

    public static function canCurrentProfileConfigure(): bool
    {
        // Super-admin no GLPI = perfil com direito de editar perfis
        if (Session::haveRight('profile', UPDATE)) {
            return true;
        }

        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }

        $configProfileIds = RightsManager::getConfigProfileIds();

        // Lista vazia = qualquer perfil com direito config/UPDATE pode configurar
        if (empty($configProfileIds)) {
            return true;
        }

        $currentProfileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
        return in_array($currentProfileId, $configProfileIds, true);
    }

    // ------------------------------------------------------------------
    // Leitura
    // ------------------------------------------------------------------

    public static function getConfig(): array
    {
        global $DB;

        $row = $DB->request([
            'FROM'  => self::TABLE,
            'WHERE' => ['id' => 1],
            'LIMIT' => 1,
        ])->current();

        return [
            'config_profiles_ids' => static::decodeIds($row['config_profiles_ids'] ?? null),
            'target_profiles_ids' => static::decodeIds($row['target_profiles_ids'] ?? null),
        ];
    }

    /**
     * IDs dos perfis com fechamento bloqueado (vazio = todos).
     */
    public static function getTargetProfileIds(): array
    {
        return static::getConfig()['target_profiles_ids'];
    }

    /**
     * Indica se o fechamento deve ser bloqueado para a execução atual.
     * Sem sessão (cron/CLI) sempre bloqueia.
     */
    public static function isClosingBlockedForCurrentProfile(): bool
    {
        if (!Session::getLoginUserID() || !isset($_SESSION['glpiactiveprofile']['id'])) {
            return true;
        }

        $targets = static::getTargetProfileIds();
        if (empty($targets)) {
            return true;
        }

        return in_array((int) $_SESSION['glpiactiveprofile']['id'], $targets, true);
    }

    /**
     * Retorna os IDs dos perfis que têm direito de gerenciar janelas (RIGHT > 0).
     */
    public static function getWindowProfileIds(): array
    {
        global $DB;

        $ids  = [];
        $rows = $DB->request([
            'FROM'  => 'glpi_profilerights',
            'WHERE' => [
                'name'   => Window::$rightname,
                ['rights' => ['>', 0]],
            ],
        ]);

        foreach ($rows as $row) {
            $ids[] = (int) $row['profiles_id'];
        }

        return $ids;
    }

    // ------------------------------------------------------------------
    // Gravação
    // ------------------------------------------------------------------

    public static function saveConfig(array $input): void
    {
        global $DB;

        // --- Perfis que podem acessar a configuração ---
        $configIds = static::sanitizeIds($input['config_profiles_ids'] ?? []);
        $targetIds = static::sanitizeIds($input['target_profiles_ids'] ?? []);

        $DB->update(self::TABLE, [
            'config_profiles_ids' => json_encode($configIds),
            'target_profiles_ids' => json_encode($targetIds),
            'date_mod'            => date('Y-m-d H:i:s'),
        ], ['id' => 1]);

        // --- Perfis com direito de gerenciar janelas ---
        $windowIds = static::sanitizeIds($input['window_profiles_ids'] ?? []);

        // Remove todos os direitos existentes do plugin para reconstruir do zero
        $DB->delete('glpi_profilerights', ['name' => Window::$rightname]);

        foreach ($windowIds as $profileId) {
            $DB->insert('glpi_profilerights', [
                'profiles_id' => $profileId,
                'name'        => Window::$rightname,
                'rights'      => ALLSTANDARDRIGHT,
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Exibição
    // ------------------------------------------------------------------

    public static function showConfigForm(): void
    {
        global $CFG_GLPI;

        TemplateRenderer::getInstance()->display('@monthlyclosing/config.html.twig', [
            'form_url'            => $CFG_GLPI['root_doc'] . '/plugins/monthlyclosing/front/config.form.php',
            'config'              => static::getConfig(),
            'profiles'            => static::getAllProfiles(),
            'window_profiles_ids' => static::getWindowProfileIds(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private static function getAllProfiles(): array
    {
        global $DB;

        $profiles = [];
        $rows     = $DB->request(['FROM' => 'glpi_profiles', 'ORDER' => 'name ASC']);

        foreach ($rows as $row) {
            $profiles[(int) $row['id']] = $row['name'];
        }

        return $profiles;
    }

    private static function decodeIds(?string $json): array
    {
        return static::sanitizeIds(json_decode($json ?? '[]', true) ?: []);
    }

    /**
     * Normaliza a lista de IDs (o multi-select do GLPI envia '' quando vazio).
     */
    private static function sanitizeIds(mixed $ids): array
    {
        $ids = array_map('intval', (array) $ids);
        return array_values(array_unique(array_filter($ids, static fn (int $id) => $id > 0)));
    }
}
