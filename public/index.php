<?php
/**
 * public/index.php
 * Front controller único da aplicação. Todas as requisições passam por aqui.
 */

// Carrega configurações gerais e de banco de dados
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';

// Inicia sessão segura (config de cookies já definida em config.php)
require_once APP_PATH . '/core/Auth.php';
Auth::start();

// Autoload simples das classes core/models/helpers utilizadas globalmente
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/core/Model.php';
require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Csrf.php';
require_once APP_PATH . '/core/Router.php';
require_once APP_PATH . '/helpers/helpers.php';

// Define as rotas da aplicação
$router = new Router();

// ---- Autenticação ----
$router->get('/', 'AuthController@login');
$router->get('login', 'AuthController@login');
$router->post('login', 'AuthController@authenticate');
$router->get('logout', 'AuthController@logout');
$router->get('esqueci-senha', 'AuthController@forgotPassword');
$router->post('esqueci-senha', 'AuthController@sendResetLink');
$router->get('redefinir-senha', 'AuthController@resetPasswordForm');
$router->post('redefinir-senha', 'AuthController@resetPassword');

// ---- Dashboard ----
$router->get('dashboard', 'DashboardController@index');
$router->get('dashboard/chart-data', 'DashboardController@chartData');

// ---- Meu Dia (Fase 7): visão unificada pessoal (leads atrasados, tarefas
// de hoje/atrasadas e leads sem primeiro contato) ----
$router->get('hoje', 'TodayController@index');

// ---- Metas pessoais mensais por vendedor (Fase 7, restrito a admin/supervisor) ----
$router->get('metas', 'GoalController@index');
$router->post('metas/update', 'GoalController@update');

// ---- Leads ----
$router->get('leads', 'LeadController@index');
$router->get('leads/create', 'LeadController@create');
$router->get('leads/buscar-rapido', 'LeadController@quickSearch');
$router->post('leads/store', 'LeadController@store');
$router->get('leads/{id}', 'LeadController@show');
$router->get('leads/{id}/edit', 'LeadController@edit');
$router->post('leads/{id}/update', 'LeadController@update');
$router->post('leads/{id}/delete', 'LeadController@destroy');
$router->post('leads/{id}/note', 'LeadController@addNote');
$router->post('leads/check-duplicate', 'LeadController@checkDuplicate');
$router->post('leads/{id}/whatsapp', 'WhatsappController@send');

// ---- Fase 7 (auditoria UX): observação rápida e ações em lote ----
// (leads/buscar-rapido é registrada ANTES de leads/{id} logo abaixo, já que
// o Router faz "primeiro match vence" e leads/{id} casaria com qualquer path).
$router->post('leads/acao-em-lote', 'LeadController@bulkAction');
$router->post('leads/{id}/transferir', 'LeadController@transfer');
$router->post('leads/{id}/nota-rapida', 'LeadController@quickNote');

// ---- Importação de leads via CSV (Fase 4) ----
$router->get('importar', 'ImportController@index');
$router->post('importar/preview', 'ImportController@preview');
$router->post('importar/processar', 'ImportController@processar');
$router->get('importar/relatorio/{id}', 'ImportController@relatorio');
$router->get('importar/relatorio/{id}/exportar-erros', 'ImportController@exportarErros');

// ---- Pipeline (Kanban) ----
$router->get('pipeline', 'PipelineController@index');
$router->post('pipeline/move', 'PipelineController@move');

// ---- SLA (Fase 2) ----
$router->get('sla', 'SlaController@index');

// ---- Indicadores: mapa do Brasil + heatmap de horários (Fase 2) ----
$router->get('indicadores', 'IndicatorController@index');

// ---- Relatórios exportáveis: CSV / Excel / Impressão-PDF (Fase 2) ----
$router->get('relatorios', 'ReportController@index');
$router->post('relatorios/analisar-ia', 'ReportController@analyze');
$router->get('relatorios/exportar-csv', 'ReportController@exportCsv');
$router->get('relatorios/exportar-excel', 'ReportController@exportExcel');
$router->get('relatorios/imprimir', 'ReportController@printView');
$router->get('relatorios/personalizar', 'ReportController@customize');
$router->post('relatorios/personalizar/atualizar', 'ReportController@customizeUpdate');

