# Titanium CRM — Fase 1 + Fase 2 + Fase 3 + Fase 4 + Fase 5

CRM administrativo em **PHP puro** (sem Composer, sem frameworks, sem Node) para a
**Titanium Consultoria** (Cartas Contempladas, Consórcios e Crédito), para gestão de
leads captados via **Meta Ads** e **Google Ads**.

Stack: PHP 8.x puro + MySQL 8 + Apache (mod_rewrite) + Bootstrap 5.3, Chart.js,
FontAwesome e SweetAlert2 via CDN. Projetado para rodar em hospedagem compartilhada
(Hostinger) apenas via upload FTP — sem build step, sem `node_modules`, sem Docker.

---

## 1. Estrutura do projeto

```
app/
  controllers/   Controllers (Auth, Dashboard, Lead, Pipeline, Sla, Indicator, Report,
                  LossReason, Agenda, User, Profile, Setting, Log, Webhook, Whatsapp,
                  Notification, Import)
  models/        Models (User, Lead, LeadHistory, Tag, LossReason, PipelineStage,
                  LeadScore, Setting, ActivityLog, Permission, Notification, Import,
                  ImportError)
  views/         Views (layouts, auth, dashboard, leads, pipeline, sla, indicators,
                  reports, loss-reasons, agenda, users, profile, settings, logs, import)
  core/          Núcleo do micro-framework (Router, Controller, Model, Database, Auth,
                  Csrf, Mailer, WhatsappClient)
  helpers/       Funções auxiliares (helpers.php, insights.php)
config/          config.php e database.php
public/          Document root (front controller, .htaccess, assets, uploads)
  assets/img/    brazil-map.svg (mapa coroplético usado em /indicadores)
storage/logs/    Logs de erro do PHP, mail.log (falhas de envio de e-mail)
storage/imports/ Arquivos CSV enviados na tela de Importar Leads (Fase 4); cada
                  arquivo é apagado automaticamente ao final do processamento
database/sql/    schema.sql, seed.sql e migrations históricas (executadas pela
                  lista única definida em app/services/DatabaseSetup.php)
setup.php         instalador único (web e CLI), com controle de migrations
```

O **document root** do seu domínio/subdomínio na Hostinger deve apontar para a pasta
`public/`. É o único diretório que fica exposto na web; todo o restante (`app`,
`config`, `database`, `storage`) fica fora do alcance público, mas **precisa** ser
enviado via FTP para um nível acima de `public/` (a estrutura de pastas deve ser
mantida exatamente como está).

---

## 2. Configurando o banco de dados (Hostinger)

1. No **hPanel** da Hostinger, vá em **Bancos de Dados > MySQL Databases** e crie um
   banco de dados (ex: `u123456789_titanium`) e um usuário com senha forte.
2. Ajuste `config/config.php`:
   - `BASE_URL`: coloque a URL final do CRM (ex: `https://crm.suaempresa.com.br`,
     sem barra no final).
   - Se o site tiver certificado SSL (HTTPS) ativo — recomendado — altere em
     `config/config.php` a linha `ini_set('session.cookie_secure', '0');` para `'1'`.

> Não é necessário editar `config/database.php` na primeira instalação: o
> `setup.php` pede os dados do MySQL e grava uma configuração local, fora da pasta
> pública, depois de concluir a conexão.

---

## 3. Instalação unificada do banco (recomendado)

Abra `https://seu-dominio/setup.php`, informe o host, nome do banco, usuário e senha
criados no hPanel, e use **Testar conexão**. O instalador salva essas credenciais em
`config/database.local.php` (fora do document root), aplica na ordem correta o
schema, os catálogos iniciais e todas as migrations do CRM. Ele também cria o
primeiro administrador quando a instalação é limpa.

- Deixe **dados de demonstração** desmarcado para produção. Assim não são criados
  leads ou usuários fictícios.
- Marque essa opção somente em ambiente de teste; ela inclui os dados de exemplo e
  o login `admin@titaniumconsultoria.com.br` / `admin123`.
- A tabela `system_migrations` registra cada etapa concluída. Ao publicar uma nova
  versão, abra novamente o mesmo `setup.php`; somente migrations ainda pendentes
  serão executadas.
- O instalador não exclui tabelas nem dados. Se um arquivo SQL já aplicado tiver
  sido alterado, ele o sinaliza para revisão em vez de executá-lo de novo.

Para usar por SSH, execute a partir da raiz do projeto:

```bash
php setup.php --status
php setup.php --install --admin-name="Administrador" --admin-email="admin@suaempresa.com" --admin-password="uma-senha-forte"
```

Inclua `--demo` no segundo comando apenas para carregar os dados de demonstração.

### Segurança do instalador

O instalador exige as credenciais reais do MySQL para executar qualquer alteração.
Opcionalmente, defina a variável de ambiente `TITANIUM_SETUP_TOKEN` no servidor para
exigir também um token adicional. Após uma instalação definitiva, remova
`public/setup.php` do document root ou restrinja o acesso a ele no painel da
hospedagem.

### Importação manual (legado)

Os arquivos em `database/sql/` continuam disponíveis para instalações antigas, mas
não devem mais ser importados individualmente em novos ambientes: o `setup.php`
passou a ser a fonte única da sequência de migrations.

### 3.1. Migration da Fase 2 (`database/sql/migration_fase2.sql`)

Se você **já tinha o banco da Fase 1 rodando em produção** (schema.sql + seed.sql já
importados anteriormente), a Fase 2 exige rodar **apenas** o arquivo
`database/sql/migration_fase2.sql` — ele é incremental e idempotente (usa
`CREATE TABLE IF NOT EXISTS` e `INSERT IGNORE`), então pode ser executado com
segurança mesmo em um banco que já tem dados de leads reais. Ele **não** altera nem
apaga nenhuma tabela existente da Fase 1.

O que essa migration faz:

- Cria a tabela `lead_score_rules` (critério, descrição, peso, ativo) usada por
  `app/models/LeadScore.php` para calcular automaticamente o campo `leads.lead_score`
  (0 a 100) sempre que um lead é criado/atualizado pelo `LeadController`.
- Insere os pesos padrão de cada critério (pode editar os valores depois direto na
  tabela via phpMyAdmin, ou rodando `UPDATE lead_score_rules SET peso = ... WHERE
  criterio = '...'`; uma tela de administração dedicada para essas regras fica para
  uma próxima iteração — ver seção "Fase 3" abaixo).

Conteúdo completo do arquivo (para referência rápida, sem precisar abrir o arquivo):

```sql
CREATE TABLE IF NOT EXISTS lead_score_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    criterio VARCHAR(50) NOT NULL,
    descricao VARCHAR(191) NOT NULL,
    peso SMALLINT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lead_score_rules_criterio (criterio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO lead_score_rules (criterio, descricao, peso, ativo) VALUES
('entrada_disponivel',   'Lead possui entrada disponível',                              15, 1),
('valor_alto',           'Valor desejado igual ou acima de R$ 100.000,00',              15, 1),
('interesse_qualificado','Interesse em produto de alto valor (imóvel, maquinário, agronegócio, investimento)', 10, 1),
('origem_qualificada',   'Origem historicamente mais convertida (indicação, Google Ads)', 10, 1),
('temperatura_quente',   'Temperatura marcada como quente ou muito quente',             15, 1),
('temperatura_fria',     'Temperatura marcada como fria (penalidade)',                  -10, 1),
('interacoes_frequentes','3 ou mais interações registradas no histórico',               10, 1),
('sem_contato_recente',  'Sem nenhum contato registrado há mais de 48h (penalidade)',   -15, 1),
('tempo_espera_critico', 'Sem nenhum contato registrado há mais de 5 dias (penalidade)', -15, 1);
```

(O arquivo real em `database/sql/migration_fase2.sql` tem os mesmos comandos, com
comentários explicativos adicionais.)

---

## 4. Credenciais do usuário admin de teste

O `seed.sql` cria o seguinte usuário administrador para primeiro acesso:

| Campo   | Valor                                      |
|---------|---------------------------------------------|
| E-mail  | `admin@titaniumconsultoria.com.br`          |
| Senha   | `admin123`                                  |

A senha já está armazenada como **hash bcrypt real** (gerado via `password_hash()`
do PHP, formato `$2y$`), nunca em texto puro. Também são criados 3 usuários
adicionais de teste (supervisor e consultores) com a mesma senha, apenas para
facilitar os testes de atribuição de leads e do pipeline.

**Troque a senha do admin assim que possível** (a troca de senha pela própria
interface fica para a Fase 2 — veja abaixo — por enquanto, para alterá-la, gere um
novo hash com `password_hash('nova_senha', PASSWORD_BCRYPT)` em um script PHP local
e atualize o campo `password` na tabela `users` via phpMyAdmin).

---

## 5. Upload via FTP (Hostinger)

1. Conecte-se ao FTP da Hostinger (dados em hPanel > Arquivos > Contas FTP) usando
   um cliente como FileZilla.
2. Envie **todo o conteúdo** deste projeto para a pasta do seu domínio, mantendo a
   estrutura de diretórios. Normalmente:
   - O document root do domínio (ex: `public_html/` ou o diretório de um
     subdomínio) deve receber o conteúdo da pasta `public/` deste projeto
     diretamente na raiz.
   - As pastas `app/`, `config/`, `database/`, `storage/` devem ficar **um nível
     acima** do document root (fora da pasta acessível publicamente), mantendo os
     caminhos relativos usados pelo código (`dirname(__DIR__)` em
     `public/index.php` sobe um nível a partir de `public/` para achar `config/`).
   - Ou seja, a estrutura final no servidor deve ficar assim:
     ```
     /home/u123456789/
       app/
       config/
       database/
       storage/
       public_html/        <- conteúdo de public/ (document root do domínio)
         .htaccess
         index.php
         assets/
         uploads/
     ```
3. Garanta que a pasta `storage/logs/` e `public/uploads/` tenham permissão de
   escrita (normalmente `755` já funciona na Hostinger; se der erro de permissão,
   tente `775`).
4. Confirme que o módulo `mod_rewrite` do Apache está ativo (padrão na Hostinger) —
   é ele quem faz o `.htaccess` em `public/` funcionar.

---

## 6. Primeiro acesso

1. Acesse a URL configurada em `BASE_URL` (ex: `https://crm.suaempresa.com.br`).
2. Você será redirecionado para a tela de login.
3. Entre com o e-mail/senha do admin de teste (seção 4 acima).
4. Você cairá no **Dashboard**, com KPIs, gráficos (Chart.js) e insights
   automáticos calculados a partir dos leads fictícios do `seed.sql`.

---

## 7. O que já está implementado (Fase 1)

- **Autenticação**: login com CSRF, `password_hash`/`password_verify`,
  `session_regenerate_id`, sessão segura (httponly, samesite), middleware de
  autenticação em todas as rotas exceto login/logout.
- **Layout base**: sidebar fixa, topbar, dropdown de usuário, dark mode
  (localStorage), identidade visual própria (`public/assets/css/app.css`).
- **Dashboard**: KPIs reais via PDO (total, novos hoje/semana/mês, qualificados,
  perdidos, em atendimento, taxa de conversão, sem contato), 4 gráficos Chart.js
  (leads por dia, por origem, por estado, funil por status) e insights automáticos
  simples calculados em PHP.
- **Leads (CRUD completo)**: listagem com busca, filtros, ordenação por coluna e
  paginação server-side (LIMIT/OFFSET); formulário completo (todos os campos do
  briefing, nenhum obrigatório) com máscaras de telefone/CPF/CEP; checagem de
  duplicidade via AJAX (telefone/WhatsApp/CPF/e-mail) com SweetAlert2 oferecendo
  abrir o lead existente; registro automático de eventos na timeline
  (`lead_history`); página de perfil do lead com histórico completo, tags e
  observação rápida; exclusão com confirmação SweetAlert2.
- **Pipeline (Kanban)**: colunas conforme `pipeline_stages`, cards arrastáveis
  via HTML5 Drag and Drop API nativo, endpoint AJAX que persiste a mudança de
  status via prepared statement e grava no histórico do lead.
- **Segurança**: todo output HTML passa por `e()` (proteção XSS); todas as
  queries usam PDO com prepared statements (proteção SQL Injection); todo POST
  exige token CSRF por sessão; sessão configurada com cookies `httponly`.
- **Banco de dados**: schema completo (`database/sql/schema.sql`) com 9 tabelas,
  FKs (`ON DELETE SET NULL`/`CASCADE` conforme apropriado), índices simples e um
  índice composto (`status + assigned_to`), comentários em cada tabela; seed
  (`database/sql/seed.sql`) com usuários, motivos de perda, estágios de pipeline,
  tags, configurações e 19 leads fictícios variados.

---

## 8. O que foi implementado na Fase 2

Todos os itens abaixo já estão implementados nesta entrega (todas as rotas exigem
login; as marcadas com 🔒 são restritas ao papel `admin`):

