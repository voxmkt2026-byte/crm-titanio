<?php
/**
 * app/views/evolution/_message.php
 * Uma bolha de mensagem do atendimento WhatsApp (Evolution/EvoAI CRM).
 * Espera $msg (ver EvolutionInboxController::normalizeMessages): id, content,
 * type (incoming|outgoing), private, created_at, time, sender.
 * O mesmo layout é gerado em JS (renderEvoMessage() em evolution.js) para
 * as mensagens que chegam via polling.
 */
$isOutgoing = ($msg['type'] ?? 'incoming') === 'outgoing';
$isPrivate = !empty($msg['private']);
$isGrouped = $isGrouped ?? false;
$senderKey = $senderKey ?? ($msg['type'] . ':' . ($msg['user_id'] ?? ($isPrivate ? 'note' : '')));
?>
<div class="tc-chat-bubble-row <?= $isOutgoing ? 'mine' : '' ?> <?= $isPrivate ? 'tc-evo-note' : '' ?> <?= $isGrouped ? 'tc-grouped' : '' ?>" data-message-id="<?= e((string) $msg['id']) ?>" data-sender-key="<?= e($senderKey) ?>">
    <?php if (!$isOutgoing): ?>
        <div class="tc-chat-bubble-avatar"><i class="fa-brands fa-whatsapp"></i></div>
    <?php endif; ?>
    <div class="tc-chat-bubble">
        <?php if ($isPrivate && !$isGrouped): ?>
            <div class="tc-chat-bubble-author"><i class="fa-solid fa-note-sticky me-1"></i>Nota interna<?= $msg['sender'] !== '' ? ' — ' . e($msg['sender']) : '' ?></div>
        <?php elseif ($isOutgoing && $msg['sender'] !== '' && !$isGrouped): ?>
            <div class="tc-chat-bubble-author"><?= e($msg['sender']) ?></div>
        <?php elseif (!$isOutgoing && !$isPrivate && !$isGrouped): ?>
            <?php $customerName = $msg['sender'] !== '' ? $msg['sender'] : ($active['name'] ?? ''); ?>
            <?php if ($customerName !== '' && $customerName !== 'Contato WhatsApp'): ?>
                <div class="tc-chat-bubble-author tc-evo-customer-name"><?= e($customerName) ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="tc-chat-bubble-content"><?= nl2br(e($msg['content'])) ?></div>
        <div class="tc-chat-bubble-meta">
            <?= e($msg['time']) ?>
            <?php if ($isPrivate): ?>
                <button type="button" class="tc-chat-delete-btn tc-evo-delete-note" data-message-id="<?= e((string) $msg['id']) ?>" title="Remover nota interna"><i class="fa-solid fa-trash"></i></button>
            <?php endif; ?>
        </div>
    </div>
</div>
