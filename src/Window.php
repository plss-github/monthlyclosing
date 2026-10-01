<?php

namespace GlpiPlugin\Monthlyclosing;

use CommonDBTM;
use CronTask;
use Glpi\Application\View\TemplateRenderer;
use Session;

/**
 * Janela de fechamento mensal.
 *
 * Durante o intervalo [date_start, date_end]:
 *  - Tickets/Problems/Changes não podem ser fechados (status Closed);
 *  - O autoclose das entidades é desativado.
 *
 * O cron `cronMonthlyClosing` ativa e desativa as janelas automaticamente.
 */
class Window extends CommonDBTM
{
    public static $rightname = 'plugin_monthlyclosing_window';

    public $dohistory = true;

    const STATUS_PENDING   = 0;
    const STATUS_ACTIVE    = 1;
    const STATUS_FINISHED  = 2;
    const STATUS_CANCELLED = 3;

    public static function getTypeName($nb = 0): string
    {
        return _n('Janela de Fechamento', 'Janelas de Fechamento', $nb, 'monthlyclosing');
    }

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_monthlyclosing_windows';
    }

    public static function getIcon(): string
    {
        return 'ti ti-calendar-x';
    }

    // ------------------------------------------------------------------
    // Lógica de negócio
    // ------------------------------------------------------------------

    /**
     * Retorna true se existe ao menos uma janela com status ACTIVE agora.
     */
    public static function hasActiveWindow(): bool
    {
        global $DB;

        $result = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['status' => self::STATUS_ACTIVE],
            'LIMIT' => 1,
        ]);

        return $result->count() > 0;
    }

    /**
     * Retorna os labels de status.
     */
    public static function getStatusLabels(): array
    {
        return [
            self::STATUS_PENDING   => __('Pendente', 'monthlyclosing'),
            self::STATUS_ACTIVE    => __('Ativa', 'monthlyclosing'),
            self::STATUS_FINISHED  => __('Encerrada', 'monthlyclosing'),
            self::STATUS_CANCELLED => __('Cancelada', 'monthlyclosing'),
        ];
    }

    /**
     * Badge HTML (padrão Tabler/GLPI) para o status informado.
     */
    public static function getStatusBadge(int $status): string
    {
        $styles = [
            self::STATUS_PENDING   => ['bg-blue-lt', 'ti ti-clock'],
            self::STATUS_ACTIVE    => ['bg-orange-lt', 'ti ti-lock'],
            self::STATUS_FINISHED  => ['bg-green-lt', 'ti ti-circle-check'],
            self::STATUS_CANCELLED => ['bg-secondary-lt', 'ti ti-circle-x'],
        ];
        [$class, $icon] = $styles[$status] ?? ['bg-secondary-lt', 'ti ti-help'];
        $label = static::getStatusLabels()[$status] ?? (string) $status;

        return sprintf(
            '<span class="badge %s"><i class="%s me-1"></i>%s</span>',
            $class,
            $icon,
            htmlescape($label)
        );
    }

    // ------------------------------------------------------------------
    // Cron
    // ------------------------------------------------------------------

    /**
     * Informações da ação automática para o painel do GLPI.
     */
    public static function cronInfo(string $name): array
    {
        if ($name === 'MonthlyClosing') {
            return [
                'description' => __('Ativa e desativa janelas de fechamento mensal, ajustando permissões e autoclose das entidades.', 'monthlyclosing'),
            ];
        }
        return [];
    }

    /**
     * Ação automática principal.
     * Executa a cada minuto; ativa janelas no horário e desativa quando expiram.
     */
    public static function cronMonthlyClosing(CronTask $task): int
    {
        global $DB;

        $now    = date('Y-m-d H:i:s');
        $volume = 0;

        // 1. Janelas pendentes cuja data de início já chegou → ativar
        $toActivate = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => [
                'status'     => self::STATUS_PENDING,
                ['date_start' => ['<=', $now]],
                ['date_end'   => ['>', $now]],
            ],
        ]);

        foreach ($toActivate as $row) {
            $window = new self();
            $window->getFromDB($row['id']);

            RightsManager::apply($window);

            $window->update([
                'id'              => $row['id'],
                'status'          => self::STATUS_ACTIVE,
                'date_activation' => $now,
            ]);

            $task->log(sprintf('Janela #%d ativada.', $row['id']));
            $volume++;
        }

        // 2. Janelas ativas cujo fim já chegou → desativar
        $toDeactivate = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => [
                'status'  => self::STATUS_ACTIVE,
                ['date_end' => ['<=', $now]],
            ],
        ]);

        foreach ($toDeactivate as $row) {
            $window = new self();
            $window->getFromDB($row['id']);

            RightsManager::restore($window);

            $window->update([
                'id'                 => $row['id'],
                'status'             => self::STATUS_FINISHED,
                'date_deactivation'  => $now,
            ]);

            $task->log(sprintf('Janela #%d encerrada.', $row['id']));
            $volume++;
        }

        $task->addVolume($volume);
        return $volume > 0 ? 1 : 0;
    }

    // ------------------------------------------------------------------
    // CRUD / formulário
    // ------------------------------------------------------------------

    public function rawSearchOptions(): array
    {
        $tab = parent::rawSearchOptions();

        $tab[] = [
            'id'            => '2',
            'table'         => static::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'       => '11',
            'table'    => static::getTable(),
            'field'    => 'date_start',
            'name'     => __('Início', 'monthlyclosing'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => '12',
            'table'    => static::getTable(),
            'field'    => 'date_end',
            'name'     => __('Fim', 'monthlyclosing'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'            => '13',
            'table'         => static::getTable(),
            'field'         => 'status',
            'name'          => __('Status', 'monthlyclosing'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals'],
        ];

        $tab[] = [
            'id'       => '14',
            'table'    => static::getTable(),
            'field'    => 'date_activation',
            'name'     => __('Ativada em', 'monthlyclosing'),
            'datatype' => 'datetime',
        ];

        $tab[] = [
            'id'       => '15',
            'table'    => static::getTable(),
            'field'    => 'date_deactivation',
            'name'     => __('Desativada em', 'monthlyclosing'),
            'datatype' => 'datetime',
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            return static::getStatusBadge((int) $values[$field]);
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        if ($field === 'status') {
            $options['display'] = false;
            $options['value']   = $values[$field];
            return \Dropdown::showFromArray($name, static::getStatusLabels(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    public function defineTabs($options = [])
    {
        $tabs = [];
        $this->addDefaultFormTab($tabs);
        $this->addStandardTab(\Log::class, $tabs, $options);
        return $tabs;
    }

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);

        $status = (int) ($this->fields['status'] ?? self::STATUS_PENDING);
        $labels = static::getStatusLabels();

        TemplateRenderer::getInstance()->display('@monthlyclosing/window.form.html.twig', [
            'item'              => $this,
            'params'            => $options,
            'is_locked'         => in_array($status, [self::STATUS_ACTIVE, self::STATUS_FINISHED], true),
            'status_badge'      => static::getStatusBadge($status),
            'editable_statuses' => [
                self::STATUS_PENDING   => $labels[self::STATUS_PENDING],
                self::STATUS_CANCELLED => $labels[self::STATUS_CANCELLED],
            ],
        ]);

        return true;
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['date_start']) || empty($input['date_end'])) {
            Session::addMessageAfterRedirect(__('Informe as datas de início e fim.', 'monthlyclosing'), true, ERROR);
            return false;
        }

        if (!static::validateDates($input['date_start'], $input['date_end'])) {
            return false;
        }

        // Status ativa/encerrada só pode ser definido pelo cron
        if (!in_array((int) ($input['status'] ?? self::STATUS_PENDING), [self::STATUS_PENDING, self::STATUS_CANCELLED], true)) {
            $input['status'] = self::STATUS_PENDING;
        }

        return $input;
    }

    private static function validateDates(string $start, string $end): bool
    {
        if (strtotime($start) >= strtotime($end)) {
            Session::addMessageAfterRedirect(__('A data de início deve ser anterior ao fim.', 'monthlyclosing'), true, ERROR);
            return false;
        }
        return true;
    }

    /**
     * Ao excluir uma janela ativa, restaura o autoclose das entidades.
     */
    public function cleanDBonPurge()
    {
        if ((int) ($this->fields['status'] ?? 0) === self::STATUS_ACTIVE) {
            RightsManager::restore($this);
        }
    }

    public function prepareInputForUpdate($input)
    {
        // Bloqueia alteração de datas em janelas ativas ou encerradas
        $lockedStatuses = [self::STATUS_ACTIVE, self::STATUS_FINISHED];
        if (in_array((int) ($this->fields['status'] ?? 0), $lockedStatuses)) {
            unset($input['date_start'], $input['date_end']);
        } elseif (isset($input['date_start']) || isset($input['date_end'])) {
            $start = $input['date_start'] ?? $this->fields['date_start'];
            $end   = $input['date_end'] ?? $this->fields['date_end'];
            if (empty($start) || empty($end)) {
                Session::addMessageAfterRedirect(__('Informe as datas de início e fim.', 'monthlyclosing'), true, ERROR);
                return false;
            }
            if (!static::validateDates($start, $end)) {
                return false;
            }
        }

        // Pelo formulário só é possível alternar entre pendente e cancelada;
        // ativa/encerrada são definidas pelo cron (que não envia _from_form)
        if (isset($input['_from_form'], $input['status'])
            && !in_array((int) $input['status'], [self::STATUS_PENDING, self::STATUS_CANCELLED], true)) {
            unset($input['status']);
        }

        return $input;
    }
}
