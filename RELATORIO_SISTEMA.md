# Relatório Completo do Sistema — Titanium CRM

**Empresa:** Titanium Consultoria (Cartas Contempladas, Consórcios e Crédito)
**Geração do relatório:** 06/08/2026
**Documento raiz complementar:** `README.md` (contém o passo a passo de instalação/deploy)

---

## 1. Visão geral

O **Titanium CRM** é um sistema administrativo completo para gestão de **leads captados via Meta Ads, Google Ads e outras fontes**, com operação comercial de atendimento, pipeline, tarefas, chat interno, atendimento WhatsApp e colaboração por IA.

Principais características:

| Característica | Detalhe |
|---|---|
| **Linguagem** | PHP 8.x **puro** (sem Composer, sem frameworks, sem Node) |
| **Banco de dados** | MySQL 8 |
| **Servidor** | Apache com `mod_rewrite` (front controller em `public/`) |
| **Front-end** | Bootstrap 5.3, Chart.js, FontAwesome, SweetAlert2, Toastify, Tippy.js, Leaflet (via CDN) |
| **Hospedagem-alvo** | Compartilhada (Hostinger) — deploy por FTP, sem build step |
| **PWA** | Instalável (manifest + service worker) com notificações do navegador |
| **Origem** | Desenvolvido em 8 fases + módulos adicionais (documentados no README) |
| **URL base configurada** | `https://azure-eel-382308.hostingersite.com` |

---

## 2. Stack tecnológica (ecossistema de bibliotecas)

### Back-end (nativos do PHP, sem dependências externas)
- **PDO** com prepared statements — todas as queries.
- **cURL nativo** — integrações HTTP/APIs (Meta Graph API, Evolution API, Gemini, crawler).
- **Sockets nativos** `fsockopen` / `stream_socket_enable_crypto` — cliente SMTP próprio.
- **`password_hash()` / `password_verify()`** — bcrypt para senhas.
- **`fgetcsv`** — importação de CSV (sem libs externas).

### Front-end (CDNs, sem build step)
- **Bootstrap 5.3** — layout e responsividade (mobile-first).
- **Chart.js** — gráficos do Dashboard, SLA, Indicadores, Motivos de Perda.
- **Leaflet.js + Leaflet.heat + OpenStreetMap** — mapa do Brasil em Indicadores (só carrega nessa tela).
- **SweetAlert2** — modais/confirmações.
- **Toastify.js** — toasts rápidos não-bloqueantes.
- **Tippy.js** — tooltips.
- **FontAwesome 6** — ícones.
- **Service Worker + manifest.json** — PWA e notificações.
- **HTML5 Drag and Drop nativo** — Kanban em desktop.

### Arquivos estáticos próprios
- `public/assets/css/app.css` (≈70 KB) — tema `--tc-*`, dark mode, responsividade, Kanban, Chat.
- `public/assets/js/app.js` (≈82 KB) — orquestra notificações, chat, kanban, busca global, ações em lote, wizard, IA, etc.
- `public/assets/js/agenda.js`, `evolution.js`, `workspace.js` — JS específicos por módulo.
- `public/assets/data/brazil-states.geojson` (~1,6 MB) — GeoJSON dos 27 estados (choropleth).

---

## 3. Arquitetura e estrutura de pastas

```
leads/
├── app/
│   ├── controllers/    → 29 controllers (lógica de cada rota)
│   ├── models/         → 35 models (acesso a dados)
│   ├── core/           → micro-framework próprio (8 arquivos)
│   ├── helpers/        → helpers.php + insights.php (funções globais)
│   ├── services/       → 4 serviços de integração (Gemini, Evolution, Crawler, Automações)
│   └── views/          → 22+ pastas de views (renderização HTML)
├── config/             → config.php + database.php
├── database/sql/       → schema.sql, seed.sql e 16 migrations incrementais
├── public/             → document root (front controller, assets, uploads, cron, PWA)
├── storage/
│   ├── imports/        → CSVs temporários de importação (apagados ao final)
│   └── logs/           → php_errors.log, mail.log
└── README.md           → guia de instalação/deploy completo
```

### Núcleo do micro-framework (`app/core/`)

| Arquivo | Responsabilidade |
|---|---|
| `Router.php` | Roteador por array de rotas, suporte a parâmetros `{id}`, primeiro-match vence |
| `Controller.php` | Base dos controllers: `view()`, `redirect()`, `json()`, `input()`, `requireLogin()` |
| `Model.php` | Base dos models: `find()`, `all()`, `delete()`, `count()` |
| `Database.php` | Singleton PDO com conexão única |
| `Auth.php` | Sessão segura, login/logout, `hasRole()`, `can()` (permissões), cache de permissões |
| `Csrf.php` | Tokens CSRF por sessão + verificação de requests |
| `Mailer.php` | Cliente SMTP nativo (STARTTLS/SSL, AUTH LOGIN), nunca trava o fluxo |
| `WhatsappClient.php` | Cliente da WhatsApp Cloud API (texto + templates) via cURL |

