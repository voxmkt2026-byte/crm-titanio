<?php
require_once __DIR__ . '/../../services/Calls/PhoneNormalizer.php';
$phoneActionNumber = null;
foreach (['phone', 'whatsapp'] as $phoneActionField) {
    $phoneActionDigits = preg_replace('/\D+/', '', (string)($phoneActionLead[$phoneActionField] ?? ''));
    $phoneActionDdd = preg_replace('/\D+/', '', (string)($phoneActionLead['ddd'] ?? ''));
    if (in_array(strlen($phoneActionDigits), [8, 9], true) && strlen($phoneActionDdd) === 2) {
        $phoneActionDigits = $phoneActionDdd . $phoneActionDigits;
    }
    $phoneActionNumber = PhoneNormalizer::canonical($phoneActionDigits);
    if ($phoneActionNumber !== null) break;
}
if (Auth::can('calls.dial') && $phoneActionNumber !== null):
?>
<button type="button" class="<?= e($phoneActionClass ?? 'btn btn-sm btn-outline-primary') ?> crm-phone-action" data-crm-phone data-lead-id="<?= (int)($phoneActionLead['id'] ?? 0) ?>" data-phone="<?= e($phoneActionNumber) ?>" title="Abrir telefone para ligar" aria-label="Ligar para este contato"><i class="fa-solid fa-phone" aria-hidden="true"></i><span class="visually-hidden"> Ligar</span></button>
<?php endif; unset($phoneActionLead, $phoneActionClass, $phoneActionNumber, $phoneActionField, $phoneActionDigits, $phoneActionDdd); ?>
