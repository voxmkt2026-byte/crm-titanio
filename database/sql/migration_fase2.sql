-- =====================================================================
-- Titanium CRM - Migration Fase 2
-- Execute este arquivo SOMENTE APÓS já ter importado database/sql/schema.sql
-- (e, opcionalmente, database/sql/seed.sql) da Fase 1.
--
-- O que esta migration cria:
--   - Tabela lead_score_rules (regras configuráveis do Lead Score automático)
--   - Seed com os pesos padrão de cada critério
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase2.sql
--
-- Esta migration é incremental e idempotente (usa IF NOT EXISTS / INSERT IGNORE)
-- para não quebrar quem já rodou o schema.sql da Fase 1.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabela: lead_score_rules
-- Regras configuráveis usadas por app/models/LeadScore.php para calcular
-- automaticamente o campo leads.lead_score (0 a 100) ao salvar um lead.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS lead_score_rules (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    criterio VARCHAR(50) NOT NULL COMMENT 'Identificador interno do critério (ver app/models/LeadScore.php)',
    descricao VARCHAR(191) NOT NULL COMMENT 'Descrição amigável exibida na tela de configuração',
    peso SMALLINT NOT NULL DEFAULT 0 COMMENT 'Pontos somados (ou subtraídos, se negativo) quando o critério é atendido',
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lead_score_rules_criterio (criterio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Regras configuráveis do Lead Score automático';

-- ---------------------------------------------------------------------
-- Seed: pesos padrão (edite pela tela /configuracoes/... ou aqui mesmo)
-- ---------------------------------------------------------------------
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

SET FOREIGN_KEY_CHECKS = 1;
