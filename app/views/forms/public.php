<?php
/**
 * app/views/forms/public.php
 * Página pública do formulário (sem login, sem layout do painel). Recebida
 * via GET/POST /f/{slug} — ver FormController::show()/submit(). Se vier com
 * ?consultor=ID (QR Code pessoal do vendedor), mostra quem vai atender.
 *
 * Multi-etapas: os campos já chegam agrupados em $steps (ver
 * Form::groupIntoSteps, quebra onde o campo tem "new_step" marcado no
 * construtor). Sem JS, todas as etapas aparecem numa página só (o formulário
 * continua 100% funcional) — o JS no fim do arquivo é só progressive
 * enhancement, escondendo as etapas não-ativas e cuidando da barra de progresso.
 */
$initials = strtoupper(mb_substr((string) $company, 0, 2));
$theme = $form['theme'] ?? 'padrao';
$validFonts = ['padrao', 'arredondada', 'serifa', 'mono'];
$fontFamilyRaw = $form['font_family'] ?? 'padrao';
$fontFamily = in_array($fontFamilyRaw, $validFonts, true) ? $fontFamilyRaw : 'padrao';
$totalSteps = count($steps);
$formLogo = $form['logo_url'] ?? null;
$coverImage = $form['cover_image_url'] ?? null;
$footerText = $form['footer_text'] ?? null;
$submitLabel = trim((string) ($form['submit_label'] ?? '')) ?: 'Enviar';
$privacyText = trim((string) ($form['privacy_text'] ?? '')) ?: 'Seus dados são usados apenas para contato comercial.';
$tracking = $tracking ?? [];
$publicSignature = $publicSignature ?? null;

