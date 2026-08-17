<?php
/**
 * Instalador único do Titanium CRM.
 *
 * Web: https://seu-dominio/setup.php (a partir da pasta public)
 * CLI: php setup.php --install --admin-name="Nome" --admin-email="email@empresa.com" --admin-password="senha-segura"
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/app/services/DatabaseSetup.php';

$configuredDatabase = [
    'host' => DB_HOST,
    'name' => DB_NAME,
    'user' => DB_USER,
    'pass' => DB_PASS,
    'charset' => DB_CHARSET,
];

$setup = new DatabaseSetup(ROOT_PATH, $configuredDatabase);

if (PHP_SAPI === 'cli') {
    $arguments = getopt('', ['install', 'status', 'demo', 'admin-name:', 'admin-email:', 'admin-password:']);
    if (isset($arguments['status']) || !isset($arguments['install'])) {
        $status = $setup->inspect();
        echo 'Banco configurado: ' . (defined('DB_NAME') ? DB_NAME : '-') . PHP_EOL;
        echo 'Banco existe: ' . ($status['database_exists'] ? 'sim' : 'não') . PHP_EOL;
        echo 'Tabelas do CRM: ' . ($status['application_exists'] ? 'sim' : 'não') . PHP_EOL;
        echo 'Migrations registradas: ' . count($status['tracked']) . PHP_EOL;
        if ($status['error']) {
            echo 'Erro: ' . $status['error'] . PHP_EOL;
            exit(1);
        }
        if (!isset($arguments['install'])) {
            echo PHP_EOL . 'Para instalar: php setup.php --install --admin-name="Nome" --admin-email="email@empresa.com" --admin-password="senha-segura"' . PHP_EOL;
        }
        exit(0);
    }

    try {
        $result = $setup->install(isset($arguments['demo']), [
            'name' => $arguments['admin-name'] ?? '',
            'email' => $arguments['admin-email'] ?? '',
            'password' => $arguments['admin-password'] ?? '',
        ]);
        echo 'Instalação concluída.' . PHP_EOL;
        foreach ($result['applied'] as $item) {
            echo '  aplicado: ' . $item . PHP_EOL;
        }
        foreach ($result['review'] as $item) {
            echo '  revisar: ' . $item . PHP_EOL;
        }
        exit(0);
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Erro: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('titanium_setup');
    session_start();
}
if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

$messages = [];
$errors = [];
$token = (string) (getenv('TITANIUM_SETUP_TOKEN') ?: getenv('SETUP_TOKEN') ?: '');
$databaseInput = [
    'host' => trim((string) ($_POST['db_host'] ?? $configuredDatabase['host'])),
    'name' => trim((string) ($_POST['db_name'] ?? $configuredDatabase['name'])),
    'user' => trim((string) ($_POST['db_user'] ?? $configuredDatabase['user'])),
    // Senhas nunca são preenchidas de volta no HTML. Se nada foi enviado,
    // a configuração já salva continua sendo usada apenas para a consulta.
    'pass' => array_key_exists('db_pass', $_POST) ? (string) $_POST['db_pass'] : $configuredDatabase['pass'],
    'charset' => 'utf8mb4',
];
$setup = new DatabaseSetup(ROOT_PATH, $databaseInput);
$status = $setup->inspect();
$isExisting = $status['application_exists'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf'] ?? '');
    $action = (string) ($_POST['action'] ?? 'install');
    if (!hash_equals($_SESSION['setup_csrf'], $csrf)) {
        $errors[] = 'A sessão do instalador expirou. Recarregue a página e tente novamente.';
    } elseif (!$status['pdo_mysql']) {
        $errors[] = 'Habilite a extensão PHP pdo_mysql antes de executar o instalador.';
    } elseif ($status['error']) {
        $errors[] = 'Não foi possível conectar com os dados informados: ' . $status['error'];
    } elseif ($action === 'test') {
        try {
            setupSaveDatabaseConfig($databaseInput);
            $messages[] = 'Conexão validada e credenciais salvas fora da pasta pública.';
            $messages[] = $status['database_exists']
                ? 'O banco informado está disponível.'
                : 'O banco ainda não existe e será criado durante a instalação, se o usuário tiver essa permissão.';
        } catch (Throwable $saveException) {
            $errors[] = 'A conexão funcionou, mas não foi possível salvar as credenciais: ' . $saveException->getMessage();
        }
    } elseif (trim((string) ($_POST['confirmation'] ?? '')) !== 'INSTALAR') {
        $errors[] = 'Digite INSTALAR para confirmar.';
    } elseif ($token !== '' && !hash_equals($token, (string) ($_POST['setup_token'] ?? ''))) {
        $errors[] = 'Token de instalação inválido.';
    } else {
        try {
            // Salva antes das migrations: se uma delas falhar, a aplicação já
            // continuará usando a mesma conexão que foi validada nesta tela.
            setupSaveDatabaseConfig($databaseInput);
            $result = $setup->install(!empty($_POST['demo']), [
                'name' => (string) ($_POST['admin_name'] ?? ''),
                'email' => (string) ($_POST['admin_email'] ?? ''),
                'password' => (string) ($_POST['admin_password'] ?? ''),
            ]);
            $messages[] = 'Banco configurado com sucesso. ' . count($result['applied']) . ' etapa(s) aplicada(s).';
            if ($result['created_admin']) {
                $messages[] = 'O administrador inicial foi criado.';
            }
            if ($result['demo_ignored']) {
                $messages[] = 'Os dados de demonstração foram ignorados para preservar o banco existente.';
            }
            foreach ($result['review'] as $review) {
                $messages[] = 'Revisão necessária: ' . $review;
            }
            $messages[] = 'As credenciais foram salvas fora da pasta pública em config/database.local.php.';
            $status = $setup->inspect();
            $isExisting = $status['application_exists'];
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

function setupEscape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function setupSaveDatabaseConfig(array $database)
{
    $localPath = __DIR__ . '/config/database.local.php';
    $safeConfig = [
        'host' => (string) $database['host'],
        'name' => (string) $database['name'],
        'user' => (string) $database['user'],
        'pass' => (string) $database['pass'],
        'charset' => 'utf8mb4',
    ];
    $localConfig = "<?php\n\nreturn " . var_export($safeConfig, true) . ";\n";
    if (file_put_contents($localPath, $localConfig, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar config/database.local.php.');
    }
    @chmod($localPath, 0640);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurar <?= setupEscape(APP_NAME) ?></title>
    <style>
        :root { color-scheme: light; font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        body { margin: 0; min-height: 100vh; background: #f4f7fb; color: #172033; display: grid; place-items: center; padding: 28px 16px; box-sizing: border-box; }
        main { width: min(720px, 100%); background: #fff; border: 1px solid #dde5f0; border-radius: 16px; box-shadow: 0 18px 45px rgba(30, 58, 95, .10); overflow: hidden; }
        header { padding: 28px 30px 22px; background: linear-gradient(135deg, #234fe6, #7044e8); color: #fff; }
        h1 { margin: 0 0 8px; font-size: 1.55rem; } p { line-height: 1.5; } header p { margin: 0; opacity: .9; }
        .content { padding: 26px 30px 30px; } .status { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 24px; }
        .status div { padding: 13px; border-radius: 10px; background: #f6f8fc; border: 1px solid #e3e9f4; font-size: .9rem; } .status strong { display:block; margin-top: 4px; }
        .ok { color: #087b4b; } .warn { color: #a6500b; } .alert { border-radius: 10px; padding: 12px 14px; margin: 0 0 16px; line-height: 1.45; } .alert.error { background: #fff2f2; color: #9f1d22; border: 1px solid #f7c7c9; } .alert.success { background: #edfdf4; color: #087444; border: 1px solid #b8eccd; }
        form { border-top: 1px solid #e4e9f2; padding-top: 22px; } fieldset { border: 0; padding: 0; margin: 0 0 22px; } legend { font-weight: 700; margin-bottom: 12px; } label { display: block; margin: 12px 0 6px; font-size: .92rem; font-weight: 600; } input { box-sizing: border-box; width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 11px 12px; font: inherit; } input:focus { outline: 3px solid rgba(59, 101, 232, .18); border-color: #3b65e8; } .checkbox { display: flex; align-items: flex-start; gap: 9px; font-weight: 400; margin: 18px 0; } .checkbox input { width: auto; margin-top: 4px; } .note { font-size: .88rem; color: #526176; margin: 7px 0 0; } button { background: #3158de; color: #fff; border: 0; border-radius: 9px; font: inherit; font-weight: 700; padding: 12px 18px; cursor: pointer; } button:hover { background: #2447c2; } .button-secondary { background: #eef2f8; color: #334155; margin-right: 8px; } .button-secondary:hover { background: #dde5ef; } code { background: #eef2f8; border-radius: 4px; padding: 1px 4px; }
        @media (max-width: 560px) { header, .content { padding-left: 20px; padding-right: 20px; } .status { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <header><h1>Configuração do Titanium CRM</h1><p>Um único instalador para a estrutura, dados iniciais e todas as migrations do sistema.</p></header>
    <div class="content">
        <?php foreach ($errors as $error): ?><div class="alert error"><?= setupEscape($error) ?></div><?php endforeach; ?>
        <?php foreach ($messages as $message): ?><div class="alert success"><?= setupEscape($message) ?></div><?php endforeach; ?>
        <section class="status">
            <div>Banco <strong><?= setupEscape($databaseInput['name'] ?: '-') ?></strong></div>
            <div>Conexão <strong class="<?= $status['error'] ? 'warn' : 'ok' ?>"><?= $status['error'] ? 'Verificar credenciais' : 'Disponível' ?></strong></div>
            <div>Migrations <strong><?= count($status['tracked']) ?> aplicada(s)</strong></div>
        </section>
        <?php if ($status['error']): ?><p class="note">Detalhe: <?= setupEscape($status['error']) ?></p><?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= setupEscape($_SESSION['setup_csrf']) ?>">
            <fieldset>
                <legend>Banco de dados Hostinger</legend>
                <p class="note">Copie estes dados em hPanel → Bancos de Dados → MySQL Databases. A senha não é exibida nem mantida no formulário.</p>
                <label for="db_host">Host</label><input id="db_host" name="db_host" value="<?= setupEscape($databaseInput['host']) ?>" required maxlength="255" placeholder="localhost">
                <label for="db_name">Nome do banco</label><input id="db_name" name="db_name" value="<?= setupEscape($databaseInput['name']) ?>" required maxlength="128" placeholder="u123456789_titanium">
                <label for="db_user">Usuário do banco</label><input id="db_user" name="db_user" value="<?= setupEscape($databaseInput['user']) ?>" required maxlength="128" placeholder="u123456789_admin">
                <label for="db_pass">Senha do banco</label><input id="db_pass" name="db_pass" type="password" required autocomplete="new-password">
            </fieldset>
            <?php if (!$isExisting): ?>
                <fieldset>
                    <legend>Administrador inicial</legend>
                    <p class="note">Obrigatório apenas na instalação sem dados de demonstração.</p>
                    <label for="admin_name">Nome</label><input id="admin_name" name="admin_name" value="<?= setupEscape($_POST['admin_name'] ?? '') ?>" maxlength="150">
                    <label for="admin_email">E-mail</label><input id="admin_email" type="email" name="admin_email" value="<?= setupEscape($_POST['admin_email'] ?? '') ?>" maxlength="150">
                    <label for="admin_password">Senha</label><input id="admin_password" type="password" name="admin_password" minlength="8">
                </fieldset>
                <label class="checkbox"><input type="checkbox" name="demo" value="1" <?= !empty($_POST['demo']) ? 'checked' : '' ?>> <span>Carregar dados de demonstração (inclui usuários de teste e leads fictícios).</span></label>
            <?php endif; ?>
            <?php if ($token !== ''): ?><label for="setup_token">Token de instalação</label><input id="setup_token" name="setup_token" type="password" required><?php endif; ?>
            <label for="confirmation">Para confirmar, digite INSTALAR</label><input id="confirmation" name="confirmation" required>
            <p class="note">O instalador não apaga tabelas ou dados. Ele registra cada etapa em <code>system_migrations</code> e executa apenas migrations pendentes.</p>
            <p><button class="button-secondary" type="submit" name="action" value="test" formnovalidate>Testar conexão</button><button type="submit" name="action" value="install">Executar configuração</button></p>
        </form>
    </div>
</main>
</body>
</html>
