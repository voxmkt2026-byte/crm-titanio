<?php
/**
 * app/views/chat/index.php
 * Tela principal do Chat Interno: lista de salas (departamento/grupos/
 * diretas) à esquerda + conversa ativa à direita. Toda a interatividade
 * (envio, polling, comandos, moderação) é feita via public/assets/js/app.js
 * (initChat()), seguindo o mesmo padrão de polling AJAX do sino de
 * notificações (sem WebSockets/Node — ver comentário no ChatController).
 */

$grouped = ['departamento' => [], 'grupo' => [], 'direto' => []];
foreach ($rooms as $room) {
    $grouped[$room['type']][] = $room;
}
$activeGroupImageUrl = ($activeRoom && $isActiveCustomGroup && !empty($activeRoom['image_filename']))
    ? url('uploads/' . rawurlencode($activeRoom['image_filename']))
    : null;

function tc_chat_room_label(array $room): string
{
    if (!empty($room['task_id'])) {
        return $room['name'] ?? ('Tarefa · ' . ($room['task_title'] ?? 'Conversa'));
    }
    if ($room['type'] === 'direto') {
        return $room['direct_other_name'] ?? 'Conversa';
    }
    if ($room['type'] === 'departamento') {
        return $room['department_name'] ?? ($room['name'] ?? 'Departamento');
    }
    return $room['name'] ?? 'Grupo';
}

function tc_chat_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    if (empty($parts) || $parts[0] === '') {
        return '?';
    }
    $initials = substr($parts[0], 0, 1);
    if (isset($parts[1])) {
        $initials .= substr($parts[1], 0, 1);
    }
    return strtoupper($initials);
}

/** Avatar do usuário sempre vem da coluna users.avatar, atualizada no perfil. */
function tc_chat_user_avatar(string $name, ?string $avatar, string $class): string
{
    $hasAvatar = trim((string) $avatar) !== '';
    $html = '<span class="' . e($class) . ' tc-chat-avatar-wrap' . ($hasAvatar ? ' tc-chat-avatar-has-photo' : '') . '">';
    if ($hasAvatar) {
        $html .= '<img src="' . e((string) $avatar) . '" alt="" loading="lazy" onerror="this.remove();this.parentElement.classList.remove(\'tc-chat-avatar-has-photo\');">';
    }
    $html .= '<span class="tc-chat-avatar-initials">' . e(tc_chat_initials($name)) . '</span></span>';
    return $html;
}
?>

