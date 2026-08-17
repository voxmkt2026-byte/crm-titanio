SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS knowledge_sources (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT,
 url VARCHAR(1000) NOT NULL,
 title VARCHAR(255) NULL,
 category VARCHAR(80) NOT NULL DEFAULT 'Site oficial',
 tags VARCHAR(500) NOT NULL DEFAULT 'site, crawler, titanium',
 ai_enabled TINYINT(1) NOT NULL DEFAULT 1,
 status ENUM('novo','analisado','publicado','erro') NOT NULL DEFAULT 'novo',
 extracted_content LONGTEXT NULL,
 content_hash CHAR(64) NULL,
 page_id INT UNSIGNED NULL,
 last_error TEXT NULL,
 last_crawled_at DATETIME NULL,
 created_by INT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(id), UNIQUE KEY uq_knowledge_source_url(url(190)), KEY idx_knowledge_source_status(status),
 CONSTRAINT fk_knowledge_source_page FOREIGN KEY(page_id) REFERENCES workspace_pages(id) ON DELETE SET NULL,
 CONSTRAINT fk_knowledge_source_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS tc_ws4_add_col;
DELIMITER $$
CREATE PROCEDURE tc_ws4_add_col(IN tbl VARCHAR(64),IN col VARCHAR(64),IN ddl TEXT)
BEGIN
 IF NOT EXISTS(SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=tbl AND COLUMN_NAME=col) THEN
  SET @q=CONCAT('ALTER TABLE `',tbl,'` ADD COLUMN ',ddl);PREPARE s FROM @q;EXECUTE s;DEALLOCATE PREPARE s;
 END IF;
END$$
DELIMITER ;
CALL tc_ws4_add_col('knowledge_sources','category',"`category` VARCHAR(80) NOT NULL DEFAULT 'Site oficial'");
CALL tc_ws4_add_col('knowledge_sources','tags',"`tags` VARCHAR(500) NOT NULL DEFAULT 'site, crawler, titanium'");
CALL tc_ws4_add_col('knowledge_sources','ai_enabled',"`ai_enabled` TINYINT(1) NOT NULL DEFAULT 1");
DROP PROCEDURE tc_ws4_add_col;

INSERT INTO workspace_pages(type,category,title,content,tags,is_pinned,visibility,created_by,updated_by)
SELECT 'wiki','Institucional','Titanium Consultoria — Informações oficiais',
'<h2>Identidade e contatos oficiais</h2><ul><li><strong>Nome:</strong> Titanium Consultoria</li><li><strong>CNPJ:</strong> 46.640.755/0001-51</li><li><strong>WhatsApp:</strong> +55 (11) 93004-8940</li><li><strong>E-mail:</strong> contato@titaniumconsultorias.com.br</li><li><strong>Site:</strong> https://titaniumconsultorias.com.br/</li><li><strong>Atendimento:</strong> todo o território nacional.</li></ul><h2>Sobre</h2><p>Consultoria especializada em aquisição patrimonial via consórcio e cartas contempladas. Realiza diagnóstico de perfil, comparação de alternativas, curadoria e orientação sobre riscos.</p><h2>Papel da empresa</h2><p>A Titanium atua como intermediadora e assessoria comercial. Não é instituição financeira, administradora de consórcios ou concedente de crédito.</p><h2>Segurança</h2><p>Nunca solicitar depósito antecipado, taxa de liberação ou transferência sem formalização contratual prévia. Dados tratados conforme a LGPD.</p>',
'titanium, institucional, cnpj, segurança, lgpd',1,'equipe',u.id,u.id
FROM users u WHERE u.role='admin' AND NOT EXISTS(SELECT 1 FROM workspace_pages WHERE title='Titanium Consultoria — Informações oficiais') ORDER BY u.id LIMIT 1;

INSERT INTO workspace_pages(type,category,title,content,tags,is_pinned,visibility,created_by,updated_by)
SELECT 'wiki','Produto','Cartas contempladas — Guia comercial',
'<h2>O que é uma carta contemplada</h2><p>Crédito de consórcio já contemplado por sorteio ou lance. A utilização depende das regras e aprovação da administradora, transferência, garantias, documentação, taxas e saldo devedor.</p><h2>Processo Titanium</h2><ol><li>Diagnóstico do objetivo, prazo, entrada e parcela.</li><li>Comparação entre consórcio, carta contemplada, financiamento ou aguardar.</li><li>Curadoria de disponibilidade real.</li><li>Verificação documental e jurídica.</li><li>Orientação sobre custos e riscos.</li></ol><h2>Categorias</h2><ul><li>Imóveis, terrenos e imóveis comerciais.</li><li>Veículos, motos e utilitários.</li><li>Caminhões e frotas.</li><li>Máquinas agrícolas.</li><li>Serviços, clínicas e equipamentos.</li></ul><h2>Administradoras exibidas no site</h2><p>Santander, Bradesco, Safra, Sicredi, Porto Seguro, Itaú, Rodobens, Embracon, SimpleBank, PagPlus e Mycon.</p><h2>Regra comercial</h2><p>Nunca prometer aprovação, prazo, economia ou disponibilidade. Confirmar cada carta com a equipe antes de apresentar.</p>',
'cartas contempladas, imóveis, veículos, máquinas, vendas',1,'equipe',u.id,u.id
FROM users u WHERE u.role='admin' AND NOT EXISTS(SELECT 1 FROM workspace_pages WHERE title='Cartas contempladas — Guia comercial') ORDER BY u.id LIMIT 1;

UPDATE workspace_pages SET content='<h2>Identidade e contatos oficiais</h2><ul><li><strong>Nome:</strong> Titanium Consultoria</li><li><strong>CNPJ:</strong> 46.640.755/0001-51</li><li><strong>WhatsApp:</strong> +55 (11) 93004-8940</li><li><strong>E-mail:</strong> contato@titaniumconsultorias.com.br</li><li><strong>Site:</strong> https://titaniumconsultorias.com.br/</li><li><strong>Atendimento:</strong> todo o território nacional.</li></ul><h2>Sobre</h2><p>Consultoria especializada em aquisição patrimonial via consórcio e cartas contempladas. Realiza diagnóstico de perfil, comparação de alternativas, curadoria e orientação sobre riscos.</p><h2>Papel da empresa</h2><p>A Titanium atua como intermediadora e assessoria comercial. Não é instituição financeira, administradora de consórcios ou concedente de crédito.</p><h2>Segurança</h2><p>Nunca solicitar depósito antecipado, taxa de liberação ou transferência sem formalização contratual prévia. Dados tratados conforme a LGPD.</p>',tags='titanium, institucional, cnpj, whatsapp, contato, segurança, lgpd',is_pinned=1 WHERE title LIKE 'Titanium Consultoria%Informa%oficiais';
UPDATE workspace_pages SET content='<h2>O que é uma carta contemplada</h2><p>Crédito de consórcio já contemplado por sorteio ou lance. Depende das regras e aprovação da administradora, transferência, garantias, documentação, taxas e saldo devedor.</p><h2>Processo Titanium</h2><ol><li>Diagnóstico do objetivo, prazo, entrada e parcela.</li><li>Comparação entre consórcio, carta, financiamento ou aguardar.</li><li>Curadoria de disponibilidade real.</li><li>Verificação documental e jurídica.</li><li>Orientação sobre custos e riscos.</li></ol><h2>Categorias</h2><ul><li>Imóveis, terrenos e imóveis comerciais.</li><li>Veículos, motos e utilitários.</li><li>Caminhões e frotas.</li><li>Máquinas agrícolas.</li><li>Serviços, clínicas e equipamentos.</li></ul><h2>Administradoras exibidas no site</h2><p>Santander, Bradesco, Safra, Sicredi, Porto Seguro, Itaú, Rodobens, Embracon, SimpleBank, PagPlus e Mycon.</p><h2>Regra comercial</h2><p>Nunca prometer aprovação, prazo, economia ou disponibilidade. Confirmar cada carta com a equipe antes de apresentar.</p>',tags='cartas contempladas, imóveis, veículos, administradoras, processo, vendas',is_pinned=1 WHERE title LIKE 'Cartas contempladas%Guia comercial';

INSERT INTO knowledge_sources(url,title,status,created_by)
SELECT 'https://titaniumconsultorias.com.br/','Site oficial Titanium','novo',u.id FROM users u WHERE u.role='admin' AND NOT EXISTS(SELECT 1 FROM knowledge_sources WHERE url='https://titaniumconsultorias.com.br/') ORDER BY u.id LIMIT 1;
INSERT INTO knowledge_sources(url,title,status,created_by)
SELECT 'https://titaniumconsultorias.com.br/cartas-contempladas/','Cartas contempladas','novo',u.id FROM users u WHERE u.role='admin' AND NOT EXISTS(SELECT 1 FROM knowledge_sources WHERE url='https://titaniumconsultorias.com.br/cartas-contempladas/') ORDER BY u.id LIMIT 1;
