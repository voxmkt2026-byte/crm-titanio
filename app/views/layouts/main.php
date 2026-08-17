<?php
/**
 * app/views/layouts/main.php
 * Layout principal: sidebar fixa + topbar + conteúdo.
 * A view da página fica disponível via closure $content().
 */

$currentUser = Auth::user();
$currentPath = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$basePath = trim(parse_url(BASE_URL, PHP_URL_PATH) ?? '', '/');
if ($basePath !== '' && strpos($currentPath, $basePath) === 0) {
    $currentPath = trim(substr($currentPath, strlen($basePath)), '/');
}

function tc_nav_active(string $path, string $current): string
{
    if ($path === '' && $current === '') {
        return 'active';
    }
    return ($path !== '' && strpos($current, $path) === 0) ? 'active' : '';
}

$initials = '?';
if (!empty($currentUser['name'])) {
    $parts = preg_split('/\s+/', trim($currentUser['name']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

/** Breadcrumb contextual: usa a rota para voltar à seção e o pageTitle para
 * identificar a tela atual, inclusive detalhes, edição e submódulos. */
function tc_breadcrumb_items(string $path, string $pageTitle): array
{
    $sections = [
        'dashboard' => ['Dashboard', 'dashboard'], 'hoje' => ['Meu Dia', 'hoje'],
        'leads' => ['Leads', 'leads'], 'importar' => ['Importar Leads', 'importar'],
        'pipeline' => ['Pipeline', 'pipeline'], 'agenda' => ['Agenda', 'agenda'],
        'calendario' => ['Calendário', 'calendario'], 'chat' => ['Chat Interno', 'chat'],
        'atendimento-whatsapp' => ['Atendimento WhatsApp', 'atendimento-whatsapp'],
        'tarefas' => ['Tarefas', 'tarefas'], 'conteudo' => ['Documentos e Wiki', 'conteudo'],
        'whiteboards' => ['Whiteboards', 'whiteboards'], 'automacoes' => ['Automações', 'automacoes'],
        'sla' => ['SLA', 'sla'], 'indicadores' => ['Indicadores', 'indicadores'],
        'relatorios' => ['Relatórios', 'relatorios'], 'motivos-perda' => ['Motivos de Perda', 'motivos-perda'],
        'formularios' => ['Formulários', 'formularios'], 'usuarios' => ['Usuários', 'usuarios'],
        'departamentos' => ['Departamentos', 'departamentos'], 'configuracoes' => ['Configurações', 'configuracoes'],
        'metas' => ['Metas', 'metas'], 'logs' => ['Logs', 'logs'], 'perfil' => ['Perfil', 'perfil'],
    ];
    $segments = array_values(array_filter(explode('/', trim($path, '/'))));
    $key = $segments[0] ?? 'dashboard';
    $section = $sections[$key] ?? [$pageTitle ?: 'Painel', $key];
    $title = trim($pageTitle) ?: $section[0];
    $simplePage = count($segments) <= 1 || mb_strtolower($title) === mb_strtolower($section[0]);

    $items = [['label' => 'Início', 'url' => url('dashboard'), 'active' => false]];
    if ($simplePage) {
        $items[] = ['label' => $title, 'url' => null, 'active' => true];
        return $items;
    }
    $items[] = ['label' => $section[0], 'url' => url($section[1]), 'active' => false];
    $items[] = ['label' => $title, 'url' => null, 'active' => true];
    return $items;
}

$tcBreadcrumbs = tc_breadcrumb_items($currentPath, (string) ($pageTitle ?? ''));

// Contador de tarefas pendentes/em andamento atribuídas ao usuário logado
// (badge no item "Tarefas" da sidebar). Calculado no carregamento da página
// (sem polling separado, ver database/sql/migration_tasks.sql). Falha
// graciosamente se a migration ainda não tiver sido executada.
$tcTaskPendingCount = 0;
if ($currentUser) {
    try {
        require_once APP_PATH . '/models/Task.php';
        $tcTaskPendingCount = (new Task())->countPendingForUser((int) $currentUser['id']);
    } catch (Throwable $e) {
        $tcTaskPendingCount = 0;
    }
}

// Contador de leads "atrasados" (next_contact_at no passado) para o badge do
// item "Agenda" na sidebar (Fase 7). Mesmo padrão do badge de Tarefas acima:
// calculado no carregamento da página, sem polling separado. Segue a MESMA
// regra de visibilidade já usada pela Agenda (AgendaController::index):
// admin/supervisor veem o total geral, os demais só a própria agenda.
// Falha graciosamente em qualquer erro de consulta.
$tcAgendaOverdueCount = 0;
if ($currentUser) {
    try {
        require_once APP_PATH . '/core/Database.php';
        $tcDb = Database::getInstance();
        if (Auth::hasRole(['admin', 'supervisor'])) {
            $tcStmt = $tcDb->query(
                "SELECT COUNT(*) AS total FROM leads
                 WHERE next_contact_at IS NOT NULL AND next_contact_at < NOW()"
            );
            $tcAgendaOverdueCount = (int) ($tcStmt->fetch()['total'] ?? 0);
        } else {
            $tcStmt = $tcDb->prepare(
                "SELECT COUNT(*) AS total FROM leads
                 WHERE next_contact_at IS NOT NULL AND next_contact_at < NOW() AND assigned_to = :user_id"
            );
            $tcStmt->execute([':user_id' => (int) $currentUser['id']]);
            $tcAgendaOverdueCount = (int) ($tcStmt->fetch()['total'] ?? 0);
        }
    } catch (Throwable $e) {
        $tcAgendaOverdueCount = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($pageTitle ?? APP_NAME) ?> · <?= e(APP_NAME) ?></title>

    <!-- PWA: instalável na tela inicial (ver public/manifest.json e public/sw.js) -->
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#1e3a5f">
    <link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
    <link rel="icon" href="<?= e(asset('img/icon-192.png')) ?>" type="image/png" sizes="192x192">
    <!-- iOS não lê o manifest para o ícone da tela inicial nem aceita SVG aqui — precisa deste link em PNG -->
    <link rel="apple-touch-icon" href="<?= e(asset('img/apple-touch-icon.png')) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= e(APP_NAME) ?>">

    <!-- Bootstrap 5.3 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <!-- CSS próprio (identidade Titanium CRM) -->
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">

    <?php // CSS extra por página (ex: Leaflet.js só na tela de Indicadores) -
    // ver Controller::view(), $pageStyles é extraído junto com os outros
    // dados ANTES deste layout ser incluído, então já está disponível aqui. ?>
    <?php if (isset($pageStyles)) { echo $pageStyles; } ?>

    <script>
        // Aplica o tema salvo o quanto antes, para evitar "flash" de tela clara
        (function () {
            var theme = localStorage.getItem('tc-theme') || 'light';
            if (theme === 'dark') {
                document.documentElement.classList.add('tc-pending-dark');
            }
        })();
    </script>
</head>
<body>
<script>
    if (document.documentElement.classList.contains('tc-pending-dark')) {
        document.body.classList.add('dark-mode');
    }
</script>

<div class="tc-wrapper">
    <!-- Sidebar -->
    <aside class="tc-sidebar">
        <div class="tc-sidebar-brand">
            <div class="tc-logo-badge">TC</div>
            <div class="tc-brand-text">
                <strong><?= e(APP_NAME) ?></strong>
                <span><?= e(COMPANY_NAME) ?></span>
            </div>
        </div>

        <nav class="tc-sidebar-nav">
            <div class="tc-nav-label">Principal</div>
            <a href="<?= e(url('dashboard')) ?>" class="<?= tc_nav_active('dashboard', $currentPath) ?>">
                <i class="fa-solid fa-chart-line"></i> Dashboard
            </a>
            <!-- "Meu Dia" (Fase 7): visão unificada e pessoal do que fazer agora
                 (leads atrasados + tarefas de hoje/atrasadas + leads sem primeiro
                 contato). Destaque visual sutil por ser a tela mais usada no dia
                 a dia - ver app/controllers/TodayController.php. -->
            <a href="<?= e(url('hoje')) ?>" class="tc-nav-highlight <?= tc_nav_active('hoje', $currentPath) ?>">
                <i class="fa-solid fa-bolt"></i> Meu Dia
            </a>
            <a href="<?= e(url('leads')) ?>" class="<?= tc_nav_active('leads', $currentPath) ?>">
                <i class="fa-solid fa-users"></i> Leads
            </a>
            <a href="<?= e(url('leads/create')) ?>" class="<?= ($currentPath === 'leads/create') ? 'active' : '' ?>">
                <i class="fa-solid fa-user-plus"></i> Novo Lead
            </a>
            <a href="<?= e(url('importar')) ?>" class="<?= tc_nav_active('importar', $currentPath) ?>">
                <i class="fa-solid fa-file-csv"></i> Importar Leads
            </a>
            <a href="<?= e(url('pipeline')) ?>" class="<?= tc_nav_active('pipeline', $currentPath) ?>">
                <i class="fa-solid fa-table-columns"></i> Pipeline
            </a>
            <a href="<?= e(url('agenda')) ?>" class="d-flex align-items-center <?= tc_nav_active('agenda', $currentPath) ?>">
                <i class="fa-solid fa-calendar-days"></i> Agenda
                <?php if ($tcAgendaOverdueCount > 0): ?>
                    <span class="badge rounded-pill bg-danger tc-task-badge"><?= (int) $tcAgendaOverdueCount ?></span>
                <?php endif; ?>
            </a>
            <a href="<?= e(url('calendario')) ?>" class="<?= tc_nav_active('calendario', $currentPath) ?>"><i class="fa-solid fa-calendar"></i> Calendário</a>
            <!-- Chat interno (polling AJAX, ver public/assets/js/app.js::initChatSidebarBadge) -->
            <a href="<?= e(url('chat')) ?>" id="tcChatSidebarLink"
               class="d-flex align-items-center justify-content-between <?= tc_nav_active('chat', $currentPath) ?>"
               data-unread-url="<?= e(url('chat/nao-lidas')) ?>">
                <span><i class="fa-solid fa-message"></i> Chat</span>
                <span class="badge rounded-pill bg-danger d-none" id="tcChatSidebarBadge" style="font-size:0.65rem;">0</span>
            </a>
            <?php if (Auth::can('evolution.view') || Auth::hasRole(['admin', 'supervisor'])): ?>
            <!-- Atendimento WhatsApp (Evolution/EvoAI CRM): inbox estilo "Zap Responder", ver EvolutionInboxController -->
            <a href="<?= e(url('atendimento-whatsapp')) ?>" class="d-flex align-items-center <?= tc_nav_active('atendimento-whatsapp', $currentPath) ?>">
                <i class="fa-brands fa-whatsapp"></i> Atendimento WhatsApp
            </a>
            <?php endif; ?>
            <a href="<?= e(url('tarefas')) ?>" class="d-flex align-items-center <?= tc_nav_active('tarefas', $currentPath) ?>">
                <i class="fa-solid fa-list-check"></i> Tarefas
                <?php if ($tcTaskPendingCount > 0): ?>
                    <span class="badge rounded-pill bg-danger tc-task-badge"><?= (int) $tcTaskPendingCount ?></span>
                <?php endif; ?>
            </a>

            <div class="tc-nav-label">Conhecimento</div>
            <a href="<?= e(url('conteudo')) ?>" class="<?= tc_nav_active('conteudo', $currentPath) ?>"><i class="fa-solid fa-book-open"></i> Documentos e Wiki</a>
            <a href="<?= e(url('whiteboards')) ?>" class="<?= tc_nav_active('whiteboards', $currentPath) ?>"><i class="fa-solid fa-bezier-curve"></i> Whiteboards</a>
            <?php if (Auth::hasRole(['admin','supervisor'])): ?>
            <a href="<?= e(url('automacoes')) ?>" class="<?= tc_nav_active('automacoes', $currentPath) ?>"><i class="fa-solid fa-diagram-project"></i> Automações</a>
            <?php endif; ?>

            <div class="tc-nav-label">Indicadores</div>
            <a href="<?= e(url('sla')) ?>" class="<?= tc_nav_active('sla', $currentPath) ?>">
                <i class="fa-solid fa-stopwatch"></i> SLA
            </a>
            <a href="<?= e(url('indicadores')) ?>" class="<?= tc_nav_active('indicadores', $currentPath) ?>">
                <i class="fa-solid fa-map-location-dot"></i> Indicadores
            </a>
            <?php if (Auth::can('reports.view')): ?>
            <a href="<?= e(url('relatorios')) ?>" class="<?= tc_nav_active('relatorios', $currentPath) ?>">
                <i class="fa-solid fa-chart-column"></i> Relatórios
            </a>
            <?php endif; ?>
            <a href="<?= e(url('motivos-perda')) ?>" class="<?= tc_nav_active('motivos-perda', $currentPath) ?>">
                <i class="fa-solid fa-circle-xmark"></i> Motivos de Perda
            </a>
            <?php if (Auth::can('forms.manage')): ?>
            <a href="<?= e(url('formularios')) ?>" class="<?= tc_nav_active('formularios', $currentPath) ?>">
                <i class="fa-solid fa-file-pen"></i> Formulários
            </a>
            <?php endif; ?>

            <div class="tc-nav-label">Gestão</div>
            <?php if (Auth::hasRole(['admin'])): ?>
            <a href="<?= e(url('usuarios')) ?>" class="<?= tc_nav_active('usuarios', $currentPath) ?>">
                <i class="fa-solid fa-user-gear"></i> Usuários
            </a>
            <a href="<?= e(url('departamentos')) ?>" class="<?= tc_nav_active('departamentos', $currentPath) ?>">
                <i class="fa-solid fa-sitemap"></i> Departamentos
            </a>
            <a href="<?= e(url('configuracoes')) ?>" class="<?= tc_nav_active('configuracoes', $currentPath) ?>">
                <i class="fa-solid fa-gear"></i> Configurações
            </a>
            <a href="<?= e(url('configuracoes/lead-score')) ?>" class="<?= tc_nav_active('configuracoes/lead-score', $currentPath) ?>">
                <i class="fa-solid fa-star-half-stroke"></i> Lead Score
            </a>
            <?php endif; ?>
            <?php if (Auth::hasRole(['admin', 'supervisor']) && Auth::can('goals.manage')): ?>
            <a href="<?= e(url('metas')) ?>" class="<?= tc_nav_active('metas', $currentPath) ?>">
                <i class="fa-solid fa-bullseye"></i> Metas
            </a>
            <?php endif; ?>
            <?php if (Auth::hasRole(['admin'])): ?>
            <a href="<?= e(url('logs')) ?>" class="<?= tc_nav_active('logs', $currentPath) ?>">
                <i class="fa-solid fa-clipboard-list"></i> Logs
            </a>
            <?php endif; ?>

            <div class="tc-nav-label">Conta</div>
            <a href="<?= e(url('perfil')) ?>" class="<?= tc_nav_active('perfil', $currentPath) ?>">
                <i class="fa-solid fa-id-card"></i> Perfil
            </a>
        </nav>
    </aside>

    <!-- Overlay escurecido atrás da sidebar em modo off-canvas (<992px) -->
    <div class="tc-sidebar-backdrop" id="tcSidebarBackdrop"></div>

    <div class="tc-main">
        <!-- Topbar -->
        <header class="tc-topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="tc-icon-btn tc-mobile-toggle" id="tcMobileToggle" type="button">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <h1 class="tc-page-title"><?= e($pageTitle ?? '') ?></h1>
            </div>

            <div class="tc-topbar-actions">
                <!-- Busca global (Fase 7 - auditoria UX): AJAX em public/assets/js/app.js
                     (seção "BUSCA GLOBAL"), consulta GET /leads/buscar-rapido -->
                <?php if ($currentUser): ?>
                <div class="tc-global-search d-none d-sm-block" id="tcGlobalSearch" data-search-url="<?= e(url('leads/buscar-rapido')) ?>">
                    <input type="text" class="form-control form-control-sm" id="tcGlobalSearchInput"
                           placeholder="Buscar lead..." autocomplete="off">
                    <div class="tc-global-search-results" id="tcGlobalSearchResults"></div>
                </div>
                <?php endif; ?>

                <button class="tc-icon-btn tc-theme-toggle" id="tcThemeToggle" type="button" title="Alternar tema">
                    <i class="fa-solid fa-moon"></i>
                </button>

                <!-- Sino de notificações (Fase 3): polling via public/assets/js/app.js -->
                <div class="dropdown tc-notif" id="tcNotificationBell"
                     data-unread-url="<?= e(url('notifications/unread')) ?>"
                     data-read-url-base="<?= e(url('notifications')) ?>"
                     data-read-all-url="<?= e(url('notifications/read-all')) ?>"
                     data-csrf-token="<?= e(Csrf::token()) ?>">
                    <button class="tc-icon-btn position-relative" type="button" data-bs-toggle="dropdown" title="Notificações" id="tcNotifBellBtn">
                        <i class="fa-solid fa-bell" id="tcNotifBellIcon"></i>
                        <span class="badge rounded-pill bg-danger tc-notification-badge d-none" id="tcNotificationCount">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow-sm p-0 tc-notif-panel">
                        <div class="tc-notif-panel-head">
                            <div>
                                <strong>Notificações</strong>
                                <span class="tc-notif-panel-sub" id="tcNotifSubtitle">Tudo em dia</span>
                            </div>
                            <button type="button" class="tc-notif-mark-all" id="tcMarkAllRead" title="Marcar todas como lidas">
                                <i class="fa-solid fa-check-double"></i>
                            </button>
                        </div>
                        <div id="tcNotificationList" class="tc-notif-list">
                            <div class="tc-notif-skel">
                                <div class="tc-notif-skel-row"><span class="tc-notif-skel-dot"></span><span class="tc-notif-skel-lines"><i></i><i></i></span></div>
                                <div class="tc-notif-skel-row"><span class="tc-notif-skel-dot"></span><span class="tc-notif-skel-lines"><i></i><i></i></span></div>
                                <div class="tc-notif-skel-row"><span class="tc-notif-skel-dot"></span><span class="tc-notif-skel-lines"><i></i><i></i></span></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center gap-2 text-decoration-none" data-bs-toggle="dropdown">
                        <?php if (!empty($currentUser['avatar'])): ?>
                            <img class="tc-user-avatar" src="<?= e($currentUser['avatar']) ?>" alt="" style="object-fit:cover;" onerror="this.style.display='none';document.getElementById('tcTopbarAvatarFallback').classList.remove('d-none');">
                            <div id="tcTopbarAvatarFallback" class="tc-user-avatar d-none"><?= e($initials) ?></div>
                        <?php else: ?>
                            <div class="tc-user-avatar"><?= e($initials) ?></div>
                        <?php endif; ?>
                        <div class="d-none d-md-block">
                            <div class="fw-semibold" style="font-size:0.85rem; color: var(--tc-text);">
                                <?= e($currentUser['name'] ?? 'Usuário') ?>
                            </div>
                            <div class="text-muted" style="font-size:0.72rem;">
                                <?= e(ucfirst($currentUser['role'] ?? '')) ?>
                            </div>
                        </div>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item" href="<?= e(url('perfil')) ?>"><i class="fa-solid fa-id-card me-2"></i>Perfil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= e(url('logout')) ?>"><i class="fa-solid fa-right-from-bracket me-2"></i>Sair</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Conteúdo -->
        <main class="tc-content">
            <nav class="tc-breadcrumb" aria-label="Navegação estrutural">
                <ol>
                    <?php foreach ($tcBreadcrumbs as $item): ?>
                        <li class="<?= !empty($item['active']) ? 'active' : '' ?>" <?= !empty($item['active']) ? 'aria-current="page"' : '' ?>>
                            <?php if (!empty($item['url'])): ?>
                                <a href="<?= e($item['url']) ?>"><?= e($item['label']) ?></a>
                            <?php else: ?>
                                <span><?= e($item['label']) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </nav>
            <?php if ($msg = flash('success')): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-circle-check me-1"></i> <?= e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($msg = flash('error')): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php $content(); ?>
        </main>
    </div>
</div>

<button type="button" class="tc-ai-fab" id="tcAiFab" title="Assistente IA"><i class="fa-solid fa-wand-magic-sparkles"></i></button>
<aside class="tc-ai-panel" id="tcAiPanel" data-url="<?= e(url('assistente/perguntar')) ?>" data-csrf="<?= e(Csrf::token()) ?>">
    <header><span><i class="fa-solid fa-wand-magic-sparkles"></i> Titanium IA <small>CRM conectado</small></span><button type="button" id="tcAiClose">×</button></header>
    <div class="tc-ai-quick"><button data-ai-purpose="assistant" data-ai-prompt="Resuma meus leads que precisam de atenção hoje"><i class="fa-solid fa-users"></i> Meus leads</button><button data-ai-purpose="approach" data-ai-prompt="Crie uma abordagem comercial para o lead que eu informar"><i class="fa-solid fa-bullhorn"></i> Abordagem</button><button data-ai-purpose="objection" data-ai-prompt="Ajude a responder esta objeção: "><i class="fa-solid fa-comments"></i> Objeção</button><button data-ai-purpose="assistant" data-ai-prompt="Busque na Wiki interna informações sobre "><i class="fa-solid fa-book-open"></i> Wiki</button></div>
    <div class="tc-ai-messages" id="tcAiMessages"><div class="tc-ai-answer"><span class="tc-ai-avatar"><i class="fa-solid fa-wand-magic-sparkles"></i></span><div>Olá! Consulto apenas os leads, tarefas e conteúdos que seu usuário pode acessar. Como posso ajudar?</div></div></div>
    <form id="tcAiForm"><textarea id="tcAiInput" rows="2" placeholder="Como posso ajudar?"></textarea><button type="submit"><i class="fa-solid fa-arrow-up"></i></button></form>
</aside>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5.3 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<!-- SweetAlert2 (confirmações/modais) -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Toastify.js (feedback rápido não-bloqueante: "Mensagem enviada",
     "Tarefa concluída" etc - ver função tcToast() em app.js) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<link rel="stylesheet" href="https://unpkg.com/tippy.js@6/dist/tippy.css">
<script src="https://unpkg.com/@popperjs/core@2"></script>
<script src="https://unpkg.com/tippy.js@6"></script>
<!-- Anime.js (animações leves: sino de notificação, entrada em cascata dos itens) -->
<script src="https://cdn.jsdelivr.net/npm/animejs@3.2.2/lib/anime.min.js"></script>
<!-- App JS -->
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/workspace.js')) ?>"></script>

<?php if (isset($pageScripts)) { echo $pageScripts; } ?>
</body>
</html>