$fieldIcons = [
    'name' => 'fa-solid fa-user', 'phone' => 'fa-solid fa-phone', 'whatsapp' => 'fa-brands fa-whatsapp',
    'email' => 'fa-solid fa-envelope', 'cpf' => 'fa-solid fa-id-card', 'city' => 'fa-solid fa-city',
    'state' => 'fa-solid fa-map-location-dot', 'interest' => 'fa-solid fa-star', 'desired_value' => 'fa-solid fa-sack-dollar',
    'profession' => 'fa-solid fa-briefcase', 'income_range' => 'fa-solid fa-chart-line', 'notes' => 'fa-solid fa-note-sticky',
];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-form-theme="<?= e($theme) ?>" data-form-font="<?= e($fontFamily) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <title><?= e($form['name']) ?> · <?= e($company) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
<div class="tc-public-form-page">
    <div class="tc-public-form-card tc-form-fade-in <?= $coverImage ? 'tc-has-cover' : '' ?>">
        <?php if ($coverImage): ?>
            <div class="tc-public-form-cover" style="background-image:url('<?= e($coverImage) ?>')"></div>
        <?php endif; ?>
        <div class="tc-public-form-brand">
            <?php if ($formLogo || !empty($logo)): ?>
                <img src="<?= e($formLogo ?: $logo) ?>" alt="<?= e($company) ?>" style="max-height:40px;">
            <?php else: ?>
                <div class="badge-logo"><?= e($initials) ?></div>
            <?php endif; ?>
            <strong><?= e($company) ?></strong>
        </div>

        <h5 class="mb-1"><?= e($form['name']) ?></h5>
        <?php if (!empty($form['description'])): ?>
            <p class="text-muted mb-3" style="font-size:0.85rem;"><?= e($form['description']) ?></p>
        <?php endif; ?>

        <?php if ($consultant): ?>
            <div class="tc-public-form-consultant">
                <i class="fa-solid fa-user-tie"></i>
                <span>Você será atendido(a) por <strong><?= e($consultant['name']) ?></strong>.</span>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger py-2 tc-form-shake" style="font-size:0.85rem;"><i class="fa-solid fa-circle-exclamation me-1"></i><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($totalSteps > 1): ?>
        <div class="tc-form-progress" id="tcFormProgress" data-total="<?= $totalSteps ?>">
            <div class="tc-form-progress-bar"><div class="tc-form-progress-fill" id="tcFormProgressFill"></div></div>
            <div class="tc-form-progress-label"><span id="tcFormStepLabel">Etapa 1</span> de <?= $totalSteps ?></div>
        </div>
        <?php endif; ?>

        <form method="POST" action="<?= e(url('f/' . $form['slug']) . ($consultant ? '?consultor=' . (int) $consultant['id'] : '')) ?>" id="tcPublicForm" novalidate>
            <?= Csrf::field() ?>
            <?php if ($publicSignature): ?><input type="hidden" name="form_signature" value="<?= e($publicSignature) ?>"><?php endif; ?>
            <?php foreach ($tracking as $trackingKey => $trackingValue): ?><input type="hidden" name="<?= e($trackingKey) ?>" value="<?= e($trackingValue) ?>"><?php endforeach; ?>
            <div class="position-absolute" aria-hidden="true" style="left:-10000px; width:1px; height:1px; overflow:hidden;"><label>Não preencha <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <?php foreach ($steps as $stepIndex => $stepFields): ?>
                <div class="tc-form-step" data-step="<?= $stepIndex ?>">
                    <?php foreach ($stepFields as $field): ?>
                        <?php $val = e($old[$field['key']] ?? ''); $icon = $fieldIcons[$field['key']] ?? 'fa-solid fa-pen'; ?>
                        <div class="mb-3">
                            <label class="form-label"><i class="<?= e($icon) ?> tc-form-field-icon"></i> <?= e($field['label']) ?><?= $field['required'] ? ' *' : '' ?></label>
                            <?php $fieldType = $field['type'] ?? Form::defaultFieldType($field['key']); $placeholder = e($field['placeholder'] ?? ''); ?>
                            <?php if ($fieldType === 'textarea' || $field['key'] === 'notes'): ?>
                                <textarea name="<?= e($field['key']) ?>" class="form-control tc-form-input" rows="3" placeholder="<?= $placeholder ?>" <?= $field['required'] ? 'required' : '' ?>><?= $val ?></textarea>
                            <?php elseif ($field['key'] === 'state'): ?>
                                <select name="state" class="form-select tc-form-input" <?= $field['required'] ? 'required' : '' ?>>
                                    <option value="">Selecione</option>
                                    <?php foreach (brazilian_states() as $uf => $ufName): ?>
                                        <option value="<?= e($uf) ?>" <?= ($old['state'] ?? '') === $uf ? 'selected' : '' ?>><?= e($ufName) ?> (<?= e($uf) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($field['key'] === 'interest'): ?>
                                <select name="interest" class="form-select tc-form-input" <?= $field['required'] ? 'required' : '' ?>>
                                    <option value="">Selecione</option>
                                    <?php foreach (['imovel' => 'Imóvel', 'veiculo' => 'Veículo', 'caminhao' => 'Caminhão', 'moto' => 'Moto', 'maquinario' => 'Maquinário', 'agronegocio' => 'Agronegócio', 'construcao' => 'Construção', 'capital_giro' => 'Capital de Giro', 'investimento' => 'Investimento', 'quitacao' => 'Quitação', 'outros' => 'Outros'] as $val2 => $label2): ?>
                                        <option value="<?= e($val2) ?>" <?= ($old['interest'] ?? '') === $val2 ? 'selected' : '' ?>><?= e($label2) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php elseif ($fieldType === 'select'): ?>
                                <select name="<?= e($field['key']) ?>" class="form-select tc-form-input" <?= $field['required'] ? 'required' : '' ?>><option value="">Selecione</option><?php foreach (($field['options'] ?? []) as $option): ?><option value="<?= e($option) ?>" <?= ($old[$field['key']] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select>
                            <?php elseif ($fieldType === 'checkbox'): ?>
                                <div class="form-check"><input type="checkbox" class="form-check-input" id="formField<?= e($field['key']) ?>" name="<?= e($field['key']) ?>" value="1" <?= !empty($old[$field['key']]) ? 'checked' : '' ?> <?= $field['required'] ? 'required' : '' ?>><label class="form-check-label" for="formField<?= e($field['key']) ?>"><?= $placeholder ?: 'Confirmo esta informação' ?></label></div>
                            <?php elseif (in_array($field['key'], ['phone', 'whatsapp'], true)): ?>
                                <input type="tel" name="<?= e($field['key']) ?>" class="form-control tc-form-input" value="<?= $val ?>" placeholder="<?= $placeholder ?: '(00) 00000-0000' ?>" inputmode="tel" <?= $field['required'] ? 'required' : '' ?>>
                            <?php elseif ($field['key'] === 'email'): ?>
                                <input type="email" name="email" class="form-control tc-form-input" value="<?= $val ?>" placeholder="<?= $placeholder ?>" inputmode="email" <?= $field['required'] ? 'required' : '' ?>>
                            <?php elseif ($field['key'] === 'desired_value'): ?>
                                <input type="text" name="desired_value" class="form-control tc-form-input" value="<?= $val ?>" placeholder="<?= $placeholder ?: 'R$' ?>" inputmode="decimal" <?= $field['required'] ? 'required' : '' ?>>
                            <?php else: ?>
                                <input type="<?= e(in_array($fieldType, ['email','tel','number'], true) ? $fieldType : 'text') ?>" name="<?= e($field['key']) ?>" class="form-control tc-form-input" value="<?= $val ?>" placeholder="<?= $placeholder ?>" <?= $fieldType === 'number' ? 'inputmode="decimal"' : '' ?> <?= $field['required'] ? 'required' : '' ?>>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="tc-form-step-actions">
                        <?php if ($stepIndex > 0): ?>
                            <button type="button" class="btn btn-outline-secondary tc-form-back-btn"><i class="fa-solid fa-arrow-left me-1"></i> Voltar</button>
                        <?php endif; ?>
                        <?php if ($stepIndex < $totalSteps - 1): ?>
                            <button type="button" class="btn tc-form-btn-accent tc-form-next-btn ms-auto">Próximo <i class="fa-solid fa-arrow-right ms-1"></i></button>
                        <?php else: ?>
                            <button type="submit" class="btn tc-form-btn-accent ms-auto"><i class="fa-solid fa-paper-plane me-1"></i> <?= e($submitLabel) ?></button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
        <p class="text-muted text-center mt-3 mb-0" style="font-size:0.7rem;"><?= e($privacyText) ?></p>
        <?php if ($footerText): ?>
            <p class="tc-public-form-footer"><?= e($footerText) ?></p>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('tcPublicForm');
    var steps = Array.prototype.slice.call(document.querySelectorAll('.tc-form-step'));
    if (!form || steps.length < 2) return; // formulário de 1 etapa só: nada a orquestrar

    var current = 0;
    var progressFill = document.getElementById('tcFormProgressFill');
    var stepLabel = document.getElementById('tcFormStepLabel');

    function showStep(index, direction) {
        steps.forEach(function (el, i) {
            el.classList.toggle('active', i === index);
            el.classList.remove('tc-form-slide-in-right', 'tc-form-slide-in-left');
        });
        var activeEl = steps[index];
        activeEl.classList.add(direction === 'back' ? 'tc-form-slide-in-left' : 'tc-form-slide-in-right');
        current = index;
        if (progressFill) progressFill.style.width = (((index + 1) / steps.length) * 100) + '%';
        if (stepLabel) stepLabel.textContent = 'Etapa ' + (index + 1);
        activeEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    steps.forEach(function (el) { el.classList.add('tc-form-step-js'); });
    showStep(0);

    form.addEventListener('click', function (evt) {
        var nextBtn = evt.target.closest('.tc-form-next-btn');
        var backBtn = evt.target.closest('.tc-form-back-btn');

        if (nextBtn) {
            var currentStepEl = steps[current];
            var inputs = currentStepEl.querySelectorAll('input, select, textarea');
            var valid = true;
            inputs.forEach(function (input) {
                if (!input.checkValidity()) {
                    valid = false;
                    input.reportValidity();
                }
            });
            if (!valid) return;
            if (current < steps.length - 1) showStep(current + 1, 'next');
        }

        if (backBtn && current > 0) {
            showStep(current - 1, 'back');
        }
    });
});
</script>
</body>
</html>
