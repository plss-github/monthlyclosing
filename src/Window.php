<?php

namespace GlpiPlugin\Monthlyclosing;

use CommonDBTM;
use CronTask;
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
        return 'fas fa-calendar-times';
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

    public function showForm($ID, array $options = []): bool
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        $isActive   = in_array($this->fields['status'] ?? self::STATUS_PENDING, [self::STATUS_ACTIVE, self::STATUS_FINISHED]);
        $readonly   = $isActive ? 'readonly' : '';

        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('Nome', 'monthlyclosing') . '</td>';
        echo '<td><input type="text" name="name" value="' . htmlescape($this->fields['name'] ?? '') . '" class="form-control" ' . $readonly . '></td>';
        echo '<td>' . __('Status', 'monthlyclosing') . '</td>';
        echo '<td>';
        if ($isActive) {
            echo static::getStatusLabels()[$this->fields['status']];
        } else {
            \Dropdown::showFromArray('status', [
                self::STATUS_PENDING   => static::getStatusLabels()[self::STATUS_PENDING],
                self::STATUS_CANCELLED => static::getStatusLabels()[self::STATUS_CANCELLED],
            ], ['value' => $this->fields['status'] ?? self::STATUS_PENDING]);
        }
        echo '</td>';
        echo '</tr>';

        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('Início da janela', 'monthlyclosing') . '</td>';
        echo '<td>';
        \Html::showDateTimeField('date_start', [
            'value'    => $this->fields['date_start'] ?? '',
            'readonly' => $isActive,
        ]);
        echo '</td>';
        echo '<td>' . __('Fim da janela', 'monthlyclosing') . '</td>';
        echo '<td>';
        \Html::showDateTimeField('date_end', [
            'value'    => $this->fields['date_end'] ?? '',
            'readonly' => $isActive,
        ]);
        echo '</td>';
        echo '</tr>';

        echo '<tr class="tab_bg_1">';
        echo '<td>' . __('Comentário', 'monthlyclosing') . '</td>';
        echo '<td colspan="3"><textarea name="comment" rows="3" class="form-control">';
        echo htmlescape($this->fields['comment'] ?? '');
        echo '</textarea></td>';
        echo '</tr>';

        if ($ID > 0) {
            echo '<tr class="tab_bg_2">';
            echo '<td>' . __('Ativada em', 'monthlyclosing') . '</td>';
            echo '<td>' . ($this->fields['date_activation'] ?? '—') . '</td>';
            echo '<td>' . __('Desativada em', 'monthlyclosing') . '</td>';
            echo '<td>' . ($this->fields['date_deactivation'] ?? '—') . '</td>';
            echo '</tr>';
        }

        $this->showFormButtons($options);
        return true;
    }

    public function prepareInputForAdd($input)
    {
        if (empty($input['date_start']) || empty($input['date_end'])) {
            Session::addMessageAfterRedirect(__('Informe as datas de início e fim.', 'monthlyclosing'), true, ERROR);
            return false;
        }

        if ($input['date_start'] >= $input['date_end']) {
            Session::addMessageAfterRedirect(__('A data de início deve ser anterior ao fim.', 'monthlyclosing'), true, ERROR);
            return false;
        }

        return $input;
    }

    public function prepareInputForUpdate($input)
    {
        // Bloqueia alteração de datas em janelas ativas ou encerradas
        $lockedStatuses = [self::STATUS_ACTIVE, self::STATUS_FINISHED];
        if (in_array((int) ($this->fields['status'] ?? 0), $lockedStatuses)) {
            unset($input['date_start'], $input['date_end']);
        }
        return $input;
    }
}
