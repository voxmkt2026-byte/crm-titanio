<?php
/**
 * app/controllers/GoalController.php
 * Metas mensais por função comercial: SDR acompanha leads trabalhados,
 * vendedor acompanha fechamentos/valor e supervisor o valor da equipe.
 * Consumido também pela tela "Meu Dia" (TodayController).
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/UserGoal.php';
require_once APP_PATH . '/models/User.php';

class GoalController extends Controller
{
    private UserGoal $model;

    public function __construct()
    {
        $this->model = new UserGoal();
    }

    private function requireManage(): void
    {
        $this->requireLogin();
        if (!Auth::hasRole(['admin', 'supervisor']) || !Auth::can('goals.manage')) {
            flash('error', 'Acesso restrito a administradores/supervisores.');
            $this->redirect('dashboard');
            exit;
        }
    }

    public function index(): void
    {
        $this->requireManage();

        $year = (int) $this->input('year', date('Y'));
        $month = (int) $this->input('month', date('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }

        $goals = $this->model->allForMonth($year, $month);

        $this->view('goals/index', [
            'pageTitle' => 'Metas por Função',
            'goals'     => $goals,
            'year'      => $year,
            'month'     => $month,
        ]);
    }

    public function update(): void
    {
        $this->requireManage();
        Csrf::verifyRequest();

        $year = (int) $this->input('year', date('Y'));
        $month = (int) $this->input('month', date('n'));
        $userIds = (array) $this->input('user_id', []);
        $targets = (array) $this->input('target_closed_deals', []);
        $targetsLeads = (array) $this->input('target_new_leads', []);
        $targetsSalesValue = (array) $this->input('target_sales_value', []);

        if ($year < 2000 || $month < 1 || $month > 12) {
            flash('error', 'Mês/ano inválidos.');
            $this->redirect('metas');
            return;
        }

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0) {
                continue;
            }
            $targetClosed = max(0, (int) ($targets[$userId] ?? 0));
            $rawLeads = $targetsLeads[$userId] ?? '';
            $targetLeads = ($rawLeads === '' || $rawLeads === null) ? null : max(0, (int) $rawLeads);
            $targetSalesValue = $this->normalizeMoney($targetsSalesValue[$userId] ?? null);

            $this->model->upsert($userId, $year, $month, $targetClosed, $targetLeads, $targetSalesValue);
        }

        log_activity('metas_atualizadas', 'Metas mensais por função atualizadas para ' . $month . '/' . $year . '.');

        flash('success', 'Metas salvas com sucesso.');
        $this->redirect('metas?year=' . $year . '&month=' . $month);
    }

    private function normalizeMoney($value): ?float
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $raw = str_replace(['R$', ' '], '', $raw);
        if (str_contains($raw, ',')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        }
        return is_numeric($raw) ? max(0, (float) $raw) : null;
    }
}
