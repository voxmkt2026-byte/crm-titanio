<?php
/**
 * app/controllers/LeadScoreController.php
 * Tela de configuração dos critérios/pesos do Lead Score automático
 * (app/models/LeadScore.php), incluindo os pesos por origem/forma de
 * captação introduzidos na Fase 5 (ver database/sql/migration_fase5.sql).
 * Restrito ao papel "admin", mesmo padrão de SettingController/UserController.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/LeadScore.php';

class LeadScoreController extends Controller
{
    private LeadScore $model;

    public function __construct()
    {
        $this->model = new LeadScore();
    }

    private function requireAdmin(): void
    {
        $this->requireLogin();
        if (!Auth::hasRole(['admin']) || !Auth::can('settings.manage')) {
            flash('error', 'Acesso restrito a administradores.');
            $this->redirect('dashboard');
        }
    }

    public function index(): void
    {
        $this->requireAdmin();

        $this->view('settings/lead-score', [
            'pageTitle' => 'Lead Score - Critérios e Pesos',
            'groups'    => $this->model->rulesGroupedForSettings(),
        ]);
    }

    /**
     * Salva peso + ativo/inativo de todas as regras de uma vez
     * (a view envia um form único com um array "rules[id][peso|ativo]").
     */
    public function update(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $rules = $this->input('rules', []);
        if (!is_array($rules)) {
            $rules = [];
        }

        $updated = 0;
        foreach ($rules as $id => $rule) {
            $id = (int) $id;
            if ($id <= 0 || !is_array($rule)) {
                continue;
            }
            $peso = (int) ($rule['peso'] ?? 0);
            $ativo = !empty($rule['ativo']);
            if ($this->model->updateRule($id, $peso, $ativo)) {
                $updated++;
            }
        }

        log_activity('lead_score_regras_atualizadas', $updated . ' regra(s) de lead score atualizada(s).');

        flash('success', 'Critérios de Lead Score atualizados com sucesso.');
        $this->redirect('configuracoes/lead-score');
    }
}