// ---- Construtor de Formulários: captação pública personalizável ----
// Painel administrativo restrito a "forms.manage" (ver FormController).
$router->get('formularios', 'FormController@index');
$router->get('formularios/novo', 'FormController@create');
$router->post('formularios/store', 'FormController@store');
$router->get('formularios/{id}/editar', 'FormController@edit');
$router->post('formularios/{id}/update', 'FormController@update');
$router->post('formularios/{id}/status', 'FormController@toggleActive');
$router->post('formularios/{id}/excluir', 'FormController@destroy');
$router->post('formularios/{id}/api-key', 'FormController@rotateApiKey');
// Página pública (sem login) — link compartilhável e destino do QR Code por vendedor.
$router->get('f/{slug}', 'FormController@show');
$router->post('f/{slug}', 'FormController@submit');
// API pública por formulário: autenticação por chave individual e CORS opt-in.
$router->options('api/v1/forms/{slug}/leads', 'FormController@apiOptions');
$router->options('api/v1/forms/{slug}/schema', 'FormController@apiOptions');
$router->get('api/v1/forms/{slug}/schema', 'FormController@apiSchema');
$router->post('api/v1/forms/{slug}/leads', 'FormController@apiSubmit');

// ---- Motivos de perda (Fase 2) ----
$router->get('motivos-perda', 'LossReasonController@index');
$router->post('motivos-perda/store', 'LossReasonController@store');
$router->post('motivos-perda/{id}/update', 'LossReasonController@update');
$router->post('motivos-perda/{id}/delete', 'LossReasonController@destroy');

// ---- Agenda (Fase 2; ação rápida de contato na Fase 5) ----
$router->get('agenda', 'AgendaController@index');
$router->post('agenda/agendar', 'AgendaController@schedule');
$router->post('agenda/{id}/quick-contact', 'AgendaController@quickContact');

// ---- Workspace colaborativo ----
$router->get('calendario', 'WorkspaceController@calendar');
$router->get('calendario/eventos', 'WorkspaceController@events');
$router->post('calendario/eventos', 'WorkspaceController@saveEvent');
$router->post('calendario/eventos/{id}/mover', 'WorkspaceController@moveEvent');
$router->post('calendario/eventos/{id}/excluir', 'WorkspaceController@deleteEvent');
$router->get('conteudo', 'WorkspaceController@content');
$router->post('conteudo/salvar', 'WorkspaceController@savePage');
$router->post('conteudo/{id}/anexos', 'WorkspaceController@uploadAttachment');
$router->post('conteudo/fontes', 'WorkspaceController@addKnowledgeSource');
$router->post('conteudo/fontes/{id}/analisar', 'WorkspaceController@crawlKnowledgeSource');
$router->post('conteudo/fontes/{id}/publicar', 'WorkspaceController@publishKnowledgeSource');
$router->post('conteudo/fontes/{id}/excluir', 'WorkspaceController@deleteKnowledgeSource');
$router->get('whiteboards', 'WorkspaceController@whiteboard');
$router->post('whiteboards/salvar', 'WorkspaceController@saveBoard');
$router->post('whiteboards/{id}/excluir', 'WorkspaceController@deleteBoard');
$router->get('automacoes', 'WorkspaceController@automations');
$router->post('automacoes/salvar', 'WorkspaceController@saveFlow');
$router->post('automacoes/testar', 'WorkspaceController@previewFlow');
$router->post('automacoes/{id}/alternar', 'WorkspaceController@toggleFlow');
$router->post('automacoes/{id}/executar', 'WorkspaceController@runFlow');
$router->post('automacoes/{id}/excluir', 'WorkspaceController@deleteFlow');
$router->post('workspace/preferencias', 'WorkspaceController@preferences');
$router->post('assistente/perguntar', 'WorkspaceController@assistant');

// ---- Usuários (Fase 2, restrito a admin) ----
$router->get('usuarios', 'UserController@index');
$router->get('usuarios/create', 'UserController@create');
$router->post('usuarios/store', 'UserController@store');
$router->get('usuarios/{id}/edit', 'UserController@edit');
$router->post('usuarios/{id}/update', 'UserController@update');
$router->post('usuarios/{id}/status', 'UserController@toggleActive');
$router->post('usuarios/{id}/delete', 'UserController@destroy');

