-- =====================================================================
-- Titanium CRM - Dados de teste (seed)
-- Execute DEPOIS de importar database/sql/schema.sql
--
-- Como importar:
--   1) phpMyAdmin: aba "Importar" > escolha este arquivo > Executar.
--   2) Linha de comando:
--      mysql -u SEU_USUARIO -p SEU_BANCO < database/sql/seed.sql
--
-- USUÁRIO ADMIN DE TESTE (troque a senha em produção!):
--   E-mail: admin@titaniumconsultoria.com.br
--   Senha:  admin123
--   (hash bcrypt gerado com password_hash() do PHP, formato $2y$)
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Usuários
-- ---------------------------------------------------------------------
INSERT INTO users (id, name, email, password, role, avatar, active, created_at, updated_at) VALUES
(1, 'Administrador Titanium', 'admin@titaniumconsultoria.com.br', '$2y$10$5jm/tH7iyagFmxV9OAUhHeuW.D45dfnq3aUpaVnLjF4oG6wFZzfoy', 'admin', NULL, 1, NOW(), NOW()),
(2, 'Carla Supervisão',       'carla.supervisora@titaniumconsultoria.com.br', '$2y$10$5jm/tH7iyagFmxV9OAUhHeuW.D45dfnq3aUpaVnLjF4oG6wFZzfoy', 'supervisor', NULL, 1, NOW(), NOW()),
(3, 'João Consultor',         'joao.consultor@titaniumconsultoria.com.br', '$2y$10$5jm/tH7iyagFmxV9OAUhHeuW.D45dfnq3aUpaVnLjF4oG6wFZzfoy', 'consultor', NULL, 1, NOW(), NOW()),
(4, 'Marina Consultora',      'marina.consultora@titaniumconsultoria.com.br', '$2y$10$5jm/tH7iyagFmxV9OAUhHeuW.D45dfnq3aUpaVnLjF4oG6wFZzfoy', 'consultor', NULL, 1, NOW(), NOW());
-- OBS: todos os usuários de teste acima usam a MESMA senha "admin123" só para facilitar os testes.

-- ---------------------------------------------------------------------
-- Motivos de perda
-- ---------------------------------------------------------------------
INSERT INTO loss_reasons (id, name, active) VALUES
(1, 'Sem entrada', 1),
(2, 'Não respondeu', 1),
(3, 'Comprou com concorrente', 1),
(4, 'Desistiu', 1),
(5, 'Sem perfil', 1),
(6, 'Não possui renda', 1),
(7, 'Outro', 1);

-- ---------------------------------------------------------------------
-- Estágios do Pipeline (Kanban)
-- ---------------------------------------------------------------------
INSERT INTO pipeline_stages (id, name, order_position, color) VALUES
(1, 'Novo', 1, '#3b82f6'),
(2, 'Contato', 2, '#0891b2'),
(3, 'Qualificado', 3, '#d97706'),
(4, 'Negociação', 4, '#7c3aed'),
(5, 'Documentação', 5, '#6366f1'),
(6, 'Fechado', 6, '#16a34a'),
(7, 'Perdido', 7, '#dc2626');

-- ---------------------------------------------------------------------
-- Tags
-- ---------------------------------------------------------------------
INSERT INTO tags (id, name, color) VALUES
(1, 'VIP', '#d97706'),
(2, 'Urgente', '#dc2626'),
(3, 'Retornar depois', '#6b7280'),
(4, 'Indicação', '#16a34a');

-- ---------------------------------------------------------------------
-- Configurações gerais (chave/valor)
-- ---------------------------------------------------------------------
INSERT INTO settings (`key`, `value`) VALUES
('company_name', 'Titanium Consultoria'),
('company_logo', ''),
('company_favicon', ''),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_user', ''),
('smtp_pass', ''),
('smtp_from_name', 'Titanium CRM');

