<?php
/**
 * app/controllers/SettingController.php
 * Configurações gerais do sistema (tabela settings): nome da empresa, logo,
 * favicon (upload simples para public/uploads/), SMTP e placeholders de
 * integrações (Meta/Google/Webhook) — apenas salva os campos, não envia
 * e-mails nem chama APIs externas de fato.
 * Restrito ao papel "admin".
 */

require_once APP_PATH . '/core/Controller.php';
require_once APP_PATH . '/models/Setting.php';
require_once APP_PATH . '/models/EvolutionConnection.php';
require_once APP_PATH . '/models/EvolutionServiceFlow.php';

class SettingController extends Controller
{
    private Setting $model;

    /** Chaves de texto simples aceitas do formulário */
    private array $textFields = [
        'company_name', 'smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from_name',
        'smtp_from_email', 'smtp_encryption',
        'integration_meta_token', 'integration_google_token', 'integration_webhook_url',
        'whatsapp_token', 'whatsapp_phone_id', 'automation_whatsapp_template', 'automation_whatsapp_language', 'webhook_token',
        'gemini_api_key', 'gemini_model',
        'evolution_api_url', 'evolution_api_token', 'evolution_instance_name', 'evolution_webhook_token',
    ];

    public function __construct()
    {
        $this->model = new Setting();
    }

    private function requireAdmin(): void
    {
        $this->requireLogin();
        if (!Auth::hasRole(['admin']) || !Auth::can('settings.manage')) {
            flash('error', 'Acesso restrito a administradores.');
            $this->redirect('dashboard');
        }
    }

    public function index(): void
    {
        $this->requireAdmin();

        $this->view('settings/index', [
            'pageTitle' => 'Configurações',
            'settings'  => $this->model->allAsMap(),
            'evolutionConnections' => (new EvolutionConnection())->allConnections(),
            'evolutionFlows'       => (new EvolutionServiceFlow())->allFlows(),
        ]);
    }

