<?php
/**
 * app/views/auth/forgot-password.php
 * Tela de solicitação de redefinição de senha (split-screen, sem layout principal).
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esqueci minha senha · <?= e(APP_NAME) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>

<div class="tc-login-wrapper">
    <div class="tc-login-brand d-none d-lg-flex">
        <div style="position: relative; z-index: 1;">
            <div class="d-flex align-items-center gap-3 mb-5">
                <div class="tc-logo-badge" style="width:52px;height:52px;font-size:1.3rem;">TC</div>
                <div>
                    <h4 class="mb-0 fw-bold">Titanium CRM</h4>
                    <small class="opacity-75">Titanium Consultoria</small>
                </div>
            </div>
            <h2 class="fw-bold mb-3" style="max-width: 480px;">Redefinição de senha segura, direto no seu e-mail.</h2>
            <p class="opacity-75" style="max-width: 460px;">
                Informe o e-mail da sua conta e enviaremos um link para você criar uma nova senha.
            </p>
        </div>
    </div>

    <div class="tc-login-form">
        <div class="tc-login-card">
            <div class="text-center mb-4 d-lg-none">
                <div class="tc-logo-badge mx-auto mb-2" style="width:52px;height:52px;font-size:1.3rem;">TC</div>
                <h5 class="fw-bold">Titanium CRM</h5>
            </div>

            <div class="tc-card">
                <div class="tc-card-body p-4">
                    <h4 class="fw-bold mb-1">Esqueci minha senha</h4>
                    <p class="text-muted mb-4" style="font-size: 0.88rem;">
                        Informe seu e-mail de acesso. Enviaremos um link de redefinição válido por 60 minutos.
                    </p>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger py-2" style="font-size: 0.85rem;">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= e($error) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($success)): ?>
                        <div class="alert alert-success py-2" style="font-size: 0.85rem;">
                            <i class="fa-solid fa-circle-check me-1"></i> <?= e($success) ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= e(url('esqueci-senha')) ?>">
                        <?= Csrf::field() ?>

                        <div class="mb-3">
                            <label class="form-label">E-mail</label>
                            <div class="input-group">
                                <span class="input-group-text bg-transparent"><i class="fa-solid fa-envelope"></i></span>
                                <input type="email" name="email" class="form-control" placeholder="voce@titanium.com.br" required autofocus>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-tc-primary w-100 py-2 mt-2">
                            <i class="fa-solid fa-paper-plane me-1"></i> Enviar link de redefinição
                        </button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="<?= e(url('login')) ?>" class="text-decoration-none" style="font-size: 0.85rem;">
                            <i class="fa-solid fa-arrow-left me-1"></i> Voltar para o login
                        </a>
                    </div>
                </div>
            </div>

            <p class="text-center text-muted mt-4" style="font-size: 0.75rem;">
                &copy; <?= date('Y') ?> <?= e(COMPANY_NAME) ?>. Todos os direitos reservados.
            </p>
        </div>
    </div>
</div>

<script>
    (function () {
        var theme = localStorage.getItem('tc-theme') || 'light';
        if (theme === 'dark') {
            document.body.classList.add('dark-mode');
        }
    })();
</script>
</body>
</html>