<div class="tc-chat-app <?= $activeRoom ? 'tc-chat-has-active' : '' ?>"
     id="tcChatApp"
     data-csrf-token="<?= e(Csrf::token()) ?>"
     data-active-room="<?= (int) $activeRoomId ?>"
     data-current-user-id="<?= (int) $currentUserId ?>"
     data-can-moderate="<?= $canModerateGlobal ? '1' : '0' ?>"
     data-can-moderate-active-room="<?= $canModerateActiveRoom ? '1' : '0' ?>"
     data-can-create-room="<?= $canCreateRoom ? '1' : '0' ?>"
     data-url-base="<?= e(url('chat')) ?>"
     data-url-search-users="<?= e(url('chat/usuarios/buscar')) ?>">

    <!-- Coluna esquerda: lista de salas -->
    <aside class="tc-chat-rooms" id="tcChatRoomsPane">
        <div class="tc-chat-rooms-header">
            <div><h6 class="mb-0">Central da equipe</h6><small class="text-muted">Conversas e colaboração</small></div>
            <?php if ($canCreateRoom): ?>
                <button type="button" class="btn btn-sm btn-tc-primary" id="tcChatNewGroupBtn" title="Nova sala de grupo">
                    <i class="fa-solid fa-plus"></i>
                </button>
            <?php endif; ?>
        </div>

        <div class="tc-chat-search px-2 pt-2">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                <input type="text" class="form-control" id="tcChatUserSearch" placeholder="Buscar colega para iniciar conversa...">
            </div>
            <div id="tcChatUserSearchResults" class="tc-chat-search-results d-none"></div>
        </div>

        <ul class="nav nav-tabs tc-chat-tabs px-2 pt-2" id="tcChatTabs">
            <li class="nav-item">
                <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tcChatTabDepartamento" type="button"><i class="fa-solid fa-building-user"></i> Equipes</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tcChatTabGrupos" type="button"><i class="fa-solid fa-user-group"></i> Grupos</button>
            </li>
            <li class="nav-item">
                <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tcChatTabDiretas" type="button"><i class="fa-solid fa-message"></i> Diretas</button>
            </li>
        </ul>

        <div class="tab-content tc-chat-room-list">
            <div class="tab-pane fade show active" id="tcChatTabDepartamento">
                <?php if (empty($grouped['departamento'])): ?>
                    <div class="tc-chat-empty">Nenhuma sala de departamento.</div>
                <?php endif; ?>
                <?php foreach ($grouped['departamento'] as $room): ?>
                    <?php include __DIR__ . '/_room_item.php'; ?>
                <?php endforeach; ?>
            </div>
            <div class="tab-pane fade" id="tcChatTabGrupos">
                <?php if (empty($grouped['grupo'])): ?>
                    <div class="tc-chat-empty">Você ainda não participa de nenhum grupo.</div>
                <?php endif; ?>
                <?php foreach ($grouped['grupo'] as $room): ?>
                    <?php include __DIR__ . '/_room_item.php'; ?>
                <?php endforeach; ?>
            </div>
            <div class="tab-pane fade" id="tcChatTabDiretas">
                <?php if (empty($grouped['direto'])): ?>
                    <div class="tc-chat-empty">Nenhuma conversa direta ainda. Use a busca acima.</div>
                <?php endif; ?>
                <?php foreach ($grouped['direto'] as $room): ?>
                    <?php include __DIR__ . '/_room_item.php'; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </aside>

    <!-- Coluna direita: conversa ativa -->
    <section class="tc-chat-main" id="tcChatMainPane">
        <?php if (!$activeRoom): ?>
            <div class="tc-chat-placeholder">
                <i class="fa-solid fa-comments"></i>
                <h5>Seu espaço de colaboração</h5><p>Selecione uma conversa ou encontre um colega para começar.</p>
            </div>
        <?php else: ?>
            <header class="tc-chat-header">
                <button type="button" class="tc-icon-btn" id="tcChatBackBtn" title="Voltar">
                    <i class="fa-solid fa-arrow-left"></i>
                </button>
                <?php if ($activeRoom['type'] === 'direto'): ?>
                    <?= tc_chat_user_avatar(tc_chat_room_label($activeRoom), $activeRoom['direct_other_avatar'] ?? null, 'tc-chat-header-avatar') ?>
                <?php else: ?>
                <div class="tc-chat-header-avatar" style="<?= $activeRoom['type'] === 'departamento' ? 'background:' . e($activeRoom['department_color'] ?? '#3b82f6') . ';' : '' ?>">
                    <?php if ($activeGroupImageUrl): ?>
                        <img src="<?= e($activeGroupImageUrl) ?>" alt="" style="width:100%;height:100%;border-radius:inherit;object-fit:cover;" onerror="this.remove();">
                    <?php elseif ($activeRoom['type'] === 'departamento'): ?>
                        <i class="<?= e($activeRoom['department_icon'] ?? 'fa-solid fa-comments') ?>"></i>
                    <?php elseif (!empty($activeRoom['lead_id'])): ?>
                        <i class="fa-solid fa-address-card"></i>
                    <?php elseif (!empty($activeRoom['task_id'])): ?>
                        <i class="fa-solid fa-list-check"></i>
                    <?php else: ?>
                        <?= e(tc_chat_initials(tc_chat_room_label($activeRoom))) ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="flex-grow-1">
                    <div class="tc-chat-header-title"><?= e(tc_chat_room_label($activeRoom)) ?></div>
                    <div class="tc-chat-header-meta">
                        <?php if ($activeRoom['type'] === 'departamento'): ?>
                            Sala de departamento
                        <?php elseif (!empty($activeRoom['lead_id'])): ?>
                            <a href="<?= e(url('leads/' . $activeRoom['lead_id'])) ?>" class="text-decoration-none">Lead: <?= e($activeRoom['lead_name'] ?: ($activeRoom['lead_code'] ?: 'ver perfil')) ?></a>
                            · Sala privada · <span id="tcChatMemberCount"><?= count($activeRoomMembers) ?></span> membro(s)
                        <?php elseif (!empty($activeRoom['task_id'])): ?>
                            <a href="<?= e(url('tarefas/' . $activeRoom['task_id'])) ?>" class="text-decoration-none">Tarefa: <?= e($activeRoom['task_title'] ?: 'ver detalhes') ?></a>
                            · Conversa privada · <span id="tcChatMemberCount"><?= count($activeRoomMembers) ?></span> membro(s)
                        <?php elseif ($activeRoom['type'] === 'grupo'): ?>
                            Grupo · <span id="tcChatMemberCount"><?= count($activeRoomMembers) ?></span> membro(s)
                        <?php else: ?>
                            Conversa direta
                        <?php endif; ?>
                    </div>
                    <?php if ($isActiveCustomGroup && !empty($activeRoom['description'])): ?>
                        <div class="small text-muted mt-1 text-truncate" style="max-width:520px;" title="<?= e($activeRoom['description']) ?>"><?= e($activeRoom['description']) ?></div>
                    <?php endif; ?>
                </div>
                <?php include APP_PATH . '/views/partials/_chat_theme_picker.php'; ?>
                <div class="dropdown">
                    <button class="tc-icon-btn" type="button" data-bs-toggle="dropdown" title="Opções da sala">
                        <i class="fa-solid fa-ellipsis-vertical"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li>
                            <a class="dropdown-item" href="#" id="tcChatToggleMute">
                                <i class="fa-solid fa-bell-slash me-2"></i>
                                <span id="tcChatMuteLabel"><?= !empty($activeRoom['muted']) ? 'Reativar notificações' : 'Silenciar sala' ?></span>
                            </a>
                        </li>
                        <?php if ($activeRoom['type'] !== 'direto'): ?>
                        <li><a class="dropdown-item" href="#" id="tcChatShowMembers"><i class="fa-solid fa-users me-2"></i>Ver membros</a></li>
                        <?php endif; ?>
                        <?php if ($activeRoom['type'] === 'grupo' && $canModerateActiveRoom): ?>
                        <li><a class="dropdown-item" href="#" id="tcChatInviteMembers"><i class="fa-solid fa-user-plus me-2"></i>Convidar pessoas</a></li>
                        <?php endif; ?>
                        <?php if ($isActiveCustomGroup && $canManageCustomGroup): ?>
                        <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#tcChatGroupManageModal"><i class="fa-solid fa-gear me-2"></i>Gerenciar grupo</a></li>
                        <?php endif; ?>
                        <?php if ($activeRoom['type'] === 'grupo'): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="<?= e(url('chat/salas/' . $activeRoom['id'] . '/sair')) ?>" class="tc-delete-form" data-confirm-text="Você sairá desta sala e deixará de receber mensagens dela.">
                                <?= Csrf::field() ?>
                                <button type="submit" class="dropdown-item text-danger"><i class="fa-solid fa-right-from-bracket me-2"></i>Sair da sala</button>
                            </form>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </header>

            <div class="tc-chat-messages" id="tcChatMessages" data-last-id="<?= !empty($messages) ? (int) end($messages)['id'] : 0 ?>">
                <div class="text-center py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="tcChatLoadOlder">Carregar mensagens mais antigas</button>
                </div>
                <div id="tcChatMessagesList">
                    <?php $prevUserId = null; $prevDateLabel = null; ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php if ($msg['date_label'] !== $prevDateLabel): ?>
                            <div class="tc-chat-date-divider"><?= e($msg['date_label']) ?></div>
                            <?php $prevUserId = null; ?>
                        <?php endif; ?>
                        <?php $isGrouped = $msg['type'] !== 'sistema' && $msg['user_id'] !== null && $msg['user_id'] === $prevUserId; ?>
                        <?php include __DIR__ . '/_message.php'; ?>
                        <?php $prevUserId = $msg['user_id']; $prevDateLabel = $msg['date_label']; ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <form class="tc-chat-composer" id="tcChatComposer" data-room-id="<?= (int) $activeRoom['id'] ?>">
                <?= Csrf::field() ?>
                <div class="tc-chat-compose-wrap">
                    <div class="tc-chat-compose-tools">
                        <button type="button" class="tc-chat-tool" data-chat-insert="/ajuda" title="Ver recursos do chat"><i class="fa-solid fa-circle-question"></i> Ajuda</button>
                        <button type="button" class="tc-chat-tool" data-chat-insert="@" title="Mencionar alguém"><i class="fa-solid fa-at"></i> Mencionar</button>
                        <button type="button" class="tc-chat-tool" data-chat-insert="/lead " title="Consultar lead"><i class="fa-solid fa-address-card"></i> Lead</button>
                        <button type="button" class="tc-chat-tool" data-chat-insert="/abordagem " title="Criar abordagem comercial"><i class="fa-solid fa-bullhorn"></i> Abordagem</button>
                        <button type="button" class="tc-chat-tool" data-chat-insert="/objecao " title="Responder objeção"><i class="fa-solid fa-comments"></i> Objeção</button>
                        <button type="button" class="tc-chat-tool" data-chat-insert="/ia " title="Consultar a IA e o CRM"><i class="fa-solid fa-wand-magic-sparkles"></i> IA</button>
                    </div>
                    <textarea class="form-control" id="tcChatInput" name="content" rows="1" placeholder="Escreva uma mensagem, mencione @alguém ou use /ajuda" maxlength="2000" autocomplete="off"></textarea>
                    <div id="tcChatComposerSuggestions" class="tc-chat-composer-suggestions d-none"></div>
                </div>
                <button type="submit" class="btn btn-tc-primary" title="Enviar">
                    <i class="fa-solid fa-paper-plane"></i>
                </button>
            </form>
        <?php endif; ?>
    </section>