- **Dashboard de SLA** (`/sla`, `SlaController` + `app/views/sla/index.php`): tempo
  médio até o primeiro contato, % de leads respondidos em até 15 minutos, leads
  aguardando primeiro contato há mais de 24h e uma tabela comparativa de SLA por
  consultor. "Primeiro contato" é o primeiro registro em `lead_history` com
  `type = 'contato'`. Tudo calculado via queries PDO reais (sem dados mockados).
- **Insights avançados** (`app/helpers/insights.php`, função `generate_insights()`,
  usada por `DashboardController`): comparação de volume semana atual x anterior,
  melhor DDD/origem/consultor por taxa de conversão, alerta de queda de conversão
  mês a mês e alerta de leads parados há mais de 10 dias sem mudança de status —
  exibidos como cards coloridos (verde/vermelho/amarelo/azul) na mesma seção de
  insights do Dashboard.
- **Relatórios exportáveis** (`/relatorios`, `ReportController` +
  `app/views/reports/{index,print}.php`): filtros por período, estado, origem,
  status e responsável; exportação em **CSV** nativo (`fputcsv`), **Excel** leve
  (tabela HTML servida com `Content-Type: application/vnd.ms-excel` e extensão
  `.xls`, técnica sem dependências externas) e **PDF via impressão** — uma página
  HTML formatada para impressão que o usuário salva como PDF pelo próprio
  navegador (`window.print()`), já que o projeto não usa Composer/libs de PDF
  binário nativo.
- **Indicadores** (`/indicadores`, `IndicatorController` +
  `app/views/indicators/index.php`): mapa coroplético do Brasil
  (`public/assets/img/brazil-map.svg`, cartograma estilizado em grade com as 27
  UFs identificáveis e clicáveis, coloridas por intensidade de leads via JS, com
  tooltip de quantidade e taxa de conversão por estado) e heatmap de horários
  (tabela 7 dias x 24 horas com células coloridas por intensidade, calculado via
  `DAYOFWEEK()`/`HOUR()` no MySQL).
- **Lead Score automático configurável** (`app/models/LeadScore.php` +
  `lead_score_rules`, ver seção 3.1): soma pesos configuráveis (entrada disponível,
  valor alto, interesse qualificado, origem qualificada, temperatura,
  interações frequentes, tempo sem contato) limitado a 0-100; recalculado
  automaticamente pelo `LeadController` a cada criação/edição/observação de lead.
- **Motivos de perda com estatísticas** (`/motivos-perda`, `LossReasonController` +
  `app/views/loss-reasons/index.php`): CRUD completo (criar, renomear, ativar/
  desativar, excluir) e gráfico Chart.js com ranking dos motivos mais usados.
- **Agenda** (`/agenda`, `AgendaController` + `app/views/agenda/index.php`):
  leads com `next_contact_at` preenchido, agrupados em Atrasados / Hoje / Próximos
  7 dias / Mais adiante, com filtro por responsável.
- **Usuários e permissões** 🔒 (`/usuarios`, `UserController` +
  `app/views/users/{index,form}.php`): CRUD completo (nome, e-mail, senha com
  `password_hash`, papel admin/supervisor/consultor, ativo/inativo), restrito ao
  papel `admin` via `requireAdmin()` no controller e ocultado do menu para os
  demais papéis.
- **Perfil do usuário logado** (`/perfil`, `ProfileController` +
  `app/views/profile/index.php`): edição do próprio nome, e-mail, avatar (upload
  simples para `public/uploads/avatars/`) e senha.
- **Configurações do sistema** 🔒 (`/configuracoes`, `SettingController` +
  `app/views/settings/index.php`): formulário para editar a tabela `settings`
  (nome da empresa, logo e favicon com upload para `public/uploads/`, credenciais
  SMTP, e placeholders de integrações Meta Ads/Google Ads/Webhook — estes últimos
  apenas **armazenam** o valor informado, sem executar nenhuma chamada externa).
- **Logs de atividade** (`/logs`, `LogController` + `app/views/logs/index.php`):
  listagem paginada de `activity_log` com filtro por usuário, ação (busca parcial)
  e período. O helper `log_activity($action, $details)`
  (`app/helpers/helpers.php`) foi conectado às ações críticas já existentes:
  login, login falho, logout, criar/editar/excluir lead, mudança de status via
  Kanban, CRUD de motivos de perda, CRUD de usuários, atualização de perfil e
  atualização de configurações.
- **Sidebar atualizada** (`app/views/layouts/main.php`): todos os itens acima têm
  entrada própria no menu lateral (com ícones FontAwesome), incluindo ocultação
  condicional de "Usuários" e "Configurações" para quem não é `admin`.

---

## 9. O que foi implementado na Fase 3 (integrações externas, sem Composer/Node)

A Fase 3 implementa, **em PHP puro** (sockets nativos `fsockopen`/`stream_socket_*`
para SMTP e `cURL` nativo para HTTP/APIs — ambos disponíveis por padrão na Hostinger,
sem precisar de Composer), tudo que na Fase 2 estava listado como "fora de escopo":

- **Webhook público de captação de leads** (`app/controllers/WebhookController.php`):
  rotas **sem login** (`POST /webhook/meta`, `POST /webhook/google`,
  `POST /webhook/generico`, mais `GET /webhook/meta` para a verificação da Meta),
  protegidas por um token secreto configurável (`?token=...`, comparado com o valor
  salvo em Configurações > "Token secreto do webhook"). Recebe o payload (JSON da
  Meta/Google ou form-urlencoded do webhook genérico), mapeia os campos para a
  tabela `leads` (nome, telefone, e-mail, campanha, UTMs, origem etc.), evita
  duplicar leads já existentes (reaproveita `Lead::findDuplicate()`), cria o
  registro em `lead_history` e recalcula o Lead Score automaticamente
  (`LeadScore::calculate()`). Para a Meta, quando o payload traz apenas o
  `leadgen_id` (formato oficial do webhook), o controller busca os dados completos
  do lead na Graph API usando o token salvo em "Token de acesso Graph API (Meta Ads)".
  Veja a seção 9.4 abaixo para o passo a passo de cadastro.
- **Integração WhatsApp Cloud API** (`app/core/WhatsappClient.php`): cliente HTTP
  via cURL nativo (`sendTextMessage()` e `sendTemplateMessage()`), lendo
  `whatsapp_token`/`whatsapp_phone_id` de Configurações. Botão **"Enviar WhatsApp"**
  no perfil do lead (`app/views/leads/show.php`) abre um modal Bootstrap; o envio é
  feito via AJAX para `POST /leads/{id}/whatsapp`
  (`app/controllers/WhatsappController.php`), que registra a mensagem em
  `lead_history` em caso de sucesso. Se as credenciais não estiverem configuradas,
  a interface mostra um aviso amigável via SweetAlert2 em vez de um erro cru.
- **Envio real de e-mail via SMTP nativo** (`app/core/Mailer.php`): cliente SMTP
  próprio (conexão via `fsockopen`, `STARTTLS`/SSL via
  `stream_socket_enable_crypto`, autenticação `AUTH LOGIN`), sem PHPMailer/Composer.
  Usado em dois pontos: (a) fluxo completo de **"Esqueci minha senha"**
  (`AuthController::forgotPassword/sendResetLink/resetPasswordForm/resetPassword`,
  telas `app/views/auth/{forgot-password,reset-password}.php`, token com validade de
  60 minutos salvo em `users.password_reset_token`/`password_reset_expires`); e
  (b) **notificação por e-mail ao consultor responsável** sempre que um lead é
  atribuído a ele (`LeadController::notifyAssignment()`, chamado em `store()` e
  `update()`). Todo o envio é protegido por `try/catch`: se o SMTP não estiver
  configurado ou a conexão falhar, o erro é gravado em `storage/logs/mail.log` e o
  fluxo principal do usuário (salvar o lead, redefinir a senha) **nunca é
  interrompido**.
- **Permissões granulares por ação** (tabelas `permissions`/`role_permissions`,
  `app/models/Permission.php`, `Auth::can($slug)` com cache em sessão por papel):
  além do controle por papel já existente (`admin`/`supervisor`/`consultor`), agora
  ações específicas são checadas individualmente — `leads.delete` (excluir lead),
  `leads.export` (exportar CSV/Excel/PDF), `reports.view` (acessar Relatórios),
  `pipeline.manage` (mover card no Kanban), `users.manage` e `settings.manage`
  (telas administrativas). Os botões/links correspondentes ficam ocultos na view
  (`Auth::can('leads.delete')` etc.) **e** o controller bloqueia a ação mesmo que a
  requisição seja forjada diretamente (retorna erro/flash de acesso negado). Ver
  seção 3.1 (Fase 3) para a matriz de permissões padrão por papel.
- **Notificações internas em tempo real via polling** (tabela `notifications`,
  `app/models/Notification.php`, `app/controllers/NotificationController.php`): sino
  no topbar (`app/views/layouts/main.php`) com contador de não lidas, dropdown com
  a lista e opção "marcar todas como lidas"; o JS (`public/assets/js/app.js`,
  função `initNotifications()`) consulta `GET /notifications/unread` a cada 45
  segundos via `setInterval` (sem WebSockets/Node). Notificações são geradas em três
  situações: (1) lead novo atribuído a um consultor (`LeadController`); (2) mudança
  de status crítica no Kanban — "fechado" ou "perdido" (`PipelineController::move()`);
  (3) lead parado sem nenhum contato há 5+ dias — gerado **sob demanda, sem cron**,
  toda vez que o Dashboard ou a Agenda são carregados
  (`Notification::generateStaleLeadAlerts()`, chamado em `DashboardController` e
  `AgendaController`), com deduplicação (não recria notificação enquanto a anterior
  sobre o mesmo lead ainda estiver não lida).

### 9.1. Migration da Fase 3 (`database/sql/migration_fase3.sql`)

Depois de já ter rodado `schema.sql` + `seed.sql` (Fase 1) e `migration_fase2.sql`
(Fase 2), rode **por último**:

```bash
mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase3.sql
```

Ou via phpMyAdmin: aba **Importar** > escolha `database/sql/migration_fase3.sql` >
Executar. Assim como a migration da Fase 2, ela é incremental e segura para rodar em
um banco que já tem leads reais (usa `CREATE TABLE IF NOT EXISTS`/`INSERT IGNORE`; a
alteração em `users` só adiciona as colunas se elas ainda não existirem).

O que essa migration faz:

- **`users`**: adiciona as colunas `password_reset_token` (VARCHAR 64) e
  `password_reset_expires` (DATETIME), usadas pelo fluxo de "Esqueci minha senha".
- **`permissions`**: catálogo de permissões (`slug` + `label`) — `leads.view`,
  `leads.create`, `leads.edit`, `leads.delete`, `leads.export`, `pipeline.manage`,
  `reports.view`, `users.manage`, `settings.manage`.
- **`role_permissions`**: relação N:N entre papel e permissão, com seed padrão:
  - `admin` → **todas** as permissões.
  - `supervisor` → todas, **exceto** `users.manage` e `settings.manage`.
  - `consultor` → `leads.view`, `leads.create`, `leads.edit`, `pipeline.manage`
    (a regra de negócio "só pode mexer nos PRÓPRIOS leads" continua sendo aplicada
    no código/filtros existentes, não nesta tabela — que controla acesso por
    ação/tela, não por registro).
  - Você pode ajustar essa matriz depois diretamente via phpMyAdmin
    (`INSERT`/`DELETE` em `role_permissions`) — não há tela dedicada de
    administração de permissões nesta entrega.
- **`notifications`**: tabela do sino de notificações (`user_id`, `title`,
  `message`, `link`, `read_at`, `created_at`).
- **`settings`**: novas chaves — `smtp_from_email`, `smtp_encryption` (`tls` por
  padrão), `whatsapp_token`, `whatsapp_phone_id`, `webhook_token`.

### 9.2. Como configurar a WhatsApp Cloud API