### Serviços de integração (`app/services/`)

| Serviço | Função |
|---|---|
| `GeminiService.php` | Chat com o Gemini (assistente, abordagem, objeção, análise de conteúdo de sites) |
| `EvolutionClient.php` | Cliente da Evolution API (gateway WhatsApp self-hosted) |
| `WebsiteCrawlerService.php` | Crawler seguro de sites (para a Wiki automática) |
| `AutomationRunner.php` | Motor de automações (fluxos "lead parado") |

---

## 4. Banco de dados — ecossistema de dados (tabelas)

### 4.1 Tabelas do núcleo (`schema.sql`)
| Tabela | Finalidade |
|---|---|
| `users` | Equipe interna (admin / supervisor / consultor) |
| `loss_reasons` | Catálogo de motivos de perda de lead |
| `leads` | Tabela principal: briefing completo do lead + metadados de captura |
| `lead_history` | Timeline de eventos do lead (criação, contato, whatsapp, status...) |
| `tags` / `lead_tags` | Etiquetas livres e relação N:N com leads |
| `pipeline_stages` | Colunas do Kanban |
| `settings` | Configurações chave/valor |
| `activity_log` | Auditoria de ações sensíveis |

### 4.2 Tabelas de módulos (migrations)
| Módulo | Tabelas |
|---|---|
| Lead Score (Fase 2) | `lead_score_rules` |
| Permissões (Fase 3) | `permissions`, `role_permissions`, `user_permissions` |
| Notificações (Fase 3) | `notifications`, `notification_events` |
| Importação (Fase 4) | `imports`, `import_errors` |
| Tarefas | `tasks`, `task_comments`, `task_history`, `task_watchers` |
| Chat interno | `chat_departments`, `chat_rooms`, `chat_room_members`, `chat_messages` |
| Metas (Fase 7) | `user_goals` |
| Templates WhatsApp (Fase 7) | `whatsapp_templates` |
| Workspace | `calendar_events`, `workspace_pages`, `workspace_attachments`, `whiteboards`, `whiteboard_members`, `knowledge_sources` |
| Automações | `automation_flows`, `automation_runs` |
| Preferências | `user_workspace_preferences` |
| Atendimento WhatsApp | `evolution_conversation_links`, `evolution_messages`, `evolution_agent_mappings` |

**Total:** 9 tabelas-base + ~28 tabelas de módulos = **37 tabelas** (todas InnoDB, utf8mb4, com FKs e índices).

### 4.3 Colunas adicionadas por migration
- `users`: `password_reset_token`, `password_reset_expires`, `department_id`
- `leads`: `lead_code` (único, formato `LEAD-AAAAMMDD-NNNN`), ENUM de `source` ampliado (`cadastro_manual`, `whatsapp`, `importacao_csv`, `api`, `webhook`)
- `calendar_events`: `priority`, `guidance`; `whiteboards`: `visibility`; `workspace_pages`: `assigned_to`, `category`, `visibility`; `automation_flows`: `description`, `is_template`
- `evolution_conversation_links`: `labels`, `last_message`, `last_message_at`, `unread_count`, `avatar_url`
- Índices novos em `leads` e `lead_history` (Fase 7)

---

## 5. Funcionalidades — mapa completo de módulos

### 5.1 Acesso e identidade
- **Login/Logout** com CSRF, sessão segura (httponly, samesite, secure), `session_regenerate_id`.
- **Esqueci minha senha** — token com validade de 60 min, envio real por SMTP (nativo).
- **Perfil** — editar nome, e-mail, avatar (upload) e trocar senha.
- **Dark mode** (localStorage) e identidade visual própria.

### 5.2 Dashboard (`/dashboard`)
- KPIs reais via PDO: total, novos (hoje/semana/mês), qualificados, perdidos, em atendimento, taxa de conversão, sem contato.
- 4 gráficos Chart.js: leads por dia, por origem, por estado, funil por status.
- **Insights automáticos** (cards coloridos): comparação semanal, melhor DDD/origem/consultor, queda de conversão, leads parados 10+ dias — **links clicáveis** que abrem a lista de leads já filtrada.

### 5.3 Meu Dia (`/hoje`)
- Visão pessoal unificada (sempre filtrada pelo usuário logado) combinando 3 fontes:
  1. Leads vencidos (mesmo critério da Agenda)
  2. Tarefas de hoje/atrasadas (atribuídas)
  3. Leads sem primeiro contato há 24h+
- Cards de contador + lista por urgência com ações rápidas ("Registrar contato agora", "Concluir tarefa").

