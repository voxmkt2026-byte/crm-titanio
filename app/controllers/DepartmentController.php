<?php
/**
 * app/controllers/DepartmentController.php
 * Gestão de departamentos (catálogo usado pelo Chat interno e pela ficha do
 * usuário — ver app/models/ChatDepartment.php e UserController::departments()).
 * Restrito a admin. Sem exclusão permanente na UI: chat_rooms.department_id é
 * ON DELETE CASCADE, então apagar um departamento apagaria a sala e o
 * histórico de mensagens dele. Usamos ativar/desativar (soft) em vez disso.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/ChatDepartment.php';

class DepartmentController extends Controller
{
    private ChatDepartment $model;

    public function __construct()
    {
        $this->model = new ChatDepartment();
    }

    private function requireAdmin(): void
    {
        $this->requireLogin();
        if (!Auth::hasRole(['admin'])) {
            flash('error', 'Acesso restrito a administradores.');
            $this->redirect('dashboard');
        }
    }

    public function index(): void
    {
        $this->requireAdmin();

        $departments = [];
        $members = [];
        try {
            $departments = $this->model->allWithUserCount();
            $members = $this->model->membersGrouped();
        } catch (Throwable $e) {
            error_log('DepartmentController::index - falha ao carregar departamentos (rode database/sql/migration_chat.sql): ' . $e->getMessage());
        }

        $this->view('departments/index', [
            'pageTitle'   => 'Departamentos',
            'departments' => $departments,
            'members'     => $members,
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) $this->input('name', ''));
        $color = trim((string) $this->input('color', '#3b82f6'));
        $icon = trim((string) $this->input('icon', 'fa-solid fa-comments'));

        if ($name === '' || mb_strlen($name) < 2) {
            flash('error', 'Informe um nome válido para o departamento (mínimo 2 caracteres).');
            $this->redirect('departamentos');
            return;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = '#3b82f6';
        }

        $slug = $this->slugify($name);
        if ($this->model->slugExists($slug)) {
            flash('error', 'Já existe um departamento com um nome muito parecido ("' . $slug . '").');
            $this->redirect('departamentos');
            return;
        }

        $id = $this->model->create($name, $slug, $color, $icon ?: 'fa-solid fa-comments');
        $this->ensureChatRoom($id);

        log_activity('departamento_criado', 'Departamento "' . $name . '" (#' . $id . ') criado.');
        flash('success', 'Departamento criado com sucesso.');
        $this->redirect('departamentos');
    }

    public function update(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $departmentId = (int) $id;
        $existing = $this->model->find($departmentId);
        if (!$existing) {
            flash('error', 'Departamento não encontrado.');
            $this->redirect('departamentos');
            return;
        }

        $name = trim((string) $this->input('name', ''));
        $color = trim((string) $this->input('color', $existing['color']));
        $icon = trim((string) $this->input('icon', $existing['icon']));

        if ($name === '' || mb_strlen($name) < 2) {
            flash('error', 'Informe um nome válido para o departamento (mínimo 2 caracteres).');
            $this->redirect('departamentos');
            return;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            $color = $existing['color'];
        }

        $this->model->update($departmentId, $name, $color, $icon ?: $existing['icon']);
        log_activity('departamento_atualizado', 'Departamento #' . $departmentId . ' atualizado para "' . $name . '".');

        flash('success', 'Departamento atualizado com sucesso.');
        $this->redirect('departamentos');
    }

    public function toggleActive(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $departmentId = (int) $id;
        $existing = $this->model->find($departmentId);
        if (!$existing) {
            flash('error', 'Departamento não encontrado.');
            $this->redirect('departamentos');
            return;
        }
        if ($existing['slug'] === 'geral') {
            flash('error', 'O departamento "Geral" não pode ser desativado — é a sala padrão de todos os usuários.');
            $this->redirect('departamentos');
            return;
        }

        $this->model->toggleActive($departmentId);
        $nowActive = (int) $existing['active'] === 1 ? 0 : 1;
        log_activity('departamento_status_alterado', 'Departamento "' . $existing['name'] . '" (#' . $departmentId . ') marcado como ' . ($nowActive ? 'ativo' : 'inativo') . '.');

        flash('success', 'Departamento ' . ($nowActive ? 'ativado' : 'desativado') . ' com sucesso.');
        $this->redirect('departamentos');
    }

    /** Slug simples (sem acento, minúsculo, hífens) a partir do nome, para casar com o padrão já usado pelos departamentos seed. */
    private function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[áàâãä]/u', 'a', $slug);
        $slug = preg_replace('/[éèêë]/u', 'e', $slug);
        $slug = preg_replace('/[íìîï]/u', 'i', $slug);
        $slug = preg_replace('/[óòôõö]/u', 'o', $slug);
        $slug = preg_replace('/[úùûü]/u', 'u', $slug);
        $slug = preg_replace('/[ç]/u', 'c', $slug);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }

    /** Garante a sala de chat oficial do departamento (mesmo padrão do seed em migration_chat.sql). */
    private function ensureChatRoom(int $departmentId): void
    {
        try {
            $db = Database::getInstance();
            $db->prepare(
                "INSERT IGNORE INTO chat_rooms (department_id, name, type, created_by, created_at) VALUES (:department_id, NULL, 'departamento', :uid, NOW())"
            )->execute([':department_id' => $departmentId, ':uid' => Auth::id()]);
        } catch (Throwable $e) {
            error_log('DepartmentController::ensureChatRoom - falha ao criar sala de chat (rode database/sql/migration_chat.sql): ' . $e->getMessage());
        }
    }
}
