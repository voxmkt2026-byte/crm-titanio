<?php
/**
 * app/models/Form.php
 * Formulários públicos de captação (Construtor de Formulários). Cada
 * formulário guarda seus campos como JSON (campos padrão mapeados para
 * `leads` e campos custom_* auditados na submissão), e opcionalmente um responsável padrão (usado também pelo QR Code por
 * vendedor, que sobrepõe esse padrão via ?consultor=ID na URL pública).
 */

require_once APP_PATH . '/core/Model.php';

class Form extends Model
{
    protected string $table = 'forms';

    /** Catálogo de campos que um formulário pode expor — sempre mapeados 1:1 para colunas de `leads`. */
    public const FIELD_CATALOG = [
        'name'          => 'Nome',
        'phone'         => 'Telefone',
        'whatsapp'      => 'WhatsApp',
        'email'         => 'E-mail',
        'cpf'           => 'CPF',
        'city'          => 'Cidade',
        'state'         => 'Estado (UF)',
        'interest'      => 'Interesse',
        'desired_value' => 'Valor desejado',
        'profession'    => 'Profissão',
        'income_range'  => 'Faixa de renda',
        'company'       => 'Empresa',
        'zipcode'       => 'CEP',
        'notes'         => 'Observações',
    ];

    public const FIELD_TYPES = ['text', 'textarea', 'select', 'email', 'tel', 'number', 'checkbox'];

    /** Fontes aceitas pelo ENUM de leads desde a migration_fase4.sql. */
    public const SOURCE_CATALOG = [
        'landing_page' => 'Landing page', 'site' => 'Site', 'organico' => 'Orgânico',
        'facebook' => 'Facebook', 'instagram' => 'Instagram', 'google' => 'Google Ads',
        'indicacao' => 'Indicação', 'whatsapp' => 'WhatsApp', 'cadastro_manual' => 'Cadastro manual',
        'api' => 'API', 'webhook' => 'Webhook', 'outros' => 'Outros',
    ];