### 5.4 Leads (CRUD completo)
- Listagem com busca, filtros, ordenação e paginação server-side.
- **Wizard de cadastro em 4 abas**: 1) Primeiro Contato, 2) Perfil, 3) Qualificação, 4) Negociação.
- Código único do lead (`LEAD-AAAAMMDD-NNNN`, sequencial diário).
- Máscaras de telefone/CPF/CEP; checagem de duplicidade via AJAX (WhatsApp/telefone/CPF/e-mail) com modal de 3 opções (abrir existente / atualizar existente / criar mesmo assim).
- Perfil do lead: timeline completa, tags, observação rápida via AJAX, motivos de perda, **nota rápida** e **templates de WhatsApp**.
- Colunas visíveis: Score (badge), Temperatura, Últ. contato (destaque vermelho quando parado).
- **Escopo "Meus leads"/"Todos os leads"** (admin/supervisor veem o toggle; consultor fica travado nos próprios).
- **Ações em lote**: mudar status, reatribuir responsável, aplicar tag (auditado lead a lead).
- **Busca global no topbar** (nome, telefone, e-mail, código) com debounce.

### 5.5 Pipeline / Kanban (`/pipeline`)
- Colunas por `pipeline_stages`; cards com temperatura, score e último contato.
- Desktop: drag and drop nativo. Mobile: scroll-snap + botão "Mover para" (mesmo endpoint AJAX).
- Endpoint `POST /pipeline/move` persiste e grava no histórico.
- Filtro por responsável (só no escopo "Todos os leads").
- Query otimizada com `ROW_NUMBER()` (MySQL 8) eliminando o problema N+1.

### 5.6 Agenda (`/agenda`)
- Agrupamento: Atrasados / Hoje / Próximos 7 dias / Mais adiante / Sem data.
- **"Novo Agendamento"** (define `next_contact_at` de um lead já cadastrado, com busca rápida).
- **"Registrar contato agora"** via AJAX (atualiza histórico, contato e Lead Score sem sair da tela).
- Filtro por responsável (restrito a admin/supervisor). Badge de atrasados na sidebar.
- Botões diretos para o perfil do lead e modal de WhatsApp.

### 5.7 SLA (`/sla`)
- Tempo médio até primeira resposta; % respondidos em 15 min; aguardando primeiro contato há 24h+.
- Tabela comparativa por consultor.
- **Metodologia (Fase 5):** primeira resposta = qualquer evento `contato`, `whatsapp` ou `ligacao` no histórico.

### 5.8 Indicadores (`/indicadores`)
- **Mapa do Brasil interativo (Leaflet)**: choropleth por intensidade de leads + heatmap + tooltip com total e taxa de conversão por UF + fallback gracioso se a lib/GeoJSON falhar.
- **Heatmap de horários**: 7 dias × 24 horas colorido por intensidade.
- Ranking de estados.

### 5.9 Relatórios (`/relatorios`)
- Filtros por período, estado, origem, status e responsável.
- Exportação em **CSV** (nativo), **Excel** (.xls via HTML) e **PDF via impressão** (`window.print()`).
- **Personalização global** (Fase 6): logo próprio, cor primária, CNPJ/endereço/telefone no cabeçalho, rodapé e **seleção/ordem de colunas** (aplicada às 3 exportações).

### 5.10 Motivos de Perda (`/motivos-perda`)
- CRUD completo (criar, renomear, ativar/desativar, excluir) + gráfico Chart.js de ranking + paginação.

### 5.11 Importação de Leads (`/importar`)
- Fluxo em 3 passos: upload (CSV até 15 MB), mapeamento de colunas (com prévia e sugestão automática), processamento (até 2.000 linhas).
- Detecção de duplicidade: atualiza existentes (só sobrescreve campos preenchidos) ou cria novos com `source = importacao_csv`.
- Relatório de importação com erros/avisos linha a linha + exportação de erros em CSV.
- Arquivo temporário em `storage/imports/` apagado ao final.
- **Limitação:** `.xlsx` nativo não é suportado (sem Composer/PhpSpreadsheet) — converter para CSV.

### 5.12 Tarefas (`/tarefas`)
- Delegação de demandas internas (opcionalmente vinculadas a um lead).
- Abas: Minhas Tarefas / Criadas por mim / Todas (com permissão).
- Timeline (`task_history`), comentários, watchers, mudança rápida de status via AJAX.
- **SLA da tarefa** (`sla_hours` opcional): badge "Xh restantes"/"Estourado", e comparação com `completed_at`.
- **Sincronização com Agenda** (conservadora): checkbox ao criar tarefa atualiza `next_contact_at`; botão "Concluir e sincronizar contato do lead"; aviso ao registrar contato se houver tarefa aberta.

