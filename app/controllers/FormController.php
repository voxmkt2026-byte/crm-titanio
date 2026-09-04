<?php
/**
 * app/controllers/FormController.php
 * Construtor de Formulários: telas administrativas (CRUD, restrito a quem
 * tem "forms.manage") + a página pública que qualquer visitante acessa para
 * preencher (GET/POST /f/{slug}, sem login).
 *
 * A submissão pública usa o MESMO princípio de criação/deduplicação de leads
 * do webhook de captação (ver WebhookController::createOrUpdateLead) — um
 * formulário é, na prática, mais uma origem automática de leads.
 *
 * QR Code por vendedor (ver app/views/profile/index.php): reaproveita esta
 * mesma URL pública com ?consultor={id} na querystring, que sobrepõe o
 * responsável padrão do formulário só para aquela submissão.
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/core/Database.php';
require_once APP_PATH . '/models/Form.php';
require_once APP_PATH . '/models/Lead.php';
require_once APP_PATH . '/models/LeadHistory.php';
require_once APP_PATH . '/models/LeadScore.php';
require_once APP_PATH . '/models/User.php';
require_once APP_PATH . '/models/Notification.php';
require_once APP_PATH . '/models/Setting.php';

class FormController extends Controller
{
    private Form $model;

    public function __construct()
    {
        $this->model = new Form();
    }

    private function requireAccess(): void
    {
        $this->requireLogin();
        if (!Auth::can('forms.manage')) {
            flash('error', 'Você não tem permissão para gerenciar formulários.');
            $this->redirect('dashboard');
            exit;
        }
    }

    /** GET /formularios */
    public function index(): void
    {
        $this->requireAccess();
        $this->view('forms/index', [
            'pageTitle' => 'Formulários de Captação',
            'forms'     => $this->model->allWithStats(),
        ]);
    }

    /** GET /formularios/novo */
    public function create(): void
    {
        $this->requireAccess();
        $this->view('forms/builder', [
            'pageTitle'   => 'Novo Formulário',
            'form'        => null,
            'fields'      => [['key' => 'name', 'label' => 'Nome', 'required' => true], ['key' => 'whatsapp', 'label' => 'WhatsApp', 'required' => true]],
            'formAction'  => url('formularios/store'),
            'users'       => (new User())->allActive(),
        ]);
    }

    /** POST /formularios/store */
    public function store(): void
    {
        $this->requireAccess();
        Csrf::verifyRequest();

        $data = $this->sanitize($_POST);
        if ($data === null) {
            flash('error', 'Informe um nome e ao menos um campo para o formulário.');
            $this->redirect('formularios/novo');
            return;
        }

        $data['logo_url'] = $this->handleImageUpload('logo', null);
        $data['cover_image_url'] = $this->handleImageUpload('cover_image', null);
        $data['slug'] = $this->model->generateSlug($data['name']);
        $data['created_by'] = Auth::id();
        $formId = $this->model->create($data);

        log_activity('formulario_criado', 'Formulário "' . $data['name'] . '" (#' . $formId . ') criado.');
        flash('success', 'Formulário criado com sucesso. Link: ' . url('f/' . $data['slug']));
        $this->redirect('formularios');
    }

    /** GET /formularios/{id}/editar */
    public function edit(string $id): void
    {
        $this->requireAccess();
        $form = $this->model->find((int) $id);
        if (!$form) {
            flash('error', 'Formulário não encontrado.');
            $this->redirect('formularios');
            return;
        }

        $this->view('forms/builder', [
            'pageTitle'  => 'Editar Formulário',
            'form'       => $form,
            'fields'     => $this->model->decodeFields($form),
            'formAction' => url('formularios/' . $form['id'] . '/update'),
            'users'      => (new User())->allActive(),
            'submissionEvents' => $this->model->recentSubmissionEvents((int) $form['id']),
        ]);
    }

    /** POST /formularios/{id}/update */
    public function update(string $id): void
    {
        $this->requireAccess();
        Csrf::verifyRequest();

        $formId = (int) $id;
        $existing = $this->model->find($formId);
        if (!$existing) {
            flash('error', 'Formulário não encontrado.');
            $this->redirect('formularios');
            return;
        }

        $data = $this->sanitize($_POST);
        if ($data === null) {
            flash('error', 'Informe um nome e ao menos um campo para o formulário.');
            $this->redirect('formularios/' . $formId . '/editar');
            return;
        }

        $postedSlug = trim((string) $this->input('slug', ''));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($postedSlug));
        $slug = trim((string) $slug, '-');
        if ($slug === '' || $this->model->slugExists($slug, $formId)) {
            $slug = $existing['slug'];
        }
        $data['slug'] = $slug;

        $data['logo_url'] = $this->input('remove_logo') === '1' ? null : $this->handleImageUpload('logo', $existing['logo_url'] ?? null);
        $data['cover_image_url'] = $this->input('remove_cover_image') === '1' ? null : $this->handleImageUpload('cover_image', $existing['cover_image_url'] ?? null);
        if (trim((string) $this->input('webhook_secret', '')) === '' && $this->input('clear_webhook_secret') !== '1') {
            $data['webhook_secret'] = $existing['webhook_secret'] ?? null;
        }

        $this->model->update($formId, $data);
        log_activity('formulario_atualizado', 'Formulário "' . $data['name'] . '" (#' . $formId . ') atualizado.');

        flash('success', 'Formulário atualizado com sucesso.');
        $this->redirect('formularios');
    }

    /** POST /formularios/{id}/excluir */
    public function destroy(string $id): void
    {
        $this->requireAccess();
        Csrf::verifyRequest();

        $formId = (int) $id;
        $form = $this->model->find($formId);
        if ($form) {
            $this->model->delete($formId);
            log_activity('formulario_excluido', 'Formulário "' . $form['name'] . '" (#' . $formId . ') excluído.');
        }

        flash('success', 'Formulário excluído com sucesso.');
        $this->redirect('formularios');
    }

    /** POST /formularios/{id}/status — ativa/desativa sem excluir. */
    public function toggleActive(string $id): void
    {
        $this->requireAccess();
        Csrf::verifyRequest();
        $this->model->toggleActive((int) $id);
        $this->redirect('formularios');
    }

    /** Gera uma nova chave da API e devolve o valor uma única vez ao painel. */
    public function rotateApiKey(string $id): void
    {
        $this->requireAccess();
        Csrf::verifyRequest();
        $form = $this->model->find((int) $id);
        if (!$form) {
            $this->json(['success' => false, 'message' => 'Formulário não encontrado.'], 404);
        }

        $key = 'tcfrm_' . bin2hex(random_bytes(24));
        try {
            $this->model->rotateApiKey((int) $form['id'], $key);
            log_activity('formulario_api_key_rotacionada', 'Chave de API rotacionada no formulário "' . $form['name'] . '" (#' . $form['id'] . ').');
            $this->json(['success' => true, 'api_key' => $key, 'last4' => substr($key, -4)]);
        } catch (Throwable $e) {
            error_log('FormController::rotateApiKey - ' . $e->getMessage());
            $this->json(['success' => false, 'message' => 'Execute migration_forms_v4.sql para habilitar a API.'], 422);
        }
    }

    /** OPTIONS /api/v1/forms/{slug}/leads — preflight CORS para landing pages externas. */
    public function apiOptions(string $slug): void
    {
        $form = $this->model->findBySlug($slug);
        if (!$form || (int) $form['active'] !== 1 || !$this->allowCors($form)) {
            http_response_code(403);
            exit;
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Form-API-Key, X-Request-Id');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
        exit;
    }

    /** GET /api/v1/forms/{slug}/schema — contrato do formulário para integrações. */
    public function apiSchema(string $slug): void
    {
        $form = $this->activeApiForm($slug);
        if (!$form) {
            return;
        }
        if (!$this->allowCors($form)) {
            $this->json(['success' => false, 'message' => 'Origem não autorizada para esta API.'], 403);
        }
        if (!$this->authorizeApi($form)) {
            $this->json(['success' => false, 'message' => 'Chave de API inválida ou ausente.'], 401);
        }

        $fields = $this->model->decodeFields($form);
        $this->json([
            'success' => true,
            'data' => [
                'version' => 'v1',
                'form' => ['slug' => $form['slug'], 'name' => $form['name']],
                'submit_url' => url('api/v1/forms/' . $form['slug'] . '/leads'),
                'content_types' => ['application/json', 'application/x-www-form-urlencoded'],
                'fields' => $fields,
                'tracking_fields' => ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'campaign', 'external_id'],
            ],
        ]);
    }

    /** POST /api/v1/forms/{slug}/leads — entrada externa autenticada. */
    public function apiSubmit(string $slug): void
    {
        $form = $this->activeApiForm($slug);
        if (!$form) {
            return;
        }
        if (!$this->allowCors($form)) {
            $this->json(['success' => false, 'message' => 'Origem não autorizada para esta API.'], 403);
        }
        if (!$this->authorizeApi($form)) {
            $this->json(['success' => false, 'message' => 'Chave de API inválida ou ausente.'], 401);
        }

        $payload = $this->readApiPayload();
        $payload = is_array($payload['data'] ?? null) ? array_merge($payload, $payload['data']) : $payload;
        $validation = $this->extractSubmissionValues($this->model->decodeFields($form), $payload);
        if ($validation['error']) {
            $this->model->recordSubmissionEvent($this->submissionEvent($form, null, 'api', 'invalid', $payload));
            $this->json(['success' => false, 'message' => $validation['error']], 422);
        }

        try {
            $result = $this->createLeadFromSubmission($form, $validation['values'], [
                'channel' => 'api', 'source' => 'api', 'tracking' => $this->trackingFrom($payload),
                'external_reference' => $this->scalar($payload['external_id'] ?? $payload['id'] ?? ''),
                'payload' => $payload,
            ]);
            if ($result === null) {
                $this->model->recordSubmissionEvent($this->submissionEvent($form, null, 'api', 'error', $payload));
                $this->json(['success' => false, 'message' => 'Informe ao menos um dado de contato válido.'], 422);
            }
            $this->model->incrementSubmissions((int) $form['id']);
            $eventId = $this->model->recordSubmissionEvent($this->submissionEvent($form, $result['lead_id'], 'api', $result['duplicate'] ? 'duplicate' : 'created', $payload, $result['external_reference'] ?? null));
            $this->deliverWebhook($form, $result, 'api', $eventId);
            log_activity('lead_formulario_api_recebido', 'Lead #' . $result['lead_id'] . ' recebido pela API do formulário "' . $form['name'] . '".');
            $this->json([
                'success' => true,
                'data' => ['lead_id' => $result['lead_id'], 'lead_code' => $result['lead_code'] ?? null, 'duplicate' => $result['duplicate'], 'form' => $form['slug']],
            ], $result['duplicate'] ? 200 : 201);
        } catch (Throwable $e) {
            error_log('FormController::apiSubmit - ' . $e->getMessage());
            $this->model->recordSubmissionEvent($this->submissionEvent($form, null, 'api', 'error', $payload));
            $this->json(['success' => false, 'message' => 'Não foi possível registrar o lead neste momento.'], 500);
        }
    }

    // ------------------------------------------------------------------
    // Página pública (sem login) — GET/POST /f/{slug}
    // ------------------------------------------------------------------

    /** GET /f/{slug} */
    public function show(string $slug): void
    {
        $form = $this->model->findBySlug($slug);
        if (!$form || (int) $form['active'] !== 1) {
            http_response_code(404);
            $this->view('forms/indisponivel', ['pageTitle' => 'Formulário indisponível'], null);
            return;
        }

        $consultantId = (int) $this->input('consultor', 0);
        $consultant = null;
        if ($consultantId > 0) {
            $u = (new User())->find($consultantId);
            if ($u && (int) $u['active'] === 1) {
                $consultant = $u;
            }
        }

        $settings = (new Setting())->allAsMap();
        $fields = $this->model->decodeFields($form);
        $secret = $this->model->publicSecret($form);

        $this->view('forms/public', [
            'pageTitle'  => $form['name'],
            'form'       => $form,
            'fields'     => $fields,
            'steps'      => $this->model->groupIntoSteps($fields),
            'consultant' => $consultant,
            'company'    => $settings['company_name'] ?? COMPANY_NAME,
            'logo'       => $settings['company_logo'] ?? null,
            'error'      => null,
            'old'        => [],
            'tracking'   => $this->trackingFrom($_GET),
            'publicSignature' => $secret ? $this->publicSubmissionSignature($form, $secret) : null,
        ], null);
    }

    /** POST /f/{slug} — cria o lead a partir da submissão pública. */
    public function submit(string $slug): void
    {
        $form = $this->model->findBySlug($slug);
        if (!$form || (int) $form['active'] !== 1) {
            http_response_code(404);
            $this->view('forms/indisponivel', ['pageTitle' => 'Formulário indisponível'], null);
            return;
        }

        $secret = $this->model->publicSecret($form);
        if (!$this->verifyPublicSubmission($form, $secret)) {
            http_response_code(403);
            $this->view('forms/indisponivel', ['pageTitle' => 'Envio não autorizado'], null);
            return;
        }
        if (trim((string) $this->input('website', '')) !== '') {
            // Honeypot: responde genericamente, sem criar o lead e sem orientar robôs.
            $this->view('forms/obrigado', [
                'pageTitle' => 'Recebido!', 'message' => $form['success_message'] ?: 'Recebemos seu contato!',
                'company' => (new Setting())->get('company_name', COMPANY_NAME),
            ], null);
            return;
        }

        $fields = $this->model->decodeFields($form);
        $validation = $this->extractSubmissionValues($fields, $_POST);
        if ($validation['error']) {
            $settings = (new Setting())->allAsMap();
            $consultantId = (int) $this->input('consultor', 0);
            $consultant = $consultantId > 0 ? (new User())->find($consultantId) : null;
            $this->view('forms/public', [
                'pageTitle'  => $form['name'], 'form' => $form, 'fields' => $fields,
                'steps' => $this->model->groupIntoSteps($fields),
                'consultant' => ($consultant && (int) $consultant['active'] === 1) ? $consultant : null,
                'company' => $settings['company_name'] ?? COMPANY_NAME, 'logo' => $settings['company_logo'] ?? null,
                'error' => $validation['error'], 'old' => $_POST, 'tracking' => $this->trackingFrom($_POST),
                'publicSignature' => $secret ? $this->publicSubmissionSignature($form, $secret) : null,
            ], null);
            return;
        }

        $result = $this->createLeadFromSubmission($form, $validation['values'], [
            'channel' => 'public', 'source' => $form['default_source'] ?? 'landing_page',
            'tracking' => $this->trackingFrom($_POST), 'payload' => $_POST,
        ]);

        if ($result === null) {
            flash('error', 'Não foi possível registrar seu contato. Tente novamente em instantes.');
            $this->redirect('f/' . $slug);
            return;
        }

        $this->model->incrementSubmissions((int) $form['id']);
        $eventId = $this->model->recordSubmissionEvent($this->submissionEvent($form, $result['lead_id'], 'public', $result['duplicate'] ? 'duplicate' : 'created', $_POST));
        $this->deliverWebhook($form, $result, 'public', $eventId);
        log_activity('lead_formulario_recebido', 'Lead #' . $result['lead_id'] . ' recebido via formulário "' . $form['name'] . '".');

        $redirectUrl = $this->safeRedirectUrl((string) ($form['redirect_url'] ?? ''));
        if ($redirectUrl) {
            $separator = str_contains($redirectUrl, '?') ? '&' : '?';
            header('Location: ' . $redirectUrl . $separator . 'form=' . rawurlencode($form['slug']));
            exit;
        }

        $this->view('forms/obrigado', [
            'pageTitle' => 'Recebido!',
            'message'   => $form['success_message'] ?: 'Recebemos seu contato! Em breve alguém da nossa equipe vai falar com você.',
            'company'   => (new Setting())->get('company_name', COMPANY_NAME),
        ], null);
    }

    // ------------------------------------------------------------------

    /** Lê e valida o formulário do painel administrativo. Retorna null se inválido. */
    private function sanitize(array $input): ?array
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $keys = (array) ($input['field_key'] ?? []);
        $labels = (array) ($input['field_label'] ?? []);
        $requireds = (array) ($input['field_required'] ?? []);
        $newSteps = (array) ($input['field_new_step'] ?? []);
        $types = (array) ($input['field_type'] ?? []);
        $placeholders = (array) ($input['field_placeholder'] ?? []);
        $options = (array) ($input['field_options'] ?? []);
        $fields = [];
        foreach ($keys as $i => $key) {
            $key = strtolower(trim((string) $key));
            if (!Form::isAllowedFieldKey($key) || array_filter($fields, static fn($field) => $field['key'] === $key)) {
                continue;
            }
            $label = mb_substr(trim((string) ($labels[$i] ?? Form::FIELD_CATALOG[$key] ?? 'Campo')), 0, 120);
            $type = (string) ($types[$i] ?? Form::defaultFieldType($key));
            if (!in_array($type, Form::FIELD_TYPES, true)) {
                $type = Form::defaultFieldType($key);
            }
            $fieldOptions = [];
            if ($type === 'select') {
                $fieldOptions = array_values(array_filter(array_map(
                    static fn($option) => mb_substr(trim($option), 0, 120),
                    preg_split('/[\r\n,]+/', (string) ($options[$i] ?? '')) ?: []
                ), static fn($option) => $option !== ''));
            }
            $fields[] = [
                'key'      => $key,
                'label'    => $label !== '' ? $label : (Form::FIELD_CATALOG[$key] ?? 'Campo personalizado'),
                'required' => in_array($key, $requireds, true),
                'new_step' => in_array($key, $newSteps, true),
                'type'     => $type,
                'placeholder' => mb_substr(trim((string) ($placeholders[$i] ?? '')), 0, 180),
                'options' => array_slice(array_unique($fieldOptions), 0, 40),
            ];
        }
        if (empty($fields)) {
            return null;
        }

        $assignee = (int) ($input['default_assigned_to'] ?? 0);
        $theme = (string) ($input['theme'] ?? 'padrao');
        $validThemes = ['padrao', 'whatsapp', 'azul', 'roxo', 'grafite', 'rosa', 'esmeralda', 'laranja'];
        $font = (string) ($input['font_family'] ?? 'padrao');
        $validFonts = ['padrao', 'arredondada', 'serifa', 'mono'];
        $source = (string) ($input['default_source'] ?? 'landing_page');
        $redirect = $this->safeRedirectUrl((string) ($input['redirect_url'] ?? ''));
        $webhook = $this->safeWebhookUrl((string) ($input['webhook_url'] ?? ''));

        return [
            'name'                 => $name,
            'description'          => trim((string) ($input['description'] ?? '')) ?: null,
            'fields'               => $fields,
            'default_source'       => array_key_exists($source, Form::SOURCE_CATALOG) ? $source : 'landing_page',
            'default_interest'     => trim((string) ($input['default_interest'] ?? '')) ?: null,
            'default_assigned_to'  => $assignee ?: null,
            'notify_assignee'      => !empty($input['notify_assignee']),
            'success_message'      => trim((string) ($input['success_message'] ?? '')) ?: null,
            'footer_text'          => trim((string) ($input['footer_text'] ?? '')) ?: null,
            'submit_label'         => mb_substr(trim((string) ($input['submit_label'] ?? '')), 0, 80) ?: null,
            'privacy_text'         => mb_substr(trim((string) ($input['privacy_text'] ?? '')), 0, 255) ?: null,
            'redirect_url'         => $redirect,
            'allowed_origins'      => $this->sanitizeOrigins((string) ($input['allowed_origins'] ?? '')),
            'webhook_url'          => $webhook,
            'webhook_secret'       => mb_substr(trim((string) ($input['webhook_secret'] ?? '')), 0, 255) ?: null,
            'theme'                => in_array($theme, $validThemes, true) ? $theme : 'padrao',
            'font_family'          => in_array($font, $validFonts, true) ? $font : 'padrao',
            'active'               => !empty($input['active']),
        ];
    }

    /**
     * Cria o lead a partir de uma submissão pública, seguindo o mesmo padrão
     * de WebhookController::createOrUpdateLead (dedup por telefone/e-mail,
     * histórico, notificação, recálculo de score).
     */
    private function createLeadFromSubmission(array $form, array $values, array $context = []): ?array
    {
        $leadModel = new Lead();
        $historyModel = new LeadHistory();

        $phone = $this->normalizeDigits($values['phone'] ?? null);
        $whatsapp = $this->normalizeDigits($values['whatsapp'] ?? null) ?: $phone;
        $email = trim((string) ($values['email'] ?? '')) ?: null;
        $name = trim((string) ($values['name'] ?? '')) ?: null;

        if (!$name && !$phone && !$whatsapp && !$email) {
            return null;
        }

        $duplicate = $leadModel->findDuplicate($phone, $whatsapp, null, $email);
        $channel = $context['channel'] ?? 'public';
        $tracking = (array) ($context['tracking'] ?? []);

        $consultantId = $channel === 'public' ? (int) $this->input('consultor', 0) : 0;
        $consultant = $consultantId > 0 ? (new User())->find($consultantId) : null;
        $assignedTo = ($consultant && (int) $consultant['active'] === 1) ? (int) $consultant['id'] : ($form['default_assigned_to'] ?: null);

        if ($duplicate) {
            $historyModel->add(
                (int) $duplicate['id'],
                null,
                'observacao',
                'Novo preenchimento recebido pelo formulário "' . $form['name'] . '" — nenhum novo cadastro criado (já existia).'
            );
            return [
                'lead_id' => (int) $duplicate['id'], 'lead_code' => $duplicate['lead_code'] ?? null,
                'duplicate' => true, 'external_reference' => $context['external_reference'] ?? null,
            ];
        }

        $customAnswers = [];
        foreach ($values as $key => $value) {
            if (str_starts_with((string) $key, 'custom_') && $value !== '') {
                $customAnswers[$key] = $value;
            }
        }
        $notes = trim((string) ($values['notes'] ?? ''));
        if ($customAnswers) {
            $lines = [];
            foreach ($customAnswers as $key => $value) {
                $lines[] = ucfirst(str_replace('_', ' ', preg_replace('/^custom_/', '', $key))) . ': ' . $this->scalar($value);
            }
            $notes = trim($notes . "\n\nRespostas adicionais do formulário:\n" . implode("\n", $lines));
        }
        $source = (string) ($context['source'] ?? $form['default_source'] ?? 'landing_page');
        if (!array_key_exists($source, Form::SOURCE_CATALOG)) {
            $source = $channel === 'api' ? 'api' : 'landing_page';
        }

        $leadFields = [
            'name'           => $name,
            'phone'          => $phone,
            'whatsapp'       => $whatsapp,
            'email'          => $email,
            'cpf'            => $this->normalizeDigits($values['cpf'] ?? null),
            'city'           => trim((string) ($values['city'] ?? '')) ?: null,
            'state'          => !empty($values['state']) ? strtoupper(substr(trim((string) $values['state']), 0, 2)) : null,
            'interest'       => $values['interest'] ?? ($form['default_interest'] ?: null),
            'desired_value'  => isset($values['desired_value']) && $values['desired_value'] !== ''
                ? (float) str_replace(['.', ','], ['', '.'], (string) $values['desired_value']) : null,
            'profession'     => trim((string) ($values['profession'] ?? '')) ?: null,
            'company'        => trim((string) ($values['company'] ?? '')) ?: null,
            'zipcode'        => $this->normalizeDigits($values['zipcode'] ?? null),
            'income_range'   => trim((string) ($values['income_range'] ?? '')) ?: null,
            'internal_notes' => $notes ?: null,
            'source'         => $source,
            'campaign'       => $this->scalar($tracking['campaign'] ?? '') ?: $form['name'],
            'utm_source'     => $this->scalar($tracking['utm_source'] ?? ''),
            'utm_medium'     => $this->scalar($tracking['utm_medium'] ?? ''),
            'utm_campaign'   => $this->scalar($tracking['utm_campaign'] ?? ''),
            'utm_content'    => $this->scalar($tracking['utm_content'] ?? ''),
            'utm_term'       => $this->scalar($tracking['utm_term'] ?? ''),
            'assigned_to'    => $assignedTo,
            'status'         => 'novo',
            'ip_address'     => $_SERVER['REMOTE_ADDR'] ?? null,
            'device'         => $channel === 'api' ? 'form_api' : 'formulario_publico',
            'browser'        => ($channel === 'api' ? 'API de formulário: ' : 'Formulário: ') . $form['name'] . ($consultant ? ' (via link de ' . $consultant['name'] . ')' : ''),
        ];
        $leadFields = array_filter($leadFields, fn($v) => $v !== null && $v !== '');
        $leadFields['status'] = 'novo';

        $leadId = $leadModel->create($leadFields);

        $historyModel->add($leadId, null, 'criacao', 'Lead recebido pelo formulário público "' . $form['name'] . '".' . ($consultant ? ' Direcionado via link pessoal de ' . $consultant['name'] . '.' : ''));

        try {
            (new LeadScore())->recalculateForLead($leadId);
        } catch (Throwable $e) {
            error_log('FormController::createLeadFromSubmission - falha ao recalcular score: ' . $e->getMessage());
        }

        if (!empty($form['notify_assignee']) && $assignedTo) {
            try {
                (new Notification())->create((int) $assignedTo, 'Novo lead pelo formulário', ($name ?: 'Novo contato') . ' preencheu "' . $form['name'] . '".', 'leads/' . $leadId);
            } catch (Throwable $e) {
                error_log('FormController::createLeadFromSubmission - falha ao notificar: ' . $e->getMessage());
            }
        }

        $createdLead = $leadModel->find($leadId);
        return [
            'lead_id' => $leadId, 'lead_code' => $createdLead['lead_code'] ?? null,
            'duplicate' => false, 'external_reference' => $context['external_reference'] ?? null,
        ];
    }

    // ------------------------------------------------------------------
    // API, embed e integrações externas

    private function activeApiForm(string $slug): ?array
    {
        $form = $this->model->findBySlug($slug);
        if (!$form || (int) $form['active'] !== 1) {
            $this->json(['success' => false, 'message' => 'Formulário inexistente ou inativo.'], 404);
            return null;
        }
        return $form;
    }

    private function authorizeApi(array $form): bool
    {
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $key = (string) ($_SERVER['HTTP_X_FORM_API_KEY'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
            $key = trim($matches[1]);
        }
        return $this->model->verifyApiKey($form, $key);
    }

    /** Só libera CORS quando o domínio estiver explicitamente autorizado no formulário. */
    private function allowCors(array $form): bool
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        if ($origin === '') {
            return true; // integração servidor-a-servidor não envia Origin
        }
        $origins = preg_split('/[\r\n,]+/', (string) ($form['allowed_origins'] ?? '')) ?: [];
        $origins = array_values(array_filter(array_map(static fn($value) => rtrim(trim($value), '/'), $origins)));
        $allowed = in_array('*', $origins, true) || in_array(rtrim($origin, '/'), $origins, true);
        if ($allowed) {
            header('Access-Control-Allow-Origin: ' . (in_array('*', $origins, true) ? '*' : $origin));
            header('Vary: Origin');
        }
        return $allowed;
    }

    private function readApiPayload(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) file_get_contents('php://input'), true);
            return is_array($decoded) ? $decoded : [];
        }
        return $_POST;
    }

    /** Extrai apenas campos previstos no formulário; o payload integral é auditado à parte. */
    private function extractSubmissionValues(array $fields, array $payload): array
    {
        $values = [];
        foreach ($fields as $field) {
            $raw = $this->scalar($payload[$field['key']] ?? '');
            if (!empty($field['required']) && $raw === '') {
                return ['values' => [], 'error' => 'Preencha o campo "' . $field['label'] . '" antes de enviar.'];
            }
            if ($raw !== '' && $field['key'] === 'email' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                return ['values' => [], 'error' => 'Informe um e-mail válido.'];
            }
            if ($raw !== '' && ($field['type'] ?? '') === 'select' && !empty($field['options']) && !in_array($raw, $field['options'], true)) {
                return ['values' => [], 'error' => 'Escolha uma opção válida para "' . $field['label'] . '".'];
            }
            $values[$field['key']] = mb_substr($raw, 0, 4000);
        }
        return ['values' => $values, 'error' => null];
    }

    private function scalar($value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return trim((string) $value);
    }

    private function trackingFrom(array $payload): array
    {
        $tracking = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'campaign'] as $key) {
            $value = mb_substr($this->scalar($payload[$key] ?? ''), 0, 150);
            if ($value !== '') {
                $tracking[$key] = $value;
            }
        }
        return $tracking;
    }

    private function publicSubmissionSignature(array $form, string $secret): string
    {
        $timestamp = time();
        $message = (int) $form['id'] . '|' . $form['slug'] . '|' . $timestamp;
        return $timestamp . '.' . hash_hmac('sha256', $message, $secret);
    }

    private function verifyPublicSubmission(array $form, ?string $secret): bool
    {
        if (!$secret) {
            Csrf::verifyRequest(); // compatibilidade até que a migration v4 seja aplicada
            return true;
        }
        $token = (string) $this->input('form_signature', '');
        [$timestamp, $signature] = array_pad(explode('.', $token, 2), 2, '');
        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 7200 || $signature === '') {
            return false;
        }
        $message = (int) $form['id'] . '|' . $form['slug'] . '|' . $timestamp;
        return hash_equals(hash_hmac('sha256', $message, $secret), $signature);
    }

    private function sanitizeOrigins(string $origins): ?string
    {
        $valid = [];
        foreach (preg_split('/[\r\n,]+/', $origins) ?: [] as $origin) {
            $origin = rtrim(trim($origin), '/');
            if ($origin === '*') {
                $valid = ['*'];
                break;
            }
            $parts = parse_url($origin);
            if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || !empty($parts['path']) && $parts['path'] !== '/') {
                continue;
            }
            $valid[] = $origin;
        }
        return $valid ? implode("\n", array_unique($valid)) : null;
    }

    private function safeRedirectUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $parts = parse_url($url);
        return filter_var($url, FILTER_VALIDATE_URL) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) ? $url : null;
    }

    private function safeWebhookUrl(string $url): ?string
    {
        $url = $this->safeRedirectUrl($url);
        if (!$url || (parse_url($url, PHP_URL_SCHEME) !== 'https')) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === 'localhost' || str_ends_with($host, '.local') || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false && filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        return $url;
    }

    private function submissionEvent(array $form, ?int $leadId, string $channel, string $status, array $payload, ?string $reference = null): array
    {
        unset($payload['csrf_token'], $payload['form_signature'], $payload['website'], $payload['consultor']);
        return [
            'form_id' => (int) $form['id'], 'lead_id' => $leadId, 'channel' => $channel, 'status' => $status,
            'external_reference' => $reference, 'payload' => $payload,
            'origin' => $_SERVER['HTTP_ORIGIN'] ?? null, 'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            'webhook_status' => !empty($form['webhook_url']) ? 'pending' : 'not_configured',
        ];
    }

    /** Envia um evento assinado para outro sistema, sem impactar a captura se ele falhar. */
    private function deliverWebhook(array $form, array $result, string $channel, ?int $eventId): void
    {
        $url = $this->safeWebhookUrl((string) ($form['webhook_url'] ?? ''));
        if (!$url) {
            return;
        }
        try {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('cURL indisponível no servidor');
            }
            $lead = (new Lead())->find((int) $result['lead_id']) ?: [];
            $body = json_encode([
                'event' => 'form.submission.created', 'occurred_at' => date(DATE_ATOM),
                'channel' => $channel, 'duplicate' => (bool) $result['duplicate'],
                'form' => ['id' => (int) $form['id'], 'slug' => $form['slug'], 'name' => $form['name']],
                'lead' => ['id' => (int) $result['lead_id'], 'lead_code' => $result['lead_code'] ?? null, 'name' => $lead['name'] ?? null, 'email' => $lead['email'] ?? null, 'phone' => $lead['phone'] ?? null, 'whatsapp' => $lead['whatsapp'] ?? null, 'status' => $lead['status'] ?? null],
            ], JSON_UNESCAPED_UNICODE);
            $signature = hash_hmac('sha256', (string) $body, (string) ($form['webhook_secret'] ?? ''));
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Form-Event: form.submission.created', 'X-Form-Signature: sha256=' . $signature], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
            curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            $this->model->updateWebhookDelivery($eventId, $code >= 200 && $code < 300 ? 'sent' : 'failed', $code ?: null);
        } catch (Throwable $e) {
            error_log('FormController::deliverWebhook - ' . $e->getMessage());
            $this->model->updateWebhookDelivery($eventId, 'failed');
        }
    }

    private function normalizeDigits($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $digits = preg_replace('/\D/', '', (string) $value);
        return $digits !== '' ? $digits : null;
    }

    /**
     * Upload simples de logo/imagem de capa do formulário (mesmo padrão de
     * ReportController::handleLogoUpload — JPG/PNG/WEBP até 2MB, sem libs
     * externas). Retorna a URL nova, ou $current se nenhum arquivo novo foi
     * enviado (preserva a imagem já cadastrada ao editar).
     */
    private function handleImageUpload(string $fieldName, ?string $current): ?string
    {
        if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
            return $current;
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $mime = mime_content_type($_FILES[$fieldName]['tmp_name']);

        if (!isset($allowed[$mime]) || $_FILES[$fieldName]['size'] > 2 * 1024 * 1024) {
            flash('error', 'Imagem inválida (use JPG/PNG/WEBP até 2MB). As demais alterações foram salvas.');
            return $current;
        }

        if (!is_dir(UPLOADS_PATH)) {
            @mkdir(UPLOADS_PATH, 0755, true);
        }

        $filename = 'formulario_' . $fieldName . '_' . time() . '_' . random_int(1000, 9999) . '.' . $allowed[$mime];
        $destination = UPLOADS_PATH . '/' . $filename;

        if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $destination)) {
            return $current;
        }

        return UPLOADS_URL . '/' . $filename;
    }
}
