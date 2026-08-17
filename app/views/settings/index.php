<?php
/**
 * app/views/settings/index.php
 * Formulário de configurações gerais do sistema (tabela settings).
 */
$s = fn($key, $default = '') => e($settings[$key] ?? $default);
?>

<div class="d-flex justify-content-end mb-3">
    <a href="<?= e(url('configuracoes/whatsapp-templates')) ?>" class="btn btn-outline-success btn-sm">
        <i class="fa-brands fa-whatsapp me-1"></i> Templates de WhatsApp
    </a>
    <a href="<?= e(url('configuracoes/email-templates')) ?>" class="btn btn-outline-primary btn-sm ms-2">
        <i class="fa-solid fa-envelope me-1"></i> Templates de E-mail
    </a>
</div>

<form method="POST" action="<?= e(url('configuracoes/update')) ?>" enctype="multipart/form-data">
    <?= Csrf::field() ?>

    <div class="tc-card mb-3">
        <div class="tc-card-header">Identidade da empresa</div>
        <div class="tc-card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Nome da empresa</label>
                    <input type="text" name="company_name" class="form-control" value="<?= $s('company_name') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Logo</label>
                    <input type="file" name="company_logo" class="form-control" accept="image/*">
                    <?php if (!empty($settings['company_logo'])): ?>
                        <div class="mt-2"><img src="<?= e($settings['company_logo']) ?>" alt="Logo atual" style="max-height:40px;max-width:100%;" onerror="this.remove();"></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Favicon</label>
                    <input type="file" name="company_favicon" class="form-control" accept="image/*,.ico">
                    <?php if (!empty($settings['company_favicon'])): ?>
                        <div class="mt-2"><img src="<?= e($settings['company_favicon']) ?>" alt="Favicon atual" style="max-height:24px;max-width:100%;" onerror="this.remove();"></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-header"><i class="fa-solid fa-wand-magic-sparkles me-1"></i> Assistente Gemini IA</div>
        <div class="tc-card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label class="form-label">API Key da Gemini</label>
                    <div class="input-group">
                        <input type="password" name="gemini_api_key" id="tcGeminiKey" class="form-control" value="" placeholder="<?= !empty($settings['gemini_api_key']) ? 'Chave configurada — deixe vazio para manter' : 'Cole aqui uma nova chave segura' ?>" autocomplete="new-password">
                        <button type="button" class="btn btn-outline-secondary tc-secret-toggle" data-target="tcGeminiKey"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <div class="form-text">A chave fica disponível somente no servidor e nunca é enviada ao navegador.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Modelo</label>
                    <input type="text" name="gemini_model" class="form-control" value="<?= $s('gemini_model', 'gemini-3.6-flash') ?>">
                </div>
                <div class="col-md-2"><span class="badge bg-<?= !empty($settings['gemini_api_key']) ? 'success' : 'secondary' ?> p-2 w-100"><?= !empty($settings['gemini_api_key']) ? 'Configurada' : 'Não configurada' ?></span></div>
            </div>
            <div class="tc-insight-card mt-3"><i class="fa-solid fa-shield-halved"></i><span>Use uma chave nova. A chave anteriormente publicada deve permanecer revogada.</span></div>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-header">SMTP (envio de e-mails)</div>
        <div class="tc-card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Servidor SMTP</label>
                    <input type="text" name="smtp_host" class="form-control" value="<?= $s('smtp_host') ?>" placeholder="smtp.seudominio.com.br">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Porta</label>
                    <input type="text" name="smtp_port" class="form-control" value="<?= $s('smtp_port', '587') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Usuário</label>
                    <input type="text" name="smtp_user" class="form-control" value="<?= $s('smtp_user') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Senha</label>
                    <input type="password" name="smtp_pass" class="form-control" value="<?= $s('smtp_pass') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nome de exibição do remetente</label>
                    <input type="text" name="smtp_from_name" class="form-control" value="<?= $s('smtp_from_name', 'Titanium CRM') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">E-mail do remetente</label>
                    <input type="email" name="smtp_from_email" class="form-control" value="<?= $s('smtp_from_email') ?>" placeholder="crm@seudominio.com.br">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Criptografia</label>
                    <select name="smtp_encryption" class="form-select">
                        <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                        <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (465)</option>
                        <option value="" <?= ($settings['smtp_encryption'] ?? '') === '' ? 'selected' : '' ?>>Nenhuma</option>
                    </select>
                </div>
            </div>
            <div class="tc-insight-card mt-3">
                <i class="fa-solid fa-circle-info"></i>
                <span>O envio real de e-mails usa um cliente SMTP próprio (<code>app/core/Mailer.php</code>, via sockets nativos do PHP, sem PHPMailer/Composer). Usado no fluxo "Esqueci minha senha" e para notificar o consultor quando um lead é atribuído a ele.</span>
            </div>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-header">WhatsApp Cloud API (Meta)</div>
        <div class="tc-card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Token de acesso (permanente)</label>
                    <input type="text" name="whatsapp_token" class="form-control" value="<?= $s('whatsapp_token') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone Number ID</label>
                    <input type="text" name="whatsapp_phone_id" class="form-control" value="<?= $s('whatsapp_phone_id') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Template aprovado para automações</label>
                    <input type="text" name="automation_whatsapp_template" class="form-control" value="<?= $s('automation_whatsapp_template') ?>" placeholder="Ex: retomar_contato_lead">
                    <div class="form-text">Nome exato do template aprovado no WhatsApp Manager. Necessário para mensagens automáticas fora da janela de 24 horas.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Idioma do template</label>
                    <input type="text" name="automation_whatsapp_language" class="form-control" value="<?= $s('automation_whatsapp_language', 'pt_BR') ?>">
                </div>
            </div>
            <div class="tc-insight-card mt-3">
                <i class="fa-solid fa-circle-info"></i>
                <span>Usado pelo botão "Enviar WhatsApp" no perfil do lead (<code>app/core/WhatsappClient.php</code>, via cURL nativo). Veja no README como obter essas credenciais no Meta for Developers.</span>
            </div>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-header"><i class="fa-brands fa-whatsapp me-1"></i> Atendimento WhatsApp (Evolution API)</div>
        <div class="tc-card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <label class="form-label">URL base da Evolution API</label>
                    <input type="text" name="evolution_api_url" class="form-control" value="<?= $s('evolution_api_url') ?>" placeholder="https://api.agenciaimpulsionai.com.br">
                    <div class="form-text">Só HTTPS, sem caminho no final (o mesmo domínio do painel <code>/manager</code>).</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Token de acesso (apikey)</label>
                    <div class="input-group">
                        <input type="password" name="evolution_api_token" id="tcEvolutionToken" class="form-control" value="" placeholder="<?= !empty($settings['evolution_api_token']) ? 'Token configurado — deixe vazio para manter' : 'Cole aqui a apikey global' ?>" autocomplete="new-password">
                        <button type="button" class="btn btn-outline-secondary tc-secret-toggle" data-target="tcEvolutionToken"><i class="fa-solid fa-eye"></i></button>
                    </div>
                    <div class="form-text">Use a <strong>apikey global</strong> (a mesma que loga no <code>/manager</code>), não o token de uma instância específica.</div>
                </div>
                <div class="col-md-2">
                    <span class="badge bg-<?= !empty($settings['evolution_api_token']) && !empty($settings['evolution_api_url']) ? 'success' : 'secondary' ?> p-2 w-100"><?= !empty($settings['evolution_api_token']) && !empty($settings['evolution_api_url']) ? 'Configurada' : 'Não configurada' ?></span>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nome da instância</label>
                    <input type="text" name="evolution_instance_name" class="form-control" value="<?= $s('evolution_instance_name') ?>" placeholder="Ex: titanium-crm">
                    <div class="form-text">O nome exato da instância criada no <code>/manager</code> (aparece em "Testar conexão" se não souber).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Token secreto do webhook de mensagens (?token=...)</label>
                    <div class="input-group">
                        <input type="text" name="evolution_webhook_token" id="tcEvoWebhookToken" class="form-control" value="<?= $s('evolution_webhook_token') ?>" placeholder="Gere uma chave aleatória">
                        <button type="button" class="btn btn-outline-secondary" id="tcEvoGenerateWebhookToken"><i class="fa-solid fa-shuffle me-1"></i> Gerar</button>
                    </div>
                    <div class="form-text">Protege <code><?= e(url('webhook/evolution')) ?></code>, a URL que recebe as mensagens em tempo real.</div>
                </div>
            </div>
            <div class="tc-insight-card mt-3">
                <i class="fa-solid fa-circle-info"></i>
                <span>
                    Usado pela aba <a href="<?= e(url('atendimento-whatsapp')) ?>">Atendimento WhatsApp</a> (<code>app/services/EvolutionClient.php</code>): envia mensagens, sincroniza etiquetas e transfere atendimentos entre colaboradores dentro do nosso CRM.
                    As conversas e o histórico de mensagens ficam no nosso banco — quem entrega as mensagens novas em tempo real é o webhook abaixo.
                    <strong>Salve esta seção antes de testar/configurar.</strong>
                </span>
            </div>
            <div class="mt-2 d-flex flex-wrap gap-2 align-items-center">
                <?php if (!empty($evolutionConnections)): ?>
                <select id="tcEvolutionInstanceTarget" class="form-select form-select-sm" style="max-width:230px;">
                    <?php foreach ($evolutionConnections as $connection): ?>
                        <?php if (!empty($connection['active'])): ?>
                        <option value="<?= e($connection['instance_name']) ?>"><?= e($connection['label'] ?: $connection['instance_name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-success btn-sm" id="tcEvolutionTestBtn" data-url="<?= e(url('atendimento-whatsapp/testar')) ?>" data-csrf="<?= e(Csrf::token()) ?>">
                    <i class="fa-solid fa-plug-circle-check me-1"></i> Testar conexão
                </button>
                <button type="button" class="btn btn-outline-primary btn-sm" id="tcEvoConfigureWebhookBtn" data-url="<?= e(url('atendimento-whatsapp/webhook/configurar')) ?>" data-csrf="<?= e(Csrf::token()) ?>">
                    <i class="fa-solid fa-satellite-dish me-1"></i> Configurar webhook automaticamente
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="tcEvoQrBtn" data-url="<?= e(url('atendimento-whatsapp/qrcode')) ?>" data-csrf="<?= e(Csrf::token()) ?>">
                    <i class="fa-solid fa-qrcode me-1"></i> Gerar QR Code / reconectar
                </button>
                <span id="tcEvolutionTestResult" class="small"></span>
            </div>
            <div id="tcEvoQrResult" class="mt-2"></div>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-header">Integrações e Webhook de captação de leads</div>
        <div class="tc-card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Token de acesso Graph API (Meta Ads)</label>
                    <input type="text" name="integration_meta_token" class="form-control" value="<?= $s('integration_meta_token') ?>">
                    <div class="form-text">Usado pelo webhook para buscar os dados completos do lead na Graph API (leadgen_id).</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Token Google Ads (referência)</label>
                    <input type="text" name="integration_google_token" class="form-control" value="<?= $s('integration_google_token') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Token secreto do webhook (?token=...)</label>
                    <div class="input-group">
                        <input type="text" name="webhook_token" id="tcWebhookToken" class="form-control" value="<?= $s('webhook_token') ?>" placeholder="Gere uma chave aleatória e cole aqui">
                        <button type="button" class="btn btn-outline-secondary" id="tcGenerateWebhookToken">
                            <i class="fa-solid fa-shuffle me-1"></i> Gerar
                        </button>
                    </div>
                    <div class="form-text">Este token deve ser enviado como <code>?token=SEU_TOKEN</code> na URL cadastrada no Meta/Google, e também usado como <code>hub.verify_token</code> na verificação da Meta.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">URL do Webhook (referência)</label>
                    <input type="text" name="integration_webhook_url" class="form-control" value="<?= $s('integration_webhook_url') ?>" placeholder="<?= e(url('webhook/meta')) ?>">
                </div>
            </div>
            <div class="tc-insight-card mt-3">
                <i class="fa-solid fa-circle-info"></i>
                <span>
                    URLs completas para cadastrar no Meta Business/Google Ads (ver README, seção "Como cadastrar o Webhook"):<br>
                    <code><?= e(url('webhook/meta')) ?>?token=SEU_TOKEN</code> (Meta Lead Ads)<br>
                    <code><?= e(url('webhook/google')) ?>?token=SEU_TOKEN</code> (Google Ads)<br>
                    <code><?= e(url('webhook/generico')) ?>?token=SEU_TOKEN</code> (qualquer outra ferramenta/formulário)
                </span>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-tc-primary px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Salvar Configurações</button>
</form>

<div class="tc-card mb-3">
    <div class="tc-card-header"><i class="fa-solid fa-mobile-screen-button me-1"></i> Linhas de atendimento WhatsApp</div>
    <div class="tc-card-body">
        <p class="text-muted small">Conecte vários números na mesma Evolution API. Cada conversa fica separada pela linha que a recebeu.</p>
        <?php if (!empty($evolutionConnections)): ?>
        <div class="table-responsive mb-3">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Nome da instância</th><th>Identificação</th><th>Envio</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    <?php foreach ($evolutionConnections as $connection): ?>
                    <tr>
                        <td><code><?= e($connection['instance_name']) ?></code></td>
                        <td><?= e($connection['label'] ?: $connection['instance_name']) ?> <?= !empty($connection['is_default']) ? '<span class="badge bg-primary ms-1">Principal</span>' : '' ?></td>
                        <td><?= e(['auto' => 'Automático', 'official' => 'Documentação atual', 'legacy_text' => 'Compatibilidade'][($connection['payload_mode'] ?? 'auto')] ?? 'Automático') ?></td>
                        <td><span class="badge bg-<?= !empty($connection['active']) ? 'success' : 'secondary' ?>"><?= !empty($connection['active']) ? 'Ativa' : 'Desativada' ?></span></td>
                        <td class="text-end">
                            <?php if (!empty($connection['id']) && !empty($connection['active'])): ?>
                            <form method="POST" action="<?= e(url('configuracoes/evolution/instancias/' . $connection['id'] . '/desativar')) ?>" class="d-inline tc-delete-form" data-confirm-text="A linha será desativada, mas o histórico das conversas será preservado.">
                                <?= Csrf::field() ?><button class="btn btn-sm btn-outline-danger">Desativar</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <form method="POST" action="<?= e(url('configuracoes/evolution/instancias/salvar')) ?>" class="row g-2 align-items-end">
            <?= Csrf::field() ?>
            <div class="col-md-4"><label class="form-label small">Nome da instância na Evolution</label><input name="instance_name" class="form-control" placeholder="Ex: comercial-sp" required></div>
            <div class="col-md-3"><label class="form-label small">Identificação no CRM</label><input name="label" class="form-control" placeholder="Comercial SP" required></div>
            <div class="col-md-3"><label class="form-label small">Formato de envio</label><select name="payload_mode" class="form-select"><option value="auto">Automático (recomendado)</option><option value="official">Documentação atual</option><option value="legacy_text">Compatibilidade com Evolution antiga</option></select></div>
            <div class="col-md-1"><label class="form-label small">Principal</label><div class="form-check"><input class="form-check-input" name="is_default" value="1" type="checkbox"></div></div>
            <input type="hidden" name="active" value="1">
            <div class="col-md-1"><button class="btn btn-tc-primary w-100" title="Adicionar linha"><i class="fa-solid fa-plus"></i></button></div>
        </form>
    </div>
</div>

<div class="tc-card mb-3">
    <div class="tc-card-header"><i class="fa-solid fa-diagram-project me-1"></i> Fluxos de atendimento</div>
    <div class="tc-card-body">
        <div class="alert alert-light border small mb-3">
            <div class="fw-semibold mb-1"><i class="fa-solid fa-route me-1"></i>Como funciona no atendimento</div>
            <div>Um fluxo é um roteiro interno, não uma automação de envio. O atendente escolhe o fluxo na conversa, avança de etapa quando fizer sentido e recebe uma orientação + texto-base no canal definido. No WhatsApp, a sugestão preenche a resposta; no e-mail, ela prepara o rascunho. Em ambos os casos, o atendente revisa e confirma o envio manualmente.</div>
        </div>
        <?php if (!empty($evolutionFlows)): ?>
        <div class="row g-2 mb-3">
            <?php foreach ($evolutionFlows as $flow): ?>
                <?php $flowSteps = json_decode((string) ($flow['steps_json'] ?? '[]'), true) ?: []; ?>
                <div class="col-md-6"><div class="border rounded p-2 h-100"><strong><?= e($flow['name']) ?></strong><span class="badge bg-<?= !empty($flow['active']) ? 'success' : 'secondary' ?> ms-1"><?= !empty($flow['active']) ? 'Ativo' : 'Inativo' ?></span><div class="small text-muted mt-1"><?= e($flow['description'] ?: 'Sem descrição') ?></div><div class="small mt-2"><?= count($flowSteps) ?> etapa(s)<?= !empty($flow['instance_name']) ? ' · ' . e($flow['instance_name']) : ' · todas as linhas' ?></div><?php if ($flowSteps): ?><div class="small text-muted mt-1"><?php foreach (array_slice($flowSteps, 0, 3) as $flowStep): ?><span class="badge text-bg-light border me-1"><?= ($flowStep['channel'] ?? 'whatsapp') === 'email' ? 'E-mail' : 'WhatsApp' ?></span><?= e($flowStep['title'] ?? 'Etapa') ?><?php endforeach; ?></div><?php endif; ?></div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <form method="POST" action="<?= e(url('configuracoes/evolution/fluxos/salvar')) ?>" class="row g-2" id="tcEvolutionFlowForm">
            <?= Csrf::field() ?>
            <div class="col-md-4"><label class="form-label small">Nome do fluxo</label><input name="name" class="form-control" placeholder="Ex: Qualificação de consórcio" required></div>
            <div class="col-md-4"><label class="form-label small">Aplicar à linha <span class="text-muted">(opcional)</span></label><select name="instance_name" class="form-select"><option value="">Todas as linhas</option><?php foreach ($evolutionConnections as $connection): ?><?php if (!empty($connection['active'])): ?><option value="<?= e($connection['instance_name']) ?>"><?= e($connection['label'] ?: $connection['instance_name']) ?></option><?php endif; ?><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small">Descrição <span class="text-muted">(opcional)</span></label><input name="description" class="form-control" placeholder="Objetivo do roteiro"></div>
            <div class="col-12"><div class="d-flex justify-content-between align-items-center"><label class="form-label small mb-1">Etapas do roteiro</label><button type="button" class="btn btn-outline-secondary btn-sm" id="tcAddFlowStep"><i class="fa-solid fa-plus me-1"></i>Adicionar etapa</button></div><div id="tcEvolutionFlowSteps" class="vstack gap-2"><div class="border rounded p-2 tc-flow-step"><div class="row g-2"><div class="col-md-3"><label class="form-label small">Etapa</label><input name="steps[0][title]" class="form-control form-control-sm" placeholder="Acolhimento" required></div><div class="col-md-2"><label class="form-label small">Canal sugerido</label><select name="steps[0][channel]" class="form-select form-select-sm"><option value="whatsapp">WhatsApp</option><option value="email">E-mail</option></select></div><div class="col-md-3"><label class="form-label small">Assunto (e-mail)</label><input name="steps[0][email_subject]" class="form-control form-control-sm" placeholder="Opcional"></div><div class="col-md-4"><label class="form-label small">Orientação ao atendente</label><input name="steps[0][guidance]" class="form-control form-control-sm" placeholder="Ex.: confirme a necessidade antes de oferecer."></div><div class="col-12"><label class="form-label small">Texto sugerido</label><textarea name="steps[0][suggestion]" class="form-control form-control-sm" rows="2" placeholder="Olá! Posso ajudar você a encontrar a melhor opção?" required></textarea></div></div></div></div><template id="tcFlowStepTemplate"><div class="border rounded p-2 tc-flow-step"><div class="d-flex justify-content-end"><button type="button" class="btn btn-link text-danger btn-sm p-0 tc-remove-flow-step">Remover</button></div><div class="row g-2"><div class="col-md-3"><label class="form-label small">Etapa</label><input name="steps[__INDEX__][title]" class="form-control form-control-sm" placeholder="Próximo passo" required></div><div class="col-md-2"><label class="form-label small">Canal sugerido</label><select name="steps[__INDEX__][channel]" class="form-select form-select-sm"><option value="whatsapp">WhatsApp</option><option value="email">E-mail</option></select></div><div class="col-md-3"><label class="form-label small">Assunto (e-mail)</label><input name="steps[__INDEX__][email_subject]" class="form-control form-control-sm" placeholder="Opcional"></div><div class="col-md-4"><label class="form-label small">Orientação ao atendente</label><input name="steps[__INDEX__][guidance]" class="form-control form-control-sm" placeholder="O que validar antes de enviar?"></div><div class="col-12"><label class="form-label small">Texto sugerido</label><textarea name="steps[__INDEX__][suggestion]" class="form-control form-control-sm" rows="2" required></textarea></div></div></div></template></div>
            <input type="hidden" name="active" value="1"><div class="col-12"><button class="btn btn-outline-primary"><i class="fa-solid fa-plus me-1"></i>Criar fluxo</button></div>
        </form>
    </div>
</div>

<script>
    (function () {
        document.querySelectorAll('.tc-secret-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                var field = document.getElementById(button.dataset.target);
                if (!field) return;
                field.type = field.type === 'password' ? 'text' : 'password';
                button.querySelector('i').className = field.type === 'password' ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash';
            });
        });
        var btn = document.getElementById('tcGenerateWebhookToken');
        var input = document.getElementById('tcWebhookToken');
        if (btn && input) {
            btn.addEventListener('click', function () {
                var bytes = new Uint8Array(24);
                window.crypto.getRandomValues(bytes);
                var token = Array.prototype.map.call(bytes, function (b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
                input.value = token;
            });
        }

        function tcEvoPost(url, csrf, extraBody) {
            return fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'csrf_token=' + encodeURIComponent(csrf) + (extraBody || '')
            }).then(function (r) { return r.json(); });
        }

        function tcEvoSelectedInstanceBody() {
            var select = document.getElementById('tcEvolutionInstanceTarget');
            return select && select.value ? '&instance_name=' + encodeURIComponent(select.value) : '';
        }

        var evoBtn = document.getElementById('tcEvolutionTestBtn');
        var evoResult = document.getElementById('tcEvolutionTestResult');
        if (evoBtn && evoResult) {
            evoBtn.addEventListener('click', function () {
                evoResult.textContent = 'Testando...';
                evoResult.className = 'small text-muted';
                tcEvoPost(evoBtn.dataset.url, evoBtn.dataset.csrf, tcEvoSelectedInstanceBody()).then(function (data) {
                    evoResult.textContent = data.message || (data.success ? 'Conexão OK.' : 'Falha na conexão.');
                    evoResult.className = 'small ' + (data.success ? 'text-success' : 'text-danger');
                }).catch(function () {
                    evoResult.textContent = 'Erro ao testar a conexão.';
                    evoResult.className = 'small text-danger';
                });
            });
        }

        var evoWebhookBtn = document.getElementById('tcEvoConfigureWebhookBtn');
        if (evoWebhookBtn && evoResult) {
            evoWebhookBtn.addEventListener('click', function () {
                evoResult.textContent = 'Configurando webhook...';
                evoResult.className = 'small text-muted';
                tcEvoPost(evoWebhookBtn.dataset.url, evoWebhookBtn.dataset.csrf, tcEvoSelectedInstanceBody()).then(function (data) {
                    evoResult.textContent = data.message || (data.success ? 'Webhook configurado.' : 'Falha ao configurar webhook.');
                    evoResult.className = 'small ' + (data.success ? 'text-success' : 'text-danger');
                }).catch(function () {
                    evoResult.textContent = 'Erro ao configurar o webhook.';
                    evoResult.className = 'small text-danger';
                });
            });
        }

        var evoQrBtn = document.getElementById('tcEvoQrBtn');
        var evoQrResult = document.getElementById('tcEvoQrResult');
        if (evoQrBtn && evoQrResult) {
            evoQrBtn.addEventListener('click', function () {
                evoQrResult.innerHTML = '<span class="text-muted small">Gerando QR Code...</span>';
                tcEvoPost(evoQrBtn.dataset.url, evoQrBtn.dataset.csrf, tcEvoSelectedInstanceBody()).then(function (data) {
                    if (data.success && data.already_connected) {
                        evoQrResult.innerHTML = '<div class="small text-success"><i class="fa-solid fa-circle-check me-1"></i>' + data.message + '</div>';
                    } else if (data.success && data.base64) {
                        evoQrResult.innerHTML = '<img src="' + data.base64 + '" alt="QR Code WhatsApp" style="max-width:220px;border:1px solid var(--tc-border);border-radius:.5rem;">' +
                            '<div class="small text-muted mt-1">Escaneie no WhatsApp do número que vai atender pelo CRM.</div>';
                    } else if (data.success && data.pairing_code) {
                        evoQrResult.innerHTML = '<div class="small">Código de pareamento: <strong>' + data.pairing_code + '</strong></div>';
                    } else {
                        evoQrResult.innerHTML = '<div class="small text-danger">' + (data.message || 'Não foi possível gerar o QR Code.') + '</div>';
                    }
                }).catch(function () {
                    evoQrResult.innerHTML = '<div class="small text-danger">Erro ao gerar o QR Code.</div>';
                });
            });
        }

        var evoWebhookTokenBtn = document.getElementById('tcEvoGenerateWebhookToken');
        var evoWebhookTokenInput = document.getElementById('tcEvoWebhookToken');
        if (evoWebhookTokenBtn && evoWebhookTokenInput) {
            evoWebhookTokenBtn.addEventListener('click', function () {
                var bytes = new Uint8Array(24);
                window.crypto.getRandomValues(bytes);
                evoWebhookTokenInput.value = Array.prototype.map.call(bytes, function (b) {
                    return ('0' + b.toString(16)).slice(-2);
                }).join('');
            });
        }

        var flowSteps = document.getElementById('tcEvolutionFlowSteps');
        var addFlowStep = document.getElementById('tcAddFlowStep');
        var flowStepTemplate = document.getElementById('tcFlowStepTemplate');
        var flowStepIndex = 1;
        if (flowSteps && addFlowStep && flowStepTemplate) {
            addFlowStep.addEventListener('click', function () {
                var markup = flowStepTemplate.innerHTML.replace(/__INDEX__/g, String(flowStepIndex++));
                flowSteps.insertAdjacentHTML('beforeend', markup);
            });
            flowSteps.addEventListener('click', function (event) {
                var button = event.target.closest('.tc-remove-flow-step');
                if (button) button.closest('.tc-flow-step').remove();
            });
        }
    })();
</script>