### 5.13 Chat Interno (`/chat`)
- Por **departamentos** (Comercial, Suporte, Financeiro, Diretoria, Geral), grupos customizados e **DM 1-a-1**.
- Polling AJAX (5s na tela, 18s no badge da sidebar), pausado com a aba em segundo plano.
- Histórico incremental (`since_id`/`before_id`), marcação de lido, silenciar, sair.
- **Comandos `/`**: `/ajuda`, `/limpar`, `/adicionar @usuario`, `/remover @usuario` (com permissões).
- Moderação local por sala (membro/moderador/admin_sala) + permissão global `chat.moderate`.
- Soft delete de mensagens, XSS prevenido na saída (PHP e JS), toasts e animações de novas mensagens.
- **Limitações aceitas:** sem tempo real via WebSocket; trocar de sala recarrega a página; sem upload de arquivos no chat.

### 5.14 Atendimento WhatsApp — Evolution API (`/atendimento-whatsapp`)
Inbox estilo "Zap Responder/Chatwoot" sobre a **Evolution API** (gateway self-hosted):
- Lista de conversas com filtros (todas, não lidas, minhas, não atribuídas) e busca.
- Envio de mensagens, **notas internas** (privadas, não enviadas ao cliente), histórico por conversa.
- **Vínculo com leads** (manual ou automático por telefone), criação de lead e de tarefa direto da conversa.
- Transferência entre colaboradores (também atualiza o responsável do lead), etiquetas (sincronizadas com tags de lead).
- Sincronização do histórico antigo com a Evolution; QR Code para conectar a instância; teste de conexão; configuração automática do webhook.
- Responsável do lead é a "fonte da verdade" (dessincronização corrigida automaticamente).
- Polling AJAX de mensagens novas por conversa.
- **Contatos de anúncio (`@lid`)**: sem número exposto pela Meta — número informado manualmente.

### 5.15 Calendário (`/calendario`)
- Eventos com data/hora, dia inteiro, cor, prioridade, descrição e orientação.
- Vínculo opcional com lead e responsável; opção de **criar tarefa automaticamente**.
- Arrastar para mover (drag) e excluir (com regra de permissão por criador/gestão).
- Notificação interna ao atribuir evento a outro usuário.

### 5.16 Documentos e Wiki (`/conteudo`)
- Páginas tipo `documento` e `wiki` com categorias, tags, fixação, visibilidade (equipe/privado) e vínculo com lead.
- Anexos (PDF, TXT, Excel, CSV, imagens até 15 MB) em `public/uploads/workspace/`.
- **Fontes de Conhecimento** (`knowledge_sources`, restrito a admin): cadastro de URL → **crawler** (extrai texto, até 2 MB, validação anti-SSRF) → **análise opcional por IA** (reorganiza em artigo de Wiki sem inventar dados) → **publicação** na Wiki.
- Páginas oficiais seedadas da Titanium (identidade, cartas contempladas, administradoras).

### 5.17 Whiteboards (`/whiteboards`)
- Quadros visuais com `board_json`, visibilidade e membros colaboradores.
- Permissão de exclusão: criador ou gestão.

### 5.18 Automações (`/automacoes`, restrito a admin/supervisor)
- Fluxos do tipo **"lead parado"** (sem contato há N dias).
- Ações por fluxo (combinação): Criar tarefa, Avisar gestor, Aumentar prioridade, Notificar responsável, Adicionar tag "Automação", Registrar histórico, **Enviar WhatsApp** (template aprovado).
- Execução: manual (botão), automática via cron (`public/cron-automations.php?token=...`), template pré-carregado.
- Log de execuções em `automation_runs` (sucesso/parcial/erro), deduplicação diária por lead.

### 5.19 Assistente IA — Gemini (`Assistente IA` no botão flutuante)
- Disponível em **todas as telas** (FAB) e acessível a todos os papéis.
- 4 modos: assistente geral, **abordagem comercial**, **quebra de objeção**, busca na Wiki.
- Contexto limitado ao que o usuário pode ver (leads visíveis, tarefas, wiki) — respeita escopo e papéis.
- Configurável em Configurações (chave `gemini_api_key`, modelo `gemini_model`).

### 5.20 Notificações internas
- Sino no topbar com contador; dropdown com lista; "marcar todas como lidas".
- Geradas em: lead novo atribuído, mudança de status crítico no Kanban (fechado/perdido), lead parado 5+ dias, mensagens no Atendimento WhatsApp, nova tarefa/evento atribuído, respostas de cliente no WhatsApp, automações.

### 5.21 Gestão administrativa
- **Usuários** (admin): CRUD com papel, ativo/inativo, departamento e senha.
- **Departamentos** (admin): catálogo com cor/ícone, criação automática da sala de chat, soft-toggle (Geral não pode ser desativado).
- **Metas** (admin/supervisor): meta mensal de fechamentos e novos leads por vendedor + barra de progresso no "Meu Dia".
- **Configurações** (admin): identidade, logo/favicon, SMTP, WhatsApp Cloud, Evolution API, webhooks, Gemini, automação de WhatsApp.
- **Lead Score** (admin): edição de critérios e pesos por origem.
- **Templates de WhatsApp** (admin): CRUD de mensagens com placeholders `{{nome}}`, `{{interesse}}`, `{{responsavel}}`.
- **Logs** (admin): auditoria paginada com filtros por usuário, ação e período.