</div>

<!-- Modal: nova sala de grupo -->
<?php if ($canCreateRoom): ?>
<div class="modal fade" id="tcChatNewGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="tcChatNewGroupForm" action="<?= e(url('chat/salas')) ?>" method="POST">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <h5 class="modal-title">Nova sala de grupo</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Nome da sala</label>
                        <input type="text" name="name" class="form-control" placeholder="Ex: Comercial — Região Sul" required minlength="2" maxlength="120">
                    </div>
                    <p class="tc-chat-modal-hint"><i class="fa-solid fa-shield-halved"></i> Você será administrador da sala e poderá gerenciar os participantes.</p>
                    <div class="mb-2">
                        <label class="form-label">Membros</label>
                        <input type="text" class="form-control form-control-sm mb-2" id="tcChatGroupMemberSearch" placeholder="Buscar colega pelo nome ou e-mail...">
                        <div id="tcChatGroupMemberResults" class="tc-chat-search-results d-none"></div>
                        <div id="tcChatGroupMembersSelected" class="d-flex flex-wrap gap-1 mt-2"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-check me-1"></i> Criar sala</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: convidar participantes (administradores da sala) -->
<?php if ($activeRoom && $activeRoom['type'] === 'grupo' && $canModerateActiveRoom): ?>
<div class="modal fade" id="tcChatInviteMembersModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="tcChatInviteMembersForm" action="<?= e(url('chat/salas/' . $activeRoom['id'] . '/convidar')) ?>" method="POST">
                <?= Csrf::field() ?>
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fa-solid fa-user-plus me-1"></i>Convidar pessoas</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted" style="font-size:0.88rem;">Os convidados terão acesso ao histórico desta sala.</p>
                    <input type="text" class="form-control form-control-sm" id="tcChatInviteMemberSearch" placeholder="Buscar colega pelo nome ou e-mail..." autocomplete="off">
                    <div id="tcChatInviteMemberResults" class="tc-chat-search-results d-none"></div>
                    <div id="tcChatInviteMembersSelected" class="d-flex flex-wrap gap-1 mt-2"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-tc-primary">Enviar convite</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: gestão completa de grupo personalizado -->