// ---- Departamentos (Fase 8): catálogo usado pelo Chat interno e pela ficha do usuário ----
$router->get('departamentos', 'DepartmentController@index');
$router->post('departamentos/store', 'DepartmentController@store');
$router->post('departamentos/{id}/update', 'DepartmentController@update');
$router->post('departamentos/{id}/status', 'DepartmentController@toggleActive');

// ---- Perfil do usuário logado (Fase 2) ----
$router->get('uploads/avatars/{filename}', 'ProfileController@avatar');
$router->get('uploads/{filename}', 'UploadController@image');
$router->get('perfil', 'ProfileController@index');
$router->post('perfil/update', 'ProfileController@update');

// ---- Configurações gerais do sistema (Fase 2, restrito a admin) ----
$router->get('configuracoes', 'SettingController@index');
$router->post('configuracoes/update', 'SettingController@update');

// ---- Lead Score: critérios e pesos, incluindo peso por origem (Fase 5) ----
$router->get('configuracoes/lead-score', 'LeadScoreController@index');
$router->post('configuracoes/lead-score/update', 'LeadScoreController@update');

// ---- Templates de mensagem para WhatsApp (Fase 7 - auditoria UX) ----
$router->get('configuracoes/whatsapp-templates', 'WhatsappTemplateController@index');
$router->get('configuracoes/whatsapp-templates/listar', 'WhatsappTemplateController@listJson');
$router->post('configuracoes/whatsapp-templates/store', 'WhatsappTemplateController@store');
$router->post('configuracoes/whatsapp-templates/{id}/update', 'WhatsappTemplateController@update');
$router->post('configuracoes/whatsapp-templates/{id}/delete', 'WhatsappTemplateController@destroy');
$router->get('configuracoes/email-templates', 'EmailTemplateController@index');
$router->get('configuracoes/email-templates/listar', 'EmailTemplateController@listJson');
$router->post('configuracoes/email-templates/store', 'EmailTemplateController@store');
$router->post('configuracoes/email-templates/{id}/update', 'EmailTemplateController@update');
$router->post('configuracoes/email-templates/{id}/delete', 'EmailTemplateController@destroy');
$router->post('configuracoes/evolution/instancias/salvar', 'SettingController@saveEvolutionConnection');
$router->post('configuracoes/evolution/instancias/{id}/desativar', 'SettingController@deactivateEvolutionConnection');
$router->post('configuracoes/evolution/fluxos/salvar', 'SettingController@saveEvolutionFlow');

// ---- Logs de atividade (Fase 2) ----
$router->get('logs', 'LogController@index');

// ---- Notificações internas (Fase 3, polling via AJAX) ----
$router->get('notifications/unread', 'NotificationController@unread');
$router->post('notifications/{id}/read', 'NotificationController@markRead');
$router->post('notifications/read-all', 'NotificationController@markAllRead');

// ---- Chat interno por departamento (polling AJAX, sem websockets) ----
$router->get('chat', 'ChatController@index');
$router->get('chat/salas/{id}/mensagens', 'ChatController@messages');
$router->post('chat/salas/{id}/mensagens', 'ChatController@send');
$router->post('chat/salas/{id}/ler', 'ChatController@markRead');
$router->post('chat/salas/{id}/silenciar', 'ChatController@toggleMute');
$router->post('chat/salas/{id}/sair', 'ChatController@leaveRoom');
$router->post('chat/salas/{id}/convidar', 'ChatController@inviteMembers');
$router->post('chat/salas/{id}/atualizar', 'ChatController@updateGroup');
$router->post('chat/salas/{id}/membros/{memberId}/remover', 'ChatController@removeGroupMember');
$router->post('chat/salas/{id}/excluir', 'ChatController@deleteGroup');
$router->get('chat/salas/{id}/membros', 'ChatController@members');
$router->post('chat/salas/lead/{id}', 'ChatController@createLeadRoom');
$router->post('chat/salas/tarefa/{id}', 'ChatController@createTaskRoom');
$router->post('chat/salas', 'ChatController@createRoom');
$router->post('chat/direto/{userId}', 'ChatController@direct');
$router->post('chat/mensagens/{id}/apagar', 'ChatController@deleteMessage');
$router->get('chat/usuarios/buscar', 'ChatController@searchUsers');
$router->get('chat/nao-lidas', 'ChatController@unreadTotal');