---

## 6. Integrações externas (conexões do sistema)

| Integração | Direção | O que faz | Configuração |
|---|---|---|---|
| **Meta Ads (Lead Gen)** | Entrada | Webhook público recebe leads; busca dados completos na Graph API pelo `leadgen_id` | Token secreto + Token Graph API |
| **Google Ads (Lead Form)** | Entrada | Webhook recebe leads via "Conectar CRM" | Token secreto do webhook |
| **Webhook genérico** | Entrada | Qualquer POST (Zapier, Make, landing page, formulário) cria leads | Token secreto |
| **WhatsApp Cloud API (Meta)** | Saída/Entrada | Envio de texto e templates; webhook de respostas do cliente | `whatsapp_token`, `whatsapp_phone_id` |
| **Evolution API** | Entrada/Saída | Gateway WhatsApp self-hosted: inbox completo, envio, QR, etiquetas, webhook | URL, apikey, instância, token do webhook |
| **SMTP (e-mail Hostinger)** | Saída | "Esqueci minha senha" + notificação ao consultor atribuído | `smtp_host/port/user/pass/...` |
| **Google Gemini (IA)** | Saída | Assistente, abordagens, objeções, análise de conteúdo p/ Wiki | `gemini_api_key`, `gemini_model` |
| **Web Crawler** | Saída | Extração de sites para a Wiki (validação anti-SSRF) | — (uso interno, admin) |
| **Leaflet + OSM** | Front-end | Mapa do Brasil em Indicadores | — (CDN + GeoJSON local) |
| **PWA / Service Worker** | Front-end | App instalável + notificações nativas do navegador | — (nativo; push Web real requer VAPID, pendente) |

### Endpoints de webhook públicos (sem login, protegidos por token)
| Endpoint | Função |
|---|---|
| `GET/POST /webhook/meta` | Verificação (hub.challenge) e recebimento de leads da Meta |
| `POST /webhook/google` | Recebimento de leads do Google Ads |
| `POST /webhook/generico` | Recebimento de leads genérico (form-urlencoded ou JSON) |
| `GET/POST /webhook/whatsapp` | Verificação e recebimento de mensagens da WhatsApp Cloud API |
| `POST /webhook/evolution` | Recebimento em tempo real de mensagens da Evolution API |
| `GET /cron-automations.php?token=...` | Execução agendada (cron) das automações |

---

## 7. Permissões e papéis

### Papéis
- **admin**: acesso total (inclui Usuários, Departamentos, Configurações, Logs, Fontes de Conhecimento, QR Evolution).
- **supervisor**: tudo, exceto telas exclusivas de admin; vê todos os leads e transfere atendimentos.
- **consultor**: opera leads próprios, tarefas, chat, atendimentos a ele atribuídos, criação de leads.

### Matriz de permissões (tabelas `permissions` + `role_permissions` + `user_permissions`)
Permissões por slug (padrão por papel):
| Slug | admin | supervisor | consultor |
|---|---|---|---|
| `leads.view` / `create` / `edit` | ✅ | ✅ | ✅ |
| `leads.delete` | ✅ | ✅ | ❌ |
| `leads.export` | ✅ | ✅ | ❌ |
| `pipeline.manage` | ✅ | ✅ | ✅ |
| `reports.view` | ✅ | ✅ | ❌ |
| `users.manage` | ✅ | ❌ | ❌ |
| `settings.manage` | ✅ | ❌ | ❌ |
| `tasks.view_all` | ✅ | ✅ | ❌ |
| `tasks.create` | ✅ | ✅ | ✅ |
| `tasks.manage` | ✅ | ✅ | ❌ |
| `chat.moderate` | ✅ | ✅ | ❌ |
| `chat.create_room` | ✅ | ✅ | ✅ |
| `evolution.view` | ✅ | ✅ | ✅ |
| `evolution.manage` | ✅ | ✅ | ❌ |
| `goals.manage` | ✅ | ✅ | ❌ |
| `workspace.manage` | ✅ | ✅ | ❌ |
| `automations.manage` | ✅ | ✅ | ❌ |

Além disso:
- **`user_permissions`**: liberar/negar permissão específica para um único usuário sem mudar o papel.
- **Regras de registro** (sempre no backend): consultor só vê/edita os próprios leads; moderação de chat local por sala; acesso a tarefas por criador/responsável/watcher; atendimentos por responsável/gestão.

---

## 8. Segurança

