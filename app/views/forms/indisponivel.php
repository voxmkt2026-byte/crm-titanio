<?php
/** app/views/forms/indisponivel.php — formulário inexistente/desativado. */
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulário indisponível</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="tc-public-form-page">
    <div class="tc-public-form-card text-center">
        <i class="fa-solid fa-circle-exclamation" style="font-size:2.6rem; color:#d97706;"></i>
        <h5 class="mt-3 mb-2">Este formulário não está disponível</h5>
        <p class="text-muted mb-0">O link pode estar incorreto ou o formulário foi desativado.</p>
    </div>
</div>
</body>
</html>