// ---- Atendimento WhatsApp (Evolution/EvoAI CRM) ----
// Inbox de conversas ao vivo (estilo "Zap Responder"): vínculo com leads,
// transferência entre colaboradores, etiquetas e polling AJAX (mesmo padrão
// sem WebSockets do Chat interno). Ver app/controllers/EvolutionInboxController.php
// e app/services/EvolutionClient.php. Restrito por evolution.view/evolution.manage
// (ver database/sql/migration_evolution_inbox.sql).
$router->get('atendimento-whatsapp', 'EvolutionInboxController@index');
$router->get('atendimento-whatsapp/{id}/poll', 'EvolutionInboxController@poll');
$router->post('atendimento-whatsapp/{id}/enviar', 'EvolutionInboxController@send');
$router->post('atendimento-whatsapp/{id}/email', 'EvolutionInboxController@sendEmail');
$router->post('atendimento-whatsapp/{id}/fluxo', 'EvolutionInboxController@setFlow');
$router->post('atendimento-whatsapp/{id}/fluxo/avancar', 'EvolutionInboxController@advanceFlow');
$router->post('atendimento-whatsapp/{id}/vincular-lead', 'EvolutionInboxController@linkLead');
$router->post('atendimento-whatsapp/{id}/criar-lead', 'EvolutionInboxController@createLead');
$router->post('atendimento-whatsapp/{id}/criar-tarefa', 'EvolutionInboxController@createTask');
$router->post('atendimento-whatsapp/{id}/atualizar-contato', 'EvolutionInboxController@refreshContact');
$router->post('atendimento-whatsapp/{id}/telefone', 'EvolutionInboxController@updatePhone');
$router->post('atendimento-whatsapp/notas/{messageId}/remover', 'EvolutionInboxController@deleteNote');
$router->post('atendimento-whatsapp/{id}/transferir', 'EvolutionInboxController@transfer');
$router->post('atendimento-whatsapp/{id}/etiquetas', 'EvolutionInboxController@labels');
$router->post('atendimento-whatsapp/mapeamentos', 'EvolutionInboxController@saveMappings');
$router->post('atendimento-whatsapp/testar', 'EvolutionInboxController@test');
$router->post('atendimento-whatsapp/qrcode', 'EvolutionInboxController@qrcode');
$router->post('atendimento-whatsapp/sincronizar', 'EvolutionInboxController@sync');
$router->post('atendimento-whatsapp/webhook/configurar', 'EvolutionInboxController@configureWebhook');
// Webhook público (sem login) que recebe mensagens em tempo real da Evolution API.
$router->post('webhook/evolution', 'EvolutionWebhookController@receive');

// ---- Tarefas: delegação de demandas de trabalho entre colaboradores ----
// (ver database/sql/migration_tasks.sql e app/controllers/TaskController.php)
$router->get('tarefas', 'TaskController@index');
$router->get('tarefas/nova', 'TaskController@create');
$router->post('tarefas', 'TaskController@store');
$router->get('tarefas/{id}', 'TaskController@show');
$router->get('tarefas/{id}/edit', 'TaskController@edit');
$router->post('tarefas/{id}', 'TaskController@update');
$router->post('tarefas/{id}/comentarios', 'TaskController@addComment');
$router->post('tarefas/{id}/status', 'TaskController@changeStatus');
$router->post('tarefas/{id}/excluir', 'TaskController@destroy');

// ---- Webhook público de captação de leads (Fase 3) ----
// IMPORTANTE: rotas SEM login (ver comentário em app/core/Router.php e
// app/controllers/WebhookController.php) — protegidas por token secreto
// (?token=...) comparado com o valor salvo em Configurações.
$router->get('webhook/meta', 'WebhookController@verifyMeta');
$router->post('webhook/meta', 'WebhookController@meta');
$router->post('webhook/google', 'WebhookController@google');
$router->post('webhook/generico', 'WebhookController@generico');
$router->get('webhook/whatsapp', 'WhatsappInboundController@verify');
$router->post('webhook/whatsapp', 'WhatsappInboundController@receive');

// Despacha a requisição
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