- **SQL Injection:** 100% das queries com PDO + prepared statements.
- **XSS:** todo output passa por `e()`; mensagens de chat escapadas na saída (PHP e JS).
- **CSRF:** token por sessão exigido em todo POST (`Csrf::verifyRequest()`).
- **Sessão:** `httponly`, `SameSite=Lax`, `Secure`, `use_strict_mode`, `session_regenerate_id`.
- **Senhas:** hash bcrypt; tokens de reset com expiração.
- **Webhooks:** protegidos por token secreto comparado com `hash_equals`.
- **Crawler:** validação anti-SSRF (bloqueia IPs privados, localhost, credenciais em URL).
- **Uploads:** validação de MIME + extensão + tamanho; nomes aleatórios.
- **Gerenciamento de credenciais:** chaves de API nunca são exibidas em claro de volta; senhas de SMTP/Evolution mascaradas.
- **Código de erro genérico:** integrações externas nunca derrubam o fluxo principal (try/catch + log em `storage/logs/`).

---

## 9. Rotas da aplicação (mapa completo)

> Todas exigem login, exceto as marcadas como públicas (webhooks).

**Públicas:** `GET /`, `GET/POST /login`, `GET /logout`, `GET/POST /esqueci-senha`, `GET/POST /redefinir-senha`, `GET/POST /webhook/meta`, `POST /webhook/google`, `POST /webhook/generico`, `GET/POST /webhook/whatsapp`, `POST /webhook/evolution`, `GET /cron-automations.php`.

**Dashboard/Meu Dia:** `GET /dashboard`, `GET /dashboard/chart-data`, `GET /hoje`.

**Leads:** `GET /leads`, `GET /leads/create`, `GET /leads/buscar-rapido`, `POST /leads/store`, `GET /leads/{id}`, `GET /leads/{id}/edit`, `POST /leads/{id}/update`, `POST /leads/{id}/delete`, `POST /leads/{id}/note`, `POST /leads/check-duplicate`, `POST /leads/{id}/whatsapp`, `POST /leads/acao-em-lote`, `POST /leads/{id}/nota-rapida`.

**Pipeline/Agenda:** `GET /pipeline`, `POST /pipeline/move`, `GET /agenda`, `POST /agenda/agendar`, `POST /agenda/{id}/quick-contact`.

**Análise:** `GET /sla`, `GET /indicadores`, `GET /relatorios`, `GET /relatorios/exportar-csv`, `GET /relatorios/exportar-excel`, `GET /relatorios/imprimir`, `GET /relatorios/personalizar`, `POST /relatorios/personalizar/atualizar`, `GET /motivos-perda`, `POST /motivos-perda/store`, `POST /motivos-perda/{id}/update`, `POST /motivos-perda/{id}/delete`.

**Importação:** `GET /importar`, `POST /importar/preview`, `POST /importar/processar`, `GET /importar/relatorio/{id}`, `GET /importar/relatorio/{id}/exportar-erros`.

**Workspace:** `GET /calendario`, `GET /calendario/eventos`, `POST /calendario/eventos`, `POST /calendario/eventos/{id}/mover`, `POST /calendario/eventos/{id}/excluir`, `GET /conteudo`, `POST /conteudo/salvar`, `POST /conteudo/{id}/anexos`, `POST /conteudo/fontes`, `POST /conteudo/fontes/{id}/analisar`, `POST /conteudo/fontes/{id}/publicar`, `POST /conteudo/fontes/{id}/excluir`, `GET /whiteboards`, `POST /whiteboards/salvar`, `POST /whiteboards/{id}/excluir`, `GET /automacoes`, `POST /automacoes/salvar`, `POST /automacoes/{id}/alternar`, `POST /automacoes/{id}/executar`, `POST /automacoes/{id}/excluir`, `POST /workspace/preferencias`, `POST /assistente/perguntar`.

**Gestão:** `GET/POST /usuarios/*`, `GET /departamentos`, `POST /departamentos/store`, `POST /departamentos/{id}/update`, `POST /departamentos/{id}/status`, `GET /perfil`, `POST /perfil/update`, `GET/POST /configuracoes*`, `GET/POST /configuracoes/lead-score*`, `GET/POST /configuracoes/whatsapp-templates*`, `GET /metas`, `POST /metas/update`, `GET /logs`, `GET /notifications/unread`, `POST /notifications/{id}/read`, `POST /notifications/read-all`.

**Chat:** `GET /chat`, `GET /chat/salas/{id}/mensagens`, `POST /chat/salas/{id}/mensagens`, `POST /chat/salas/{id}/ler`, `POST /chat/salas/{id}/silenciar`, `POST /chat/salas/{id}/sair`, `GET /chat/salas/{id}/membros`, `POST /chat/salas`, `POST /chat/direto/{userId}`, `POST /chat/mensagens/{id}/apagar`, `GET /chat/usuarios/buscar`, `GET /chat/nao-lidas`.