    /** Salva uma linha WhatsApp (instância) adicional da mesma Evolution API. */
    public function saveEvolutionConnection(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) $this->input('instance_name', ''));
        $label = trim((string) $this->input('label', ''));
        if (!preg_match('/^[A-Za-z0-9_-]{2,120}$/', $name)) {
            flash('error', 'Informe um nome de instância válido (letras, números, hífen ou sublinhado).');
            $this->redirect('configuracoes');
            return;
        }

        try {
            (new EvolutionConnection())->saveConnection([
                'id'            => (int) $this->input('id', 0),
                'instance_name' => $name,
                'label'         => $label ?: $name,
                'payload_mode'  => (string) $this->input('payload_mode', 'auto'),
                'active'        => $this->input('active', '1') === '1',
                'is_default'    => $this->input('is_default', '') === '1',
            ]);
            log_activity('evolution_instancia_salva', 'Linha WhatsApp "' . ($label ?: $name) . '" salva.');
            flash('success', 'Linha WhatsApp salva com sucesso.');
        } catch (Throwable $e) {
            flash('error', 'Não foi possível salvar a linha WhatsApp. Rode a migration evolution_inbox_v3 e tente novamente.');
        }
        $this->redirect('configuracoes');
    }

    /** Desativa sem apagar uma linha que pode ter histórico associado. */
    public function deactivateEvolutionConnection(string $id): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();
        try {
            (new EvolutionConnection())->deactivate((int) $id);
            flash('success', 'Linha WhatsApp desativada. O histórico foi preservado.');
        } catch (Throwable $e) {
            flash('error', 'Não foi possível desativar a linha WhatsApp.');
        }
        $this->redirect('configuracoes');
    }

    /** Salva um fluxo guiado (etapas + textos sugeridos) para o atendimento. */
    public function saveEvolutionFlow(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $name = trim((string) $this->input('name', ''));
        if ($name === '') {
            flash('error', 'Informe um nome para o fluxo de atendimento.');
            $this->redirect('configuracoes');
            return;
        }
        $steps = [];
        $rawSteps = $this->input('steps', []);
        // Cada etapa informa o canal e a orientação. Fluxos antigos que
        // ainda enviem texto simples continuam compatíveis com o formato
        // "Etapa | texto sugerido" usado nas versões anteriores.
        if (is_array($rawSteps)) {
            foreach (array_slice($rawSteps, 0, 12) as $rawStep) {
                if (!is_array($rawStep)) {
                    continue;
                }
                $title = mb_substr(trim((string) ($rawStep['title'] ?? '')), 0, 120);
                $suggestion = mb_substr(trim((string) ($rawStep['suggestion'] ?? '')), 0, 4000);
                if ($title === '' && $suggestion === '') {
                    continue;
                }
                $channel = (string) ($rawStep['channel'] ?? 'whatsapp');
                $steps[] = [
                    'title' => $title ?: 'Etapa ' . (count($steps) + 1),
                    'channel' => in_array($channel, ['whatsapp', 'email'], true) ? $channel : 'whatsapp',
                    'suggestion' => $suggestion,
                    'email_subject' => mb_substr(trim((string) ($rawStep['email_subject'] ?? '')), 0, 255),
                    'guidance' => mb_substr(trim((string) ($rawStep['guidance'] ?? '')), 0, 800),
                ];
            }
        } else {
            foreach (preg_split('/\r\n|\r|\n/', (string) $rawSteps) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                [$title, $suggestion] = array_pad(explode('|', $line, 2), 2, '');
                $steps[] = ['title' => mb_substr(trim($title), 0, 120), 'channel' => 'whatsapp', 'suggestion' => mb_substr(trim($suggestion), 0, 4000), 'email_subject' => '', 'guidance' => ''];
            }
        }
        if (empty($steps)) {
            flash('error', 'Adicione ao menos uma etapa ao fluxo.');
            $this->redirect('configuracoes');
            return;
        }

        try {
            (new EvolutionServiceFlow())->saveFlow([
                'id'            => (int) $this->input('id', 0),
                'name'          => mb_substr($name, 0, 120),
                'description'   => mb_substr(trim((string) $this->input('description', '')), 0, 2000),
                'instance_name' => trim((string) $this->input('instance_name', '')),
                'steps'         => $steps,
                'active'        => $this->input('active', '1') === '1',
            ]);
            log_activity('evolution_fluxo_salvo', 'Fluxo de atendimento "' . $name . '" salvo.');
            flash('success', 'Fluxo de atendimento salvo com sucesso.');
        } catch (Throwable $e) {
            flash('error', 'Não foi possível salvar o fluxo. Rode a migration evolution_inbox_v3 e tente novamente.');
        }
        $this->redirect('configuracoes');
    }

    public function update(): void
    {
        $this->requireAdmin();
        Csrf::verifyRequest();

        $data = [];
        foreach ($this->textFields as $field) {
            $value = trim((string) $this->input($field, ''));
            // Campos secretos vazios preservam o valor já salvo.
            if (in_array($field, ['gemini_api_key','smtp_pass','whatsapp_token','evolution_api_token'], true) && $value === '') {
                continue;
            }
            $data[$field] = $value;
        }

        $logo = $this->handleImageUpload('company_logo', 'logo');
        if ($logo !== null) {
            $data['company_logo'] = $logo;
        }

        $favicon = $this->handleImageUpload('company_favicon', 'favicon');
        if ($favicon !== null) {
            $data['company_favicon'] = $favicon;
        }

        $this->model->setMany($data);
        log_activity('configuracoes_atualizadas', 'Configurações gerais do sistema atualizadas.');

        flash('success', 'Configurações salvas com sucesso.');
        $this->redirect('configuracoes');
    }

    /** Upload de logo/favicon; a URL também possui fallback pelo front controller. */
    private function handleImageUpload(string $fileInputName, string $prefix): ?string
    {
        if (empty($_FILES[$fileInputName]) || $_FILES[$fileInputName]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($_FILES[$fileInputName]['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES[$fileInputName]['tmp_name'])) {
            flash('error', 'Não foi possível receber o arquivo de ' . $prefix . '. Tente novamente.');
            return null;
        }

        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'];
        $mime = mime_content_type($_FILES[$fileInputName]['tmp_name']);

        if (!isset($allowed[$mime]) || $_FILES[$fileInputName]['size'] > 1 * 1024 * 1024) {
            flash('error', 'Arquivo de ' . $prefix . ' inválido (use JPG/PNG/WEBP/ICO até 1MB). As demais configurações foram salvas.');
            return null;
        }

        if (!is_dir(UPLOADS_PATH)) {
            @mkdir(UPLOADS_PATH, 0755, true);
        }
        if (!is_dir(UPLOADS_PATH) || !is_writable(UPLOADS_PATH)) {
            flash('error', 'A pasta de uploads não tem permissão de escrita. Verifique public/uploads na hospedagem.');
            return null;
        }

        $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
        $destination = UPLOADS_PATH . '/' . $filename;

        if (!move_uploaded_file($_FILES[$fileInputName]['tmp_name'], $destination) || !is_file($destination) || filesize($destination) === 0) {
            flash('error', 'Não foi possível salvar o arquivo de ' . $prefix . '. Verifique a permissão da pasta uploads.');
            return null;
        }

        return url('uploads/' . rawurlencode($filename));
    }
}
