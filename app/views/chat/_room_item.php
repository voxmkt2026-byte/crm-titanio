<?php
/**
 * app/views/chat/_room_item.php
 * Item de sala na lista da coluna esquerda. Espera $room (linha de
 * ChatRoom::roomsForUser) e $activeRoomId no escopo (ver chat/index.php).
 */
$label = tc_chat_room_label($room);
$isActive = (int) $room['id'] === (int) $activeRoomId;
$unread = (int) ($room['unread_count'] ?? 0);
$muted = !empty($room['muted']);
$groupImageUrl = ($room['type'] === 'grupo' && empty($room['lead_id']) && empty($room['task_id']) && !empty($room['image_filename']))
    ? url('uploads/' . rawurlencode($room['image_filename']))
    : null;
?>
<a href="<?= e(url('chat?sala=' . $room['id'])) ?>" class="tc-chat-room-item <?= $isActive ? 'active' : '' ?>" data-room-id="<?= (int) $room['id'] ?>">
    <?php if ($room['type'] === 'direto'): ?>
        <?= tc_chat_user_avatar($label, $room['direct_other_avatar'] ?? null, 'tc-chat-room-avatar') ?>
    <?php else: ?>
    <div class="tc-chat-room-avatar" <?php if ($room['type'] === 'departamento' && !empty($room['department_color'])): ?>style="background:<?= e($room['department_color']) ?>;"<?php endif; ?>>
        <?php if ($groupImageUrl): ?>
            <img src="<?= e($groupImageUrl) ?>" alt="" style="width:100%;height:100%;border-radius:inherit;object-fit:cover;" onerror="this.remove();">
        <?php elseif ($room['type'] === 'departamento'): ?>
            <i class="<?= e($room['department_icon'] ?? 'fa-solid fa-comments') ?>"></i>
        <?php elseif (!empty($room['lead_id'])): ?>
            <i class="fa-solid fa-address-card"></i>
        <?php elseif (!empty($room['task_id'])): ?>
            <i class="fa-solid fa-list-check"></i>
        <?php else: ?>
            <?= e(tc_chat_initials($label)) ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="tc-chat-room-info">
        <div class="tc-chat-room-name">
            <?= e($label) ?>
            <?php if ($muted): ?><i class="fa-solid fa-bell-slash text-muted ms-1" style="font-size:0.7rem;" title="Silenciada"></i><?php endif; ?>
        </div>
        <div class="tc-chat-room-preview"><?= e($room['last_message'] ? mb_strimwidth($room['last_message'], 0, 42, '...') : 'Nenhuma mensagem ainda') ?></div>
    </div>
    <?php if ($unread > 0): ?>
        <span class="badge rounded-pill bg-danger tc-chat-room-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
    <?php endif; ?>
</a>