1. Crie (ou use) um app em [Meta for Developers](https://developers.facebook.com/apps/)
   e adicione o produto **WhatsApp**.
2. Na página do produto WhatsApp > **Introdução/API Setup**, você verá:
   - **Temporary access token** (para testes rápidos, válido por 24h) ou, para uso
     em produção, gere um **token permanente** vinculado a um System User com
     permissão `whatsapp_business_messaging` (Business Settings > System Users).
   - **Phone number ID** (ID numérico do número de teste ou do número comercial
     verificado).
3. No Titanium CRM, acesse **Configurações > WhatsApp Cloud API** e cole o Token em
   "Token de acesso (permanente)" e o número em "Phone Number ID". Salve.
4. Teste abrindo qualquer lead com telefone/WhatsApp cadastrado e clicando em
   **"Enviar WhatsApp"**.
5. **Importante (regra da própria Meta):** fora da janela de 24h após a última
   mensagem do cliente, a API só aceita **mensagens de template pré-aprovado**
   (`WhatsappClient::sendTemplateMessage()`), não texto livre — o botão do CRM usa
   texto livre (`sendTextMessage()`), então funciona normalmente dentro da janela de
   24h de uma conversa ativa; para lembretes automáticos fora dessa janela, seria
   necessário cadastrar um template aprovado no Meta Business Manager e usar o
   método de template já disponível na classe.

### 9.3. Como configurar o SMTP real

Use os dados de e-mail fornecidos pela própria Hostinger (hPanel > **E-mails** >
crie uma conta de e-mail, ex: `crm@seudominio.com.br`):

| Campo em Configurações | Valor típico Hostinger |
|---|---|
| Servidor SMTP | `smtp.hostinger.com` (ou o exibido no hPanel > Detalhes da conta de e-mail) |
| Porta | `587` (STARTTLS) ou `465` (SSL/TLS) |
| Criptografia | `STARTTLS (587)` ou `SSL/TLS (465)`, conforme a porta escolhida |
| Usuário | o e-mail completo, ex: `crm@seudominio.com.br` |
| Senha | a senha dessa conta de e-mail |
| E-mail do remetente | geralmente o mesmo e-mail do usuário |

Salve em **Configurações > SMTP (envio de e-mails)**. Para testar, use "Esqueci
minha senha" na tela de login com o e-mail de um usuário cadastrado — se tudo
estiver certo, o e-mail chega em poucos segundos. Se não chegar, confira
`storage/logs/mail.log` (mensagens de erro do `Mailer.php`) e
`storage/logs/php_errors.log`.

### 9.4. Como cadastrar o Webhook no Meta Business/Google Ads

1. Em **Configurações > Integrações e Webhook**, clique em **"Gerar"** ao lado de
   "Token secreto do webhook" (ou cole um valor forte de sua escolha) e salve.
2. As URLs completas a cadastrar são (substitua pelo seu `BASE_URL` real e pelo
   token gerado no passo 1):

   ```
   Meta Lead Ads:    https://seudominio.com.br/webhook/meta?token=SEU_TOKEN
   Google Ads:        https://seudominio.com.br/webhook/google?token=SEU_TOKEN
   Genérico/outros:   https://seudominio.com.br/webhook/generico?token=SEU_TOKEN
   ```

3. **Meta Business (Lead Ads):** em Meta for Developers > seu App > **Webhooks** >
   assine o objeto **Page** (ou **WhatsApp Business Account**, se for o caso) para o
   campo `leadgen`, e cadastre a "Callback URL" acima e o "Verify Token" — use
   **o mesmo valor** salvo em "Token secreto do webhook" também como `hub.verify_token`
   (o CRM responde automaticamente ao desafio `hub.challenge` em
   `WebhookController::verifyMeta()`). Também cole, em "Token de acesso Graph API
   (Meta Ads)", um token de página com permissão `leads_retrieval`, necessário para
   o CRM buscar os dados completos do lead a partir do `leadgen_id` recebido.
4. **Google Ads (Lead Form Extensions):** em Google Ads > Ferramentas > Configurações
   de leads > **Conectar CRM via webhook**, cole a URL do passo 2 e a "Chave do
   webhook" (o Google chama esse parâmetro de forma diferente conforme a versão da
   interface; use o mesmo `webhook_token` como valor).
5. **Genérico:** qualquer ferramenta capaz de fazer um `POST` (Zapier, Make,
   formulário próprio, landing page etc.) pode enviar campos como
   `name`, `phone`, `whatsapp`, `email`, `city`, `state`, `campaign`, `utm_source`,
   `utm_medium`, `utm_campaign`, `interest` (JSON ou form-urlencoded) para a URL do
   passo 2.

---

## 10. O que foi implementado na Fase 4 (produtividade da equipe comercial)

A Fase 4 foca em tornar o **cadastro manual de leads mais rápido e organizado** para
a equipe comercial, e em permitir a **importação em massa de leads** a partir de
planilhas (ex: uma base antiga em Excel, uma lista de indicações, um export de outra
ferramenta), sem precisar cadastrar um por um.

- **Código único do lead (`lead_code`)**: toda vez que um lead é criado — pelo
  formulário, pela importação CSV ou pelos webhooks — o sistema gera um código no
  formato `LEAD-AAAAMMDD-NNNN` (ex: `LEAD-20260804-0001`, `LEAD-20260804-0002`...),
  onde `NNNN` é um sequencial de 4 dígitos que **reinicia todo dia**. O código é
  gerado em `Lead::generateLeadCode()` (usa uma transação com
  `SELECT ... FOR UPDATE` como proteção leve contra concorrência) e o
  `Lead::createWithLeadCode()` tenta novamente algumas vezes caso duas requisições
  simultâneas colidam no mesmo código (constraint `UNIQUE` na coluna). O código
  aparece em destaque (badge) na listagem de leads e no perfil do lead.
- **Novas origens (`source`)**: além das origens já existentes (Facebook, Instagram,
  Google Ads, Indicação, Site, Landing Page, Orgânico, Outros), o ENUM agora aceita
  `cadastro_manual`, `whatsapp`, `importacao_csv`, `api` e `webhook`. O cadastro
  manual pela tela já vem com "Cadastro Manual" pré-selecionado, mas o usuário pode
  trocar livremente; leads criados pela importação CSV sempre recebem
  `importacao_csv`.
- **Wizard de cadastro em 4 abas** (`app/views/leads/form.php`): o formulário de
  lead foi reorganizado em etapas que seguem a evolução natural do relacionamento —
  **1) Primeiro Contato** (nome, DDD, telefone, WhatsApp, e-mail), **2) Perfil**
  (endereço, CPF, interesse, valor desejado, campanha/UTMs/origem),
  **3) Qualificação** (entrada, renda, profissão, empresa, temperatura, prioridade,
  Lead Score já calculado — somente leitura — e tags) e **4) Negociação** (status,
  responsável, datas de contato, observações). Nenhum campo é obrigatório e o botão
  **"Salvar Lead"** funciona a partir de qualquer aba (todos os campos continuam no
  HTML, só ficam visualmente ocultos). Um indicador de progresso com círculos
  numerados 1-2-3-4 no topo mostra a etapa atual; a navegação entre abas usa apenas
  JS simples (sem lib nova), em `public/assets/js/app.js` (`initLeadWizard`).
- **Tags no cadastro**: o formulário agora permite marcar tags existentes ou digitar
  novas (separadas por vírgula) na aba "Qualificação" — `Tag::findOrCreateByName()`
  cria a tag na hora se ela ainda não existir, e `Tag::syncForLead()` substitui o
  conjunto de tags do lead.
- **Checagem de duplicidade reforçada**: ao detectar um lead já existente com o
  mesmo WhatsApp/telefone/CPF/e-mail, o modal (SweetAlert2) agora mostra nome,
  telefone, status e código do cadastro existente com **dois botões dedicados**:
  "Abrir cadastro existente" (vai para o perfil do lead) e "Atualizar cadastro
  existente" (vai direto para a edição, já preenchida); a opção de continuar
  salvando mesmo assim (criando um possível duplicado de propósito) continua
  disponível como terceira opção.

### 10.1. Migration da Fase 4 (`database/sql/migration_fase4.sql`)

Depois de já ter rodado `schema.sql` + `seed.sql` (Fase 1), `migration_fase2.sql`
(Fase 2) e `migration_fase3.sql` (Fase 3), rode **por último**:

```bash
mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase4.sql
```

Ou via phpMyAdmin: aba **Importar** > escolha `database/sql/migration_fase4.sql` >
Executar. Ela é incremental e segura para rodar em um banco que já tem leads reais.

O que essa migration faz:

- **`leads`**: adiciona a coluna `lead_code VARCHAR(20)` com índice `UNIQUE`
  (`uq_leads_lead_code`), usada pelo código único do lead. Leads antigos ficam com
  `lead_code = NULL` até serem editados/salvos novamente (o MySQL permite múltiplos
  `NULL` em uma coluna `UNIQUE`, então isso não causa erro).
- **`leads.source`**: expande o `ENUM` com `cadastro_manual`, `whatsapp`,
  `importacao_csv`, `api` e `webhook`, mantendo todos os valores já existentes.
- **`imports`**: um registro por importação de CSV realizada — arquivo, total de
  linhas, quantos leads foram criados/atualizados, quantos erros/avisos e o status
  final (`processando`, `concluido`, `concluido_com_erros`, `falhou`).
- **`import_errors`**: erros/avisos linha a linha de cada importação (`row_num`,
  `raw_data` em JSON para depuração, `error_message`).

### 10.2. Como usar a importação de leads via CSV

Acesse **Importar Leads** no menu lateral (`/importar`). O fluxo tem 3 passos:

1. **Upload**: envie um arquivo `.csv` cuja primeira linha seja o cabeçalho das
   colunas (ex: `Nome;Telefone;E-mail;Cidade;Origem`). Vírgula, ponto e vírgula e
   tab são detectados automaticamente como separador. Tamanho máximo: 15MB.
2. **Mapeamento de colunas**: para cada coluna do arquivo, escolha a qual campo do
   lead ela corresponde (Nome, Telefone, WhatsApp, E-mail, CPF, Cidade, Estado, CEP,
   Origem, Campanha, UTMs, Interesse, Valor desejado, Possui entrada, Renda,
   Profissão, Empresa, Status, Observações etc.) ou marque **"Ignorar coluna"**. O
   sistema já sugere um mapeamento automático a partir do nome do cabeçalho — revise
   antes de continuar. Uma prévia das 5 primeiras linhas do arquivo é exibida para
   ajudar na conferência.
3. **Processamento**: ao confirmar, o sistema lê o CSV linha a linha
   (`fgetcsv`, sem libs externas), processa até **2.000 linhas por importação**
   (limite pensado para rodar de forma síncrona, sem fila/cron) e, para cada linha:
   - Valida formato básico de telefone/WhatsApp/CPF/e-mail — problemas de formato
     **não travam a importação**, apenas geram um aviso no relatório final.
   - Verifica duplicidade por WhatsApp/telefone/CPF/e-mail contra a base atual: se
     encontrar um lead existente, **atualiza** (campos vazios no CSV mantêm o valor
     atual, só sobrescreve o que veio preenchido); se não encontrar, **cria** um
     lead novo com `source = importacao_csv`, `lead_code` gerado automaticamente e
     registro em `lead_history`.
   - Ao final, mostra o **relatório da importação** (`/importar/relatorio/{id}`):
     resumo de criados/atualizados/erros e uma tabela detalhada linha a linha, com
     botão para exportar os erros/avisos em CSV.

O arquivo CSV enviado fica temporariamente em `storage/imports/` (nome único
gerado, sem relação com o nome original) e é apagado automaticamente assim que o
processamento termina.

**Sobre `.xlsx` (Excel binário):** esta fase **não** inclui um parser nativo de
`.xlsx`, pois isso exigiria uma biblioteca externa (ex: PhpSpreadsheet via
Composer) incompatível com a restrição do projeto de rodar 100% sem
Composer/Node/build step. Para importar uma planilha Excel ou Google Sheets, use
**Arquivo > Salvar como / Fazer download > CSV** antes de enviar. Um parser `.xlsx`
nativo (o formato é um ZIP com XML interno) fica como sugestão para uma fase futura.

---

## 11. O que foi implementado na Fase 5 (refinamentos pedidos pela Titanium Consultoria)

### 11.1. Lead Score: peso por forma de captação (origem)

Na Fase 2, o critério `origem_qualificada` era binário: só somava pontos se a origem
fosse `indicacao` ou `google`. A Fase 5 substitui esse critério genérico por **um
peso individual por origem**, refletindo a realidade comercial da Titanium: Landing
Page própria converte muito melhor que WhatsApp direto, e Google Ads converte melhor
que Meta Ads (Facebook/Instagram). Os pesos padrão (editáveis pela tela
**Configurações > Lead Score**, `/configuracoes/lead-score`) são:

| Origem | Peso | Racional |
| --- | --- | --- |
| Indicação | 18 | Maior conversão histórica — lead já chega pré-qualificado pela confiança de quem indicou. |
| Landing Page | 15 | Formulário preenchido = interesse ativo; converte melhor que contato frio. |
| Google Ads | 12 | Busca ativa (intenção), converte melhor que Meta Ads. |
| Site / Orgânico | 8 | Qualificação média, sem investimento pago direcionado. |
| Facebook Ads / Instagram Ads | 8 | Mídia paga qualificada, mas menor intenção de busca que Google. |
| WhatsApp direto | 6 | Contato "frio", sem qualificação prévia por formulário/anúncio. |
| Cadastro manual | 5 | Neutro — não indica qualidade por si só. |
| Importação CSV / API / Webhook | 4 | Neutro-baixo — indica só o meio de entrada, não a intenção do lead. |
| Outros | 3 | Origem não identificada. |

Implementação: `app/models/LeadScore.php::calculate()` busca dinamicamente a regra
`"origem_" . $lead['source']` no mapa de regras ativas (`activeRulesMap()`) e soma o
peso correspondente, se existir. Se a `migration_fase5.sql` ainda não tiver sido
rodada, o método simplesmente não encontra a regra e não soma nada extra — **não
quebra o sistema** (comportamento gracioso). O critério antigo `origem_qualificada`
é desativado (`ativo = 0`) pela migration, sem apagar o histórico.

