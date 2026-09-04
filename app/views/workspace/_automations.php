<?php
$flows = $flows ?? [];
$automationRuns = $automationRuns ?? [];
$automationTriggers = $automationTriggers ?? [];
$automationActions = $automationActions ?? [];
$canManageAutomation = !empty($automationCanManage);
$statusOptions = [
    'novo' => 'Novo', 'primeiro_contato' => 'Primeiro contato', 'tentando_contato' => 'Tentando contato',
    'em_negociacao' => 'Em negociação', 'documentacao' => 'Documentação', 'aguardando_cliente' => 'Aguardando cliente',
    'aguardando_aprovacao' => 'Aguardando aprovação', 'aprovado' => 'Aprovado', 'fechado' => 'Fechado',
    'perdido' => 'Perdido', 'sem_interesse' => 'Sem interesse', 'sem_entrada' => 'Sem entrada',
    'numero_invalido' => 'Número inválido', 'nao_responde' => 'Não responde', 'bloqueou' => 'Bloqueou', 'duplicado' => 'Duplicado',
];
$sourceOptions = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'google' => 'Google Ads', 'indicacao' => 'Indicação', 'site' => 'Site', 'landing_page' => 'Landing page', 'organico' => 'Orgânico', 'whatsapp' => 'WhatsApp', 'cadastro_manual' => 'Cadastro manual', 'outros' => 'Outros'];
?>

