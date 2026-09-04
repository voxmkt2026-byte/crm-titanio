<?php
/**
 * app/controllers/ProfileController.php
 * Perfil do usuário logado: editar próprio nome/avatar/senha.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Form.php';

class ProfileController extends Controller
{
    private User $model;

    public function __construct()
    {
        $this->model = new User();
    }

    public function index(): void
    {
        $this->requireLogin();

        $user = $this->model->find((int) Auth::id());

        // Link pessoal de captação + QR Code (ver forms/public.php): reaproveita
        // o Construtor de Formulários — qualquer formulário ativo funciona como
        // base, só adicionamos ?consultor={id} para direcionar o lead direto
        // para este usuário. Falha graciosamente se a tabela ainda não existir.
        $captureForms = [];
        try {
            $captureForms = array_filter((new Form())->allWithStats(), fn($f) => (int) $f['active'] === 1);
        } catch (Throwable $e) {
            $captureForms = [];
        }

        $this->view('profile/index', [
            'pageTitle'     => 'Meu Perfil',
            'user'          => $user,
            'captureForms'  => array_values($captureForms),
        ]);
    }

    public function update(): void
    {
        $this->requireLogin();
        Csrf::verifyRequest();

        $userId = (int) Auth::id();
        $existing = $this->model->find($userId);
        if (!$existing) {
            flash('error', 'Usuário não encontrado.');
            $this->redirect('perfil');
            return;
        }

        $name = trim((string) $this->input('name', ''));
        $email = trim((string) $this->input('email', ''));
        $password = (string) $this->input('password', '');
        $passwordConfirm = (string) $this->input('password_confirmation', '');

        if ($name === '' || $email === '') {
            flash('error', 'Nome e e-mail são obrigatórios.');
            $this->redirect('perfil');
            return;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Informe um e-mail válido.');
            $this->redirect('perfil');
            return;
        }
        if ($this->model->emailExists($email, $userId)) {
            flash('error', 'Já existe outro usuário cadastrado com este e-mail.');
            $this->redirect('perfil');
            return;
        }
        if ($password !== '' && $password !== $passwordConfirm) {
            flash('error', 'A confirmação de senha não confere.');
            $this->redirect('perfil');
            return;
        }
        if ($password !== '' && strlen($password) < 6) {
            flash('error', 'A nova senha deve ter pelo menos 6 caracteres.');
            $this->redirect('perfil');
            return;
        }

        $avatar = $this->handleAvatarUpload($existing['avatar'] ?? null);

        $data = [
            'name'   => $name,
            'email'  => $email,
            'role'   => $existing['role'],
            'active' => (int) $existing['active'],
            'avatar' => $avatar,
        ];
        if ($password !== '') {
            $data['password'] = password_hash($password, PASSWORD_BCRYPT);
        }

        $this->model->updateUser($userId, $data);

        // Atualiza a sessão com os novos dados exibidos no topbar
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_avatar'] = $avatar ?? ($existing['avatar'] ?? null);

        log_activity('perfil_atualizado', 'Usuário atualizou seus próprios dados de perfil.');

        flash('success', 'Perfil atualizado com sucesso.');
        $this->redirect('perfil');
    }

    /**
     * Fallback de publicação para hospedagens em que /public/uploads não é
     * exposto como diretório estático. Também recupera URLs já gravadas no
     * banco: se o Apache não encontrar o arquivo, a regra de rewrite chega
     * aqui e o CRM o entrega com MIME seguro.
     */
    public function avatar(string $filename): void
    {
        if (!preg_match('/^avatar_[0-9]+_[0-9]+\.(?:jpg|jpeg|png|webp)$/i', $filename)) {
            $this->avatarNotFound();
        }

        $directory = realpath(UPLOADS_PATH . '/avatars');
        $path = $directory ? realpath($directory . DIRECTORY_SEPARATOR . $filename) : false;
        if (!$directory || !$path || !str_starts_with($path, $directory . DIRECTORY_SEPARATOR) || !is_file($path)) {
            $this->avatarNotFound();
        }

        $mime = mime_content_type($path);
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed, true)) {
            $this->avatarNotFound();
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /** Upload simples de avatar para public/uploads/avatars/ (sem libs externas) */
    private function handleAvatarUpload(?string $current): ?string
    {
        if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            return $current;
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = mime_content_type($_FILES['avatar']['tmp_name']);

        if (!isset($allowed[$mime]) || $_FILES['avatar']['size'] > 2 * 1024 * 1024) {
            flash('error', 'Avatar inválido (use JPG/PNG/WEBP até 2MB). As demais alterações foram salvas.');
            return $current;
        }

        $dir = UPLOADS_PATH . '/avatars';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $filename = 'avatar_' . Auth::id() . '_' . time() . '.' . $allowed[$mime];
        $destination = $dir . '/' . $filename;

        if (!move_uploaded_file($_FILES['avatar']['tmp_name'], $destination) || !is_file($destination) || filesize($destination) === 0) {
            flash('error', 'Não foi possível salvar o avatar. Verifique a permissão de escrita da pasta uploads/avatars.');
            return $current;
        }

        // Esta URL também é atendida por avatar() se a hospedagem não servir
        // public/uploads diretamente; mantém compatibilidade com os links antigos.
        return url('uploads/avatars/' . rawurlencode($filename));
    }

    private function avatarNotFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Avatar não encontrado.');
    }
}
