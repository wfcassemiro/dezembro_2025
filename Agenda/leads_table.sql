-- =====================================================
-- Script SQL para criar a tabela de Leads
-- Translators101 - Captura de Leads da Agenda
-- =====================================================

-- Criar tabela de leads (se não existir)
CREATE TABLE IF NOT EXISTS `leads` (
    `id` VARCHAR(36) NOT NULL,
    `nome` VARCHAR(255) NOT NULL COMMENT 'Nome completo do lead',
    `email` VARCHAR(255) NOT NULL COMMENT 'Email do lead',
    `whatsapp` VARCHAR(20) NOT NULL COMMENT 'Número do WhatsApp',
    `fonte` VARCHAR(100) DEFAULT 'agenda_palestras' COMMENT 'Origem do cadastro',
    `sorteado` TINYINT(1) DEFAULT 0 COMMENT 'Indica se já foi sorteado',
    `data_sorteio` DATETIME DEFAULT NULL COMMENT 'Data em que foi sorteado',
    `acesso_concedido` TINYINT(1) DEFAULT 0 COMMENT 'Se o acesso foi concedido',
    `acesso_inicio` DATE DEFAULT NULL COMMENT 'Data de início do acesso',
    `acesso_fim` DATE DEFAULT NULL COMMENT 'Data de fim do acesso',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Data de cadastro',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Última atualização',
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_email` (`email`),
    KEY `idx_fonte` (`fonte`),
    KEY `idx_sorteado` (`sorteado`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabela de leads capturados na página de agenda';

-- Índice para busca por WhatsApp
ALTER TABLE `leads` ADD INDEX `idx_whatsapp` (`whatsapp`);

-- =====================================================
-- Consultas úteis para gerenciamento de leads
-- =====================================================

-- Listar todos os leads ordenados por data de cadastro
-- SELECT * FROM leads ORDER BY created_at DESC;

-- Contar total de leads por fonte
-- SELECT fonte, COUNT(*) as total FROM leads GROUP BY fonte;

-- Buscar leads que ainda não foram sorteados
-- SELECT * FROM leads WHERE sorteado = 0 ORDER BY created_at ASC;

-- Selecionar um lead aleatório para sorteio mensal
-- SELECT * FROM leads WHERE sorteado = 0 ORDER BY RAND() LIMIT 1;

-- Marcar lead como sorteado e conceder acesso de 15 dias
-- UPDATE leads 
-- SET sorteado = 1, 
--     data_sorteio = NOW(), 
--     acesso_concedido = 1, 
--     acesso_inicio = CURDATE(), 
--     acesso_fim = DATE_ADD(CURDATE(), INTERVAL 15 DAY) 
-- WHERE id = 'ID_DO_LEAD';

-- Listar leads com acesso ativo
-- SELECT * FROM leads WHERE acesso_concedido = 1 AND acesso_fim >= CURDATE();

-- =====================================================
-- FIM DO SCRIPT
-- =====================================================