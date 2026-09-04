<?php
/**
 * app/controllers/LossReasonController.php
 * CRUD simples de motivos de perda + ranking de uso (Chart.js).
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/LossReason.php';

class LossReasonController extends Controller
{
    private LossReason $model;

    public function __construct()
    {
        $this->model = new LossReason();
    }

    public function index(): void
    {
        $this->requireLogin();

        // O ranking completo (todos os motivos) alimenta o gráfico Chart.js;
        // a tabela abaixo, que pode crescer bastante, é paginada em memória
        // sobre esse mesmo resultado (Fase 5) para não duplicar a consulta.
        $allRanking = $this->model->rankingUsage();
        $total = count($allRanking);
        $perPage = 20;
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min((int) $this->input('page', 1), $totalPages));
        $ranking = array_slice($allRanking, ($page - 1) * $perPage, $perPage);

        $this->view('loss-reasons/index', [
            'pageTitle'     => 'Motivos de Perda',
            'ranking'       => $ranking,
            'chartRanking'  => $allRanking,
            'total'         => $total,
            'page'          => $page,
            'totalPages'    => $totalPages,
        ]);
    }

    public function store(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $name = trim((string) $this->input('name', ''));
        if ($name === '') {
            flash('error', 'Informe o nome do motivo de perda.');
            $this->redirect('motivos-perda');
            return;
        }

        $this->model->create($name, true);
        log_activity('motivo_perda_criado', 'Motivo de perda "' . $name . '" criado.');

        flash('success', 'Motivo de perda criado com sucesso.');
        $this->redirect('motivos-perda');
    }

    public function update(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $name = trim((string) $this->input('name', ''));
        $active = $this->input('active') ? true : false;

        if ($name === '') {
            flash('error', 'Informe o nome do motivo de perda.');
            $this->redirect('motivos-perda');
            return;
        }

        $this->model->updateReason((int) $id, $name, $active);
        log_activity('motivo_perda_atualizado', 'Motivo de perda #' . $id . ' atualizado para "' . $name . '".');

        flash('success', 'Motivo de perda atualizado com sucesso.');
        $this->redirect('motivos-perda');
    }

    public function destroy(string $id): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $this->model->delete((int) $id);
        log_activity('motivo_perda_excluido', 'Motivo de perda #' . $id . ' excluído.');

        flash('success', 'Motivo de perda excluído com sucesso.');
        $this->redirect('motivos-perda');
    }
}
