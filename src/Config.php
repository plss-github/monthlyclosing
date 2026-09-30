<?php

namespace GlpiPlugin\Monthlyclosing;

use CommonGLPI;
use Session;

/**
 * Configuração do plugin Fechamento Mensal.
 *
 * - Quais perfis podem acessar esta página de configuração
 * - Quais perfis podem gerenciar janelas de fechamento (CRUD)
 *
 * O bloqueio Solucionado → Fechado é universal e não depende de perfil.
 */
class Config extends CommonGLPI
{
    public static $rightname = 'config';

    private const TABLE = 'glpi_plugin_monthlyclosing_configs';

    public static function getTypeName($nb = 0): string
    {
        return __('Fechamento Mensal — Configuração', 'monthlyclosing');
    }

    // ------------------------------------------------------------------
    // Verificação de acesso à configuração
    // ------------------------------------------------------------------

    public static function canCurrentProfileConfigure(): bool
    {
        if (!empty($_SESSION['glpiactiveprofile']['is_super_admin'])) {
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
            'config_profiles_ids' => json_decode($row['config_profiles_ids'] ?? '[]', true) ?: [],
        ];
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
        $configIds = array_map('intval', (array) ($input['config_profiles_ids'] ?? []));

        $DB->update(self::TABLE, [
            'config_profiles_ids' => json_encode(array_values($configIds)),
            'date_mod'            => date('Y-m-d H:i:s'),
        ], ['id' => 1]);

        // --- Perfis com direito de gerenciar janelas ---
        $windowIds = array_map('intval', (array) ($input['window_profiles_ids'] ?? []));

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
        $config         = static::getConfig();
        $profiles       = static::getAllProfiles();
        $windowProfiles = static::getWindowProfileIds();

        echo '<form method="post" action="' . \Plugin::getWebDir('monthlyclosing') . '/front/config.form.php">';
        echo '<table class="tab_cadre_fixe">';

        // --- Seção 1: quem pode configurar o plugin ---
        echo '<tr class="tab_bg_2"><th colspan="2">';
        echo __('Perfis que podem configurar o plugin', 'monthlyclosing');
        echo ' <small class="text-muted ms-2">' . __('(vazio = qualquer admin)', 'monthlyclosing') . '</small>';
        echo '</th></tr>';
        echo '<tr class="tab_bg_1"><td colspan="2">';
        static::showProfileCheckboxes('config_profiles_ids', $profiles, $config['config_profiles_ids']);
        echo '</td></tr>';

        // --- Seção 2: quem pode gerenciar janelas ---
        echo '<tr class="tab_bg_2"><th colspan="2">';
        echo __('Perfis que podem gerenciar janelas de fechamento', 'monthlyclosing');
        echo ' <small class="text-muted ms-2">' . __('(criar, editar e excluir)', 'monthlyclosing') . '</small>';
        echo '</th></tr>';
        echo '<tr class="tab_bg_1"><td colspan="2">';
        static::showProfileCheckboxes('window_profiles_ids', $profiles, $windowProfiles);
        echo '</td></tr>';

        echo '<tr class="tab_bg_2">';
        echo '<td colspan="2" class="center">';
        echo \Html::submit(__('Salvar'), ['name' => 'update']);
        echo '</td>';
        echo '</tr>';

        echo '</table>';
        \Html::closeForm();
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

    private static function showProfileCheckboxes(string $name, array $profiles, array $selected): void
    {
        echo '<div class="d-flex flex-wrap gap-3 p-2">';

        foreach ($profiles as $id => $label) {
            $checked = in_array($id, $selected, true) ? 'checked' : '';
            echo '<div class="form-check form-check-inline">';
            echo '<input class="form-check-input" type="checkbox"';
            echo ' name="' . htmlescape($name) . '[]"';
            echo ' id="' . htmlescape($name) . '_' . $id . '"';
            echo ' value="' . $id . '" ' . $checked . '>';
            echo '<label class="form-check-label" for="' . htmlescape($name) . '_' . $id . '">';
            echo htmlescape($label);
            echo '</label>';
            echo '</div>';
        }

        echo '</div>';
    }
}
