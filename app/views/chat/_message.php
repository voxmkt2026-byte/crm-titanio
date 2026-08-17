<?php
/**
 * app/views/chat/_message.php
 * Uma bolha de mensagem (carga inicial via PHP). O mesmo layout é gerado
 * em JS (renderMessage() em app.js) para as mensagens que chegam via
 * polling, para manter os dois caminhos visualmente idênticos.
 * Espera $msg (ver ChatController::formatMessage) e $currentUserId.
 */
$isGrouped = $isGrouped ?? false;
if ($msg['type'] === 'sistema'):
?>
<div class="tc-chat-system-msg" data-message-id="<?= (int) $msg['id'] ?>"><?= e($msg['content'] ?? '') ?></div>
<?php else: ?>
<div class="tc-chat-bubble-row <?= $msg['mine'] ? 'mine' : '' ?> <?= $isGrouped ? 'tc-grouped' : '' ?>" data-message-id="<?= (int) $msg['id'] ?>" data-user-id="<?= $msg['user_id'] !== null ? (int) $msg['user_id'] : '' ?>">
    <?php if (!$msg['mine']): ?>
        <?= tc_chat_user_avatar((string) $msg['user_name'], $msg['avatar'] ?? null, 'tc-chat-bubble-avatar') ?>
    <?php endif; ?>
    <div class="tc-chat-bubble">
        <?php if (!$msg['mine'] && !$isGrouped): ?>
            <div class="tc-chat-bubble-author"><?= e($msg['user_name']) ?></div>
        <?php endif; ?>
        <div class="tc-chat-bubble-content">
            <?php if ($msg['deleted']): ?>
                <em class="text-muted"><i class="fa-solid fa-ban me-1"></i>Mensagem apagada</em>
            <?php else: ?>
                <?= nl2br(e($msg['content'])) ?>
            <?php endif; ?>
        </div>
        <div class="tc-chat-bubble-meta">
            <?= e($msg['time']) ?>
            <?php if ($msg['edited']): ?><span class="text-muted">(editada)</span><?php endif; ?>
            <?php if ($msg['mine'] && !$msg['deleted']): ?>
                <button type="button" class="tc-chat-delete-btn" data-message-id="<?= (int) $msg['id'] ?>" title="Apagar mensagem">
                    <i class="fa-solid fa-trash"></i>
                </button>
            <?php elseif (!$msg['mine'] && !$msg['deleted'] && !empty($canModerateActiveRoom)): ?>
                <button type="button" class="tc-chat-delete-btn" data-message-id="<?= (int) $msg['id'] ?>" title="Apagar mensagem (moderação)">
                    <i class="fa-solid fa-trash"></i>
                </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