**Tarefas:** `GET /tarefas`, `GET /tarefas/nova`, `POST /tarefas`, `GET /tarefas/{id}`, `GET /tarefas/{id}/edit`, `POST /tarefas/{id}`, `POST /tarefas/{id}/comentarios`, `POST /tarefas/{id}/status`, `POST /tarefas/{id}/excluir`.

**Atendimento WhatsApp:** `GET /atendimento-whatsapp`, `GET /atendimento-whatsapp/{id}/poll`, `POST /atendimento-whatsapp/{id}/enviar`, `POST /atendimento-whatsapp/{id}/vincular-lead`, `POST /atendimento-whatsapp/{id}/criar-lead`, `POST /atendimento-whatsapp/{id}/criar-tarefa`, `POST /atendimento-whatsapp/{id}/atualizar-contato`, `POST /atendimento-whatsapp/{id}/telefone`, `POST /atendimento-whatsapp/notas/{messageId}/remover`, `POST /atendimento-whatsapp/{id}/transferir`, `POST /atendimento-whatsapp/{id}/etiquetas`, `POST /atendimento-whatsapp/mapeamentos`, `POST /atendimento-whatsapp/testar`, `POST /atendimento-whatsapp/qrcode`, `POST /atendimento-whatsapp/sincronizar`, `POST /atendimento-whatsapp/webhook/configurar`.

---

## 10. Controllers e Models (inventário)

**Controllers (29):** Agenda, Auth, Chat, Dashboard, Department, EvolutionInbox, EvolutionWebhook, Goal, Import, Indicator, Lead, LeadScore, Log, LossReason, Notification, Pipeline, Profile, Report, Setting, Sla, Task, Today, User, Webhook, Whatsapp, WhatsappInbound, WhatsappTemplate, Workspace.

**Models (35):** ActivityLog, ChatDepartment, ChatMessage, ChatRoom, Import, ImportError, Lead, LeadHistory, LeadScore, LossReason, Notification, Permission, PipelineStage, Setting, Tag, Task, TaskComment, TaskHistory, TaskWatcher, User, UserGoal, WhatsappTemplate (+ User/Permission internos do Auth).

**Principais funções globais (`helpers.php`):** `e()`, `old()`, `asset()`, `url()`, `format_phone/cpf/cep/money/date()`, `time_ago()`, `status_label/color()`, `source_label()`, `interest_label()`, `brazilian_states()`, `log_activity()`, `render_pagination()`, `days_since_contact_*()`, `score_badge_class()`, `temperature_*()`, `chat_date_label()`, `flash()`.

**Insights (`insights.php`):** `generate_insights()` — usado pelo Dashboard.

---

## 11. Migrations — ordem de instalação

1. `schema.sql` (9 tabelas base)
2. `seed.sql` (usuários, estágios, tags, motivos, configs, 19 leads fictícios)
3. `migration_fase2.sql` (Lead Score)
4. `migration_fase3.sql` (permissões, notificações, reset de senha, SMTP/WhatsApp/webhook)
5. `migration_fase4.sql` (lead_code, importação)
6. `migration_fase5.sql` (peso do Lead Score por origem)
7. `migration_fase6` — não existe (Fase 6 só usou CSS/JS e settings)
8. `migration_tasks.sql` e `migration_chat.sql` (independentes entre si, depois das fase2–5)
9. `migration_fase7_agenda.sql` e `migration_fase7_leads.sql` (independentes)
10. `migration_workspace.sql` → `migration_workspace_v2.sql` → `migration_workspace_v3.sql` → `migration_workspace_v4.sql` (nesta ordem)
11. `migration_evolution_inbox.sql` → `migration_evolution_api.sql` → `migration_evolution_api_v2.sql`
12. `migration_user_permissions.sql` (opcional, a qualquer momento após a fase 3)

Todas as migrations são **incrementais e idempotentes** (`CREATE TABLE IF NOT EXISTS`, `INSERT IGNORE`, stored procedures condicionais), seguras para rodar em produção com dados reais.

---

## 12. Configurações do sistema (chaves da tabela `settings`)

| Grupo | Chaves |
|---|---|
| Identidade | `company_name`, `company_logo`, `company_favicon` |
| SMTP | `smtp_host`, `smtp_port`, `smtp_encryption`, `smtp_user`, `smtp_pass`, `smtp_from_name`, `smtp_from_email` |
| WhatsApp Cloud | `whatsapp_token`, `whatsapp_phone_id` |
| Webhook de leads | `webhook_token` |
| WhatsApp webhook | `webhook_verify_token` |
| Gemini IA | `gemini_api_key`, `gemini_model` |
| Automação WhatsApp | `automation_whatsapp_template`, `automation_whatsapp_language` |
| Evolution API | `evolution_api_url`, `evolution_api_token`, `evolution_instance_name`, `evolution_webhook_token` |
| Relatórios | `report_logo`, `report_primary_color`, `report_cnpj`, `report_address`, `report_phone`, `report_footer_text`, `report_columns` |

