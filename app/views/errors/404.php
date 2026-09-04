<?php
/**
 * app/views/errors/404.php
 * Página simples de erro 404 (rota não encontrada).
 */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>404 - Página não encontrada</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center justify-content-center vh-100 bg-light">
    <div class="text-center">
        <h1 class="display-4 fw-bold">404</h1>
        <p class="text-muted mb-4">A página que você procura não foi encontrada.</p>
        <a href="<?= (defined('BASE_URL') ? htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') : '/') ?>/dashboard" class="btn btn-primary">Voltar ao início</a>
    </div>
</body>
</html>