    public function allWithStats(): array
    {
        $stmt = $this->db->query(
            "SELECT f.*, u.name AS assignee_name, c.name AS creator_name
             FROM forms f
             LEFT JOIN users u ON u.id = f.default_assigned_to
             LEFT JOIN users c ON c.id = f.created_by
             ORDER BY f.created_at DESC"
        );
        return $stmt->fetchAll();
    }

    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM forms WHERE slug = :slug LIMIT 1");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch() ?: null;
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT id FROM forms WHERE slug = :slug";
        $params = [':slug' => $slug];
        if ($excludeId) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }
        $stmt = $this->db->prepare($sql . " LIMIT 1");
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }

    /** Gera um slug único a partir do nome (ex: "Captação Instagram" -> "captacao-instagram", ou "-2" se já existir). */
    public function generateSlug(string $name): string
    {
        $base = strtolower(trim($name));
        $base = preg_replace('/[^a-z0-9]+/', '-', $base) ?? '';
        $base = trim($base, '-');
        if ($base === '') {
            $base = 'formulario';
        }
        $slug = $base;
        $i = 2;
        while ($this->slugExists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    /** Colunas "opcionais" (adicionadas em migrations incrementais depois da v1) e de onde tirar o valor de cada uma. */
    private const OPTIONAL_COLUMNS = [
        'theme'            => 'theme',
        'font_family'      => 'font_family',
        'logo_url'         => 'logo_url',
        'cover_image_url'  => 'cover_image_url',
        'footer_text'      => 'footer_text',
        'default_source'   => 'default_source',
        'submit_label'     => 'submit_label',
        'privacy_text'     => 'privacy_text',
        'redirect_url'     => 'redirect_url',
        'allowed_origins'  => 'allowed_origins',
        'webhook_url'      => 'webhook_url',
        'webhook_secret'   => 'webhook_secret',
    ];

    private static ?array $existingColumns = null;

    /**
     * Colunas que realmente existem na tabela `forms` neste banco, cacheado em
     * memória por request. Assim, cada nova opção de personalização adicionada
     * no futuro (nova migration incremental) nunca quebra o salvamento do
     * formulário se a migration ainda não tiver rodado — ela é simplesmente
     * ignorada até lá, em vez de gerar erro de "coluna desconhecida".
     */
    private function existingColumns(): array
    {
        if (self::$existingColumns === null) {
            try {
                $stmt = $this->db->query('SHOW COLUMNS FROM forms');
                self::$existingColumns = array_column($stmt->fetchAll(), 'Field');
            } catch (Throwable $e) {
                self::$existingColumns = [];
            }
        }
        return self::$existingColumns;
    }

    public function create(array $data): int
    {
        $columns = [
            'name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'] ?? null,
            'fields' => json_encode($data['fields'], JSON_UNESCAPED_UNICODE),
            'default_source' => $data['default_source'] ?? 'landing_page',
            'default_interest' => $data['default_interest'] ?? null,
            'default_assigned_to' => $data['default_assigned_to'] ?? null,
            'notify_assignee' => !empty($data['notify_assignee']) ? 1 : 0,
            'success_message' => $data['success_message'] ?? null,
            'active' => !empty($data['active']) ? 1 : 0,
            'created_by' => $data['created_by'] ?? null,
        ];
        foreach (self::OPTIONAL_COLUMNS as $column => $dataKey) {
            if (in_array($column, $this->existingColumns(), true) && array_key_exists($dataKey, $data)) {
                $columns[$column] = $data[$dataKey];
            }
        }

        $placeholders = array_map(fn($c) => ':' . $c, array_keys($columns));
        $stmt = $this->db->prepare(
            'INSERT INTO forms (' . implode(', ', array_keys($columns)) . ') VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute(array_combine($placeholders, array_values($columns)));
        return (int) $this->db->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $columns = [
            'name' => $data['name'], 'slug' => $data['slug'], 'description' => $data['description'] ?? null,
            'fields' => json_encode($data['fields'], JSON_UNESCAPED_UNICODE),
            'default_source' => $data['default_source'] ?? 'landing_page',
            'default_interest' => $data['default_interest'] ?? null,
            'default_assigned_to' => $data['default_assigned_to'] ?? null,
            'notify_assignee' => !empty($data['notify_assignee']) ? 1 : 0,
            'success_message' => $data['success_message'] ?? null,
            'active' => !empty($data['active']) ? 1 : 0,
        ];
        foreach (self::OPTIONAL_COLUMNS as $column => $dataKey) {
            if (in_array($column, $this->existingColumns(), true) && array_key_exists($dataKey, $data)) {
                $columns[$column] = $data[$dataKey];
            }
        }

        $sets = array_map(fn($c) => "{$c} = :{$c}", array_keys($columns));
        $stmt = $this->db->prepare('UPDATE forms SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $params = [];
        foreach ($columns as $c => $v) {
            $params[':' . $c] = $v;
        }
        $params[':id'] = $id;
        return $stmt->execute($params);
    }

    public function toggleActive(int $id): bool
    {
        $stmt = $this->db->prepare("UPDATE forms SET active = IF(active = 1, 0, 1) WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public function incrementSubmissions(int $id): void
    {
        $this->db->prepare("UPDATE forms SET submissions_count = submissions_count + 1 WHERE id = :id")->execute([':id' => $id]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM forms WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    public static function isAllowedFieldKey(string $key): bool
    {
        return isset(self::FIELD_CATALOG[$key]) || (bool) preg_match('/^custom_[a-z0-9_]{2,48}$/', $key);
    }

    public static function defaultFieldType(string $key): string
    {
        return match ($key) {
            'email' => 'email', 'phone', 'whatsapp' => 'tel', 'desired_value' => 'number',
            'notes' => 'textarea', default => 'text',
        };
    }

    /** Decodifica os campos e aceita custom_* para dados complementares da submissão. */
    public function decodeFields(array $form): array
    {
        $decoded = json_decode((string) ($form['fields'] ?? '[]'), true);
        if (!is_array($decoded)) {
            return [];
        }
        $fields = [];
        foreach ($decoded as $field) {
            $key = (string) ($field['key'] ?? '');
            if (!self::isAllowedFieldKey($key)) {
                continue;
            }
            $type = (string) ($field['type'] ?? self::defaultFieldType($key));
            if (!in_array($type, self::FIELD_TYPES, true)) {
                $type = self::defaultFieldType($key);
            }
            $options = array_values(array_filter(array_map(
                static fn($option) => mb_substr(trim((string) $option), 0, 120),
                (array) ($field['options'] ?? [])
            ), static fn($option) => $option !== ''));
            $fallbackLabel = self::FIELD_CATALOG[$key] ?? ucfirst(str_replace('_', ' ', preg_replace('/^custom_/', '', $key)));
            $fields[] = [
                'key'      => $key,
                'label'    => (string) ($field['label'] ?? $fallbackLabel),
                'required' => !empty($field['required']),
                'new_step' => !empty($field['new_step']),
                'type'     => $type,
                'placeholder' => mb_substr(trim((string) ($field['placeholder'] ?? '')), 0, 180),
                'options'  => array_slice($options, 0, 40),
            ];
        }
        return $fields;
    }

    /** Agrupa os campos (já decodificados) em etapas, quebrando onde "new_step" está marcado. Sempre retorna ao menos 1 etapa. */
    public function groupIntoSteps(array $fields): array
    {
        $steps = [[]];
        foreach ($fields as $i => $field) {
            if ($i > 0 && !empty($field['new_step'])) {
                $steps[] = [];
            }
            $steps[count($steps) - 1][] = $field;
        }
        return $steps;
    }

    /** Chave de API fica guardada somente como hash; o valor puro nunca é persistido. */
    public function rotateApiKey(int $id, string $plainKey): bool
    {
        if (!in_array('api_key_hash', $this->existingColumns(), true)) {
            throw new RuntimeException('Execute migration_forms_v4.sql para habilitar a API de formulários.');
        }
        $stmt = $this->db->prepare(
            'UPDATE forms SET api_key_hash=:hash, api_key_last4=:last4, api_key_created_at=NOW() WHERE id=:id'
        );
        return $stmt->execute([
            ':hash' => hash('sha256', $plainKey), ':last4' => substr($plainKey, -4), ':id' => $id,
        ]);
    }

    public function verifyApiKey(array $form, string $plainKey): bool
    {
        $hash = (string) ($form['api_key_hash'] ?? '');
        return $hash !== '' && $plainKey !== '' && hash_equals($hash, hash('sha256', $plainKey));
    }

    /** Segredo de assinatura usado no formulário público/iframe, sem depender de cookies de terceiros. */
    public function publicSecret(array $form): ?string
    {
        if (!in_array('public_secret', $this->existingColumns(), true)) {
            return null;
        }
        $secret = (string) ($form['public_secret'] ?? '');
        if ($secret !== '') {
            return $secret;
        }
        $secret = bin2hex(random_bytes(32));
        $this->db->prepare('UPDATE forms SET public_secret=:secret WHERE id=:id')
            ->execute([':secret' => $secret, ':id' => (int) $form['id']]);
        return $secret;
    }

    public function recordSubmissionEvent(array $event): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO form_submission_events(form_id,lead_id,channel,status,external_reference,payload_json,origin,ip_address,user_agent,webhook_status)
                 VALUES(:form,:lead,:channel,:status,:reference,:payload,:origin,:ip,:agent,:webhook)'
            );
            $stmt->execute([
                ':form' => (int) $event['form_id'], ':lead' => $event['lead_id'] ?? null,
                ':channel' => $event['channel'] ?? 'public', ':status' => $event['status'] ?? 'created',
                ':reference' => $event['external_reference'] ?? null,
                ':payload' => json_encode($event['payload'] ?? [], JSON_UNESCAPED_UNICODE),
                ':origin' => $event['origin'] ?? null, ':ip' => $event['ip_address'] ?? null,
                ':agent' => $event['user_agent'] ?? null, ':webhook' => $event['webhook_status'] ?? 'not_configured',
            ]);
            return (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log('Form::recordSubmissionEvent - ' . $e->getMessage());
            return null;
        }
    }

    public function updateWebhookDelivery(?int $eventId, string $status, ?int $responseCode = null): void
    {
        if (!$eventId) {
            return;
        }
        try {
            $this->db->prepare('UPDATE form_submission_events SET webhook_status=:status, webhook_response_code=:code WHERE id=:id')
                ->execute([':status' => $status, ':code' => $responseCode, ':id' => $eventId]);
        } catch (Throwable $e) {
            error_log('Form::updateWebhookDelivery - ' . $e->getMessage());
        }
    }

    public function recentSubmissionEvents(int $formId, int $limit = 12): array
    {
        try {
            $stmt = $this->db->prepare(
                'SELECT e.*, l.name AS lead_name, l.lead_code FROM form_submission_events e
                 LEFT JOIN leads l ON l.id=e.lead_id WHERE e.form_id=:form ORDER BY e.created_at DESC LIMIT :limit'
            );
            $stmt->bindValue(':form', $formId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}