-- ---------------------------------------------------------------------
-- Leads fictícios de teste (variados: origens, estados, status, datas)
-- ---------------------------------------------------------------------
INSERT INTO leads
(name, ddd, phone, whatsapp, email, cpf, city, state, zipcode, source, campaign, adset, ad, utm_source, utm_medium, utm_campaign, interest, desired_value, has_down_payment, down_payment_value, income_range, profession, company, status, last_contact_at, next_contact_at, notes, internal_notes, assigned_to, lead_score, temperature, priority, ip_address, device, browser, created_at, updated_at)
VALUES
('Ana Paula Ferreira', '11', '1140028922', '11987654321', 'ana.ferreira@example.com', '11122233396', 'São Paulo', 'SP', '01310100', 'facebook', 'Carta Contemplada - SP', 'Interesse Imóvel 25-45', 'Video - Carta Contemplada', 'facebook', 'cpc', 'carta_contemplada_sp', 'imovel', 350000.00, 1, 35000.00, 'R$ 5.000 - R$ 8.000', 'Engenheira', 'Autônoma', 'em_negociacao', NOW() - INTERVAL 2 DAY, NOW() + INTERVAL 1 DAY, 'Cliente muito interessada, aguardando proposta.', 'Já enviou documentos pessoais.', 3, 78, 'quente', 'alta', '187.12.45.10', 'mobile', 'Mozilla/5.0 (Linux; Android 13)', NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 2 DAY),
('Carlos Eduardo Souza', '21', '2133221100', '21999887766', 'carlos.souza@example.com', '22233344405', 'Rio de Janeiro', 'RJ', '20040020', 'google', 'Consórcio Veículos RJ', 'Pesquisa - Consórcio Carro', 'Anúncio Texto - Consórcio', 'google', 'cpc', 'consorcio_veiculos_rj', 'veiculo', 80000.00, 0, NULL, 'R$ 3.000 - R$ 5.000', 'Motorista de aplicativo', NULL, 'novo', NULL, NULL, NULL, NULL, NULL, 0, 'frio', 'media', '201.10.55.3', 'desktop', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 1 DAY),
('Fernanda Lima', '31', '3132145566', '31988776655', 'fernanda.lima@example.com', '33344455519', 'Belo Horizonte', 'MG', '30130000', 'instagram', 'Crédito Capital de Giro', 'PJ 30-55', 'Carrossel - Capital de Giro', 'instagram', 'cpc', 'credito_capital_giro', 'capital_giro', 60000.00, 1, 10000.00, 'R$ 8.000 - R$ 15.000', 'Empresária', 'Lima Confecções', 'documentacao', NOW() - INTERVAL 4 DAY, NOW() + INTERVAL 2 DAY, 'Aguardando envio do contrato social.', NULL, 2, 65, 'morno', 'alta', '177.55.10.22', 'mobile', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', NOW() - INTERVAL 20 DAY, NOW() - INTERVAL 4 DAY),
('Roberto Alves', '41', '4133556677', '41991122334', 'roberto.alves@example.com', '44455566633', 'Curitiba', 'PR', '80010000', 'google', 'Consórcio Caminhão PR', 'Pesquisa - Consórcio Caminhão', 'Anúncio Texto - Caminhão', 'google', 'cpc', 'consorcio_caminhao_pr', 'caminhao', 220000.00, 0, NULL, 'R$ 5.000 - R$ 8.000', 'Transportador autônomo', NULL, 'perdido', NOW() - INTERVAL 15 DAY, NULL, NULL, 'Cliente disse que fechou com concorrente.', 3, 20, 'frio', 'baixa', '191.20.30.40', 'desktop', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', NOW() - INTERVAL 30 DAY, NOW() - INTERVAL 15 DAY),
('Juliana Costa', '51', '5132445566', '51987001122', 'juliana.costa@example.com', '55566677701', 'Porto Alegre', 'RS', '90010000', 'site', NULL, NULL, NULL, NULL, NULL, NULL, 'quitacao', 45000.00, 0, NULL, 'R$ 3.000 - R$ 5.000', 'Professora', NULL, 'primeiro_contato', NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 3 DAY, 'Quer quitar carta contemplada existente.', NULL, NULL, 40, 'morno', 'media', '138.90.12.5', 'mobile', 'Mozilla/5.0 (Android 12)', NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 1 DAY),
('Pedro Henrique Martins', '61', '6133009988', '61999443322', 'pedro.martins@example.com', '66677788815', 'Brasília', 'DF', '70040010', 'landing_page', 'Financiamento Imobiliário DF', NULL, NULL, 'landing_page', 'organic', NULL, 'imovel', 480000.00, 1, 96000.00, 'R$ 10.000+', 'Servidor Público', NULL, 'aguardando_aprovacao', NOW() - INTERVAL 5 DAY, NOW() + INTERVAL 1 DAY, 'Documentação completa, aguardando análise do banco.', NULL, 2, 85, 'quente', 'urgente', '200.150.30.9', 'desktop', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 18 DAY, NOW() - INTERVAL 5 DAY),
('Beatriz Rocha', '11', '1145678899', '11976543210', 'beatriz.rocha@example.com', '77788899902', 'Campinas', 'SP', '13010000', 'facebook', 'Consórcio Moto SP', 'Interesse Moto 18-30', 'Vídeo - Moto Nova', 'facebook', 'cpc', 'consorcio_moto_sp', 'moto', 18000.00, 0, NULL, 'R$ 1.500 - R$ 3.000', 'Vendedora', NULL, 'sem_entrada', NOW() - INTERVAL 8 DAY, NULL, NULL, 'Cliente não possui entrada disponível no momento.', 4, 15, 'frio', 'baixa', '177.30.40.50', 'mobile', 'Mozilla/5.0 (Linux; Android 11)', NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 8 DAY),
('Lucas Gabriel Pereira', '71', '7133112233', '71988990011', 'lucas.pereira@example.com', '88899900016', 'Salvador', 'BA', '40010000', 'organico', NULL, NULL, NULL, NULL, NULL, NULL, 'agronegocio', 150000.00, 1, 20000.00, 'R$ 8.000 - R$ 15.000', 'Produtor Rural', 'Fazenda Boa Vista', 'aprovado', NOW() - INTERVAL 6 DAY, NOW() + INTERVAL 4 DAY, 'Crédito aprovado, aguardando assinatura.', NULL, 3, 92, 'muito_quente', 'urgente', '189.40.60.70', 'desktop', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 25 DAY, NOW() - INTERVAL 6 DAY),
('Camila Nogueira', '81', '8133445566', '81997001122', 'camila.nogueira@example.com', '99900011129', 'Recife', 'PE', '50010000', 'google', 'Investimento Consórcio PE', 'Pesquisa - Investir Consórcio', 'Anúncio Texto - Investimento', 'google', 'cpc', 'investimento_consorcio_pe', 'investimento', 100000.00, 1, 15000.00, 'R$ 5.000 - R$ 8.000', 'Contadora', NULL, 'tentando_contato', NOW() - INTERVAL 7 DAY, NOW() + INTERVAL 1 DAY, NULL, 'Tentativas de contato: 3.', 4, 45, 'morno', 'media', '138.20.10.15', 'mobile', 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_5)', NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 7 DAY),
('Diego Santos', '85', '8532110099', '85991223344', 'diego.santos@example.com', '10101010100', 'Fortaleza', 'CE', '60010000', 'indicacao', NULL, NULL, NULL, NULL, NULL, NULL, 'construcao', 90000.00, 0, NULL, 'R$ 3.000 - R$ 5.000', 'Pedreiro autônomo', NULL, 'nao_responde', NOW() - INTERVAL 11 DAY, NULL, NULL, 'Não atende ligações há mais de uma semana.', NULL, 10, 'frio', 'baixa', '191.80.90.10', 'mobile', 'Mozilla/5.0 (Android 13)', NOW() - INTERVAL 14 DAY, NOW() - INTERVAL 11 DAY),
('Patrícia Gonçalves', '11', '1140556677', '11999001122', 'patricia.goncalves@example.com', '12121212120', 'São Paulo', 'SP', '04567000', 'facebook', 'Carta Contemplada - SP', 'Interesse Imóvel 25-45', 'Carrossel - Carta Contemplada', 'facebook', 'cpc', 'carta_contemplada_sp', 'imovel', 300000.00, 1, 30000.00, 'R$ 5.000 - R$ 8.000', 'Analista de Sistemas', 'Tech Solutions', 'fechado', NOW() - INTERVAL 3 DAY, NULL, 'Negócio fechado com sucesso!', NULL, 3, 100, 'muito_quente', 'urgente', '187.15.20.25', 'desktop', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', NOW() - INTERVAL 40 DAY, NOW() - INTERVAL 3 DAY),
('Marcos Vinícius Tavares', '19', '1933221144', '19981234567', 'marcos.tavares@example.com', '13131313131', 'Campinas', 'SP', '13020000', 'google', 'Consórcio Maquinário SP', 'Pesquisa - Maquinário Agrícola', 'Anúncio Texto - Maquinário', 'google', 'cpc', 'consorcio_maquinario_sp', 'maquinario', 200000.00, 0, NULL, 'R$ 8.000 - R$ 15.000', 'Produtor Rural', NULL, 'novo', NULL, NULL, NULL, NULL, NULL, 0, 'frio', 'media', '177.60.70.80', 'desktop', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW(), NOW()),
('Vanessa Ribeiro', '27', '2733009911', '27988112233', 'vanessa.ribeiro@example.com', '14141414142', 'Vitória', 'ES', '29010000', 'landing_page', 'Consórcio Imóvel ES', NULL, NULL, 'landing_page', 'organic', NULL, 'imovel', 250000.00, 1, 25000.00, 'R$ 5.000 - R$ 8.000', 'Advogada', 'Ribeiro Advocacia', 'aguardando_cliente', NOW() - INTERVAL 2 DAY, NOW() + INTERVAL 2 DAY, 'Aguardando cliente enviar RG e comprovante de renda.', NULL, 2, 70, 'quente', 'alta', '138.40.50.60', 'mobile', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_1)', NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 2 DAY),
('Rafael Batista', '62', '6233445500', NULL, 'rafael.batista@example.com', NULL, 'Goiânia', 'GO', '74010000', 'facebook', 'Crédito Consignado GO', 'Aposentados 50+', 'Imagem - Crédito Consignado', 'facebook', 'cpc', 'credito_consignado_go', 'outros', 25000.00, 0, NULL, 'R$ 1.500 - R$ 3.000', 'Aposentado', NULL, 'numero_invalido', NULL, NULL, NULL, 'Número informado não existe / está incorreto.', NULL, 5, 'frio', 'baixa', '191.10.20.30', 'mobile', 'Mozilla/5.0 (Android 10)', NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY),
('Larissa Mendes', '48', '4833667788', '48999776655', 'larissa.mendes@example.com', '15151515153', 'Florianópolis', 'SC', '88010000', 'organico', NULL, NULL, NULL, NULL, NULL, NULL, 'investimento', 120000.00, 1, 24000.00, 'R$ 8.000 - R$ 15.000', 'Médica', 'Clínica Vida', 'em_negociacao', NOW() - INTERVAL 1 DAY, NOW() + INTERVAL 1 DAY, 'Negociando valor de entrada.', NULL, 3, 80, 'quente', 'alta', '177.90.10.20', 'desktop', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 1 DAY),
('Eduardo Cardoso', '84', '8433221199', '84988776600', 'eduardo.cardoso@example.com', '16161616164', 'Natal', 'RN', '59010000', 'google', 'Consórcio Veículo RN', 'Pesquisa - Consórcio Carro', 'Anúncio Texto - Consórcio', 'google', 'cpc', 'consorcio_veiculos_rn', 'veiculo', 70000.00, 0, NULL, 'R$ 3.000 - R$ 5.000', 'Comerciante', 'Loja Cardoso', 'sem_interesse', NOW() - INTERVAL 9 DAY, NULL, NULL, 'Cliente informou que não tem mais interesse.', 4, 8, 'frio', 'baixa', '138.70.80.90', 'mobile', 'Mozilla/5.0 (Android 12)', NOW() - INTERVAL 16 DAY, NOW() - INTERVAL 9 DAY),
('Isabela Duarte', '11', '1140998877', '11955443322', 'isabela.duarte@example.com', '17171717175', 'São Paulo', 'SP', '05010000', 'instagram', 'Consórcio Imóvel SP', 'Interesse Imóvel 30-50', 'Reels - Consórcio Imóvel', 'instagram', 'cpc', 'consorcio_imovel_sp', 'imovel', 400000.00, 1, 60000.00, 'R$ 10.000+', 'Empresária', 'Duarte Móveis', 'novo', NULL, NULL, NULL, NULL, NULL, 0, 'frio', 'media', '187.20.30.40', 'mobile', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2)', NOW() - INTERVAL 6 HOUR, NOW() - INTERVAL 6 HOUR),
('Thiago Correia', '47', '4733221100', '47999112233', 'thiago.correia@example.com', '18181818186', 'Joinville', 'SC', '89010000', 'facebook', 'Crédito Capital de Giro SC', 'PJ 30-55', 'Vídeo - Capital de Giro', 'facebook', 'cpc', 'credito_capital_giro_sc', 'capital_giro', 55000.00, 0, NULL, 'R$ 5.000 - R$ 8.000', 'Empresário', 'Correia Metais', 'bloqueou', NOW() - INTERVAL 13 DAY, NULL, NULL, 'Cliente bloqueou o número de WhatsApp.', NULL, 2, 'frio', 'baixa', '177.10.20.30', 'mobile', 'Mozilla/5.0 (Android 11)', NOW() - INTERVAL 22 DAY, NOW() - INTERVAL 13 DAY),
('Aline Barros', '62', '6233112244', '62981234567', 'aline.barros@example.com', '19191919197', 'Goiânia', 'GO', '74020000', 'site', NULL, NULL, NULL, NULL, NULL, NULL, 'quitacao', 30000.00, 0, NULL, 'R$ 3.000 - R$ 5.000', 'Enfermeira', NULL, 'duplicado', NULL, NULL, 'Lead duplicado, já existe cadastro anterior.', NULL, NULL, 0, 'frio', 'baixa', '191.30.40.50', 'desktop', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 2 DAY);

-- ---------------------------------------------------------------------
-- Tags aplicadas a alguns leads (exemplo)
-- ---------------------------------------------------------------------
INSERT INTO lead_tags (lead_id, tag_id) VALUES
(1, 1), (1, 2),
(6, 1),
(8, 4),
(11, 1);

-- ---------------------------------------------------------------------
-- Histórico inicial de alguns leads (exemplo de timeline)
-- ---------------------------------------------------------------------
INSERT INTO lead_history (lead_id, user_id, type, description, created_at) VALUES
(1, NULL, 'criacao', 'Lead criado no sistema via importação de teste.', NOW() - INTERVAL 12 DAY),
(1, 3, 'whatsapp', 'Primeiro contato realizado via WhatsApp.', NOW() - INTERVAL 10 DAY),
(1, 3, 'status', 'Status alterado de "Novo" para "Em Negociação".', NOW() - INTERVAL 2 DAY),
(11, NULL, 'criacao', 'Lead criado no sistema via importação de teste.', NOW() - INTERVAL 40 DAY),
(11, 3, 'fechamento', 'Negócio fechado com sucesso!', NOW() - INTERVAL 3 DAY);

SET FOREIGN_KEY_CHECKS = 1;
