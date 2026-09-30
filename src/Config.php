<?php

namespace GlpiPlugin\Monthlyclosing;

use CommonGLPI;
use Profile;
use Session;

/**
 * Configuração do plugin Fechamento Mensal.
 *
 * Permite selecionar:
 *  - Quais perfis GLPI podem acessar as configurações do plugin
 *  - Quais perfis terão o fechamento bloqueado durante a janela (vazio = todos)
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
    // Verificação de acesso
    // ------------------------------------------------------------------

    /**
     * Retorna true se o perfil ativo do usuário pode configurar o plugin.
     * Super-admin (profile id=4 por convenção) sempre pode; além disso,
     * respeita a lista salva na configuração do plugin.
     */
    public static function canCurrentProfileConfigure(): bool
    {
        if (Session::isSuperAdmin()) {
            return true;
        }

        if (!Session::haveRight('config', UPDATE)) {
            return false;
        }

        $configProfileIds = RightsManager::getConfigProfileIds();

        // Se a lista estiver vazia, qualquer um com right config/UPDATE pode configurar
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

        if (!$row) {
            return ['config_profiles_ids' => [], 'target_profiles_ids' => []];
        }

        return [
            'config_profiles_ids' => json_decode($row['config_profiles_ids'] ?? '[]', true) ?: [],
            'target_profiles_ids' => json_decode($row['target_profiles_ids'] ?? '[]', true) ?: [],
        ];
    }

    // ------------------------------------------------------------------
    // Gravação
    // ------------------------------------------------------------------

    public static function saveConfig(array $input): void
    {
        global $DB;

        $configIds  = array_map('intval', (array) ($input['config_profiles_ids'] ?? []));
        $targetIds  = array_map('intval', (array) ($input['target_profiles_ids'] ?? []));

        $DB->update(self::TABLE, [
            'config_profiles_ids' => json_encode(array_values($configIds)),
            'target_profiles_ids' => json_encode(array_values($targetIds)),
            'date_mod'            => date('Y-m-d H:i:s'),
        ], ['id' => 1]);
    }

    // ------------------------------------------------------------------
    // Exibição
    // ------------------------------------------------------------------

    public static function showConfigForm(): void
    {
        $config   = static::getConfig();
        $profiles = static::getAllProfiles();

        echo '<form method="post" action="' . \Plugin::getWebDir('monthlyclosing') . '/front/config.form.php">';
        echo '<table class="tab_cadre_fixe">';

        echo '<tr class="tab_bg_2"><th colspan="2">';
        echo __('Perfis que podem configurar o plugin', 'monthlyclosing');
        echo ' <span class="badge bg-secondary ms-2">' . __('Vazio = qualquer admin', 'monthlyclosing') . '</span>';
        echo '</th></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">';
        static::showProfileCheckboxes(
            'config_profiles_ids',
            $profiles,
            $config['config_profiles_ids']
        );
        echo '</td></tr>';

        echo '<tr class="tab_bg_2"><th colspan="2">';
        echo __('Perfis com fechamento bloqueado durante a janela', 'monthlyclosing');
        echo ' <span class="badge bg-secondary ms-2">' . __('Vazio = todos os perfis', 'monthlyclosing') . '</span>';
        echo '</th></tr>';

        echo '<tr class="tab_bg_1"><td colspan="2">';
        static::showProfileCheckboxes(
            'target_profiles_ids',
            $profiles,
            $config['target_profiles_ids']
        );
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
            echo '<input class="form-check-input" type="checkbox" ';
            echo 'name="' . htmlescape($name) . '[]" ';
            echo 'id="' . htmlescape($name) . '_' . $id . '" ';
            echo 'value="' . $id . '" ' . $checked . '>';
            echo '<label class="form-check-label" for="' . htmlescape($name) . '_' . $id . '">';
            echo htmlescape($label);
            echo '</label>';
            echo '</div>';
        }

        echo '</div>';
    }
}
