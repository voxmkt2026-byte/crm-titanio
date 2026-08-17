<?php
/**
 * app/views/leads/form.php
 * Wizard de criação/edição de lead (Fase 4), dividido em 4 abas que seguem
 * a evolução natural do relacionamento com o cliente: Primeiro Contato ->
 * Perfil -> Qualificação -> Negociação. Nenhum campo é obrigatório e o
 * lead pode ser salvo a partir de qualquer aba (o botão "Salvar Lead" fica
 * sempre visível, e todos os campos permanecem no DOM/POST mesmo quando a
 * aba está oculta).
 */

$isEdit = !empty($lead);
$l = fn($key, $default = '') => e((string) ($lead[$key] ?? old($key, $default)));
$leadTagIds = array_column($leadTags ?? [], 'id');

$sourceOptions = [
    'cadastro_manual' => 'Cadastro Manual',
    'whatsapp'        => 'WhatsApp',
    'facebook'        => 'Facebook',
    'instagram'       => 'Instagram',
    'google'          => 'Google Ads',
    'indicacao'       => 'Indicação',
    'site'            => 'Site',
    'landing_page'    => 'Landing Page',
    'organico'        => 'Orgânico',
    'importacao_csv'  => 'Importação CSV',
    'api'             => 'API',
    'webhook'         => 'Webhook',
    'outros'          => 'Outros',
];
// Cadastro manual pela tela: origem padrão pré-selecionada, mas o usuário
// pode trocar livremente (ver LeadController::store).
$currentSource = $lead['source'] ?? ($isEdit ? '' : 'cadastro_manual');
?>