<?php if ($activeRoom && $isActiveCustomGroup && $canManageCustomGroup): ?>
<div class="modal fade" id="tcChatGroupManageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa-solid fa-user-gear me-1"></i>Gerenciar grupo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" action="<?= e(url('chat/salas/' . $activeRoom['id'] . '/atualizar')) ?>" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <div class="mb-3">
                        <label class="form-label">Nome do grupo</label>
                        <input type="text" name="name" class="form-control" value="<?= e($activeRoom['name'] ?? '') ?>" required minlength="2" maxlength="120">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <textarea name="description" class="form-control" rows="3" maxlength="1000" placeholder="Explique o objetivo deste grupo."><?= e($activeRoom['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Foto do grupo</label>
                        <input type="file" name="group_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">JPG, PNG ou WEBP, até 2 MB.</div>
                        <?php if ($activeGroupImageUrl): ?>
                            <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_image" value="1"> <span class="form-check-label">Remover foto atual</span></label>
                        <?php endif; ?>
                    </div>
                    <div class="text-end"><button type="submit" class="btn btn-tc-primary"><i class="fa-solid fa-floppy-disk me-1"></i>Salvar informações</button></div>
                </form>

                <hr class="my-4">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <strong>Participantes (<?= count($activeRoomMembers) ?>)</strong>
                    <span class="text-muted small">Remoções ficam registradas no chat.</span>
                </div>
                <div class="list-group list-group-flush border rounded">
                    <?php foreach ($activeRoomMembers as $member): ?>
                        <?php $roleLabel = ['admin_sala' => 'Administrador', 'moderador' => 'Moderador', 'membro' => 'Membro'][$member['role']] ?? 'Membro'; ?>
                        <div class="list-group-item d-flex align-items-center gap-2">
                            <?= tc_chat_user_avatar((string) $member['name'], $member['avatar'] ?? null, 'tc-chat-bubble-avatar') ?>
                            <span class="flex-grow-1 text-truncate"><strong class="d-block text-truncate"><?= e($member['name']) ?></strong><small class="text-muted"><?= e($roleLabel) ?></small></span>
                            <?php if ((int) $member['user_id'] !== (int) $currentUserId): ?>
                                <form method="POST" action="<?= e(url('chat/salas/' . $activeRoom['id'] . '/membros/' . $member['user_id'] . '/remover')) ?>" class="tc-delete-form" data-confirm-text="Remover <?= e($member['name']) ?> deste grupo?">
                                    <?= Csrf::field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Remover participante"><i class="fa-solid fa-user-minus"></i></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <hr class="my-4">
                <form method="POST" action="<?= e(url('chat/salas/' . $activeRoom['id'] . '/excluir')) ?>" class="tc-delete-form" data-confirm-text="Excluir o grupo ‘<?= e($activeRoom['name'] ?? '') ?>’? Todas as mensagens e participantes serão removidos de forma permanente.">
                    <?= Csrf::field() ?>
                    <div class="d-flex align-items-center justify-content-between gap-3">
                        <div><strong class="text-danger">Excluir grupo</strong><div class="small text-muted">Essa ação não pode ser desfeita.</div></div>
                        <button type="submit" class="btn btn-outline-danger"><i class="fa-solid fa-trash me-1"></i>Excluir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: membros da sala -->
<div class="modal fade" id="tcChatMembersModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Membros da sala</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <ul class="list-group list-group-flush" id="tcChatMembersList"></ul>
            </div>
        </div>
    </div>
</div>
