-- =====================================================================
-- Titanium CRM - Migration Fase 5
-- Execute este arquivo SOMENTE APÓS já ter importado database/sql/schema.sql
-- e as migrations das fases 2, 3 e 4 (nesta ordem).
--
-- O que esta migration faz:
--   1) Lead Score por ORIGEM (peso específico por forma de captação, em vez
--      do critério binário genérico "origem_qualificada" da Fase 2).
--   2) Desativa (ativo = 0) a regra antiga "origem_qualificada" sem apagá-la,
--      preservando o histórico de quem já rodou a Fase 2 (ver
--      app/models/LeadScore.php::calculate(), que agora ignora regras
--      inativas e soma o peso de "origem_" . source dinamicamente).
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/migration_fase5.sql
--
-- Esta migration é incremental e idempotente (usa INSERT IGNORE e um UPDATE
-- condicional) para poder ser executada mais de uma vez sem erro.
--
-- ---------------------------------------------------------------------
-- RACIONAL COMERCIAL (Titanium Consultoria) dos pesos por origem:
--
--   indicacao (18)         -> Indicação é historicamente a origem que MAIS
--                              converte: o lead já chega pré-qualificado
--                              pela confiança de quem indicou. Maior peso
--                              de todos.
--   landing_page (15)      -> Landing Page própria converte muito melhor
--                              que contato direto via WhatsApp: o lead
--                              preencheu um formulário demonstrando
--                              interesse ativo. Maior peso entre as
--                              origens de mídia/paginas próprias.
--   google (12)            -> Google Ads converte melhor que Meta Ads: em
--                              geral o usuário já está buscando ativamente
--                              (intenção de busca) quando clica no anúncio.
--   site (8) / organico (8)-> Tráfego do site institucional ou orgânico:
--                              qualificação média, sem investimento pago
--                              direcionado.
--   facebook (8) /
--   instagram (8)          -> Meta Ads converte pior que Google Ads (menor
--                              intenção de busca, mais impulso/scroll),
--                              mas ainda é mídia paga qualificada.
--   whatsapp (6)            -> Contato direto pelo WhatsApp costuma ser
--                              "frio", sem qualificação prévia por
--                              formulário ou anúncio. Peso baixo/médio.
--   cadastro_manual (5)    -> Cadastro feito manualmente pela equipe não
--                              indica, por si só, qualidade do lead
--                              (neutro).
--   importacao_csv (4) /
--   api (4) / webhook (4)  -> Origem operacional/neutra-baixa: apenas
--                              indica o MEIO de entrada no sistema, não a
--                              intenção do lead.
--   outros (3)             -> Origem não identificada/baixa relevância.
--
-- Ajuste os pesos livremente pela tela Configurações > Lead Score
-- (app/controllers/LeadScoreController.php), mantendo de preferência a
-- ordem relativa acima (indicação > landing page > google > meta ads >
-- site/orgânico > whatsapp > manual > importação/api/webhook > outros).
-- ---------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Desativa o critério binário antigo (Fase 2), sem apagar o histórico.
UPDATE lead_score_rules SET ativo = 0 WHERE criterio = 'origem_qualificada';

-- Seed: peso individual por origem (Fase 5)
INSERT IGNORE INTO lead_score_rules (criterio, descricao, peso, ativo) VALUES
('origem_indicacao',       'Origem: Indicação (maior taxa de conversão histórica)',        18, 1),
('origem_landing_page',    'Origem: Landing Page própria (formulário preenchido)',          15, 1),
('origem_google',          'Origem: Google Ads (busca ativa, converte melhor que Meta Ads)', 12, 1),
('origem_site',            'Origem: Site institucional',                                     8, 1),
('origem_organico',        'Origem: Orgânico (redes sociais, busca não paga)',               8, 1),
('origem_facebook',        'Origem: Facebook Ads',                                           8, 1),
('origem_instagram',       'Origem: Instagram Ads',                                          8, 1),
('origem_whatsapp',        'Origem: WhatsApp direto (contato frio, sem qualificação prévia)', 6, 1),
('origem_cadastro_manual', 'Origem: Cadastro manual pela equipe (neutro)',                    5, 1),
('origem_importacao_csv',  'Origem: Importação via CSV (neutro-baixo)',                       4, 1),
('origem_api',             'Origem: API (neutro-baixo)',                                      4, 1),
('origem_webhook',         'Origem: Webhook (Meta/Google Lead Ads, neutro-baixo)',            4, 1),
('origem_outros',          'Origem: Outros / não identificada',                               3, 1);

SET FOREIGN_KEY_CHECKS = 1;