<form method="POST" action="<?= e($formAction) ?>" id="leadForm"
      data-check-duplicate-url="<?= e(url('leads/check-duplicate')) ?>"
      data-lead-id="<?= $isEdit ? (int) $lead['id'] : '' ?>">
    <?= Csrf::field() ?>

    <?php if ($isEdit && !empty($lead['lead_code'])): ?>
        <div class="mb-3">
            <span class="badge tc-badge-code"><i class="fa-solid fa-hashtag me-1"></i><?= e($lead['lead_code']) ?></span>
        </div>
    <?php endif; ?>

    <!-- Indicador de progresso do wizard -->
    <div class="tc-wizard-steps mb-4" id="tcWizardSteps">
        <div class="tc-wizard-step active" data-step-indicator="1">
            <div class="tc-wizard-step-circle">1</div>
            <div class="tc-wizard-step-label">Primeiro Contato</div>
        </div>
        <div class="tc-wizard-step" data-step-indicator="2">
            <div class="tc-wizard-step-circle">2</div>
            <div class="tc-wizard-step-label">Perfil</div>
        </div>
        <div class="tc-wizard-step" data-step-indicator="3">
            <div class="tc-wizard-step-circle">3</div>
            <div class="tc-wizard-step-label">Qualificação</div>
        </div>
        <div class="tc-wizard-step" data-step-indicator="4">
            <div class="tc-wizard-step-circle">4</div>
            <div class="tc-wizard-step-label">Negociação</div>
        </div>
    </div>

    <div class="tc-card mb-3">
        <div class="tc-card-body">

            <!-- ================= Aba 1: Primeiro Contato ================= -->
            <div class="tc-wizard-pane" data-step="1">
                <div class="tc-form-section-title">Primeiro Contato</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome completo</label>
                        <input type="text" name="name" class="form-control" value="<?= $l('name') ?>" placeholder="Nome do lead">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">DDD</label>
                        <input type="text" name="ddd" class="form-control" maxlength="2" value="<?= $l('ddd') ?>" placeholder="11">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Telefone</label>
                        <input type="text" name="phone" class="form-control" data-mask="phone" value="<?= $l('phone') ?>" placeholder="(11) 4000-0000">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label">WhatsApp</label>
                        <input type="text" name="whatsapp" class="form-control" data-mask="phone" value="<?= $l('whatsapp') ?>" placeholder="(11) 90000-0000">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">E-mail</label>
                        <input type="email" name="email" class="form-control" value="<?= $l('email') ?>" placeholder="email@exemplo.com">
                    </div>
                </div>
            </div>

            <!-- ================= Aba 2: Perfil ================= -->
            <div class="tc-wizard-pane d-none" data-step="2">
                <div class="tc-form-section-title">Endereço</div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">CEP</label>
                        <input type="text" name="zipcode" class="form-control" data-mask="cep" value="<?= $l('zipcode') ?>" placeholder="00000-000">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Cidade</label>
                        <input type="text" name="city" class="form-control" value="<?= $l('city') ?>" placeholder="Cidade">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Estado (UF)</label>
                        <select name="state" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach ($states as $uf => $name): ?>
                                <option value="<?= e($uf) ?>" <?= ($lead['state'] ?? '') === $uf ? 'selected' : '' ?>><?= e($uf) ?> - <?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">CPF</label>
                        <input type="text" name="cpf" class="form-control" data-mask="cpf" value="<?= $l('cpf') ?>" placeholder="000.000.000-00">
                    </div>
                </div>

                <div class="tc-form-section-title">Interesse</div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Interesse</label>
                        <select name="interest" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach (['imovel' => 'Imóvel','veiculo' => 'Veículo','caminhao' => 'Caminhão','moto' => 'Moto','maquinario' => 'Maquinário','agronegocio' => 'Agronegócio','construcao' => 'Construção','capital_giro' => 'Capital de Giro','investimento' => 'Investimento','quitacao' => 'Quitação','outros' => 'Outros'] as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($lead['interest'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Valor desejado (R$)</label>
                        <input type="text" name="desired_value" class="form-control" value="<?= $l('desired_value') ?>" placeholder="50000,00">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Valor da venda concluída (R$)</label>
                        <input type="text" name="closed_value" class="form-control" value="<?= $l('closed_value') ?>" placeholder="Preencha ao fechar a venda">
                        <div class="form-text">Usado nas metas de valor. Só entra no indicador quando o status estiver como “Fechado”.</div>
                    </div>
                </div>

                <div class="tc-form-section-title">Campanha / Origem &amp; UTMs</div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Origem</label>
                        <select name="source" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach ($sourceOptions as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= $currentSource === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Campanha</label>
                        <input type="text" name="campaign" class="form-control" value="<?= $l('campaign') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Conjunto de anúncios</label>
                        <input type="text" name="adset" class="form-control" value="<?= $l('adset') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Anúncio</label>
                        <input type="text" name="ad" class="form-control" value="<?= $l('ad') ?>">
                    </div>

                    <div class="col-md-3"><label class="form-label">UTM Source</label><input type="text" name="utm_source" class="form-control" value="<?= $l('utm_source') ?>"></div>
                    <div class="col-md-3"><label class="form-label">UTM Medium</label><input type="text" name="utm_medium" class="form-control" value="<?= $l('utm_medium') ?>"></div>
                    <div class="col-md-3"><label class="form-label">UTM Campaign</label><input type="text" name="utm_campaign" class="form-control" value="<?= $l('utm_campaign') ?>"></div>
                    <div class="col-md-3"><label class="form-label">UTM Content</label><input type="text" name="utm_content" class="form-control" value="<?= $l('utm_content') ?>"></div>
                    <div class="col-md-3"><label class="form-label">UTM Term</label><input type="text" name="utm_term" class="form-control" value="<?= $l('utm_term') ?>"></div>
                </div>
            </div>

            <!-- ================= Aba 3: Qualificação ================= -->
            <div class="tc-wizard-pane d-none" data-step="3">
                <div class="tc-form-section-title">Qualificação Financeira</div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Possui entrada?</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="has_down_payment" value="1" <?= !empty($lead['has_down_payment']) ? 'checked' : '' ?>>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Valor da entrada (R$)</label>
                        <input type="text" name="down_payment_value" class="form-control" value="<?= $l('down_payment_value') ?>" placeholder="5000,00">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Faixa de renda</label>
                        <input type="text" name="income_range" class="form-control" value="<?= $l('income_range') ?>" placeholder="Ex: R$ 3.000 - R$ 5.000">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Profissão</label>
                        <input type="text" name="profession" class="form-control" value="<?= $l('profession') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Empresa</label>
                        <input type="text" name="company" class="form-control" value="<?= $l('company') ?>">
                    </div>
                </div>

                <div class="tc-form-section-title">Classificação</div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Temperatura</label>
                        <select name="temperature" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach (['frio' => 'Frio','morno' => 'Morno','quente' => 'Quente','muito_quente' => 'Muito Quente'] as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($lead['temperature'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Prioridade</label>
                        <select name="priority" class="form-select">
                            <option value="">Selecione</option>
                            <?php foreach (['baixa' => 'Baixa','media' => 'Média','alta' => 'Alta','urgente' => 'Urgente'] as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= ($lead['priority'] ?? '') === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Lead Score</label>
                        <div class="tc-score-display">
                            <span class="badge bg-<?= (int) ($lead['lead_score'] ?? 0) >= 70 ? 'success' : ((int) ($lead['lead_score'] ?? 0) >= 40 ? 'warning' : 'secondary') ?>">
                                <?= (int) ($lead['lead_score'] ?? 0) ?>/100
                            </span>
                            <div class="text-muted mt-1" style="font-size:0.72rem;">Calculado automaticamente ao salvar</div>
                        </div>
                    </div>
                </div>

                <div class="tc-form-section-title">Tags</div>
                <div class="row g-3">
                    <div class="col-12">
                        <?php if (!empty($allTags)): ?>
                            <div class="d-flex flex-wrap gap-3 mb-2">
                                <?php foreach ($allTags as $tag): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="tags_existing[]"
                                               id="tag_<?= (int) $tag['id'] ?>" value="<?= (int) $tag['id'] ?>"
                                               <?= in_array((int) $tag['id'], $leadTagIds, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="tag_<?= (int) $tag['id'] ?>">
                                            <span class="badge" style="background-color: <?= e($tag['color'] ?: '#6b7280') ?>;"><?= e($tag['name']) ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <label class="form-label">Novas tags (separadas por vírgula)</label>
                        <input type="text" name="tags_new" class="form-control" placeholder="Ex: VIP, Urgente, Reengajar">
                    </div>
                </div>
            </div>

            <!-- ================= Aba 4: Negociação ================= -->
            <div class="tc-wizard-pane d-none" data-step="4">
                <div class="tc-form-section-title">Gestão do Lead</div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php
                            $statusOptions = ['novo' => 'Novo','primeiro_contato' => 'Primeiro Contato','tentando_contato' => 'Tentando Contato','em_negociacao' => 'Em Negociação','documentacao' => 'Documentação','aguardando_cliente' => 'Aguardando Cliente','aguardando_aprovacao' => 'Aguardando Aprovação','aprovado' => 'Aprovado','fechado' => 'Fechado','perdido' => 'Perdido','sem_interesse' => 'Sem Interesse','sem_entrada' => 'Sem Entrada','numero_invalido' => 'Número Inválido','nao_responde' => 'Não Responde','bloqueou' => 'Bloqueou','duplicado' => 'Duplicado'];
                            $currentStatus = $lead['status'] ?? 'novo';
                            foreach ($statusOptions as $val => $label): ?>
                                <option value="<?= e($val) ?>" <?= $currentStatus === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Responsável</label>
                        <select name="assigned_to" class="form-select">
                            <option value="">Não atribuído</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= (int) ($lead['assigned_to'] ?? 0) === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Último contato</label>
                        <input type="datetime-local" name="last_contact_at" class="form-control" value="<?= e($lead['last_contact_at'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Próximo contato</label>
                        <input type="datetime-local" name="next_contact_at" class="form-control" value="<?= e($lead['next_contact_at'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Motivo de perda</label>
                        <select name="loss_reason_id" class="form-select">
                            <option value="">-</option>
                            <?php foreach ($lossReasons as $lr): ?>
                                <option value="<?= (int) $lr['id'] ?>" <?= (int) ($lead['loss_reason_id'] ?? 0) === (int) $lr['id'] ? 'selected' : '' ?>><?= e($lr['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="tc-form-section-title">Observações</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Observações (visível para a equipe)</label>
                        <textarea name="notes" class="form-control" rows="3"><?= $l('notes') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Observações internas</label>
                        <textarea name="internal_notes" class="form-control" rows="3"><?= $l('internal_notes') ?></textarea>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <div class="d-flex justify-content-between tc-wizard-nav-bar">
        <div class="d-flex gap-2">
            <a href="<?= e(url('leads')) ?>" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Cancelar</a>
            <button type="button" class="btn btn-outline-secondary tc-wizard-prev d-none">
                <i class="fa-solid fa-chevron-left me-1"></i> Voltar
            </button>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-tc-primary tc-wizard-next">
                Avançar <i class="fa-solid fa-chevron-right ms-1"></i>
            </button>
            <button type="submit" class="btn btn-success px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Salvar Lead</button>
        </div>
    </div>
</form>
