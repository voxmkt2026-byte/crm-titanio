<?php
/**
 * app/controllers/LogController.php
 * Listagem paginada da tabela activity_log com filtro por usuário/ação/data.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/ActivityLog.php';
require_once APP_PATH . '/models/User.php';

class LogController extends Controller
{
    private ActivityLog $model;

    public function __construct()
    {
        $this->model = new ActivityLog();
    }

    public function index(): void
    {
        $this->requireLogin();
        if (!Auth::hasRole(['admin'])) {
            flash('error', 'Acesso restrito a administradores.');
            $this->redirect('dashboard');
            return;
        }

        $filters = [
            'user_id'   => $this->input('user_id', ''),
            'action'    => trim((string) $this->input('action', '')),
            'date_from' => $this->input('date_from', ''),
            'date_to'   => $this->input('date_to', ''),
        ];

        $page = (int) $this->input('page', 1);
        $result = $this->model->paginate($filters, $page, 30);

        $userModel = new User();

        $this->view('logs/index', [
            'pageTitle'  => 'Logs de Atividade',
            'logs'       => $result['items'],
            'total'      => $result['total'],
            'page'       => $result['page'],
            'totalPages' => $result['totalPages'],
            'filters'    => $filters,
            'users'      => $userModel->allUsers(),
        ]);
    }
}
