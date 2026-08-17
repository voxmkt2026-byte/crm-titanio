-- Titanium CRM · Formulários v4
-- Execute após migration_forms.sql, migration_forms_v2.sql e migration_forms_v3.sql.
-- Disponibiliza API por formulário, integração por webhook, CORS controlado,
-- personalização da experiência pública e auditoria das submissões.

SET NAMES utf8mb4;

ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS submit_label VARCHAR(80) NULL AFTER footer_text,
    ADD COLUMN IF NOT EXISTS privacy_text VARCHAR(255) NULL AFTER submit_label,
    ADD COLUMN IF NOT EXISTS redirect_url VARCHAR(500) NULL AFTER privacy_text,
    ADD COLUMN IF NOT EXISTS allowed_origins TEXT NULL AFTER redirect_url,
    ADD COLUMN IF NOT EXISTS api_key_hash CHAR(64) NULL AFTER allowed_origins,
    ADD COLUMN IF NOT EXISTS api_key_last4 CHAR(4) NULL AFTER api_key_hash,
    ADD COLUMN IF NOT EXISTS api_key_created_at DATETIME NULL AFTER api_key_last4,
    ADD COLUMN IF NOT EXISTS public_secret CHAR(64) NULL AFTER api_key_created_at,
    ADD COLUMN IF NOT EXISTS webhook_url VARCHAR(500) NULL AFTER public_secret,
    ADD COLUMN IF NOT EXISTS webhook_secret VARCHAR(255) NULL AFTER webhook_url;

-- "formulario" nunca foi um valor válido do ENUM leads.source. Formulários
-- existentes passam a usar landing_page, preservando a origem no campaign.
UPDATE forms SET default_source = 'landing_page'
WHERE default_source IS NULL OR default_source = '' OR default_source = 'formulario';

-- Assinatura do formulário público/iframe: permite funcionar mesmo quando o
-- navegador bloqueia cookies de terceiros. O segredo nunca é enviado ao cliente.
UPDATE forms
SET public_secret = SHA2(CONCAT(UUID(), ':', id, ':', RAND(), ':', NOW(6)), 256)
WHERE public_secret IS NULL OR public_secret = '';

CREATE TABLE IF NOT EXISTS form_submission_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    form_id INT UNSIGNED NOT NULL,
    lead_id INT UNSIGNED NULL,
    channel ENUM('public','api') NOT NULL DEFAULT 'public',
    status ENUM('created','duplicate','invalid','error') NOT NULL DEFAULT 'created',
    external_reference VARCHAR(120) NULL,
    payload_json LONGTEXT NULL,
    origin VARCHAR(255) NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    webhook_status ENUM('not_configured','pending','sent','failed') NOT NULL DEFAULT 'not_configured',
    webhook_response_code SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_form_events_form_created (form_id, created_at),
    KEY idx_form_events_lead (lead_id),
    KEY idx_form_events_channel_status (channel, status),
    CONSTRAINT fk_form_events_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_form_events_lead FOREIGN KEY (lead_id) REFERENCES leads(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
