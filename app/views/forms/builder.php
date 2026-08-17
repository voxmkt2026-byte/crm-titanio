<?php
/**
 * app/views/forms/builder.php
 * Criar/editar formulário: campos arrastáveis (SortableJS, já usado no
 * Kanban) — arraste um campo do catálogo à esquerda para a lista do
 * formulário à direita, reordene arrastando dentro da lista, remova com o "x".
 */
$isEdit = !empty($form);
$catalog = Form::FIELD_CATALOG;
$usedKeys = array_column($fields, 'key');
?>

<form method="POST" action="<?= e($formAction) ?>" id="tcFormBuilder" enctype="multipart/form-data">
    <?= Csrf::field() ?>
    <input type="hidden" name="slug" value="<?= e($form['slug'] ?? '') ?>">

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="tc-card mb-3">
                <div class="tc-card-header">Dados do formulário</div>
                <div class="tc-card-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Nome do formulário</label>
                            <input type="text" name="name" class="form-control" value="<?= e($form['name'] ?? '') ?>" required placeholder="Ex: Captação Instagram">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="active" value="1" <?= (!$isEdit || !empty($form['active'])) ? 'checked' : '' ?>>
                                <label class="form-check-label">Ativo (recebendo leads)</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição <span class="text-muted">(interna, não aparece no formulário)</span></label>
                            <input type="text" name="description" class="form-control" value="<?= e($form['description'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="tc-card mb-3">
                <div class="tc-card-header">
                    <i class="fa-solid fa-arrows-up-down-left-right me-1"></i> Campos do formulário
                    <small class="text-muted d-block mt-1" style="font-size:0.72rem;">Arraste um campo da lista abaixo para dentro do formulário. Arraste dentro da lista para reordenar.</small>
                </div>
                <div class="tc-card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <div class="tc-form-palette-label">Campos disponíveis</div>
                            <ul class="list-group tc-form-palette" id="tcFieldPalette">
                                <?php foreach ($catalog as $key => $label): ?>
                                    <li class="list-group-item tc-form-palette-item" data-key="<?= e($key) ?>" data-label="<?= e($label) ?>">
                                        <i class="fa-solid fa-grip-vertical text-muted me-2"></i><?= e($label) ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div class="col-md-7">
                            <div class="tc-form-palette-label">No formulário <span class="text-muted">(campo "Nome" é sempre recomendado)</span></div>
                            <ul class="list-group tc-form-canvas" id="tcFieldCanvas">
                                <?php foreach ($fields as $i => $f): ?>
                                    <?php $isCustomField = str_starts_with($f['key'], 'custom_'); $fieldType = $f['type'] ?? Form::defaultFieldType($f['key']); ?>
                                    <li class="list-group-item tc-form-canvas-item" data-key="<?= e($f['key']) ?>">
                                        <i class="fa-solid fa-grip-vertical text-muted"></i>
                                        <div class="flex-grow-1">
                                            <?php if ($isCustomField): ?><div class="row g-1 mb-1"><div class="col-md-5"><input type="text" name="field_key[]" class="form-control form-control-sm" value="<?= e($f['key']) ?>" pattern="custom_[a-z0-9_]{2,48}" title="Use custom_nome_do_campo"></div><div class="col-md-7"><select name="field_type[]" class="form-select form-select-sm tc-custom-field-type"><?php foreach (Form::FIELD_TYPES as $type): ?><option value="<?= e($type) ?>" <?= $fieldType === $type ? 'selected' : '' ?>><?= e(ucfirst($type)) ?></option><?php endforeach; ?></select></div></div><?php else: ?><input type="hidden" name="field_key[]" value="<?= e($f['key']) ?>"><input type="hidden" name="field_type[]" value="<?= e($fieldType) ?>"><?php endif; ?>
                                            <input type="text" name="field_label[]" class="form-control form-control-sm mb-1" value="<?= e($f['label']) ?>">
                                            <div class="tc-custom-field-details <?= $isCustomField ? '' : 'd-none' ?>"><input type="text" name="field_placeholder[]" class="form-control form-control-sm mb-1" value="<?= e($f['placeholder'] ?? '') ?>" placeholder="Texto de ajuda / placeholder"><input type="text" name="field_options[]" class="form-control form-control-sm mb-1" value="<?= e(implode(', ', $f['options'] ?? [])) ?>" placeholder="Opções, separadas por vírgula (para lista)"></div><?php if (!$isCustomField): ?><input type="hidden" name="field_placeholder[]" value="<?= e($f['placeholder'] ?? '') ?>"><input type="hidden" name="field_options[]" value="<?= e(implode(', ', $f['options'] ?? [])) ?>"><?php endif; ?>
                                            <label class="form-check form-check-inline" style="font-size:0.75rem;">
                                                <input type="checkbox" class="form-check-input" name="field_required[]" value="<?= e($f['key']) ?>" <?= $f['required'] ? 'checked' : '' ?>>
                                                Obrigatório
                                            </label>
                                            <?php if ($i > 0): ?>
                                            <label class="form-check form-check-inline" style="font-size:0.75rem;" title="O formulário vira várias etapas, com barra de progresso, quebrando aqui">
                                                <input type="checkbox" class="form-check-input" name="field_new_step[]" value="<?= e($f['key']) ?>" <?= !empty($f['new_step']) ? 'checked' : '' ?>>
                                                <i class="fa-solid fa-shoe-prints"></i> Nova etapa aqui
                                            </label>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="btn btn-sm btn-outline-danger tc-form-remove-field" title="Remover"><i class="fa-solid fa-xmark"></i></button>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="tc-form-canvas-empty text-muted text-center py-3 <?= !empty($fields) ? 'd-none' : '' ?>" id="tcFieldCanvasEmpty" style="font-size:0.8rem; border:1px dashed var(--tc-border); border-radius:0.5rem;">
                                Arraste campos aqui
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="tcAddCustomField"><i class="fa-solid fa-plus"></i> Campo personalizado</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="tc-card mb-3">
                <div class="tc-card-header">Destino do lead</div>
                <div class="tc-card-body">
                    <div class="mb-3">
                        <label class="form-label">Interesse padrão <span class="text-muted">(se o formulário não perguntar)</span></label>
                        <select name="default_interest" class="form-select">
                            <option value="">Nenhum</option>
                            <?php foreach (['imovel' => 'Imóvel', 'veiculo' => 'Veículo', 'caminhao' => 'Caminhão', 'moto' => 'Moto', 'maquinario' => 'Maquinário', 'agronegocio' => 'Agronegócio', 'construcao' => 'Construção', 'capital_giro' => 'Capital de Giro', 'investimento' => 'Investimento', 'quitacao' => 'Quitação', 'outros' => 'Outros'] as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($form['default_interest'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Fonte padrão do lead</label>
                        <select name="default_source" class="form-select">
                            <?php foreach (Form::SOURCE_CATALOG as $sourceKey => $sourceLabel): ?><option value="<?= e($sourceKey) ?>" <?= ($form['default_source'] ?? 'landing_page') === $sourceKey ? 'selected' : '' ?>><?= e($sourceLabel) ?></option><?php endforeach; ?>
                        </select>
                        <div class="form-text">Mantém origem e indicadores corretos. Pela API, a origem é sempre <code>api</code>.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Responsável padrão</label>
                        <select name="default_assigned_to" class="form-select">
                            <option value="">Sem responsável (fica na fila)</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= (int) ($form['default_assigned_to'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">O link com <code>?consultor=ID</code> (usado pelo QR Code pessoal, ver Perfil) sobrepõe este campo.</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="notify_assignee" value="1" <?= (!$isEdit || !empty($form['notify_assignee'])) ? 'checked' : '' ?>>
                        <label class="form-check-label">Notificar o responsável quando chegar um lead</label>
                    </div>
                </div>
            </div>

            <div class="tc-card mb-3">
                <div class="tc-card-header"><i class="fa-solid fa-palette me-1"></i> Aparência da página pública</div>
                <div class="tc-card-body">
                    <label class="form-label small fw-semibold">Cor do tema</label>
                    <?php $currentTheme = $form['theme'] ?? 'padrao'; ?>
                    <div class="tc-form-theme-swatches mb-3">
                        <?php foreach ([
                            'padrao' => '#3b82f6', 'whatsapp' => '#16a34a', 'azul' => '#2563eb', 'roxo' => '#7c3aed',
                            'grafite' => '#475569', 'rosa' => '#db2777', 'esmeralda' => '#059669', 'laranja' => '#ea580c',
                        ] as $themeKey => $color): ?>
                            <label class="tc-form-theme-swatch" style="background:<?= e($color) ?>" title="<?= e(ucfirst($themeKey)) ?>">
                                <input type="radio" name="theme" value="<?= e($themeKey) ?>" class="d-none" <?= $currentTheme === $themeKey ? 'checked' : '' ?>>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label small fw-semibold">Tipografia</label>
                    <?php $currentFont = $form['font_family'] ?? 'padrao'; ?>
                    <select name="font_family" class="form-select form-select-sm mb-1" id="tcFormFontSelect">
                        <option value="padrao" <?= $currentFont === 'padrao' ? 'selected' : '' ?>>Padrão (limpa e neutra)</option>
                        <option value="arredondada" <?= $currentFont === 'arredondada' ? 'selected' : '' ?>>Arredondada (amigável)</option>
                        <option value="serifa" <?= $currentFont === 'serifa' ? 'selected' : '' ?>>Serifada (clássica, transmite confiança)</option>
                        <option value="mono" <?= $currentFont === 'mono' ? 'selected' : '' ?>>Monoespaçada (técnica)</option>
                    </select>
                    <p class="tc-form-font-preview" id="tcFormFontPreview" data-font="<?= e($currentFont) ?>">Assim fica o texto do formulário.</p>
                </div>
            </div>

            <div class="tc-card mb-3">
                <div class="tc-card-header"><i class="fa-solid fa-image me-1"></i> Logo e imagem de capa <span class="badge bg-secondary ms-1" style="font-size:0.6rem;">opcional</span></div>
                <div class="tc-card-body">
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Logo do formulário</label>
                            <?php if (!empty($form['logo_url'])): ?>
                                <div class="mb-2"><img src="<?= e($form['logo_url']) ?>" alt="Logo" style="max-height:36px;"></div>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="remove_logo" value="1" id="tcRemoveLogo">
                                    <label class="form-check-label" for="tcRemoveLogo" style="font-size:0.75rem;">Remover logo</label>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-1" style="font-size:0.72rem;">Sem logo — usa o logo da empresa (Configurações).</p>
                            <?php endif; ?>
                            <input type="file" name="logo" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Imagem de capa <span class="text-muted">(topo da página)</span></label>
                            <?php if (!empty($form['cover_image_url'])): ?>
                                <div class="mb-2"><img src="<?= e($form['cover_image_url']) ?>" alt="Capa" style="max-height:36px; border-radius:0.3rem;"></div>
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="checkbox" name="remove_cover_image" value="1" id="tcRemoveCover">
                                    <label class="form-check-label" for="tcRemoveCover" style="font-size:0.75rem;">Remover capa</label>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-1" style="font-size:0.72rem;">Sem imagem de capa (opcional, ex: foto de um imóvel/produto).</p>
                            <?php endif; ?>
                            <input type="file" name="cover_image" class="form-control form-control-sm" accept="image/png,image/jpeg,image/webp">
                        </div>
                    </div>
                </div>
            </div>

            <div class="tc-card mb-3">
                <div class="tc-card-header">Mensagem de confirmação</div>
                <div class="tc-card-body">
                    <textarea name="success_message" class="form-control" rows="3" placeholder="Recebemos seu contato! Em breve alguém vai falar com você."><?= e($form['success_message'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="tc-card mb-3">
                <div class="tc-card-header"><i class="fa-solid fa-wand-magic-sparkles me-1"></i> Experiência após o envio</div>
                <div class="tc-card-body">
                    <div class="mb-3"><label class="form-label small fw-semibold">Texto do botão</label><input type="text" name="submit_label" class="form-control" maxlength="80" value="<?= e($form['submit_label'] ?? '') ?>" placeholder="Enviar"></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Texto de privacidade</label><input type="text" name="privacy_text" class="form-control" maxlength="255" value="<?= e($form['privacy_text'] ?? '') ?>" placeholder="Seus dados são usados apenas para contato comercial."></div>
                    <div><label class="form-label small fw-semibold">Redirecionar após o envio <span class="text-muted">(opcional)</span></label><input type="url" name="redirect_url" class="form-control" value="<?= e($form['redirect_url'] ?? '') ?>" placeholder="https://seusite.com.br/obrigado"><div class="form-text">Se preenchido, substitui a página de confirmação do CRM.</div></div>
                </div>
            </div>

            <div class="tc-card mb-3" id="integracoes">
                <div class="tc-card-header"><i class="fa-solid fa-plug-circle-bolt me-1"></i> Integrações, API e embed</div>
                <div class="tc-card-body">
                    <div class="mb-3"><label class="form-label small fw-semibold">Domínios autorizados para CORS <span class="text-muted">(um por linha)</span></label><textarea name="allowed_origins" class="form-control" rows="2" placeholder="https://www.sualandingpage.com.br&#10;https://app.parceiro.com"><?= e($form['allowed_origins'] ?? '') ?></textarea><div class="form-text">Necessário apenas para chamar a API diretamente do navegador. Deixe em branco para integrações servidor a servidor. Use <code>*</code> somente em ambientes públicos controlados.</div></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Webhook de saída <span class="text-muted">(opcional)</span></label><input type="url" name="webhook_url" class="form-control mb-2" value="<?= e($form['webhook_url'] ?? '') ?>" placeholder="https://sistema-parceiro.com/webhooks/leads"><input type="password" name="webhook_secret" class="form-control" placeholder="Segredo para assinar o webhook (deixe vazio para manter)"><?php if (!empty($form['webhook_secret'])): ?><label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="clear_webhook_secret" value="1"> <span class="form-check-label">Remover segredo atual</span></label><?php endif; ?><div class="form-text">O CRM envia <code>POST</code> assinado com <code>X-Form-Signature</code> após cada captura. Apenas URLs HTTPS são aceitas.</div></div>
                    <?php if ($isEdit): ?>
                    <?php $embedUrl = url('f/' . $form['slug']); $apiUrl = url('api/v1/forms/' . $form['slug'] . '/leads'); ?>
                    <hr><label class="form-label small fw-semibold">Incorpore em qualquer landing page</label><div class="input-group input-group-sm mb-2"><input id="tcEmbedCode" class="form-control" readonly value='&lt;iframe src="<?= e($embedUrl) ?>" width="100%" height="720" style="border:0;border-radius:12px" loading="lazy"&gt;&lt;/iframe&gt;'><button type="button" class="btn btn-outline-secondary tc-copy-integration" data-target="tcEmbedCode"><i class="fa-solid fa-copy"></i></button></div><div class="form-text mb-3">O embed não expõe chave e funciona como formulário hospedado pelo CRM.</div>
                    <label class="form-label small fw-semibold">API de captação</label><div class="input-group input-group-sm mb-2"><input id="tcApiEndpoint" class="form-control" readonly value="<?= e($apiUrl) ?>"><button type="button" class="btn btn-outline-secondary tc-copy-integration" data-target="tcApiEndpoint"><i class="fa-solid fa-copy"></i></button></div>
                    <div class="d-flex align-items-center gap-2"><button type="button" class="btn btn-sm btn-outline-primary" id="tcRotateFormApiKey" data-url="<?= e(url('formularios/' . $form['id'] . '/api-key')) ?>"><i class="fa-solid fa-key"></i> <?= !empty($form['api_key_hash']) ? 'Rotacionar chave' : 'Gerar chave de API' ?></button><?php if (!empty($form['api_key_last4'])): ?><small class="text-muted">Chave ativa terminando em <code><?= e($form['api_key_last4']) ?></code></small><?php else: ?><small class="text-muted">Nenhuma chave ativa.</small><?php endif; ?></div><div class="alert alert-warning py-2 px-3 mt-2 mb-0 d-none" id="tcApiKeyOnce"><strong>Copie agora:</strong> <code></code><button type="button" class="btn btn-sm btn-outline-dark ms-2 tc-copy-integration" data-target="tcApiKeyOnceValue">Copiar</button><input id="tcApiKeyOnceValue" class="visually-hidden"></div>
                    <details class="mt-3"><summary class="small fw-semibold">Exemplo de integração</summary><pre class="small bg-light border rounded p-2 mt-2 mb-0"><code>curl -X POST <?= e($apiUrl) ?> \
  -H "Authorization: Bearer SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"name":"Ana","whatsapp":"5511999999999","utm_source":"parceiro"}'</code></pre></details>
                    <?php if (!empty($submissionEvents)): ?><div class="mt-3 border-top pt-3"><div class="d-flex justify-content-between align-items-center mb-2"><span class="small fw-semibold">Últimas entradas integradas</span><span class="text-muted" style="font-size:.68rem;">auditoria de captura e webhook</span></div><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:.72rem;"><thead><tr><th>Canal</th><th>Lead</th><th>Status</th><th>Webhook</th><th>Data</th></tr></thead><tbody><?php foreach ($submissionEvents as $event): ?><tr><td><?= e($event['channel'] === 'api' ? 'API' : 'Página') ?></td><td><?= e($event['lead_name'] ?: ($event['lead_code'] ?: '—')) ?></td><td><span class="badge bg-<?= $event['status'] === 'created' ? 'success' : ($event['status'] === 'duplicate' ? 'warning text-dark' : 'danger') ?>"><?= e($event['status']) ?></span></td><td><?= e($event['webhook_status']) ?><?= $event['webhook_response_code'] ? ' (' . (int) $event['webhook_response_code'] . ')' : '' ?></td><td><?= e(format_date($event['created_at'], true)) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
                    <?php else: ?><div class="alert alert-info py-2 mb-0" style="font-size:.78rem;"><i class="fa-solid fa-circle-info me-1"></i> Salve o formulário primeiro para gerar a chave da API, copiar o código de embed e obter o endpoint.</div><?php endif; ?>
                </div>
            </div>

            <div class="tc-card mb-3">
                <div class="tc-card-header">Rodapé <span class="badge bg-secondary ms-1" style="font-size:0.6rem;">opcional</span></div>
                <div class="tc-card-body">
                    <input type="text" name="footer_text" class="form-control" maxlength="255" placeholder="Ex: © 2026 Sua Empresa · CRECI 00000" value="<?= e($form['footer_text'] ?? '') ?>">
                </div>
            </div>

            <?php if ($isEdit): ?>
            <div class="tc-insight-card mb-3">
                <i class="fa-solid fa-link"></i>
                <span>Link público: <code><?= e(url('f/' . $form['slug'])) ?></code></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex justify-content-between">
        <a href="<?= e(url('formularios')) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Cancelar</a>
        <button type="submit" class="btn btn-tc-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Salvar Formulário</button>
    </div>
</form>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var palette = document.getElementById('tcFieldPalette');
    var canvas = document.getElementById('tcFieldCanvas');
    var canvasEmpty = document.getElementById('tcFieldCanvasEmpty');
    var catalog = <?= json_encode($catalog, JSON_UNESCAPED_UNICODE) ?>;
    if (!palette || !canvas || typeof Sortable === 'undefined') return;

    function toggleEmptyState() {
        canvasEmpty.classList.toggle('d-none', canvas.children.length > 0);
    }

    function fieldNode(key, label, required) {
        var li = document.createElement('li');
        li.className = 'list-group-item tc-form-canvas-item';
        li.setAttribute('data-key', key);
        li.innerHTML =
            '<i class="fa-solid fa-grip-vertical text-muted"></i>' +
            '<div class="flex-grow-1">' +
                '<input type="hidden" name="field_key[]" value="' + key + '">' +
                '<input type="hidden" name="field_type[]" value="' + (key === 'notes' ? 'textarea' : (key === 'email' ? 'email' : ((key === 'phone' || key === 'whatsapp') ? 'tel' : (key === 'desired_value' ? 'number' : 'text')))) + '">' +
                '<input type="text" name="field_label[]" class="form-control form-control-sm mb-1" value="' + label + '">' +
                '<input type="hidden" name="field_placeholder[]" value="">' +
                '<input type="hidden" name="field_options[]" value="">' +
                '<label class="form-check form-check-inline" style="font-size:0.75rem;">' +
                    '<input type="checkbox" class="form-check-input" name="field_required[]" value="' + key + '"' + (required ? ' checked' : '') + '>' +
                    ' Obrigatório' +
                '</label>' +
                '<label class="form-check form-check-inline" style="font-size:0.75rem;" title="O formulário vira várias etapas, com barra de progresso, quebrando aqui (ignorado se este for o 1º campo)">' +
                    '<input type="checkbox" class="form-check-input" name="field_new_step[]" value="' + key + '">' +
                    ' <i class="fa-solid fa-shoe-prints"></i> Nova etapa aqui' +
                '</label>' +
            '</div>' +
            '<button type="button" class="btn btn-sm btn-outline-danger tc-form-remove-field" title="Remover"><i class="fa-solid fa-xmark"></i></button>';
        return li;
    }

    function customFieldNode() {
        var index = canvas.querySelectorAll('[data-key^="custom_"]').length + 1;
        var key = 'custom_resposta_' + index;
        var li = document.createElement('li');
        li.className = 'list-group-item tc-form-canvas-item';
        li.setAttribute('data-key', key);
        li.innerHTML =
            '<i class="fa-solid fa-grip-vertical text-muted"></i>' +
            '<div class="flex-grow-1">' +
                '<div class="row g-1 mb-1"><div class="col-md-5"><input type="text" name="field_key[]" class="form-control form-control-sm" value="' + key + '" pattern="custom_[a-z0-9_]{2,48}" title="Use custom_nome_do_campo"></div>' +
                '<div class="col-md-7"><select name="field_type[]" class="form-select form-select-sm tc-custom-field-type"><option value="text">Texto</option><option value="textarea">Texto longo</option><option value="select">Lista de opções</option><option value="email">E-mail</option><option value="tel">Telefone</option><option value="number">Número</option><option value="checkbox">Confirmação</option></select></div></div>' +
                '<input type="text" name="field_label[]" class="form-control form-control-sm mb-1" value="Nova pergunta">' +
                '<div class="tc-custom-field-details"><input type="text" name="field_placeholder[]" class="form-control form-control-sm mb-1" placeholder="Texto de ajuda / placeholder"><input type="text" name="field_options[]" class="form-control form-control-sm mb-1" placeholder="Opções, separadas por vírgula (para lista)"></div>' +
                '<label class="form-check form-check-inline" style="font-size:0.75rem;"><input type="checkbox" class="form-check-input" name="field_required[]" value="' + key + '"> Obrigatório</label>' +
                '<label class="form-check form-check-inline" style="font-size:0.75rem;"><input type="checkbox" class="form-check-input" name="field_new_step[]" value="' + key + '"> <i class="fa-solid fa-shoe-prints"></i> Nova etapa aqui</label>' +
            '</div><button type="button" class="btn btn-sm btn-outline-danger tc-form-remove-field" title="Remover"><i class="fa-solid fa-xmark"></i></button>';
        return li;
    }

    // Arrastar da paleta para o canvas: usa Sortable com grupo compartilhado
    // e "pull: clone" (a paleta nunca esvazia, sempre pode reaproveitar o mesmo campo).
    new Sortable(palette, {
        group: { name: 'tcFormFields', pull: 'clone', put: false },
        sort: false,
        animation: 150,
    });

    new Sortable(canvas, {
        group: 'tcFormFields',
        animation: 150,
        handle: '.fa-grip-vertical',
        onAdd: function (evt) {
            var item = evt.item;
            var key = item.getAttribute('data-key');
            // Evita campo duplicado: se já existir no canvas, remove a cópia recém-solta.
            var existing = canvas.querySelectorAll('[data-key="' + key + '"]');
            if (existing.length > 1) {
                item.remove();
            } else {
                var label = item.getAttribute('data-label') || catalog[key] || key;
                var fresh = fieldNode(key, label, false);
                item.replaceWith(fresh);
            }
            toggleEmptyState();
        },
    });

    canvas.addEventListener('click', function (evt) {
        var btn = evt.target.closest('.tc-form-remove-field');
        if (!btn) return;
        btn.closest('.tc-form-canvas-item').remove();
        toggleEmptyState();
    });

    var addCustomField = document.getElementById('tcAddCustomField');
    if (addCustomField) {
        addCustomField.addEventListener('click', function () {
            canvas.appendChild(customFieldNode());
            toggleEmptyState();
        });
    }

    canvas.addEventListener('change', function (evt) {
        var typeSelect = evt.target.closest('.tc-custom-field-type');
        if (typeSelect) {
            var details = typeSelect.closest('.flex-grow-1').querySelector('.tc-custom-field-details');
            if (details) details.querySelector('[name="field_options[]"]').classList.toggle('d-none', typeSelect.value !== 'select');
        }
        var keyInput = evt.target.closest('input[name="field_key[]"]');
        if (keyInput && keyInput.closest('.tc-form-canvas-item').querySelector('.tc-custom-field-type')) {
            var normalized = keyInput.value.toLowerCase().trim().replace(/[^a-z0-9_]/g, '_');
            keyInput.value = normalized;
            var row = keyInput.closest('.tc-form-canvas-item');
            row.dataset.key = normalized;
            row.querySelectorAll('input[name="field_required[]"], input[name="field_new_step[]"]').forEach(function (input) { input.value = normalized; });
        }
    });

    // Seletor de cor do tema: mantém a classe .active sincronizada com o rádio marcado
    // (a borda em si já reage sozinha via CSS :has(), isto é só reforço para navegadores mais antigos).
    var themeSwatches = document.querySelectorAll('.tc-form-theme-swatch');
    function syncThemeSwatches() {
        themeSwatches.forEach(function (label) {
            var input = label.querySelector('input');
            label.classList.toggle('active', !!(input && input.checked));
        });
    }
    themeSwatches.forEach(function (label) {
        label.addEventListener('click', function () {
            var input = label.querySelector('input');
            if (input) input.checked = true;
            syncThemeSwatches();
        });
    });
    syncThemeSwatches();

    var fontSelect = document.getElementById('tcFormFontSelect');
    var fontPreview = document.getElementById('tcFormFontPreview');
    if (fontSelect && fontPreview) {
        fontSelect.addEventListener('change', function () {
            fontPreview.setAttribute('data-font', fontSelect.value);
        });
    }

    document.getElementById('tcFormBuilder').addEventListener('submit', function (evt) {
        if (!canvas.children.length) {
            evt.preventDefault();
            if (typeof Swal !== 'undefined') {
                Swal.fire('Atenção', 'Adicione pelo menos um campo ao formulário (arraste da lista à esquerda).', 'warning');
            } else {
                alert('Adicione pelo menos um campo ao formulário.');
            }
        }
    });

    toggleEmptyState();
    canvas.querySelectorAll('.tc-custom-field-type').forEach(function (select) {
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    function copyText(value) {
        if (!value) return;
        navigator.clipboard.writeText(value).then(function () {
            if (window.Toastify) Toastify({ text: 'Copiado!', duration: 1800, gravity: 'top', position: 'right', style: { background: '#16a34a', borderRadius: '.5rem' } }).showToast();
        });
    }
    document.querySelectorAll('.tc-copy-integration').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = document.getElementById(button.dataset.target);
            copyText(input ? input.value : '');
        });
    });
    var rotate = document.getElementById('tcRotateFormApiKey');
    if (!rotate) return;
    rotate.addEventListener('click', function () {
        if (!window.confirm('Gerar uma nova chave? A chave anterior deixará de funcionar imediatamente.')) return;
        rotate.disabled = true;
        var csrf = document.querySelector('#tcFormBuilder input[name="csrf_token"]');
        fetch(rotate.dataset.url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json' }, body: new URLSearchParams({ csrf_token: csrf ? csrf.value : '' }).toString() })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) throw new Error(data.message || 'Não foi possível gerar a chave.');
                var box = document.getElementById('tcApiKeyOnce');
                var keyInput = document.getElementById('tcApiKeyOnceValue');
                box.querySelector('code').textContent = data.api_key;
                keyInput.value = data.api_key;
                box.classList.remove('d-none');
                rotate.innerHTML = '<i class="fa-solid fa-key"></i> Rotacionar chave';
            }).catch(function (error) { window.alert(error.message || 'Não foi possível gerar a chave.'); })
            .finally(function () { rotate.disabled = false; });
    });
});
</script>
