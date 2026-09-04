<?php
/**
 * app/controllers/AuthController.php
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/User.php';

class AuthController extends Controller
{
    public function login(): void
    {
        // Se já estiver logado, vai direto pro dashboard
        if (Auth::check()) {
            $this->redirect('dashboard');
            return;
        }

        $this->view('auth/login', [
            'pageTitle' => 'Login',
            'error'     => flash('error'),
        ], null);
    }

    public function authenticate(): void
    {
        Csrf::verifyRequest();

        $email = trim($this->input('email', ''));
        $password = (string) $this->input('password', '');

        if ($email === '' || $password === '') {
            flash('error', 'Informe e-mail e senha.');
            $this->redirect('login');
            return;
        }

        if (Auth::attempt($email, $password)) {
            log_activity('login', 'Login realizado com sucesso (' . $email . ').');
            $this->syncChatMembership();
            $this->redirect('dashboard');
            return;
        }

        log_activity('login_falhou', 'Tentativa de login falhou para o e-mail ' . $email . '.');
        flash('error', 'E-mail ou senha inválidos.');
        $this->redirect('login');
    }

    public function logout(): void
    {
        log_activity('logout', 'Logout realizado.');
        Auth::logout();
        $this->redirect('login');
    }

    // ---- "Esqueci minha senha" (Fase 3) ----

    public function forgotPassword(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
            return;
        }

        $this->view('auth/forgot-password', [
            'pageTitle' => 'Esqueci minha senha',
            'error'     => flash('error'),
            'success'   => flash('success'),
        ], null);
    }

    /**
     * Gera o token de redefinição e envia por e-mail via SMTP (app/core/Mailer.php).
     * Por segurança, sempre exibe a mesma mensagem de sucesso, exista ou não
     * o e-mail informado (evita enumeração de usuários cadastrados).
     */
    public function sendResetLink(): void
    {
        Csrf::verifyRequest();

        $email = trim((string) $this->input('email', ''));
        $genericMessage = 'Se este e-mail estiver cadastrado, enviaremos um link de redefinição de senha em instantes.';

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Informe um e-mail válido.');
            $this->redirect('esqueci-senha');
            return;
        }

        require_once APP_PATH . '/models/User.php';
        $userModel = new User();
        $user = $userModel->findByEmail($email);

        if ($user && (int) $user['active'] === 1) {
            $token = bin2hex(random_bytes(32));
            $userModel->setResetToken((int) $user['id'], $token, 60);

            $link = url('redefinir-senha?token=' . $token);
            $html = '<p>Olá, ' . e($user['name']) . '!</p>'
                . '<p>Recebemos uma solicitação de redefinição de senha para sua conta no ' . e(APP_NAME) . '.</p>'
                . '<p><a href="' . e($link) . '">Clique aqui para criar uma nova senha</a> (o link expira em 60 minutos).</p>'
                . '<p>Se você não solicitou isso, apenas ignore este e-mail.</p>';

            try {
                require_once APP_PATH . '/core/Mailer.php';
                $mailer = Mailer::fromSettings();
                $mailer->send($email, 'Redefinição de senha - ' . APP_NAME, $html, $user['name']);
            } catch (Throwable $e) {
                // Nunca quebra o fluxo do usuário por falha de SMTP
                error_log('AuthController::sendResetLink - falha ao enviar e-mail: ' . $e->getMessage());
            }

            log_activity('senha_reset_solicitado', 'Redefinição de senha solicitada para ' . $email . '.');
        }

        flash('success', $genericMessage);
        $this->redirect('esqueci-senha');
    }

    public function resetPasswordForm(): void
    {
        $token = (string) $this->input('token', '');

        require_once APP_PATH . '/models/User.php';
        $userModel = new User();
        $user = $token !== '' ? $userModel->findByResetToken($token) : null;

        $this->view('auth/reset-password', [
            'pageTitle' => 'Redefinir senha',
            'token'     => $token,
            'valid'     => (bool) $user,
            'error'     => flash('error'),
        ], null);
    }

    public function resetPassword(): void
    {
        Csrf::verifyRequest();

        $token = (string) $this->input('token', '');
        $password = (string) $this->input('password', '');
        $passwordConfirm = (string) $this->input('password_confirm', '');

        require_once APP_PATH . '/models/User.php';
        $userModel = new User();
        $user = $token !== '' ? $userModel->findByResetToken($token) : null;

        if (!$user) {
            flash('error', 'Link de redefinição inválido ou expirado. Solicite um novo.');
            $this->redirect('esqueci-senha');
            return;
        }

        if (strlen($password) < 6 || $password !== $passwordConfirm) {
            flash('error', 'As senhas informadas não coincidem ou têm menos de 6 caracteres.');
            $this->redirect('redefinir-senha?token=' . urlencode($token));
            return;
        }

        $userModel->updatePassword((int) $user['id'], password_hash($password, PASSWORD_BCRYPT));
        $userModel->clearResetToken((int) $user['id']);

        log_activity('senha_redefinida', 'Senha redefinida com sucesso para ' . $user['email'] . '.');

        flash('success', 'Senha redefinida com sucesso. Faça login com a nova senha.');
        $this->redirect('login');
    }

    /**
     * Garante que o usuário recém-logado está nas salas certas do chat
     * interno (Geral + a do próprio departamento). Ver
     * app/models/ChatRoom.php::syncUserDepartmentMembership(). Protegido
     * por try/catch para nunca travar o login se a migration_chat.sql
     * ainda não tiver sido executada.
     */
    private function syncChatMembership(): void
    {
        try {
            require_once APP_PATH . '/models/ChatRoom.php';
            $userModel = new User();
            $user = $userModel->findByEmail((string) ($_POST['email'] ?? ''));
            if ($user) {
                (new ChatRoom())->syncUserDepartmentMembership((int) $user['id'], isset($user['department_id']) ? ((int) $user['department_id'] ?: null) : null);
            }
        } catch (Throwable $e) {
            error_log('AuthController::syncChatMembership - falha ao sincronizar chat (rode database/sql/migration_chat.sql): ' . $e->getMessage());
        }
    }
}
