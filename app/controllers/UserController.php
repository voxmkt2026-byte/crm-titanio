<?php
/**
 * app/controllers/UserController.php
 * CRUD completo de usuários (nome, email, senha com hash, role, ativo/inativo).
 * Restrito exclusivamente ao papel "admin" (ver requireAdmin()).
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/ChatDepartment.php';
require_once APP_PATH . '/models/ChatRoom.php';
require_once APP_PATH . '/models/Permission.php';

class UserController extends Controller
{
    private User $model;

    public function __construct()
    {
        $this->model = new User();
    }

    /** Middleware simples: só admin acessa a tela de usuários */
    private function requireAdmin(): void
    {
        $this->requireLogin();
        if (!Auth::hasRole(['admin']) || !Auth::can('users.manage')) {
            flash('error', 'Acesso restrito a administradores.');
            $this->redirect('dashboard');
        }
    }

    public function index(): void
    {
        $this->requireAdmin();

        $filters = [
            'search'        => trim((string) $this->input('search', '')),
            'role'          => trim((string) $this->input('role', '')),
            'department_id' => trim((string) $this->input('department_id', '')),
            'active'        => trim((string) $this->input('active', '')),
        ];
        $page = (int) $this->input('page', 1);
        $result = $this->model->paginate($filters, $page, 20);

        $this->view('users/index', [
            'pageTitle'   => 'Usuários',
            'users'       => $result['items'],
            'total'       => $result['total'],
            'page'        => $result['page'],
            'totalPages'  => $result['totalPages'],
            'filters'     => $filters,
            'departments' => $this->departments(),
        ]);
    }

    public function create(): void
    {
        $this->requireAdmin();

        $this->view('users/form', [
            'pageTitle'      => 'Novo Usuário',
            'user'           => null,
            'formAction'     => url('usuarios/store'),
            'departments'    => $this->departments(),
            'permissionGroups' => $this->permissionGroups(),
            'overrides'      => ['allow' => [], 'deny' => []],
        ]);
    }

    public function store(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        [$data, $error] = $this->validate($_POST, null);
        if ($error) {
            flash('error', $error);
            $this->redirect('usuarios/create');
            return;
        }

        $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        $userId = $this->model->create($data);

        log_activity('usuario_criado', 'Usuário "' . $data['name'] . '" (#' . $userId . ') criado com papel ' . $data['role'] . '.');
        $this->syncChatMembership($userId, $data['department_id']);
        $this->savePermissionOverrides($userId);

        flash('success', 'Usuário criado com sucesso.');
        $this->redirect('usuarios');
    }

    public function edit(string $id): void
    {
        $this->requireAdmin();

        $user = $this->model->find((int) $id);
        if (!$user) {
            flash('error', 'Usuário não encontrado.');
            $this->redirect('usuarios');
            return;
        }

        $this->view('users/form', [
            'pageTitle'      => 'Editar Usuário',
            'user'           => $user,
            'formAction'     => url('usuarios/' . $user['id'] . '/update'),
            'departments'    => $this->departments(),
            'permissionGroups' => $this->permissionGroups(),
            'overrides'      => $this->userOverrides((int) $user['id']),
        ]);
    }

    public function update(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $userId = (int) $id;
        $existing = $this->model->find($userId);
        if (!$existing) {
            flash('error', 'Usuário não encontrado.');
            $this->redirect('usuarios');
            return;
        }

        [$data, $error] = $this->validate($_POST, $userId, false);
        if ($error) {
            flash('error', $error);
            $this->redirect('usuarios/' . $userId . '/edit');
            return;
        }

        if (!empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        $this->model->updateUser($userId, $data);
        log_activity('usuario_atualizado', 'Usuário "' . $data['name'] . '" (#' . $userId . ') atualizado.');
        $this->syncChatMembership($userId, $data['department_id']);
        $this->savePermissionOverrides($userId);

        flash('success', 'Usuário atualizado com sucesso.');
        $this->redirect('usuarios');
    }

    /** Alterna ativo/inativo (alternativa rápida à exclusão permanente). */
    public function toggleActive(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $userId = (int) $id;
        if ($userId === Auth::id()) {
            flash('error', 'Você não pode desativar seu próprio usuário.');
            $this->redirect('usuarios');
            return;
        }

        $user = $this->model->find($userId);
        if (!$user) {
            flash('error', 'Usuário não encontrado.');
            $this->redirect('usuarios');
            return;
        }

        $this->model->toggleActive($userId);
        $nowActive = (int) $user['active'] === 1 ? 0 : 1;
        log_activity('usuario_status_alterado', 'Usuário "' . $user['name'] . '" (#' . $userId . ') marcado como ' . ($nowActive ? 'ativo' : 'inativo') . '.');

        flash('success', 'Usuário ' . ($nowActive ? 'ativado' : 'desativado') . ' com sucesso.');
        $this->redirect('usuarios');
    }

    public function destroy(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $userId = (int) $id;
        if ($userId === Auth::id()) {
            flash('error', 'Você não pode excluir seu próprio usuário.');
            $this->redirect('usuarios');
            return;
        }

        $this->model->delete($userId);
        log_activity('usuario_excluido', 'Usuário #' . $userId . ' excluído.');

        flash('success', 'Usuário excluído com sucesso.');
        $this->redirect('usuarios');
    }

    /**
     * Valida os dados do formulário de usuário.
     * @return array{0: array, 1: ?string} [dados_validados, mensagem_de_erro|null]
     */
    private function validate(array $input, ?int $excludeId, bool $passwordRequired = true): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = trim((string) ($input['email'] ?? ''));
        $role = (string) ($input['role'] ?? 'consultor');
        $commercialFunction = (string) ($input['commercial_function'] ?? 'vendedor');
        $password = (string) ($input['password'] ?? '');
        $active = !empty($input['active']) ? 1 : 0;
        $departmentId = !empty($input['department_id']) ? (int) $input['department_id'] : null;

        if ($name === '' || $email === '') {
            return [[], 'Nome e e-mail são obrigatórios.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return [[], 'Informe um e-mail válido.'];
        }
        if (!in_array($role, ['admin', 'supervisor', 'consultor'], true)) {
            return [[], 'Papel inválido.'];
        }
        if (!in_array($commercialFunction, ['sdr', 'vendedor', 'supervisor'], true)) {
            return [[], 'Função comercial inválida.'];
        }
        if ($this->model->emailExists($email, $excludeId)) {
            return [[], 'Já existe um usuário cadastrado com este e-mail.'];
        }
        if ($passwordRequired && strlen($password) < 6) {
            return [[], 'A senha deve ter pelo menos 6 caracteres.'];
        }
        if ($password !== '' && strlen($password) < 6) {
            return [[], 'A senha deve ter pelo menos 6 caracteres.'];
        }

        return [[
            'name'          => $name,
            'email'         => $email,
            'role'          => $role,
            'commercial_function' => $commercialFunction,
            'password'      => $password,
            'active'        => $active,
            'department_id' => $departmentId,
        ], null];
    }

    /** Catálogo de departamentos para o <select> do formulário (chat interno). */
    private function departments(): array
    {
        try {
            return (new ChatDepartment())->allActive();
        } catch (Throwable $e) {
            error_log('UserController::departments - falha ao carregar departamentos (rode database/sql/migration_chat.sql): ' . $e->getMessage());
            return [];
        }
    }

    /** Sincroniza a entrada do usuário na sala de departamento do chat interno. */
    private function syncChatMembership(int $userId, ?int $departmentId): void
    {
        try {
            (new ChatRoom())->syncUserDepartmentMembership($userId, $departmentId);
        } catch (Throwable $e) {
            error_log('UserController::syncChatMembership - falha ao sincronizar chat (rode database/sql/migration_chat.sql): ' . $e->getMessage());
        }
    }

    /** Overrides de permissão já salvos para este usuário. Falha graciosamente se a tabela ainda não existir. */
    private function userOverrides(int $userId): array
    {
        try {
            return (new Permission())->overridesForUser($userId);
        } catch (Throwable $e) {
            error_log('UserController::userOverrides - falha ao carregar overrides (rode database/sql/migration_user_permissions.sql): ' . $e->getMessage());
            return ['allow' => [], 'deny' => []];
        }
    }

    /** Catálogo de permissões agrupado por prefixo (ex: "leads.*", "reports.*") para a tela de edição de usuário. */
    private function permissionGroups(): array
    {
        try {
            $all = (new Permission())->allPermissions();
        } catch (Throwable $e) {
            error_log('UserController::permissionGroups - falha ao carregar permissões (rode database/sql/migration_fase3.sql): ' . $e->getMessage());
            return [];
        }

        $groups = [];
        foreach ($all as $permission) {
            $prefix = explode('.', $permission['slug'])[0];
            $groups[$prefix][] = $permission;
        }
        ksort($groups);
        return $groups;
    }

    /**
     * Salva os overrides de permissão por pessoa (allow/deny) vindos do formulário
     * de usuário, ver database/sql/migration_user_permissions.sql. Falha graciosamente
     * (não bloqueia salvar o usuário) se a tabela ainda não existir.
     */
    private function savePermissionOverrides(int $userId): void
    {
        // Um <select>/radio por permissão: 'default' (segue o papel), 'allow' ou 'deny'.
        $states = (array) $this->input('perm_state', []);
        $allow = array_keys(array_filter($states, fn($v) => $v === 'allow'));
        $deny = array_keys(array_filter($states, fn($v) => $v === 'deny'));
        try {
            (new Permission())->setOverridesForUser($userId, $allow, $deny);
        } catch (Throwable $e) {
            error_log('UserController::savePermissionOverrides - falha ao salvar overrides (rode database/sql/migration_user_permissions.sql): ' . $e->getMessage());
        }
    }
}