Variáveis de ambiente usadas: `GEMINI_API_KEY` (fallback da chave no settings) e `AUTOMATION_CRON_TOKEN` (proteção do cron).

---

## 13. Automação e agendamentos

- **Cron único:** acessar `public/cron-automations.php?token=<AUTOMATION_CRON_TOKEN>` a cada hora (ex.: cron da Hostinger). Roda os fluxos de "lead parado".
- **Sem cron, sob demanda:** alertas de leads parados (notificações) gerados ao carregar Dashboard/Agenda.
- **Polling do navegador (sem servidor de tempo real):** notificações (45s), chat (5s/18s), atendimento WhatsApp (por conversa), badges da sidebar.

---

## 14. Deploy e infraestrutura (resumo)

- Document root = `public/`; demais pastas (app/config/database/storage) **um nível acima**, fora do acesso web.
- `storage/logs/`, `storage/imports/`, `public/uploads/` com permissão de escrita.
- `config/config.php`: ajustar `BASE_URL` e `session.cookie_secure`; trocar `ASSET_VERSION` ao publicar CSS/JS (cache de 7 dias da Hostinger).
- `config/database.php`: credenciais do MySQL.
- Bug de infraestrutura Hostinger conhecido: CDN com compressão zstd pode corromper respostas PHP grandes → abrir chamado no suporte (sem correção de código).
- Antes do deploy: `php -l` em todos os `.php` e teste end-to-end (SMTP, WhatsApp, webhooks).

---

## 15. Limitações conhecidas e pendências

1. **Sem tempo real (WebSocket):** chat e atendimento usam polling (até ~5s) — limitação aceita da hospedagem compartilhada.
2. **Push Web verdadeiro** (navegador fechado) requer provedor Web Push/VAPID — infraestrutura não implementada; notificações internas continuam funcionando.
3. **`.xlsx` na importação:** não suportado sem Composer — usar CSV.
4. **Data de fechamento do lead** usa `updated_at` como aproximação (falta coluna `closed_at`).
5. **Contatos `@lid` (anúncio)** não expõem número via nenhuma API da Meta — preenchimento manual.
6. **Upload de arquivos no Chat** não implementado (só texto).
7. **Crawler** aceita apenas HTTPS, sem credenciais/porta, e valida anti-SSRF.
8. **Instalação de `admin` não é automática** — vem do `seed.sql` (trocar a senha padrão em produção).
9. Testes end-to-end reais das integrações dependem de credenciais em produção (Meta, Google, WhatsApp, SMTP, Evolution).

---

## 16. Ecossistema — como os módulos se conectam

```
                ┌────────────────────────────────────────────┐
                │            PONTAS DE ENTRADA                │
                │  Meta Ads  Google Ads  Genérico  WhatsApp   │
                │  └─────── webhooks públicos (token) ───────┘
                │        Evolution API (mensagens/QR/etiquetas)
                └──────────────────────┬─────────────────────┘
                                       ▼
                        ┌─────────────────────────────┐
                        │     leads  +  lead_history   │◄── atribuição/notificação
                        └─────────────┬───────────────┘
         ┌──────────────┬─────────────┼──────────────┬────────────────┐
         ▼              ▼             ▼              ▼                ▼
      Pipeline      Agenda/MeuDia   SLA          Relatórios      Indicadores
      (Kanban)      (contatos)   (1ª resposta)  (CSV/Excel/PDF)  (mapa/heatmap)
         │              │             │              │
         ▼              ▼             ▼              ▼
      LeadScore ──► Dashboard ──► Insights ──► Importação CSV
      (auto 0-100) (KPIs/gráficos) (ação)      (lead_code)
         │
         ├──► Tarefas ──► Agenda (sincronização opcional) ──► Metas
         ├──► Chat interno (departamentos) ──► Notificações
         ├──► Atendimento WhatsApp (Evolution) ──► cria lead/tarefa, transfere
         ├──► Calendário (eventos + vínculo lead/tarefa)
         ├──► Documentos e Wiki (crawler + IA) ──► Assistente Gemini
         ├──► Whiteboards (colaboração)
         └──► Automações (lead parado → ações) ──► cron
                └──────────────► ActivityLog (auditoria) / Notificações

        Serviços externos: SMTP (e-mails) · WhatsApp Cloud (templates)
                           · Gemini (IA) · Evolution API (WhatsApp)
```

**Fluxo de dados típico:** o lead entra por um webhook (ou cadastro/importação) → gera `lead_code` e Lead Score → aparece no Pipeline/Agenda/Dashboard → é trabalhado via tarefas, chat e WhatsApp → vira base para SLA, relatórios, indicadores, metas e automações — com tudo auditado em `activity_log` e `lead_history`.

---

*Fim do relatório. Detalhes operacionais (passo a passo de instalação, credenciais de teste, configuração de cada integração) estão em `README.md`.*