<section class="tc-automation-page" id="tcAutomationPage"
  data-save-url="<?= e(url('automacoes/salvar')) ?>"
  data-preview-url="<?= e(url('automacoes/testar')) ?>"
  data-base-url="<?= e(url('automacoes')) ?>"
  data-csrf="<?= e(Csrf::token()) ?>"
  data-triggers='<?= e(json_encode($automationTriggers, JSON_UNESCAPED_UNICODE)) ?>'>
  <div class="tc-workspace-toolbar tc-automation-heading">
    <div>
      <div class="tc-automation-eyebrow"><i class="fa-solid fa-wand-magic-sparkles"></i> ORQUESTRAÇÃO COMERCIAL</div>
      <h5>Automações sem código</h5>
      <small>Conecte leads, tarefas, agenda, WhatsApp, e-mail e a equipe em fluxos rastreáveis.</small>
    </div>
    <?php if ($canManageAutomation): ?>
      <button type="button" class="btn btn-tc-primary" id="tcAutomationNew"><i class="fa-solid fa-plus"></i> Novo fluxo</button>
    <?php endif; ?>
  </div>

  <div class="tc-automation-guide">
    <span><b>1</b> Escolha o momento</span><i class="fa-solid fa-arrow-right"></i>
    <span><b>2</b> Adicione regras</span><i class="fa-solid fa-arrow-right"></i>
    <span><b>3</b> Configure ações</span><i class="fa-solid fa-arrow-right"></i>
    <span><b>4</b> Teste e ative</span>
    <small><i class="fa-solid fa-shield-halved"></i> O teste só mostra os leads elegíveis; ele não envia nem altera dados.</small>
  </div>

  <?php if (!empty($migrationPending)): ?>
    <div class="alert alert-warning mb-3"><i class="fa-solid fa-database me-1"></i> A estrutura de automações ainda não está disponível. Execute as migrações do Workspace antes de criar fluxos.</div>
  <?php else: ?>
    <div class="row g-3 align-items-start">
      <div class="col-xl-5">
        <div class="tc-card tc-automation-library">
          <div class="tc-card-body">
            <div class="tc-automation-library-head">
              <div><h6>Biblioteca de fluxos</h6><p>Fluxos ativos e modelos para adaptar ao seu processo.</p></div>
              <label class="tc-automation-search"><i class="fa-solid fa-magnifying-glass"></i><input id="tcAutomationSearch" placeholder="Buscar fluxo"></label>
            </div>
            <div class="tc-automation-filter" role="tablist">
              <button type="button" class="active" data-filter="all">Todos <span><?= count($flows) ?></span></button>
              <button type="button" data-filter="active">Ativos</button>
              <button type="button" data-filter="template">Modelos</button>
            </div>
            <div class="tc-automation-flow-list" id="tcAutomationFlowList">
              <?php foreach ($flows as $flow):
                $config = json_decode((string) ($flow['trigger_config'] ?? '{}'), true) ?: [];
                $storedActions = json_decode((string) ($flow['actions_json'] ?? '[]'), true) ?: [];
                $actions = [];
                foreach ($storedActions as $storedAction) { $key = AutomationRunner::normalizeAction((string) $storedAction); if ($key && !in_array($key, $actions, true)) $actions[] = $key; }
                $flowData = ['id'=>(int) $flow['id'], 'name'=>$flow['name'], 'description'=>$flow['description'] ?? '', 'trigger_type'=>$flow['trigger_type'], 'trigger_config'=>$config, 'actions'=>$actions, 'is_template'=>(int) ($flow['is_template'] ?? 0), 'active'=>(int) $flow['active']];
                $trigger = $automationTriggers[$flow['trigger_type']] ?? ['label' => 'Gatilho personalizado'];
                $isTemplate = !empty($flow['is_template']);
              ?>
                <article class="tc-automation-flow" data-kind="<?= $isTemplate ? 'template' : (!empty($flow['active']) ? 'active' : 'paused') ?>" data-search="<?= e(mb_strtolower(($flow['name'] ?? '') . ' ' . ($flow['description'] ?? ''))) ?>">
                  <div class="tc-automation-flow-icon <?= $isTemplate ? 'template' : (!empty($flow['active']) ? 'active' : '') ?>"><i class="fa-solid <?= $isTemplate ? 'fa-copy' : 'fa-bolt' ?>"></i></div>
                  <div class="tc-automation-flow-main">
                    <div class="d-flex align-items-center gap-2 flex-wrap"><strong><?= e($flow['name']) ?></strong><?php if ($isTemplate): ?><span class="tc-automation-chip neutral">Modelo</span><?php else: ?><span class="tc-automation-chip <?= !empty($flow['active']) ? 'success' : 'muted' ?>"><?= !empty($flow['active']) ? 'Ativo' : 'Pausado' ?></span><?php endif; ?></div>
                    <p><?= e($flow['description'] ?: $trigger['label']) ?></p>
                    <div class="tc-automation-path"><span><i class="fa-solid fa-bolt"></i> <?= e($trigger['label']) ?></span><i class="fa-solid fa-arrow-right"></i><span><?= count($actions) ?> <?= count($actions) === 1 ? 'ação' : 'ações' ?></span></div>
                    <div class="tc-automation-action-tags">
                      <?php foreach (array_slice($actions, 0, 3) as $action): ?><span title="<?= e($automationActions[$action]['help'] ?? '') ?>"><i class="<?= e($automationActions[$action]['icon'] ?? 'fa-solid fa-check') ?>"></i> <?= e($automationActions[$action]['label'] ?? $action) ?></span><?php endforeach; ?>
                      <?php if (count($actions) > 3): ?><span>+<?= count($actions) - 3 ?></span><?php endif; ?>
                    </div>
                    <?php if (!$isTemplate): ?><small class="tc-automation-flow-health"><i class="fa-solid fa-chart-line"></i> <?= (int) ($flow['runs_30d'] ?? 0) ?> execuções / 30 dias<?= !empty($flow['last_attempt_at']) ? ' · última: ' . e(format_date($flow['last_attempt_at'], true)) : '' ?></small><?php endif; ?>
                  </div>
                  <div class="tc-automation-flow-controls">
                    <button type="button" class="btn btn-sm btn-light tc-automation-edit" data-flow='<?= e(json_encode($flowData, JSON_UNESCAPED_UNICODE)) ?>' title="<?= $isTemplate ? 'Usar este modelo' : 'Editar fluxo' ?>"><i class="fa-solid <?= $isTemplate ? 'fa-copy' : 'fa-pen' ?>"></i></button>
                    <?php if (!$isTemplate && $canManageAutomation): ?>
                      <div class="dropdown"><button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" aria-label="Mais opções"><i class="fa-solid fa-ellipsis"></i></button><ul class="dropdown-menu dropdown-menu-end"><li><button class="dropdown-item tc-automation-run" data-id="<?= (int) $flow['id'] ?>"><i class="fa-solid fa-play"></i> Executar agora</button></li><li><button class="dropdown-item tc-automation-toggle" data-id="<?= (int) $flow['id'] ?>"><i class="fa-solid <?= !empty($flow['active']) ? 'fa-pause' : 'fa-play' ?>"></i> <?= !empty($flow['active']) ? 'Pausar fluxo' : 'Ativar fluxo' ?></button></li><li><hr class="dropdown-divider"></li><li><button class="dropdown-item text-danger tc-automation-delete" data-id="<?= (int) $flow['id'] ?>"><i class="fa-solid fa-trash"></i> Excluir</button></li></ul></div>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
              <?php if (!$flows): ?><div class="tc-automation-empty"><i class="fa-solid fa-wand-magic-sparkles"></i><strong>Comece com um fluxo</strong><span>Use um modelo ou crie uma automação para organizar os próximos passos da equipe.</span></div><?php endif; ?>
              <div class="tc-automation-empty d-none" id="tcAutomationNoResults"><i class="fa-solid fa-magnifying-glass"></i><strong>Nenhum fluxo encontrado</strong><span>Tente outro termo ou filtro.</span></div>
            </div>
          </div>
        </div>

        <div class="tc-card tc-automation-monitor mt-3">
          <div class="tc-card-body"><div class="tc-automation-monitor-head"><div><h6>Monitor de execuções</h6><small>Últimas 12 ações registradas pelos fluxos.</small></div><span><i class="fa-solid fa-clock-rotate-left"></i> Histórico</span></div>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Fluxo / lead</th><th>Resultado</th><th>Quando</th></tr></thead><tbody>
              <?php foreach ($automationRuns as $run): ?><tr><td><strong><?= e($run['flow_name']) ?></strong><small><?= e($run['lead_name'] ?: ($run['lead_code'] ?: 'Lead removido')) ?></small></td><td><span class="tc-automation-run-status <?= e($run['status']) ?>"><?= e(ucfirst($run['status'])) ?></span></td><td><?= e(format_date($run['created_at'], true)) ?></td></tr><?php endforeach; ?>
              <?php if (!$automationRuns): ?><tr><td colspan="3" class="text-muted text-center py-3">Ainda não há execuções. Teste e ative um fluxo quando estiver pronto.</td></tr><?php endif; ?>
            </tbody></table></div>
          </div>
        </div>
      </div>

      <div class="col-xl-7">
        <form class="tc-card tc-automation-builder" id="tcAutomationForm" novalidate>
          <div class="tc-card-body">
            <div class="tc-automation-builder-title"><div><span>CONSTRUTOR VISUAL</span><h6 id="tcAutomationFormTitle">Novo fluxo</h6><p id="tcAutomationFormHint">Defina o gatilho, as regras e o que deve acontecer.</p></div><button type="button" class="btn btn-sm btn-outline-primary" data-ai-purpose="assistant" data-ai-prompt="Sugira um fluxo de automação comercial seguro e objetivo, com gatilho, condição e ação para um CRM de leads."><i class="fa-solid fa-wand-magic-sparkles"></i> Ideias com IA</button></div>
            <?php if (!$canManageAutomation): ?><div class="alert alert-info"><i class="fa-solid fa-lock"></i> Você pode consultar os fluxos, mas somente administradores e supervisores podem alterá-los.</div><?php endif; ?>
            <fieldset <?= $canManageAutomation ? '' : 'disabled' ?>><input type="hidden" name="id" value="">
              <div class="row g-2"><div class="col-md-7"><label class="form-label">Nome do fluxo</label><input class="form-control" name="name" maxlength="180" value="Novo fluxo comercial" required></div><div class="col-md-5"><label class="form-label">Janela de repetição</label><div class="input-group"><input class="form-control" name="cooldown_hours" type="number" min="1" max="720" value="24"><span class="input-group-text">horas</span></div></div><div class="col-12"><label class="form-label">Objetivo</label><input class="form-control" name="description" maxlength="1000" placeholder="Ex.: garantir que todo lead quente receba uma ação rápida da equipe."></div></div>

              <div class="tc-automation-step"><div class="tc-automation-step-head"><span class="tc-automation-step-number">1</span><div><small>QUANDO</small><strong>O que deve iniciar este fluxo?</strong></div></div><div class="row g-2 mt-1"><div class="col-md-8"><label class="form-label">Gatilho</label><select class="form-select" name="trigger_type" id="tcAutomationTrigger"><?php foreach ($automationTriggers as $key => $trigger): ?><option value="<?= e($key) ?>"><?= e($trigger['label']) ?></option><?php endforeach; ?></select><small id="tcAutomationTriggerHelp" class="form-text"></small></div><div class="col-md-4"><label class="form-label" id="tcAutomationValueLabel">Janela</label><div class="input-group"><input class="form-control" type="number" name="trigger_value" id="tcAutomationTriggerValue" min="1" value="10"><span class="input-group-text" id="tcAutomationValueUnit">dias</span></div></div></div>
                <div class="tc-automation-status-condition d-none" id="tcAutomationStatusCondition"><label class="form-label">Em quais status?</label><div class="tc-automation-status-list"><?php foreach ($statusOptions as $key => $label): ?><label><input type="checkbox" name="trigger_statuses[]" value="<?= e($key) ?>"> <span><?= e($label) ?></span></label><?php endforeach; ?></div></div>
              </div>

              <div class="tc-automation-step"><div class="tc-automation-step-head"><span class="tc-automation-step-number">2</span><div><small>SE FOR NECESSÁRIO</small><strong>Refine quem entra no fluxo</strong></div><span class="tc-automation-optional">opcional</span></div><div class="row g-2 mt-1"><div class="col-md-5"><label class="form-label">Origem do lead</label><select class="form-select" name="condition_source"><option value="">Todas as origens</option><?php foreach ($sourceOptions as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label">Responsável atual</label><select class="form-select" name="condition_assigned_to"><option value="">Qualquer responsável</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-2 d-flex align-items-end"><label class="tc-automation-check-wide"><input type="checkbox" name="only_unassigned" value="1"><span>Sem responsável</span></label></div></div></div>

              <div class="tc-automation-step"><div class="tc-automation-step-head"><span class="tc-automation-step-number">3</span><div><small>ENTÃO</small><strong>O que o CRM deve fazer?</strong></div></div><p class="tc-automation-actions-help">Selecione quantas ações quiser. Cada uma aparece configurável logo abaixo.</p><div class="tc-automation-actions-grid">
                <?php foreach ($automationActions as $key => $action): ?><label class="tc-automation-action-choice"><input type="checkbox" name="actions[]" value="<?= e($key) ?>"><span class="tc-automation-action-icon"><i class="<?= e($action['icon']) ?>"></i></span><span><strong><?= e($action['label']) ?></strong><small><?= e($action['help']) ?></small></span><i class="fa-solid fa-check"></i></label><?php endforeach; ?>
              </div></div>

              <div class="tc-automation-configurations"><div class="tc-automation-config-head"><i class="fa-solid fa-sliders"></i><div><strong>Personalize as ações</strong><small>Campos em branco usam um padrão seguro do CRM.</small></div></div>
                <div class="tc-automation-action-config" data-action-config="send_whatsapp"><h6><i class="fa-brands fa-whatsapp"></i> WhatsApp</h6><div class="row g-2"><div class="col-md-8"><label class="form-label">Nome do template aprovado</label><input class="form-control" name="whatsapp_template" placeholder="Ex.: retorno_comercial"></div><div class="col-md-4"><label class="form-label">Idioma</label><input class="form-control" name="whatsapp_language" value="pt_BR"></div></div><small class="form-text">É necessário conectar uma instância e aprovar o template no WhatsApp Business.</small></div>
                <div class="tc-automation-action-config" data-action-config="send_email"><h6><i class="fa-solid fa-envelope"></i> E-mail</h6><div class="row g-2"><div class="col-md-5"><label class="form-label">Assunto</label><input class="form-control" name="email_subject" placeholder="Uma atualização para {{lead.nome}}"></div><div class="col-md-7"><label class="form-label">Mensagem</label><textarea class="form-control" name="email_body" rows="2" placeholder="Olá, {{lead.nome}}..."></textarea></div></div></div>
                <div class="tc-automation-action-config" data-action-config="create_task"><h6><i class="fa-solid fa-list-check"></i> Tarefa no Kanban</h6><div class="row g-2"><div class="col-md-6"><label class="form-label">Título</label><input class="form-control" name="task_title" placeholder="Retomar contato: {{lead.nome}}"></div><div class="col-md-3"><label class="form-label">Prazo</label><div class="input-group"><input class="form-control" type="number" name="task_due_hours" value="24" min="1"><span class="input-group-text">h</span></div></div><div class="col-md-3"><label class="form-label">Prioridade</label><select class="form-select" name="task_priority"><option value="baixa">Baixa</option><option value="media">Média</option><option value="alta" selected>Alta</option><option value="urgente">Urgente</option></select></div><div class="col-md-6"><label class="form-label">Responsável</label><select class="form-select" name="task_assigned_to"><option value="">Responsável do lead</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label">Orientação</label><input class="form-control" name="task_description" placeholder="Instruções para quem vai executar"></div></div></div>
                <div class="tc-automation-action-config" data-action-config="create_calendar_event"><h6><i class="fa-solid fa-calendar-plus"></i> Evento na agenda</h6><div class="row g-2"><div class="col-md-5"><label class="form-label">Título</label><input class="form-control" name="event_title" placeholder="Follow-up: {{lead.nome}}"></div><div class="col-md-2"><label class="form-label">Em</label><div class="input-group"><input class="form-control" type="number" name="event_in_hours" value="24" min="1"><span class="input-group-text">h</span></div></div><div class="col-md-2"><label class="form-label">Duração</label><div class="input-group"><input class="form-control" type="number" name="event_duration_minutes" value="30" min="15"><span class="input-group-text">min</span></div></div><div class="col-md-3"><label class="form-label">Responsável</label><select class="form-select" name="event_assigned_to"><option value="">Responsável do lead</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label">Descrição</label><input class="form-control" name="event_description" placeholder="Contexto exibido no calendário"></div></div></div>
                <div class="tc-automation-action-config" data-action-config="set_priority"><h6><i class="fa-solid fa-flag"></i> Prioridade comercial</h6><select class="form-select" name="priority_to"><option value="baixa">Baixa</option><option value="media">Média</option><option value="alta" selected>Alta</option><option value="urgente">Urgente</option></select></div>
                <div class="tc-automation-action-config" data-action-config="change_status"><h6><i class="fa-solid fa-arrow-right-arrow-left"></i> Status de destino</h6><select class="form-select" name="status_to"><option value="">Escolha uma etapa</option><?php foreach ($statusOptions as $key => $label): ?><?php if ($key !== 'fechado'): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endif; ?><?php endforeach; ?></select></div>
                <div class="tc-automation-action-config" data-action-config="reassign_owner"><h6><i class="fa-solid fa-people-arrows"></i> Novo responsável</h6><select class="form-select" name="reassign_to"><option value="">Selecione alguém</option><?php foreach ($users as $user): ?><option value="<?= (int) $user['id'] ?>"><?= e($user['name']) ?></option><?php endforeach; ?></select></div>
                <div class="tc-automation-action-config" data-action-config="schedule_followup"><h6><i class="fa-solid fa-clock"></i> Próximo contato</h6><div class="input-group"><input class="form-control" type="number" name="followup_hours" value="24" min="1"><span class="input-group-text">horas a partir de agora</span></div></div>
                <div class="tc-automation-action-config" data-action-config="add_tag"><h6><i class="fa-solid fa-tag"></i> Etiqueta</h6><div class="row g-2"><div class="col-md-9"><input class="form-control" name="tag_name" value="Automação" maxlength="80" placeholder="Nome da etiqueta"></div><div class="col-md-3"><input class="form-control form-control-color w-100" type="color" name="tag_color" value="#7c3aed" title="Cor da etiqueta"></div></div></div>
                <div class="tc-automation-action-config" data-action-config="log_history"><h6><i class="fa-solid fa-clock-rotate-left"></i> Registro no histórico</h6><input class="form-control" name="history_message" placeholder="Fluxo automático executado: {{fluxo}}."></div>
                <div class="tc-automation-variables"><i class="fa-solid fa-code"></i> Variáveis disponíveis: <code>{{lead.nome}}</code>, <code>{{lead.codigo}}</code>, <code>{{lead.status}}</code> e <code>{{fluxo}}</code>.</div>
              </div>
              <div class="tc-automation-preview" id="tcAutomationPreview"><i class="fa-solid fa-flask"></i><div><strong>Valide antes de ativar</strong><span>O teste exibirá a quantidade e uma amostra dos leads que atendem às regras.</span></div></div>
              <div class="tc-automation-builder-actions"><button type="button" class="btn btn-outline-primary" id="tcAutomationTest"><i class="fa-solid fa-flask"></i> Testar critérios</button><button class="btn btn-tc-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Salvar e ativar</button></div>
            </fieldset>
          </div>
        </form>
      </div>
    </div>
  <?php endif; ?>
</section>
<?php $pageScripts = '<script src="' . e(asset('js/automations.js')) . '"></script>'; ?>