A lógica de "buscar lead → contar interações do histórico → calcular → salvar" foi
extraída para `LeadScore::recalculateForLead($leadId)`, reaproveitada por
`LeadController`, `ImportController` e `AgendaController` (ação "Registrar contato
agora"), evitando duplicar a mesma lógica em cada controller.

### 11.2. SLA: definição de "primeira resposta"

A partir da Fase 5, `SlaController` considera como **primeira resposta** qualquer
evento de contato real da equipe registrado em `lead_history`: `contato` (registro
manual), `whatsapp` ou `ligacao` — não apenas `contato` como nas fases anteriores.
O tempo de SLA é calculado da criação do lead (`leads.created_at`) até o `MIN` desses
eventos. Um card explicativo no topo de `/sla` deixa essa metodologia visível e
auditável para a equipe.

### 11.3. Agenda integrada ao ecossistema

`/agenda` ganhou: ação rápida **"Registrar contato agora"** (AJAX, via SweetAlert2 —
cria o evento em `lead_history`, atualiza `last_contact_at`/`next_contact_at` e
recalcula o Lead Score, sem sair da tela); filtro por consultor responsável restrito
a admin/supervisor (`Auth::hasRole(['admin','supervisor'])` — um consultor comum só
vê a própria agenda, mesmo alterando a querystring); contadores de resumo
(Atrasados / Hoje / Próximos 7 dias / Sem data definida, ativo há 5+ dias); e botões
diretos para o perfil completo do lead e para o modal de WhatsApp já existente em
`leads/show.php` (via `?open=whatsapp`, sem duplicar a lógica de envio). Toda
ação de contato pela Agenda é registrada em `activity_log` (`log_activity()`).

### 11.4. Dashboard: insights acionáveis

Os insights que representam uma lista específica de leads (ex: "N leads sem contato
há mais de 5 dias") agora são **links clicáveis** que levam para `/leads` com o
filtro já aplicado na querystring, reaproveitando a tela de listagem existente.
Dois filtros novos foram adicionados em `Lead::buildWhere()` /
`LeadController::index()`:

- `?sem_contato_dias=N` — leads nunca contatados (cadastrados há mais de N dias) ou
  cuja última interação foi há mais de N dias, ainda em andamento.
- `?vencidos=1` — leads com `next_contact_at` no passado, ainda em andamento.

### 11.5. Paginação em todo o sistema

Extraído o helper `render_pagination()` (`app/helpers/helpers.php`), que renderiza a
navegação "Anterior / 1 2 3 / Próxima" preservando a querystring de filtros atuais —
o mesmo padrão visual já usado em Leads/Logs, agora reaproveitável. Aplicado em:

- **Usuários** (`/usuarios`): `User::paginate()` com busca por nome/e-mail.
- **Motivos de Perda** (`/motivos-perda`): paginação em memória sobre o ranking já
  carregado (o gráfico Chart.js continua usando o ranking completo).

Kanban/Pipeline, Dashboard e Agenda continuam sem paginação tradicional (drag-and-
drop, cards agregados e agrupamento por dia, respectivamente) — a Agenda limita o
grupo "sem data definida" a 50 itens por página, com aviso de que o restante pode
ser visto filtrando a tela de Leads.

### 11.6. Bug do MySQL 8: alias de agregação em expressão/ORDER BY

Reforçando a nota já existente: **nunca** referencie um alias de `SUM`/`AVG`/`COUNT`
dentro de uma expressão aritmética ou de comparação em `ORDER BY`/`HAVING` (ex:
`ORDER BY (alias1/alias2)` ou `ORDER BY alias IS NULL`) — o MySQL 8 recusa com
`SQLSTATE[42S22] ... reference to group function`. Sempre repita a expressão
agregada completa. Isso já estava corrigido em `app/helpers/insights.php` e
`app/controllers/SlaController.php` antes da Fase 5; uma varredura em **todos** os
controllers e models do projeto nesta fase não encontrou nenhuma outra ocorrência do
padrão problemático.

### 11.7. Migration da Fase 5 (`database/sql/migration_fase5.sql`)

Depois de já ter rodado `schema.sql` + `seed.sql` (Fase 1), `migration_fase2.sql`
(Fase 2), `migration_fase3.sql` (Fase 3) e `migration_fase4.sql` (Fase 4), rode
**por último**:

```bash
mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase5.sql
```

Ou via phpMyAdmin: aba **Importar** > escolha `database/sql/migration_fase5.sql` >
Executar. Ela é incremental e idempotente (`INSERT IGNORE` + `UPDATE` condicional),
segura para rodar mais de uma vez. O que ela faz:

- Desativa (`ativo = 0`, sem apagar) o critério antigo `origem_qualificada` em
  `lead_score_rules`.
- Insere um critério `origem_<source>` por origem (`origem_indicacao`,
  `origem_landing_page`, `origem_google`, `origem_site`, `origem_organico`,
  `origem_facebook`, `origem_instagram`, `origem_whatsapp`,
  `origem_cadastro_manual`, `origem_importacao_csv`, `origem_api`,
  `origem_webhook`, `origem_outros`) com os pesos da tabela da seção 11.1.

---

## 12. Notas técnicas importantes

- Nenhum campo de lead é obrigatório no banco (`NOT NULL`), exceto `id` e os
  timestamps — conforme solicitado no briefing, permitindo cadastro rápido e
  incremental.
- O roteador (`app/core/Router.php`) é um router simples baseado em array de
  rotas, sem dependências externas, com suporte a parâmetros dinâmicos
  (`/leads/{id}`).
- O ambiente de desenvolvimento usado para gerar este projeto **não tinha PHP nem
  MySQL instalados** — o código foi escrito e revisado manualmente com atenção a
  sintaxe (chaves, parênteses, ponto-e-vírgula). Antes de subir para produção,
  recomenda-se rodar `php -l` em todos os arquivos `.php` em um ambiente com PHP
  disponível, e testar o fluxo completo (login → dashboard → leads → pipeline) em
  um ambiente local (XAMPP/Laragon) antes do deploy final via FTP.
- Os novos uploads da Fase 2 (avatar do usuário e logo/favicon da empresa) são
  gravados em `public/uploads/avatars/` e `public/uploads/`, respectivamente;
  confirme que essas pastas têm permissão de escrita (`755`/`775`) na Hostinger.
- As telas `/usuarios` e `/configuracoes` fazem a checagem de permissão
  (`Auth::hasRole(['admin'])` + `Auth::can('users.manage'|'settings.manage')`)
  diretamente no controller (`requireAdmin()`), além de ficarem ocultas do menu
  lateral para quem não é `admin` — mas o controle real de acesso está no backend,
  não apenas no menu.
- As rotas do webhook (`/webhook/meta`, `/webhook/google`, `/webhook/generico`) são
  as únicas do sistema que **não** chamam `Auth::requireLogin()` — são
  intencionalmente públicas, pois o Meta/Google chamam a URL diretamente, sem
  sessão de usuário. A segurança delas é 100% via token secreto, então **gere um
  token forte** (o botão "Gerar" em Configurações usa `crypto.getRandomValues` para
  isso) e nunca deixe o campo em branco em produção.
- `app/core/Mailer.php` e `app/core/WhatsappClient.php` nunca lançam exceção para
  quem os chama — todo erro (SMTP fora do ar, token do WhatsApp inválido, etc.) é
  capturado internamente e vira `false`/mensagem de erro amigável, para nunca
  travar o cadastro de um lead ou a redefinição de senha por causa de uma
  integração externa fora do ar.

---

## 13. Status do projeto após a Fase 3, Fase 4 e Fase 5

Com a Fase 3, o Titanium CRM implementa **todos** os itens do briefing original,
incluindo as integrações externas que nas Fases 1/2 ficavam como placeholders
(webhook de captação, WhatsApp, SMTP real, permissões granulares e notificações).
Tudo foi escrito em PHP puro (cURL nativo + sockets `fsockopen`/
`stream_socket_enable_crypto`), sem Composer/Node/Docker, conforme a restrição do
projeto.

**O que ainda precisa ser validado por você em produção:** o ambiente usado para
desenvolver este projeto não tem PHP, MySQL nem acesso às APIs externas (Meta,
Google, WhatsApp Cloud API, um servidor SMTP real) instalados/disponíveis — todo o
código foi escrito e revisado manualmente com atenção a sintaxe e à documentação
oficial de cada API, mas o teste **end-to-end com credenciais reais** (receber um
lead de verdade via webhook, enviar um WhatsApp de verdade, receber o e-mail de
redefinição de senha de verdade) só pode ser feito por você, já hospedado na
Hostinger com banco de dados e domínio configurados. Recomendação de ordem de
teste: (1) rode `php -l` em todos os `.php` alterados assim que tiver acesso a um
ambiente com PHP; (2) importe as 5 migrations na ordem correta (schema/seed, seção
3, 9.1 e 10.1); (3) configure SMTP e teste "Esqueci minha senha"; (4) configure
WhatsApp e teste o botão no perfil de um lead; (5) configure o token do webhook e
cadastre a URL no Meta/Google, testando primeiro com uma ferramenta como
Postman/Insomnia (`POST /webhook/generico?token=...`) antes de depender do tráfego
real de anúncios.

Com a **Fase 4**, o cadastro manual de leads passou a gerar um código único
rastreável (`lead_code`), o formulário virou um wizard em 4 etapas mais rápido de
preencher pela equipe comercial, e ficou possível importar leads em massa via CSV
com mapeamento de colunas, detecção de duplicidade e relatório de erros — tudo isso
sem Composer/Node/Docker, como as fases anteriores. Antes de liberar a importação
para a equipe em produção, valide: (1) rode `database/sql/migration_fase4.sql`;
(2) confirme que `storage/imports/` existe e tem permissão de escrita (`755`/`775`)
na Hostinger; (3) teste uma importação pequena (5-10 linhas) de ponta a ponta antes
de importar uma base grande.

Com a **Fase 5**, o Lead Score passou a considerar o peso de cada forma de
captação, o SLA ficou mais preciso ao contar ligação/WhatsApp como resposta válida,
a Agenda passou a permitir registrar contato sem sair da tela, os insights do
Dashboard viraram atalhos diretos para a lista de leads correspondente, e a
paginação server-side chegou às telas de Usuários e Motivos de Perda. Antes de
liberar para a equipe: (1) rode `database/sql/migration_fase5.sql`; (2) confira os
pesos por origem em **Configurações > Lead Score** e ajuste se a realidade
comercial mudar; (3) rode `php -l` (ou o script de checagem de sintaxe) em todos os
arquivos alterados.

---

## 14. O que foi implementado na Fase 6 (responsividade + personalização de relatórios)

A Fase 6 atendeu dois pedidos da Titanium Consultoria: (A) revisão sistemática de
responsividade mobile-first em todo o sistema, com foco no Kanban e na listagem de
Leads; e (B) um sistema de personalização visual/de conteúdo para os relatórios
exportados. **Não há migration nova para rodar** — os dois itens reaproveitam
100% a estrutura já existente (CSS/JS do layout e a tabela `settings`). Se você já
rodou as migrations das Fases 2 a 5, não precisa fazer mais nada no banco.

### 14.1. Responsividade (mobile-first, breakpoints Bootstrap 5.3)

Todos os ajustes foram feitos como CSS/JS cirúrgico (sem reescrever views), reunidos
numa nova seção `RESPONSIVIDADE` ao final de `public/assets/css/app.css`, mantendo as
variáveis de tema (`--tc-*`) e o dark mode existentes:

- **Sidebar/topbar (`app/views/layouts/main.php`, `app.css`, `app.js`)** — a sidebar
  já virava um drawer off-canvas abaixo de 992px (regra pré-existente); a Fase 6
  adicionou um **overlay escurecido** (`.tc-sidebar-backdrop`) atrás do drawer, que
  fecha o menu ao clicar fora, ao clicar em qualquer item do menu, ao pressionar
  `Esc`, ou automaticamente se a tela for redimensionada de volta para desktop
  (`initSidebarToggle()` em `app.js`). O topbar reduz padding/altura e trunca o
  título da página com reticências em telas <768px, mantendo sempre visíveis o
  hambúrguer, o sino de notificações e o avatar do usuário. Dropdowns (notificações)
  ganharam largura máxima baseada em `vw` para nunca vazar da tela em celulares.
- **Tabelas (Leads, Usuários, Logs, Motivos de Perda, SLA, Agenda, Relatórios)** —
  todas já usavam `.table-responsive` (scroll horizontal), então esse continua sendo
  o piso mínimo de responsividade (decisão consciente: converter cada linha em
  "card" empilhado, como o padrão `data-label`, exigiria reescrever a marcação de
  ~8 tabelas por muito pouco ganho real, já que o scroll horizontal contido já
  resolve o vazamento de layout). O que mudou: fonte e padding das células reduzem
  em telas <576px para caber mais colunas visíveis antes do scroll, e o scroll
  ganhou inércia suave no iOS (`-webkit-overflow-scrolling: touch`).
- **Wizard de Leads (`app/views/leads/form.php`)** — os indicadores de etapa
  (`.tc-wizard-steps`) viram uma faixa com scroll horizontal em telas <576px (em vez
  de espremer os 4 passos), com círculos e rótulos menores. Os botões
  Cancelar/Voltar/Avançar/Salvar (`.tc-wizard-nav-bar`) empilham em coluna e ocupam
  100% da largura, evitando toques acidentais em botões próximos.
- **Dashboard, SLA, Motivos de Perda (gráficos Chart.js)** — todo `<canvas>` de
  gráfico agora fica dentro de um `.tc-chart-wrap` (altura fixa via CSS: 260px em
  mobile/tablet, 300px a partir de 992px) e cada `new Chart(...)` ganhou
  `maintainAspectRatio: false`, resolvendo gráficos gigantes ou cortados em celulares
  (antes dependiam só do atributo `height` do `<canvas>`, que o Chart.js ignora
  quando `responsive: true` sem esse flag).
- **Indicadores (mapa do Brasil + heatmap)** — o wrapper do SVG (`#tcBrazilMapWrap`)
  ganha `max-width` menor em telas <576px para não dominar a tela, e as células do
  heatmap de horários encolhem (largura mínima e fonte) para caber mais colunas
  antes do scroll horizontal, que já estava contido em `.table-responsive`.
- **KPIs (Dashboard, SLA, Agenda)** — já usavam `col-6 col-md-3`/`col-md-4`
  (2 colunas no mobile, mais no desktop); a Fase 6 apenas reduziu o tamanho da fonte
  do valor principal (`.tc-kpi-value`) e o padding do card em telas <576px para não
  cortar números grandes.

### 14.2. Kanban do Pipeline em mobile — arraste vs. botão "Mover para"

Este era o ponto mais delicado do pedido: o **HTML5 Drag and Drop API nativo**
(usado em `public/assets/js/app.js::initKanban()`) **não dispara eventos de arraste
de forma confiável em navegadores touch** (não existe um equivalente nativo de
`dragstart`/`dragover` para toque sem uma lib externa como SortableJS/interact.js,
que o projeto não usa por não ter Composer/NPM). Diante disso, a solução adotada
foi híbrida:

1. **Carrossel horizontal com scroll-snap** — abaixo de 768px, `.tc-kanban` ganha
   `scroll-snap-type: x mandatory` e cada `.tc-kanban-column` passa a ocupar `86vw`
   (`scroll-snap-align: start`), então o usuário "desliza" e cada coluna encaixa
   quase cheia na tela, em vez de aparecer cortada ao meio. Uma dica de texto
   (`.tc-kanban-hint`, só visível em mobile) explica o gesto.
2. **Botão "Mover para" como fallback de toque** — cada card do Kanban
   (`app/views/pipeline/index.php`) ganhou um botão circular (ícone de setas,
   `.tc-kanban-move-btn`) que só aparece em telas <768px (`d-md-none` via CSS). Ao
   tocar, abre um SweetAlert2 com um `<select>` das outras colunas/estágios; ao
   confirmar, chama o **mesmo endpoint AJAX** (`POST /pipeline/move`, mesmo CSRF)
   já usado pelo drag desktop, atualizando o card na tela sem recarregar a página —
   com o mesmo tratamento de erro/rollback visual do fluxo de arraste original. Em
   telas ≥768px o botão fica oculto e o arraste nativo continua funcionando
   exatamente como antes (nenhuma lógica de desktop foi alterada).

Resultado prático: em desktop/notebook o Kanban se comporta 100% como antes
(arraste nativo). Em celular/tablet touch, o usuário navega as colunas por
scroll-snap e move os leads pelo botão dedicado — sem depender de uma gestão de
toque que o HTML5 DnD nativo não garante.

### 14.3. Personalização de Relatórios (`app/controllers/ReportController.php`)

Nova tela **Relatórios > Personalizar Relatório**
(`GET /relatorios/personalizar`, `POST /relatorios/personalizar/atualizar`,
view `app/views/reports/customize.php`), acessível pelo botão "Personalizar
Relatório" em `/relatorios`, restrita a quem tem as permissões `reports.view` **e**
`leads.export` (mesma checagem já usada para exportar CSV/Excel/PDF — nenhuma
permissão nova foi criada).

**Decisão de armazenamento:** as preferências são **globais** (uma única
configuração para toda a empresa, não por usuário) e reaproveitam a tabela
`settings` (padrão chave/valor) já usada por `SettingController`, em vez de criar
uma tabela `report_templates` nova. Motivo: o pedido era personalizar a aparência
da empresa nos relatórios (não guardar múltiplos modelos diferentes por usuário), e
manter tudo em `settings` evita uma migration adicional — qualquer administrador
que rodar as migrations das Fases 2-5 já tem a tabela `settings` pronta para essas
novas chaves, que são criadas sob demanda (`INSERT ... ON DUPLICATE KEY UPDATE`, o
mesmo UPSERT que `Setting::set()` já fazia).

Novas chaves em `settings`:

| Chave                     | Conteúdo                                                        |
|----------------------------|------------------------------------------------------------------|
| `report_logo`              | URL do logo específico do relatório (upload em `public/uploads/`); se vazio, o relatório usa o `company_logo` já cadastrado em Configurações |
| `report_primary_color`     | Cor primária em hex (`#rrggbb`), escolhida via `<input type="color">`, aplicada ao cabeçalho/títulos do PDF/impressão |
| `report_cnpj`, `report_address`, `report_phone` | Campos livres exibidos no cabeçalho do relatório |
| `report_footer_text`       | Texto livre exibido no rodapé do relatório |
| `report_columns`           | JSON com as chaves das colunas selecionadas, **na ordem** em que aparecem no relatório |

Quem gerou o relatório e a data/hora continuam automáticos (já existiam antes da
Fase 6, via `Auth::user()['name']` e `date('d/m/Y H:i')`), sem precisar de campo de
configuração.

**Colunas selecionáveis** (checkboxes, catálogo completo em
`ReportController::columnCatalog()`): Código, Nome, Telefone, WhatsApp, E-mail, CPF,
Cidade, UF, Origem, Campanha, Interesse, Valor desejado, Faixa de renda, Profissão,
Status, Responsável, Lead Score, Temperatura, Prioridade, Data de cadastro, Último
contato, Próximo contato. A seleção persiste a última escolha usada (global) e é
aplicada, **na mesma ordem**, às três exportações:

- **CSV** (`ReportController::exportCsv()`) e **Excel** (`exportExcel()`) — o
  cabeçalho e as linhas passam a ser montados dinamicamente a partir da seleção de
  colunas (`columnHeaders()`/`rowValues()`), em vez da lista fixa que existia antes.
- **Impressão/PDF** (`app/views/reports/print.php`) — o cabeçalho agora mostra o
  logo configurado (ou o logo padrão do sistema como fallback), aplica a cor
  primária no título e no cabeçalho da tabela via uma variável CSS
  (`--tc-print-color`), exibe CNPJ/telefone/endereço no canto superior direito (se
  preenchidos) e o texto de rodapé customizado ao final da página, além de montar as
  colunas da tabela dinamicamente na ordem escolhida. A página de impressão também
  ganhou ajustes de responsividade próprios (ela é standalone, sem o layout
  principal): tabela com scroll horizontal contido e padding menor em telas <576px,
  para quem abrir o link em um celular antes de mandar para a impressora/PDF.

Antes de liberar a personalização para a equipe: (1) confirme que
`public/uploads/` tem permissão de escrita (`755`/`775`) na Hostinger, já usada
pelo upload de logo/favicon de Configurações; (2) acesse
**Relatórios > Personalizar Relatório**, defina cor, logo e colunas desejadas, e
gere um PDF de teste (`Gerar PDF (imprimir)`) para conferir o resultado antes de
enviar para o cliente final; (3) rode `php -l` (ou o script de checagem de sintaxe)
nos arquivos alterados desta fase.

---

## Módulo: Tarefas (delegação de demandas internas entre colaboradores)

Sistema de gestão de tarefas internas da equipe, independente do "próximo
contato de um lead" (que já existe na Agenda). Uma tarefa aqui é uma demanda
de trabalho genérica delegada entre colaboradores — pode ou não estar
vinculada a um lead específico (ex: "Preparar apresentação para reunião de
sexta", ligada ou não a um lead).

**Migration:** `database/sql/migration_tasks.sql` — **independente** das
migrations fase2/fase3/fase4/fase5/chat, pode ser executada a qualquer
momento **depois** delas (usa apenas as tabelas `users`, `leads` e, se
existirem, `permissions`/`role_permissions` da Fase 3 — se essas duas últimas
ainda não existirem, os `INSERT`s de permissão simplesmente não têm efeito e
`Auth::can()` já cai no fallback seguro descrito em `app/core/Auth.php`).

```
mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_tasks.sql
```

Cria as tabelas `tasks`, `task_comments`, `task_history` (timeline de
auditoria, mesmo padrão de `lead_history`) e `task_watchers` (usuários que
acompanham a tarefa mesmo sem serem o responsável; quem cria uma tarefa vira
watcher automático).

**Permissões** (catálogo `permissions`/`role_permissions` da Fase 3):

| Slug              | Descrição                                              | Padrão                         |
|-------------------|----------------------------------------------------------|-------------------------------|
| `tasks.view_all`  | Ver todas as tarefas do sistema                          | admin, supervisor             |
| `tasks.create`    | Criar/delegar tarefas                                    | admin, supervisor, consultor  |
| `tasks.manage`    | Editar, excluir e reatribuir qualquer tarefa (não só as próprias) | admin, supervisor  |

Um consultor comum sem `tasks.view_all` só enxerga (e só pode comentar/mudar
status de) tarefas onde é `creator_id` **ou** `assigned_to` **ou** está em
`task_watchers` — a checagem é sempre feita no backend
(`TaskController::canView()`/`canManage()`), nunca só escondendo botões no
front-end.

**Cálculo do SLA da tarefa** (`Task::slaStatus()`, em
`app/models/Task.php`): cada tarefa pode ter um `sla_hours` opcional (ex: uma
tarefa urgente com SLA de 4h). O prazo-limite é `created_at + sla_hours
horas`. Enquanto a tarefa está aberta, compara esse prazo-limite contra
`NOW()` e mostra "Xh restantes" ou "Estourado há Xh"; quando a tarefa é
concluída (`completed_at` preenchido), a comparação passa a ser contra
`completed_at`, mostrando "Concluída dentro do SLA" ou "Concluída com SLA
estourado (Xh de atraso)". Tarefas sem `sla_hours` definido simplesmente não
exibem badge de SLA. O campo `due_at` (prazo) é independente do SLA: é a data
combinada de entrega, usada para o indicador visual de "atrasada" na
listagem/detalhe (badge vermelho piscando quando `due_at` já passou e o
status não é `concluida`/`cancelada`).

**Fluxo de notificação:** reaproveita integralmente o model/tela de
notificações já existente (sino do topbar, `app/models/Notification.php` +
`NotificationController`), sem tabela nova. Toda mudança relevante — criação
com responsável já definido, reatribuição, mudança de status, novo comentário
— dispara uma notificação interna para o responsável atual **e** para todos
os `task_watchers`, **exceto** para quem executou a própria ação
(`TaskController::notifyOthers()`). O link da notificação leva direto para
`/tarefas/{id}`.

**Rotas** (todas atrás de `requireLogin()`, ver `public/index.php`):
`GET /tarefas` (listagem com abas "Minhas Tarefas" / "Criadas por mim" /
"Todas" — esta última só visível com `tasks.view_all`), `GET /tarefas/nova` e
`POST /tarefas` (criar), `GET /tarefas/{id}` (detalhe: timeline, comentários,
ações rápidas de status), `GET /tarefas/{id}/edit` e `POST /tarefas/{id}`
(editar), `POST /tarefas/{id}/comentarios`, `POST /tarefas/{id}/status`
(mudança rápida de status via AJAX, usada tanto no botão "Concluir" da
listagem quanto nos botões Iniciar/Aguardando/Concluir/Cancelar/Reabrir da
tela de detalhe) e `POST /tarefas/{id}/excluir`.

**Integração com o restante do sistema:**
- Item "Tarefas" na sidebar (`app/views/layouts/main.php`), com badge de
  contagem de tarefas pendentes/em andamento atribuídas ao usuário logado
  (calculado no carregamento da página via `Task::countPendingForUser()`,
  sem polling adicional — decisão deliberada para não duplicar a lógica de
  polling já usada pelo sino de notificações/chat).
- Seção "Tarefas relacionadas" no perfil do lead
  (`app/views/leads/show.php`), com botão "Nova Tarefa" que pré-preenche o
  lead vinculado (`/tarefas/nova?lead_id={id}`). Implementada isoladamente
  dentro da própria view (busca as tarefas do lead com `try/catch` próprio),
  sem alterar `LeadController::show()`, para não conflitar com outras
  mudanças em andamento nessa tela.
- `log_activity()` registrado ao criar, atualizar status e excluir tarefas.

Antes de liberar o módulo: rode a migration, confirme que os três papéis
(admin/supervisor/consultor) têm as permissões esperadas em **Configurações
de Usuários/Permissões**, e teste o fluxo completo (criar tarefa atribuída a
outro usuário → confirmar que a notificação aparece no sino dele → mudar
status → verificar timeline e badge de SLA).

## Módulo: Chat Interno (dividido por departamento)

Chat interno entre os usuários do CRM, separado por departamento (Comercial,
Suporte, Financeiro, Diretoria) além de uma sala "Geral" visível a todos,
grupos customizados criados pelos próprios usuários e conversas diretas
(DM) 1-a-1. Módulo novo e independente do restante do sistema — não altera
nada de leads/pipeline.

**Migration:** `database/sql/migration_chat.sql` — execute **depois** de
`schema.sql`, `seed.sql` e das migrations `migration_fase2.sql` até
`migration_fase5.sql` (usa a tabela `permissions`/`role_permissions` da Fase
3). Pode ser executada antes ou depois de `migration_tasks.sql` — são
independentes entre si.

```
mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_chat.sql
```

Cria as tabelas `chat_departments` (catálogo de departamentos, com seed
inicial: Comercial, Suporte, Financeiro, Diretoria e Geral), `chat_rooms`
(sala de departamento, grupo customizado ou DM), `chat_room_members`
(participantes + papel de moderação local + `last_read_at` para o cálculo de
não lidas) e `chat_messages` (mensagens com soft delete e edição). Também
adiciona a coluna `department_id` em `users` (FK nullable para
`chat_departments`, usada para a entrada automática do usuário na sala do
seu departamento) e semeia uma sala de departamento para cada
`chat_department`, já com todos os usuários existentes adicionados à sala
"Geral". Não foi criada uma tabela extra de leitura por mensagem: o campo
`last_read_at` em `chat_room_members` já é suficiente para calcular não
lidas (`COUNT` de mensagens com `created_at > last_read_at`).

### Como funciona o polling (sem WebSockets/Node)

A hospedagem é compartilhada (Hostinger) e o projeto é PHP puro, então não
há WebSockets nem processo Node em segundo plano — **isso é uma limitação
aceita do ambiente, não um bug**. Em vez disso, o front-end
(`public/assets/js/app.js`) usa dois polls AJAX independentes, ambos
pausados quando a aba do navegador está em segundo plano (Page Visibility
API, `document.hidden`), para não sobrecarregar o servidor:

- **`initChat()`** — roda só dentro da tela `/chat`, a cada **5 segundos**,
  buscando em `GET /chat/salas/{id}/mensagens?since_id=...` somente as
  mensagens novas da sala aberta (nunca recarrega a conversa inteira). Ao
  receber mensagens novas com a aba em foco, marca a sala como lida
  automaticamente (`POST /chat/salas/{id}/ler`).
- **`initChatSidebarBadge()`** — roda em **todas as telas** (item "Chat" da
  sidebar), a cada **18 segundos**, chamando `GET /chat/nao-lidas` só para
  atualizar o contador do badge (não busca mensagens).

O histórico mais antigo é carregado sob demanda ("Carregar mensagens mais
antigas", `before_id`) em vez de paginação automática por scroll, para
manter o front-end simples.

### Departamentos e entrada automática nas salas

Cada usuário tem um `department_id` opcional (editável em
**Usuários > Novo/Editar**, `app/views/users/form.php`). A entrada nas
salas de departamento é sincronizada automaticamente em três pontos —
`ChatRoom::syncUserDepartmentMembership()` — para nunca depender de um
único evento:

1. No **login** (`AuthController::authenticate()`);
2. No **cadastro/edição de usuário** (`UserController::store()`/`update()`);
3. Defensivamente, a cada vez que a tela `/chat` é aberta
   (`ChatController::index()`, "self-heal"), cobrindo usuários que existiam
   antes da migration ser executada.

Todo usuário sempre participa da sala "Geral". Ao trocar de departamento, o
usuário é removido automaticamente da sala do departamento antigo (a
"Geral" nunca é removida) e adicionado à nova.

### Comandos de barra ("/comando")

Mensagens que começam com `/` são interpretadas como comando em vez de
texto (`ChatController::handleCommand()`), sempre validando permissão no
backend antes de executar:

| Comando              | O que faz                                                      | Quem pode usar                          |
|-----------------------|----------------------------------------------------------------|------------------------------------------|
| `/ajuda`               | Lista os comandos disponíveis                                  | Qualquer membro da sala                  |
| `/limpar`              | Apaga (soft delete) todas as mensagens da sala                 | Moderador da sala ou `chat.moderate`     |
| `/adicionar @usuario`  | Adiciona um membro a uma sala de **grupo** (nome ou e-mail)     | Moderador da sala ou `chat.moderate`     |
| `/remover @usuario`    | Remove um membro de uma sala de **grupo**                      | Moderador da sala ou `chat.moderate`     |

`/ajuda` e as respostas de erro são **efêmeras**: aparecem só para quem
digitou o comando (retornadas direto no JSON da resposta, sem gravar no
banco). `/limpar`, `/adicionar` e `/remover`, quando bem-sucedidos, geram
uma mensagem de sistema real (gravada e visível a todos) e um registro em
`log_activity()`. Comandos não reconhecidos retornam um erro amigável sem
travar o chat.

### Permissões e moderação

Reaproveita o sistema `Auth::can()` já existente (Fase 3). Duas novas
permissões globais, cadastradas por `migration_chat.sql`:

| Slug                 | Descrição                                                                 | Padrão                        |
|----------------------|-----------------------------------------------------------------------------|-------------------------------|
| `chat.moderate`      | Apagar/limpar mensagens e gerenciar membros de **qualquer** sala            | admin, supervisor             |
| `chat.create_room`   | Criar salas de grupo                                                        | admin, supervisor, consultor  |

Além da permissão global, cada `chat_room_members.role` (`membro` /
`moderador` / `admin_sala`) dá poderes de moderação **só naquela sala
específica** — por exemplo, um consultor sem `chat.moderate` que cria um
grupo entra automaticamente como `admin_sala` dele e pode moderar aquele
grupo, mesmo sem a permissão global. `ChatController::canModerate()`
verifica as duas condições (permissão global OU papel local) antes de
qualquer ação de moderação.

**Controle de acesso à sala:** um usuário só lê/escreve numa sala se
existir uma linha dele em `chat_room_members` para aquele `room_id`
(`ChatRoom::isMember()`) — checado no backend em toda ação (`GET`
mensagens, `POST` mensagens, apagar, silenciar, sair, ver membros),
retornando 403 caso contrário. O acesso nunca é decidido só escondendo
elementos no front-end.

### Segurança

Prepared statements PDO em 100% das queries; todo texto de mensagem é
gravado como texto puro e escapado **na saída**, tanto no PHP
(`app/views/chat/_message.php`, via `e()`/`nl2br`) quanto no JS
(`escapeHtml()` em `app.js`, reaproveitado do sino de notificações) — nunca
é renderizado como HTML vindo do usuário, prevenindo XSS via chat. CSRF
token obrigatório em todo `POST` (envio de mensagem, apagar, silenciar,
criar sala, sair, comandos). Apagar mensagem é sempre soft delete
(`deleted_at`), preservando auditoria mesmo após "apagada" na tela.

### Rotas (todas atrás de `requireLogin()`, ver `public/index.php`)

`GET /chat` (tela principal), `GET /chat/salas/{id}/mensagens` (polling
incremental via `since_id` ou histórico via `before_id`), `POST
/chat/salas/{id}/mensagens` (enviar mensagem/comando), `POST
/chat/salas/{id}/ler`, `POST /chat/salas/{id}/silenciar`, `POST
/chat/salas/{id}/sair`, `GET /chat/salas/{id}/membros`, `POST /chat/salas`
(criar grupo), `POST /chat/direto/{userId}` (cria/abre DM), `POST
/chat/mensagens/{id}/apagar`, `GET /chat/usuarios/buscar` (typeahead para
iniciar DM) e `GET /chat/nao-lidas` (badge da sidebar).

### Limitações conhecidas (aceitas, não são bugs)

- **Sem tempo real via WebSocket**: mensagens novas aparecem em até ~5s
  (intervalo do polling), não instantaneamente — decisão deliberada dada a
  restrição de hospedagem compartilhada sem Node/processo persistente.
- Trocar de sala navega a página (recarrega o servidor); só o conteúdo
  *dentro* da sala aberta é atualizado via polling incremental, para manter
  o front-end simples e consistente com o resto do sistema (server-rendered
  PHP + AJAX pontual, sem framework SPA).
- Sem upload de arquivo/imagem nesta primeira versão (somente texto).

## Fase 7 (parte 2): "Meu Dia", badge de Agenda, Metas e sincronização Tarefas ↔ Agenda

Complemento à auditoria de UX da Fase 7 (produtividade diária). Migration
própria, **independente** de qualquer outra migration `migration_fase7_*.sql`
aplicada em paralelo: `database/sql/migration_fase7_agenda.sql`.

### O que a migration cria

- **Tabela `user_goals`**: meta mensal por vendedor (`user_id`, `year`,
  `month`, `target_closed_deals`, `target_new_leads` opcional), com índice
  único em `(user_id, year, month)` para nunca duplicar a meta de um mês.
- **Permissão `goals.manage`** (definir metas de qualquer vendedor),
  atribuída por padrão a `admin` e `supervisor`.

Como importar (idempotente, pode rodar mais de uma vez):
```
mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase7_agenda.sql
```

### Tela "Meu Dia" (`GET /hoje`)

`app/controllers/TodayController.php` + `app/views/today/index.php`. Visão
unificada e **pessoal** (sempre filtrada por `Auth::id()`) do que o usuário
logado precisa fazer agora, combinando três fontes que já existiam no
sistema — reaproveitando os MESMOS critérios de cada uma, sem duplicar
regra de negócio nova:

- **Leads vencidos**: mesmo critério de "atrasados" da Agenda
  (`next_contact_at` no passado).
- **Tarefas de hoje/atrasadas**: `Task::dueTodayOrOverdueForUser()`,
  atribuídas ao usuário logado, status não concluído/cancelado.
- **Leads sem primeiro contato**: mesmo critério do SLA (nenhum registro em
  `lead_history` do tipo `contato`/`whatsapp`/`ligacao`, lead criado há mais
  de 24h), restrito aos leads do usuário logado.

Três cards de contador no topo (com link direto para a tela de origem de
cada métrica) + uma lista combinada ordenada por urgência (data mais
próxima primeiro), cada item com ação rápida: "Registrar contato agora"
para leads (reaproveita o MESMO endpoint AJAX e modal da Agenda,
`AgendaController::quickContact`, sem duplicar lógica) e "Concluir" para
tarefas (reaproveita `TaskController::changeStatus`). Também exibe, quando
o usuário tem uma meta cadastrada para o mês corrente, uma barra de
progresso "fechados vs. meta".

**Item de menu**: "Meu Dia" foi adicionado à sidebar (ícone `fa-bolt`, logo
abaixo de Dashboard) com destaque visual sutil (classe `.tc-nav-highlight`
em `app.css`) por ser a tela mais usada no dia a dia.

**Sobre o redirecionamento pós-login**: avaliamos redirecionar consultores
direto para `/hoje` após o login (em vez de `/dashboard`), mas decidimos
**não alterar** `AuthController::authenticate()` nesta fase — o fluxo atual
já é usado por admin/supervisor/consultor sem distinção, e uma mudança de
redirect por papel exigiria testar todos os fluxos de login novamente sem
necessidade real (a tela já está a um clique de distância no menu). Fica
registrado como melhoria possível para uma fase futura, não como limitação
bloqueante.

### Badge de "atrasados" na Agenda (sidebar)

Mesmo padrão já usado no badge de Tarefas (contador calculado no
carregamento da página, em `app/views/layouts/main.php`, sem polling
separado): conta leads com `next_contact_at` no passado. Segue a MESMA
regra de visibilidade que a própria Agenda usa — admin/supervisor veem o
total geral do sistema, os demais usuários só a contagem dos próprios
leads (`assigned_to = Auth::id()`).

### Metas pessoais por vendedor (`GET/POST /metas`)

`app/controllers/GoalController.php` + `app/models/UserGoal.php` + `app/views/goals/index.php`.
Restrito a `admin`/`supervisor` via `Auth::hasRole()` + `Auth::can('goals.manage')`.
Tela simples: seletor de mês/ano + uma linha por vendedor ativo, com campo
de meta de fechamentos (`target_closed_deals`) e meta opcional de novos
leads trabalhados (`target_new_leads`). Item de menu "Metas" (`fa-bullseye`)
aparece em Gestão só para quem tem a permissão.

O cálculo de "fechados no mês" (`UserGoal::closedDealsForUser()`) usa
`leads.status = 'fechado' AND assigned_to = <usuário> AND YEAR(updated_at) = ... AND MONTH(updated_at) = ...`.
**Limitação conhecida**: como o schema atual não tem uma coluna dedicada
`closed_at`, usamos `updated_at` como aproximação de "quando o lead foi
fechado" — se um lead já fechado for editado por outro motivo num mês
seguinte, ele passa a contar no mês da edição, não no mês do fechamento
original. Aceitável para uma primeira versão do recurso; migrar para uma
coluna `closed_at` dedicada fica registrado como melhoria futura.

### Sincronização Tarefas ↔ Agenda

Integração deliberadamente **conservadora**: nada nela é automático sem uma
ação explícita do usuário, para não surpreender o fluxo já existente e
funcional de Agenda/Tarefas.

| Direção | O que faz | É automático? |
|---|---|---|
| Criar tarefa → Agenda | Se a tarefa tem `lead_id` e `due_at` preenchidos, uma checkbox no formulário ("Também atualizar o próximo contato deste lead") permite atualizar `leads.next_contact_at` para bater com o prazo da tarefa. | **Não** — só se a checkbox for marcada (`app/views/tasks/form.php`, campo `sync_next_contact`). |
| Concluir tarefa → Agenda | Um botão dedicado "Concluir e sincronizar contato do lead" (`app/views/tasks/show.php`, exibido só quando a tarefa tem lead vinculado) conclui a tarefa E atualiza `leads.last_contact_at = NOW()` / limpa `next_contact_at`. O botão "Concluir" padrão continua existindo sem nenhuma mudança de comportamento. | **Não** — é uma ação separada e explícita (`TaskController::changeStatus` só sincroniza quando recebe `sync_lead_contact=1`). |
| Registrar contato (Agenda) → Tarefas | Se o lead tiver uma tarefa aberta vinculada a ele, o retorno AJAX de `AgendaController::quickContact` inclui um aviso (`open_task_warning`) exibido no SweetAlert de sucesso, sugerindo concluir a tarefa também. | **Não conclui nada sozinho** — é só um aviso informativo; o usuário precisa ir em Tarefas e concluir manualmente. |

Cada sincronização registra um evento em `lead_history` (`type =
'agendamento'` ou `'observacao'`) para manter a timeline do lead auditável
mesmo quando a mudança veio do módulo de Tarefas.

### Arquivos desta parte da Fase 7

- `database/sql/migration_fase7_agenda.sql` (nova, independente)
- `app/models/UserGoal.php` (novo)
- `app/controllers/TodayController.php` (novo)
- `app/controllers/GoalController.php` (novo)
- `app/views/today/index.php` (novo)
- `app/views/goals/index.php` (novo)
- `app/models/Task.php` (método novo `dueTodayOrOverdueForUser()`)
- `app/controllers/TaskController.php` (sincronização opcional com a Agenda)
- `app/controllers/AgendaController.php` (aviso opcional de tarefa aberta em `quickContact()`)
- `app/views/tasks/form.php`, `app/views/tasks/show.php`, `app/views/agenda/index.php` (ajustes de UI para a sincronização)
- `app/views/layouts/main.php` — **somente a sidebar**: item "Meu Dia", badge de atrasados na Agenda, item "Metas"
- `public/assets/css/app.css` (classe `.tc-nav-highlight`)
- `public/index.php` (rotas `GET /hoje`, `GET /metas`, `POST /metas/update`)

## Fase 7 (parte 3): Leads/Pipeline — auditoria de UX (score/temperatura na
listagem, escopo "meus leads", busca global, nota rápida, templates de
WhatsApp, ações em lote, índices e N+1 do Pipeline)

Implementação em paralelo à Fase 7 (parte 2) acima, focada em Leads e
Pipeline. Migration própria (`migration_fase7_leads.sql`), sem tocar na
sidebar/`AgendaController`/`TaskController`/`DashboardController`.

### 1. Score, Temperatura e "dias sem contato" visíveis

- `app/helpers/helpers.php`: novos helpers `days_since_contact_label()`,
  `days_since_contact_is_stale()`, `score_badge_class()`, `temperature_label()`
  e `temperature_badge_class()`.
- `app/views/leads/index.php`: colunas "Score" (badge verde ≥70 / amarelo
  40-69 / cinza <40), "Temperatura" e "Últ. contato" (destacado em vermelho
  via classe `.tc-text-stale` quando >5 dias ou nunca contatado).
- `app/views/pipeline/index.php`: cada card do Kanban ganhou uma bolinha
  colorida de temperatura ao lado do nome, o badge de score e a linha
  "Últ. contato", sem perder a compacidade do card.

### 2. Escopo "Meus leads" / "Todos os leads"

- `app/controllers/LeadController::index()` e `PipelineController::index()`
  agora usam a MESMA regra de permissão já usada pela Agenda
  (`Auth::hasRole(['admin','supervisor'])`): quem não tem esse papel é
  travado em "meus leads" (`assigned_to = Auth::id()`), sem ver o toggle.
  Quem tem o papel vê o toggle "Meus leads"/"Todos os leads" — com "Meus
  leads" como padrão mesmo para admin/supervisor — controlado pela
  querystring `?view=mine|all` (não persiste em sessão/banco).
- No Pipeline, o filtro "Filtrar por responsável" (select de consultor) só
  aparece quando o escopo está em "Todos os leads".

### 3. Busca global no topbar

- `app/views/layouts/main.php` — **somente a área do topbar** (ao lado do
  sino de notificações), campo de busca `#tcGlobalSearchInput` dentro de
  `#tcGlobalSearch`.
- Rota `GET /leads/buscar-rapido` → `LeadController::quickSearch()`, que usa
  `Lead::quickSearch()` (novo método no model) para buscar por nome,
  telefone, whatsapp, e-mail ou `lead_code` (LIMIT 8), respeitando o mesmo
  escopo "meus leads" de quem não é admin/supervisor.
- `public/assets/js/app.js`, seção `/* ==== BUSCA GLOBAL (Fase 7 - auditoria
  UX) ==== */` (função `initGlobalSearch()`): debounce de ~300ms, dropdown
  simples abaixo do campo, cada item linkando para `/leads/{id}`.

### 4. Nota rápida via AJAX (listagem, Pipeline e perfil do lead)

- Novo endpoint reaproveitável `POST /leads/{id}/nota-rapida` →
  `LeadController::quickNote()`: grava em `lead_history` (tipo
  `observacao`), atualiza `last_contact_at` e recalcula o `lead_score`
  (mesmo padrão do "Registrar contato agora" da Agenda — só leitura de
  `AgendaController.php`, sem editá-lo).
- Botão "Nota rápida" (ícone de post-it) em cada linha de
  `app/views/leads/index.php` e em cada card de `app/views/pipeline/index.php`,
  usando SweetAlert2 com textarea (`app.js`, seção `/* ==== NOTA RÁPIDA ====
  */`, funções `tcPromptQuickNote()`/`tcSubmitQuickNote()`/`initQuickNoteButtons()`).
- `app/views/leads/show.php`: o formulário de "observação rápida" do perfil
  do lead passou a ser enviado via AJAX (`initLeadQuickNoteForm()` em
  `app.js`) contra o mesmo endpoint `/nota-rapida`, atualizando a timeline
  na hora sem recarregar a página. O `action`/CSRF tradicionais continuam no
  HTML como fallback caso o JS não carregue.

### 5. Templates de mensagem para WhatsApp

- Migration cria a tabela `whatsapp_templates` (`name`, `content` com
  placeholders `{{nome}}`/`{{interesse}}`/`{{responsavel}}`, `active`), com
  4 templates de exemplo (primeiro contato, lembrete de documentação,
  proposta enviada, follow-up).
- Novo `app/models/WhatsappTemplate.php` e
  `app/controllers/WhatsappTemplateController.php` (CRUD completo + rota
  JSON `GET /configuracoes/whatsapp-templates/listar` para o `<select>` do
  modal), com view `app/views/settings/whatsapp-templates.php` — acessível
  por um botão em `app/views/settings/index.php` (Configurações), restrito a
  `Auth::hasRole(['admin']) && Auth::can('settings.manage')` (mesma regra do
  restante de `SettingController`).
- `app/views/leads/show.php`: o modal de envio de WhatsApp ganhou um
  `<select>` de templates que, ao ser escolhido, substitui os placeholders
  pelos dados reais do lead (nome, interesse, responsável) via JS antes de
  preencher a textarea (`app.js`, dentro de `initWhatsappForm()`).

### 6. Ações em lote na listagem de Leads

- `app/views/leads/index.php`: checkbox por linha + "selecionar todos" no
  cabeçalho, e uma barra de ações (`#tcBulkBar`) que aparece quando há
  seleção — só é renderizada para quem tem `Auth::can('leads.edit')`.
- Ações disponíveis: mudar status, reatribuir responsável, aplicar tag —
  cada uma com confirmação via SweetAlert2 mostrando quantos leads serão
  afetados.
- Endpoint `POST /leads/acao-em-lote` → `LeadController::bulkAction()`:
  valida `Auth::can('leads.edit')` e registra um evento em `lead_history`
  **para cada lead afetado** (a auditoria não é pulada por ser em lote).
- `app.js`, seção `/* ==== AÇÕES EM LOTE NA LISTAGEM DE LEADS ==== */`
  (função `initBulkActions()`).

### 7. Índices adicionados (`migration_fase7_leads.sql`)

- `leads.idx_leads_next_contact_at`, `leads.idx_leads_last_contact_at` e
  `lead_history.idx_lead_history_type`, todos criados de forma idempotente
  (checagem via `INFORMATION_SCHEMA.STATISTICS` + `PREPARE`/`EXECUTE`, igual
  ao padrão de `migration_fase4.sql`).

### 8. N+1 do Pipeline eliminado

- `PipelineController::index()` deixou de rodar um `paginate()` por status
  (um SELECT + um COUNT por status, dentro de um loop) e passou a buscar
  TODOS os leads dos status usados no Kanban em uma única query, usando
  `ROW_NUMBER() OVER (PARTITION BY l.status ORDER BY l.updated_at DESC)`
  (MySQL 8) para manter o mesmo limite de 200 leads por status que existia
  antes — sem usar alias de agregação em `ORDER BY`/`HAVING` (o bug já
  conhecido nesta sessão), já que `ROW_NUMBER()` não é uma função de
  agregação e o filtro `tc_rn <= 200` fica no `WHERE` da query externa, não
  em `HAVING`. O resultado é agrupado em PHP pelo `$stageStatusMap` já
  existente, mantendo a mesma estrutura de dados (`['stage' => ..., 'leads'
  => ...]`) que `pipeline/index.php` já esperava.

### Arquivos desta parte da Fase 7 (Leads/Pipeline)

- `database/sql/migration_fase7_leads.sql` (nova, independente da migration
  de Agenda/Metas da parte 2)
- `app/models/WhatsappTemplate.php` (novo)
- `app/controllers/WhatsappTemplateController.php` (novo)
- `app/views/settings/whatsapp-templates.php` (novo)
- `app/models/Lead.php` (método novo `quickSearch()`)
- `app/controllers/LeadController.php` (métodos novos `quickSearch()`,
  `quickNote()`, `bulkAction()`; escopo "meus leads" em `index()`)
- `app/controllers/PipelineController.php` (escopo "meus leads" + reescrita
  de `index()` para eliminar o N+1)
- `app/views/leads/index.php`, `app/views/pipeline/index.php`,
  `app/views/leads/show.php` (colunas novas, nota rápida, ações em lote,
  templates de WhatsApp)
- `app/views/settings/index.php` (link para a sub-tela de templates)
- `app/views/layouts/main.php` — **somente o topbar**: campo de busca global
- `public/assets/js/app.js` (seções "BUSCA GLOBAL", "NOTA RÁPIDA", "AÇÕES EM
  LOTE NA LISTAGEM DE LEADS", "OBSERVAÇÃO RÁPIDA VIA AJAX NO PERFIL DO
  LEAD", e ajuste em `initWhatsappForm()`)
- `public/assets/css/app.css` (seção "Fase 7 (auditoria UX): Temperatura do
  lead, busca global, ações em lote" + estilos do botão de nota rápida no
  Kanban)
- `public/index.php` (rotas de busca rápida, nota rápida, ação em lote e
  templates de WhatsApp)

## Fase 8 — Agenda (novo agendamento), mapa Leaflet.js e melhorias de interação

Nota de contexto importante: uma investigação ao vivo em produção
(https://azure-eel-382308.hostingersite.com) mostrou que a maior parte dos
bugs reportados pelo cliente nesta fase (Chat sem estilo, botões do Kanban
sem função, barra de ações em lote "empilhada") era causada por
`public/assets/css/app.css` e `public/assets/js/app.js` estarem
desatualizados em produção (deploy/FTP do cliente, não um bug de código) —
esses recursos JÁ EXISTIAM no código-fonte antes desta fase. Também foi
identificado um bug de infraestrutura da Hostinger (CDN com compressão zstd
corrompendo respostas PHP dinâmicas grandes, causando HTTP 500 em telas de
tarefa com lead vinculado) que é 100% do lado da hospedagem — não há nada a
corrigir no código para isso; o cliente foi orientado a abrir chamado com o
suporte da Hostinger.

### 1. Agenda: botão "Novo Agendamento"

Antes desta fase, a Agenda só listava leads que já tinham `next_contact_at`
preenchido — não havia como criar um agendamento novo pela tela. Agora:

- Botão **"Novo Agendamento"** no topo de `app/views/agenda/index.php`, que
  abre um modal SweetAlert2 (mesmo padrão visual já usado no "Registrar
  contato agora") com:
  - Busca de lead já cadastrado, reaproveitando o endpoint existente
    `GET /leads/buscar-rapido` (`LeadController::quickSearch()`) — a mesma
    busca usada no campo global do topbar;
  - Campo de data/hora (`next_contact_at`);
  - Observação opcional.
- **Importante**: agendar aqui significa **definir o próximo contato de um
  lead já cadastrado** — não é um evento de calendário solto. Isso é
  deixado explícito no texto do próprio modal, mantendo a lógica do
  restante do sistema (tudo gira em torno do lead).
- Novo endpoint `POST /agenda/agendar` → `AgendaController::schedule()`:
  valida o lead (e a permissão — consultor comum só agenda os próprios
  leads, igual ao `quickContact()` já existente), atualiza
  `leads.next_contact_at` via `Lead::update()` e registra o evento em
  `lead_history` com `type = 'agendamento'` (valor já existente no ENUM da
  tabela, ver `database/sql/schema.sql`), além de `log_activity()`.
- Após salvar com sucesso, a tela recarrega (`window.location.reload()`) —
  optou-se por isso em vez de atualizar a lista via JS porque o agrupamento
  por urgência (atrasados/hoje/semana/futuro) depende de lógica de datas já
  calculada no PHP (`AgendaController::index()`); replicar essa lógica em
  JS só para evitar um reload simples não valia o custo/risco.
- Rota registrada em `public/index.php`: `POST agenda/agendar`.

### 2. Mapa de Indicadores migrado para Leaflet.js (mapa real + choropleth + calor)

O cartograma SVG estilizado antigo (`public/assets/img/brazil-map.svg`, um
grid de retângulos, não um mapa geográfico real) foi substituído por um
mapa real do Brasil usando **Leaflet.js**, a pedido do cliente.

- **GeoJSON dos estados**: baixado com sucesso durante esta sessão (havia
  acesso à internet no ambiente) de
  `https://raw.githubusercontent.com/codeforgermany/click_that_hood/main/public/data/brazil-states.geojson`
  e salvo em `public/assets/data/brazil-states.geojson`. O arquivo original
  tinha ~3,4 MB; foi processado localmente (script descartável, não
  versionado) para manter só as propriedades usadas (`sigla`, `name`) e
  arredondar as coordenadas para 4 casas decimais (~11 m de precisão, mais
  que suficiente para um mapa em nível de estado) — o resultado final tem
  **~1,6 MB**. Testado localmente via `php -S` + `curl`: serve em poucos
  milissegundos como arquivo estático (sem passar por PHP dinâmico, então
  **não** é afetado pelo bug de compressão zstd da Hostinger mencionado
  acima, que é específico de respostas PHP dinâmicas grandes). Se ainda
  assim parecer pesado em produção, o próximo passo natural seria servir
  o arquivo com `Content-Encoding: gzip` via `.htaccess` (Apache já faz
  isso por padrão para `.json` na maioria das configs Hostinger) — não foi
  necessário criar um endpoint PHP intermediário para isso.
  - Caso o arquivo precise ser atualizado/re-baixado no futuro: qualquer
    GeoJSON simplificado dos 27 estados do Brasil com uma propriedade
    `sigla` (UF) em cada feature funciona, por exemplo a mesma fonte acima
    ou `https://github.com/giuliano-macedo/geodata-br-states`.
- **CDNs do Leaflet só carregam na tela de Indicadores**, não no layout
  global: `app/views/layouts/main.php` ganhou um mecanismo genérico
  `$pageStyles` (variável de dados passada pelo Controller para a `view()`,
  disponível no `<head>` porque `Controller::view()` faz `extract($data)`
  *antes* de incluir o layout) — `IndicatorController::index()` usa isso
  para injetar o `<link>` do `leaflet.css` só nessa página. O JS do Leaflet
  e do plugin `Leaflet.heat` continuam entrando pelo `$pageScripts` já
  existente (final do `<body>`), também só nesta view.
- `app/views/indicators/index.php`:
  - `#tcLeafletMap` substitui o antigo `#tcBrazilMapWrap` (SVG). Mapa
    centralizado no Brasil (`L.map(...).setView([-14.2, -51.9], 4)`), tile
    layer OpenStreetMap padrão (gratuita).
  - Camada **choropleth**: `L.geoJSON(geojson, { style, onEachFeature })`
    colore cada UF pela mesma escala de 3 níveis já usada antes (poucos/
    médio/muitos, baseada em `$byState` vindo do `IndicatorController`).
  - **Tooltip nativo do Leaflet** (`layer.bindTooltip(...)`) mostrando UF +
    total de leads + taxa de conversão — substitui o tooltip manual em
    JS/CSS que existia antes (`#tcMapTooltip`, removido).
  - **Camada de calor opcional** (`Leaflet.heat`, CDN
    `cdn.jsdelivr.net/npm/leaflet.heat@0.2.0`): como não há lat/lng exata
    por lead, usa o **centroide aproximado de cada UF** como ponto
    (`$stateCentroids` no PHP, tabela de 27 coordenadas), com peso = total
    de leads do estado. Complementa o choropleth, não substitui.
  - **Fallback gracioso**: se o `fetch()` do GeoJSON falhar (arquivo
    ausente, erro de rede) ou a biblioteca Leaflet não carregar (CDN fora
    do ar), o mapa é escondido e uma mensagem amigável "Mapa indisponível"
    (`#tcMapUnavailable`) é exibida no lugar — a página nunca quebra.
  - A tabela "Ranking de estados" ao lado foi mantida sem alterações.
- `IndicatorController::index()` passa `geoJsonUrl` (via `asset()`) e o
  `$byState` já calculado (mesma query de antes) para a view, que serializa
  com `json_encode(..., JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT |
  JSON_HEX_AMP | JSON_UNESCAPED_UNICODE)`, igual ao padrão já usado.
- O SVG antigo (`public/assets/img/brazil-map.svg`) foi deixado no
  repositório (não removido), mas não é mais referenciado por nenhuma view.

### 3. Bibliotecas de interação/notificação mais modernas

- **Auditoria de `alert()`/`confirm()`/`prompt()` nativos**: busca completa
  no projeto (`grep -rn` por esses três) não encontrou nenhuma ocorrência —
  o projeto já usava SweetAlert2 de forma consistente em todas as
  confirmações (Leads, Pipeline, Tarefas, Chat, exclusões via
  `.tc-delete-form`, etc). Nada precisou ser substituído aqui.
- **Toastify.js** (`cdn.jsdelivr.net/npm/toastify-js`) foi adicionado como
  CDN global em `app/views/layouts/main.php` (arquivo leve, poucos KB) para
  feedback rápido e não-bloqueante, complementando o SweetAlert2 (que
  continua sendo o padrão para confirmações/formulários — não foi mexido).
  Nova função utilitária `tcToast(message, type)` em `public/assets/js/app.js`.
  - Trocado por Toastify nos dois pontos de `initTaskQuickStatus()` que já
    usavam um SweetAlert2 "timer-only" só como aviso rápido ("Tarefa
    concluída!", "Status atualizado!") — mais leve e não bloqueia a tela
    enquanto a página recarrega logo em seguida.
  - Novo aviso "Você tem novas mensagens no Chat." via `tcToast()`, disparado
    em `initChatSidebarBadge()` quando o contador de não lidas aumenta
    (comparando com a leitura anterior do polling) e o usuário não está na
    tela de Chat no momento.
- **Chat — animação de entrada de mensagens**: `public/assets/js/app.js`
  (`renderBubble()`/`appendEphemeral()`) agora adiciona a classe
  `tc-chat-msg-new` só nas mensagens inseridas via JS (envio/polling) — a
  animação CSS (`fade-in` + leve `slide-up`, `@keyframes tcChatMsgIn` em
  `public/assets/css/app.css`) roda apenas para essas, não para o histórico
  já carregado no primeiro load da página (evita "piscar" tudo de uma vez).
- **Chat — destaque de mensagem não lida**: puramente CSS, sem nova
  chamada ao servidor — `.tc-chat-room-item:has(.tc-chat-room-badge)` (na
  lista lateral de salas) e `#tcChatSidebarLink.tc-unread-pulse` (no item
  "Chat" da sidebar principal, ativado via JS em `initChatSidebarBadge()`
  quando o total de não lidas sobe) ganham uma pulsação sutil de fundo
  (`@keyframes tcChatRoomPulse`). Navegadores sem suporte a `:has()`
  simplesmente não exibem o pulso da lista lateral — degradação graciosa,
  nada quebra.

### Arquivos desta fase (Agenda/Indicadores/Interação)

- `app/controllers/AgendaController.php` (método novo `schedule()`)
- `app/views/agenda/index.php` (botão + modal "Novo Agendamento")
- `public/index.php` (rota nova `POST agenda/agendar`)
- `app/controllers/IndicatorController.php` (`$pageStyles`, `geoJsonUrl`
  passados para a view)
- `app/views/indicators/index.php` (mapa Leaflet.js + choropleth + calor +
  tooltip nativo, substituindo o SVG antigo)
- `app/views/layouts/main.php` (mecanismo `$pageStyles`; CDN do
  Toastify.js)
- `public/assets/data/brazil-states.geojson` (novo — GeoJSON simplificado
  dos 27 estados, ~1,6 MB)
- `public/assets/js/app.js` (função `tcToast()`; animação de mensagens do
  Chat; pulso de não lida; toasts em `initTaskQuickStatus()`)
- `public/assets/css/app.css` (estilos do modal de agendamento, do mapa
  Leaflet, das animações do Chat)
# Workspace colaborativo e IA

Execute `database/sql/migration_workspace.sql` após as migrations existentes, depois `migration_workspace_v2.sql`, `migration_workspace_v3.sql`, `migration_automation_v2.sql`, `migration_workspace_v4.sql` e `migration_workspace_v5.sql`. Os módulos incluem Calendário, Documentos/Wiki, Whiteboards, Automações e preferências individuais do dashboard.

No servidor, configure `GEMINI_API_KEY` como variável de ambiente. Nunca grave a chave em arquivos versionados. Para executar os fluxos agendados, configure `AUTOMATION_CRON_TOKEN` e crie um cron da hospedagem para acessar `public/cron-automations.php?token=SEU_TOKEN` a cada hora.

As notificações do navegador usam a permissão nativa e o service worker. O sino solicita permissão após o primeiro clique. Notificações com o navegador totalmente fechado requerem um provedor Web Push/VAPID na hospedagem; as notificações internas continuam funcionando sem essa configuração.

# Formulários integráveis e API v1

Execute as migrations na ordem `migration_forms.sql`, `migration_forms_v2.sql`, `migration_forms_v3.sql` e `migration_forms_v4.sql`. A v4 adiciona campos personalizados, fonte configurável, embed, API por formulário, webhook de saída e auditoria das submissões.

No editor de cada formulário, na seção **Integrações, API e embed**:

- gere uma chave individual; ela só é exibida no instante da geração e fica salva como hash;
- copie o `iframe` para incorporar o formulário em qualquer landing page, sem expor segredo;
- cadastre os domínios de front-end permitidos em CORS apenas se a chamada for feita pelo navegador;
- opcionalmente informe um webhook HTTPS. O CRM envia `form.submission.created` assinado em `X-Form-Signature: sha256=...` depois de cada captura.

Para integrações servidor a servidor, use `POST /api/v1/forms/{slug}/leads` com `Authorization: Bearer SUA_CHAVE` (ou `X-Form-API-Key`). Aceita JSON ou form-urlencoded. `GET /api/v1/forms/{slug}/schema` devolve o contrato de campos configurado no formulário e também exige a chave. Campos de rastreamento aceitos: `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `campaign` e `external_id`.

```bash
curl -X POST https://seudominio.com.br/api/v1/forms/captacao-site/leads \
  -H "Authorization: Bearer SUA_CHAVE" \
  -H "Content-Type: application/json" \
  -d '{"name":"Ana","whatsapp":"5511999999999","utm_source":"parceiro"}'
```

Uma resposta de criação usa HTTP `201`; quando o contato já existe, retorna HTTP `200` com `duplicate: true`. A API registra toda entrada e o resultado do webhook no monitor do próprio formulário.
