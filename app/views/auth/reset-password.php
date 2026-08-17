<?php
/**
 * app/views/auth/reset-password.php
 * Tela de definição de nova senha a partir do token recebido por e-mail.
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir senha · <?= e(APP_NAME) ?></title>

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
            <h2 class="fw-bold mb-3" style="max-width: 480px;">Crie uma nova senha para sua conta.</h2>
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
                    <h4 class="fw-bold mb-1">Redefinir senha</h4>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger py-2" style="font-size: 0.85rem;">
                            <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= e($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$valid): ?>
                        <div class="alert alert-warning py-2" style="font-size: 0.85rem;">
                            <i class="fa-solid fa-circle-exclamation me-1"></i>
                            Este link de redefinição é inválido ou já expirou.
                        </div>
                        <a href="<?= e(url('esqueci-senha')) ?>" class="btn btn-tc-primary w-100 py-2 mt-2">
                            Solicitar novo link
                        </a>
                    <?php else: ?>
                        <p class="text-muted mb-4" style="font-size: 0.88rem;">Escolha uma nova senha (mínimo 6 caracteres).</p>

                        <form method="POST" action="<?= e(url('redefinir-senha')) ?>">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="token" value="<?= e($token) ?>">

                            <div class="mb-3">
                                <label class="form-label">Nova senha</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent"><i class="fa-solid fa-lock"></i></span>
                                    <input type="password" name="password" class="form-control" placeholder="••••••••" minlength="6" required autofocus>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Confirmar nova senha</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-transparent"><i class="fa-solid fa-lock"></i></span>
                                    <input type="password" name="password_confirm" class="form-control" placeholder="••••••••" minlength="6" required>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-tc-primary w-100 py-2 mt-2">
                                <i class="fa-solid fa-floppy-disk me-1"></i> Salvar nova senha
                            </button>
                        </form>
                    <?php endif; ?>
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
