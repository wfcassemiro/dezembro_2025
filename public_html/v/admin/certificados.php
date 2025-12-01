<?php
session_start();

// Configurar timezone para Brasil (GMT-3)
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/certificate_generator_helper.php';

// Função simples de envio de email (integrada)
function sendCertificateEmailNotification($user_email, $user_name, $certificate_id, $lecture_title) {
    try {
        $subject = "Seu Certificado T101 - " . $lecture_title;
        
        $verification_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/verificar_certificado.php?id=" . $certificate_id;
        $view_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/view_certificate_files.php?id=" . $certificate_id;
        $download_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . $_SERVER['HTTP_HOST'] . "/download_certificate_files.php?id=" . $certificate_id;
        
        $message = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>Seu Certificado T101</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #8e44ad, #9b59b6); color: white; padding: 30px; text-align: center; border-radius: 10px; }
                .content { background: #f9f9f9; padding: 30px; border-radius: 10px; margin: 20px 0; }
                .button { display: inline-block; background: #8e44ad; color: white; padding: 12px 25px; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
                .footer { text-align: center; color: #666; font-size: 14px; margin-top: 30px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🎓 Certificado T101 Emitido!</h1>
                    <p>Parabéns pela conclusão da palestra</p>
                </div>
                
                <div class='content'>
                    <h2>Olá, " . htmlspecialchars($user_name) . "!</h2>
                    
                    <p>Seu certificado de participação foi gerado com sucesso no sistema T101!</p>
                    
                    <h3>📋 Detalhes do Certificado:</h3>
                    <ul>
                        <li><strong>Palestra:</strong> " . htmlspecialchars($lecture_title) . "</li>
                        <li><strong>Data de Emissão:</strong> " . date('d/m/Y H:i') . "</li>
                        <li><strong>ID do Certificado:</strong> " . htmlspecialchars($certificate_id) . "</li>
                    </ul>
                    
                    <h3>🔗 Acesse seu certificado:</h3>
                    <p style='text-align: center;'>
                        <a href='" . $view_url . "' class='button'>👁️ Visualizar Certificado</a>
                        <a href='" . $download_url . "' class='button'>📥 Baixar Certificado</a>
                    </p>
                    
                    <h3>🔍 Verificação de Autenticidade:</h3>
                    <p>Qualquer pessoa pode verificar a autenticidade deste certificado através do link:</p>
                    <p style='text-align: center;'>
                        <a href='" . $verification_url . "' class='button'>🛡️ Verificar Autenticidade</a>
                    </p>
                    
                    <p><small>Este link também está disponível via QR Code no certificado.</small></p>
                </div>
                
                <div class='footer'>
                    <p>© " . date('Y') . " Translators101 - Educação Continuada para Tradutores</p>
                    <p>Este é um email automático, não responda.</p>
                </div>
            </div>
        </body>
        </html>";
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: Translators101 <noreply@translators101.com>',
            'Reply-To: noreply@translators101.com',
            'X-Mailer: PHP/' . phpversion()
        ];
        
        $headers_string = implode("\r\n", $headers);
        
        // Tentar enviar email
        $sent = mail($user_email, $subject, $message, $headers_string);
        
        if ($sent) {
            error_log("Email T101 enviado com sucesso para: " . $user_email);
            return true;
        } else {
            error_log("Falha no envio de email T101 para: " . $user_email);
            return false;
        }
        
    } catch (Exception $e) {
        error_log("Erro no envio de email T101: " . $e->getMessage());
        return false;
    }
}

// Verificar se é admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Gerenciar Certificados - Admin';
$message = '';
$error = '';

// Função para gerar UUID
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Função para log de auditoria
function logCertificateAction($action, $certificate_id, $user_id, $lecture_id, $admin_id, $details = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO certificate_audit_log (action, certificate_id, target_user_id, lecture_id, admin_user_id, details, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$action, $certificate_id, $user_id, $lecture_id, $admin_id, $details]);
    } catch (Exception $e) {
        // Log silenciosamente em arquivo se não conseguir inserir no banco
        error_log("Certificate audit log error: " . $e->getMessage());
    }
}

// Função de log personalizada para o gerador
function writeToCustomLog($message) {
    $log_file = __DIR__ . '/../certificate_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] [ADMIN] $message\n", FILE_APPEND);
}

// Processar ações administrativas
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // GERAR CERTIFICADOS (ÚNICO OU MÚLTIPLOS)
    if (isset($_POST['generate_certificates'])) {
        $user_id = $_POST['user_id'] ?? '';
        $lecture_ids = $_POST['lecture_ids'] ?? [];
        $force_generate = isset($_POST['force_generate']) ? true : false;
        $send_email = isset($_POST['send_email']) ? true : false;
        
        if (empty($user_id) || empty($lecture_ids)) {
            $error = 'Usuário e pelo menos uma palestra são obrigatórios.';
        } else {
            try {
                // Verificar se usuário existe
                $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
                $stmt->execute([$user_id]);
                $user = $stmt->fetch();
                
                if (!$user) {
                    $error = 'Usuário não encontrado.';
                } else {
                    $generated_count = 0;
                    $skipped_count = 0;
                    $error_count = 0;
                    $email_sent_count = 0;
                    $generated_certificates = [];
                    
                    foreach ($lecture_ids as $lecture_id) {
                        try {
                            // Verificar se palestra existe
                            $stmt = $pdo->prepare("SELECT id, title, speaker, description, duration_minutes FROM lectures WHERE id = ?");
                            $stmt->execute([$lecture_id]);
                            $lecture = $stmt->fetch();
                            
                            if (!$lecture) {
                                $error_count++;
                                continue;
                            }
                            
                            // Verificar se já existe certificado
                            $stmt = $pdo->prepare("SELECT id FROM certificates WHERE user_id = ? AND lecture_id = ?");
                            $stmt->execute([$user_id, $lecture_id]);
                            $existing = $stmt->fetch();
                            
                            if ($existing && !$force_generate) {
                                $skipped_count++;
                                continue;
                            }
                            
                            // Deletar certificado existente se forçando
                            if ($existing && $force_generate) {
                                $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
                                $stmt->execute([$existing['id']]);
                                
                                // Deletar arquivo físico antigo
                                $old_file = __DIR__ . '/../certificates/certificate_' . $existing['id'] . '.png';
                                if (file_exists($old_file)) {
                                    unlink($old_file);
                                }
                                
                                logCertificateAction('DELETE_REPLACED', $existing['id'], $user_id, $lecture_id, $_SESSION['user_id'], 'Deletado para substituição');
                            }
                            
                            // Calcular duração em horas (usando a lógica da T101)
                            $duration_minutes = $lecture['duration_minutes'] ?? 0;
                            if ($duration_minutes <= 0.5 * 60) {
                                $duration_hours = 0.5;
                            } elseif ($duration_minutes <= 1.0 * 60) {
                                $duration_hours = 1.0;
                            } elseif ($duration_minutes <= 1.5 * 60) {
                                $duration_hours = 1.5;
                            } else {
                                $duration_hours = ceil($duration_minutes / 60 * 2) / 2;
                            }
                            
                            // Gerar novo certificado
                            $certificate_id = generateUUID();
                            $issued_at = date('Y-m-d H:i:s'); // Já com timezone correto
                            
                            $stmt = $pdo->prepare("
                                INSERT INTO certificates (id, user_id, lecture_id, user_name, lecture_title, speaker_name, duration_hours, issued_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            $stmt->execute([
                                $certificate_id, 
                                $user_id, 
                                $lecture_id, 
                                $user['name'], 
                                $lecture['title'], 
                                $lecture['speaker'], 
                                $duration_hours,
                                $issued_at
                            ]);
                            
                            // Gerar arquivo físico do certificado usando o sistema T101
                            $certificate_data = [
                                'user_name' => $user['name'],
                                'lecture_title' => $lecture['title'],
                                'speaker_name' => $lecture['speaker'],
                                'duration_minutes' => $duration_minutes
                            ];
                            
                            $physical_file_path = generateAndSaveCertificatePng(
                                $certificate_id,
                                $certificate_data,
                                'ADMIN_GENERATE',
                                'writeToCustomLog'
                            );
                            
                            if ($physical_file_path) {
                                $generated_certificates[] = [
                                    'id' => $certificate_id,
                                    'lecture_title' => $lecture['title'],
                                    'file' => basename($physical_file_path)
                                ];
                                
                                // Log da ação
                                logCertificateAction('GENERATE', $certificate_id, $user_id, $lecture_id, $_SESSION['user_id'], "Gerado para {$user['name']} - {$lecture['title']} - Arquivo: " . basename($physical_file_path));
                                
                                $generated_count++;
                                
                                // Enviar email se solicitado
                                if ($send_email) {
                                    $email_sent = sendCertificateEmailNotification($user['email'], $user['name'], $certificate_id, $lecture['title']);
                                    if ($email_sent) {
                                        $email_sent_count++;
                                        logCertificateAction('EMAIL_SENT', $certificate_id, $user_id, $lecture_id, $_SESSION['user_id'], "Email enviado para {$user['email']}");
                                    }
                                }
                            } else {
                                // Se falhou ao gerar arquivo, remover do banco
                                $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
                                $stmt->execute([$certificate_id]);
                                $error_count++;
                                writeToCustomLog("ERRO: Falha na geração do arquivo físico para certificado $certificate_id");
                            }
                            
                        } catch (Exception $e) {
                            error_log("Erro ao gerar certificado: " . $e->getMessage());
                            writeToCustomLog("ERRO: Exceção ao gerar certificado: " . $e->getMessage());
                            $error_count++;
                        }
                    }
                    
                    // Mensagem de resultado
                    $result_message = "✅ Processamento concluído: ";
                    $result_parts = [];
                    
                    if ($generated_count > 0) {
                        $result_parts[] = "{$generated_count} certificado(s) gerado(s)";
                    }
                    if ($skipped_count > 0) {
                        $result_parts[] = "{$skipped_count} ignorado(s) (já existiam)";
                    }
                    if ($error_count > 0) {
                        $result_parts[] = "{$error_count} erro(s)";
                    }
                    
                    $message = $result_message . implode(', ', $result_parts) . " para {$user['name']}";
                    
                    if ($send_email && $email_sent_count > 0) {
                        $message .= " 📧 {$email_sent_count} email(s) enviado(s) automaticamente.";
                    } elseif ($send_email && $email_sent_count == 0 && $generated_count > 0) {
                        $message .= " ⚠️ Certificados gerados mas emails não puderam ser enviados.";
                    }
                }
            } catch (Exception $e) {
                $error = 'Erro ao gerar certificados: ' . $e->getMessage();
            }
        }
    }
    
    // DELETAR CERTIFICADO
    elseif (isset($_POST['delete_certificate'])) {
        $certificate_id = $_POST['certificate_id'] ?? '';
        $confirm_delete = $_POST['confirm_delete'] ?? '';
        
        if (empty($certificate_id)) {
            $error = 'ID do certificado é obrigatório.';
        } elseif ($confirm_delete !== 'DELETE') {
            $error = 'Digite "DELETE" para confirmar a exclusão.';
        } else {
            try {
                // Buscar dados do certificado antes de deletar
                $stmt = $pdo->prepare("
                    SELECT c.*, u.name as user_name, l.title as lecture_title 
                    FROM certificates c 
                    LEFT JOIN users u ON c.user_id = u.id 
                    LEFT JOIN lectures l ON c.lecture_id = l.id 
                    WHERE c.id = ?
                ");
                $stmt->execute([$certificate_id]);
                $cert = $stmt->fetch();
                
                if (!$cert) {
                    $error = 'Certificado não encontrado.';
                } else {
                    // Deletar arquivo físico
                    $physical_file = __DIR__ . '/../certificates/certificate_' . $certificate_id . '.png';
                    if (file_exists($physical_file)) {
                        unlink($physical_file);
                    }
                    
                    // Deletar certificado do banco
                    $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
                    $stmt->execute([$certificate_id]);
                    
                    // Log da ação
                    logCertificateAction('DELETE', $certificate_id, $cert['user_id'], $cert['lecture_id'], $_SESSION['user_id'], "Deletado: {$cert['user_name']} - {$cert['lecture_title']}");
                    
                    $message = "🗑️ Certificado deletado com sucesso: {$cert['user_name']} - {$cert['lecture_title']}";
                }
            } catch (Exception $e) {
                $error = 'Erro ao deletar certificado: ' . $e->getMessage();
            }
        }
    }
    
    // REGENERAR CERTIFICADO
    elseif (isset($_POST['regenerate_certificate'])) {
        $certificate_id = $_POST['certificate_id'] ?? '';
        
        if (empty($certificate_id)) {
            $error = 'ID do certificado é obrigatório.';
        } else {
            try {
                // Buscar certificado com dados da palestra
                $stmt = $pdo->prepare("
                    SELECT c.*, u.name as user_name, l.title as lecture_title, l.speaker, l.duration_minutes
                    FROM certificates c 
                    LEFT JOIN users u ON c.user_id = u.id 
                    LEFT JOIN lectures l ON c.lecture_id = l.id 
                    WHERE c.id = ?
                ");
                $stmt->execute([$certificate_id]);
                $cert = $stmt->fetch();
                
                if (!$cert) {
                    $error = 'Certificado não encontrado.';
                } else {
                    // Atualizar data de emissão
                    $new_issued_at = date('Y-m-d H:i:s');
                    $stmt = $pdo->prepare("UPDATE certificates SET issued_at = ? WHERE id = ?");
                    $stmt->execute([$new_issued_at, $certificate_id]);
                    
                    // Regenerar arquivo físico usando sistema T101
                    $certificate_data = [
                        'user_name' => $cert['user_name'],
                        'lecture_title' => $cert['lecture_title'],
                        'speaker_name' => $cert['speaker'],
                        'duration_minutes' => $cert['duration_minutes']
                    ];
                    
                    $physical_file_path = generateAndSaveCertificatePng(
                        $certificate_id,
                        $certificate_data,
                        'ADMIN_REGENERATE',
                        'writeToCustomLog'
                    );
                    
                    if ($physical_file_path) {
                        // Log da ação
                        logCertificateAction('REGENERATE', $certificate_id, $cert['user_id'], $cert['lecture_id'], $_SESSION['user_id'], "Regenerado: {$cert['user_name']} - {$cert['lecture_title']} - Arquivo: " . basename($physical_file_path));
                        
                        $message = "🔄 Certificado regenerado com sucesso: {$cert['user_name']} - {$cert['lecture_title']}";
                    } else {
                        $error = 'Certificado atualizado no banco, mas erro ao gerar arquivo físico.';
                    }
                }
            } catch (Exception $e) {
                $error = 'Erro ao regenerar certificado: ' . $e->getMessage();
            }
        }
    }
}

// Função para extrair informações do padrão S##E##
function extractSeasonEpisode($title) {
    // Padrão: S## seguido opcionalmente por E##
    if (preg_match('/^S(\d{1,2})(?:E(\d{1,2}))?/i', $title, $matches)) {
        $season = (int)$matches[1];
        $episode = isset($matches[2]) ? (int)$matches[2] : 0;
        return ['season' => $season, 'episode' => $episode, 'has_pattern' => true];
    }
    return ['season' => 999, 'episode' => 999, 'has_pattern' => false];
}

// Função de comparação personalizada para ordenação
function sortLecturesForAdmin($lectures) {
    // Separar palestras com padrão S##E## das demais
    $withPattern = [];
    $withoutPattern = [];
    
    foreach ($lectures as $lecture) {
        $info = extractSeasonEpisode($lecture['title']);
        if ($info['has_pattern']) {
            $lecture['_sort_info'] = $info;
            $withPattern[] = $lecture;
        } else {
            $withoutPattern[] = $lecture;
        }
    }
    
    // Ordenar palestras com padrão S##E## (ordem decrescente)
    usort($withPattern, function($a, $b) {
        $infoA = $a['_sort_info'];
        $infoB = $b['_sort_info'];
        
        // Primeiro por season (decrescente)
        if ($infoA['season'] !== $infoB['season']) {
            return $infoB['season'] - $infoA['season'];
        }
        
        // Depois por episode (decrescente)
        return $infoB['episode'] - $infoA['episode'];
    });
    
    // Ordenar palestras sem padrão (alfabética)
    usort($withoutPattern, function($a, $b) {
        return strcasecmp($a['title'], $b['title']);
    });
    
    // Juntar as duas listas (com padrão primeiro, depois sem padrão)
    return array_merge($withPattern, $withoutPattern);
}

// Carregar dados para os formulários
try {
    // Buscar certificados com informações de tempo assistido
    $stmt = $pdo->query("
        SELECT c.*, 
               u.name as user_name, 
               u.email, 
               l.title as lecture_title, 
               al.accumulated_watch_time,
               al.last_watched_seconds
        FROM certificates c 
        LEFT JOIN users u ON c.user_id = u.id 
        LEFT JOIN lectures l ON c.lecture_id = l.id 
        LEFT JOIN access_logs al ON (c.user_id = al.user_id AND al.resource = l.title AND al.certificate_generated = 1)
        ORDER BY c.issued_at DESC 
        LIMIT 100
    ");
    $certificates = $stmt->fetchAll();
    
    // Buscar usuários para o formulário
    $stmt = $pdo->query("SELECT id, name, email, role FROM users WHERE role IN ('subscriber', 'admin') ORDER BY name");
    $users = $stmt->fetchAll();
    
    // Buscar palestras para o formulário (sem ordenação - será feita via PHP)
    $stmt = $pdo->query("SELECT id, title, speaker, description, duration_minutes FROM lectures");
    $all_lectures = $stmt->fetchAll();
    
    // Aplicar ordenação personalizada S##E## decrescente
    $lectures = sortLecturesForAdmin($all_lectures);
    
    // Estatísticas
    $stmt = $pdo->query("SELECT COUNT(*) FROM certificates");
    $total_certificates = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM certificates");
    $unique_users = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM certificates WHERE DATE(issued_at) = CURDATE()");
    $today_certificates = $stmt->fetchColumn();
    
} catch (PDOException $e) {
    $certificates = [];
    $users = [];
    $lectures = [];
    $total_certificates = 0;
    $unique_users = 0;
    $today_certificates = 0;
    $error = 'Erro ao carregar dados: ' . $e->getMessage();
}

include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-certificate"></i> Gerenciar Certificados</h1>
            <p>Sistema administrativo completo para certificados T101</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="success-alert">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error-alert">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="video-card glass-card">
        <h3><i class="fas fa-chart-bar"></i> Estatísticas</h3>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_certificates; ?></div>
                <div class="stat-label">Total de Certificados</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $unique_users; ?></div>
                <div class="stat-label">Usuários com Certificados</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $today_certificates; ?></div>
                <div class="stat-label">Emitidos Hoje</div>
            </div>
        </div>
    </div>

    <!-- Ações Administrativas -->
    <div class="video-card glass-card">
        <h2><i class="fas fa-tools"></i> Ações Administrativas</h2>
        
        <!-- Formulário Gerar Certificado -->
        <div class="admin-form-section">
            <h3><i class="fas fa-plus-circle"></i> Gerar Certificado(s) T101</h3>
            <form method="POST" class="admin-form">
                
                <!-- Botão de Gerar no Topo -->
                <div class="generate-section">
                    <button type="submit" name="generate_certificates" class="cta-btn generate-btn" id="generate_btn" disabled>
                        <i class="fas fa-certificate"></i> Selecione palestras para gerar certificados
                    </button>
                    <p class="generate-info">
                        <i class="fas fa-info-circle"></i> 
                        Email será enviado automaticamente após a geração
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="user_id">Selecionar Usuário *</label>
                    <select name="user_id" id="user_id" required class="form-control">
                        <option value="">Escolha um usuário...</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['name']); ?> 
                                (<?php echo htmlspecialchars($user['email']); ?>) 
                                [<?php echo $user['role']; ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group full-width">
                    <label for="lecture_ids">Selecionar Palestra(s) * - Ordenação S##E## Decrescente</label>
                    
                    <!-- Campo de Busca -->
                    <div class="search-section">
                        <div class="search-box">
                            <i class="fas fa-search"></i>
                            <input type="text" id="lecture_search" placeholder="Buscar palestras por título..." class="search-input">
                            <button type="button" id="clear_search" class="clear-search" title="Limpar busca">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Controles de seleção -->
                    <div class="select-controls">
                        <label class="checkbox-label">
                            <input type="checkbox" id="select_all_lectures"> 
                            Selecionar todas as palestras visíveis
                        </label>
                        <span class="lectures-count">
                            <span id="visible_count"><?php echo count($lectures); ?></span> de <?php echo count($lectures); ?> palestras
                        </span>
                    </div>

                    <!-- Grid de cards das palestras -->
                    <div class="lectures-cards-grid" id="lectures_grid">
                        <?php foreach ($lectures as $lecture): 
                            $seasonInfo = extractSeasonEpisode($lecture['title']);
                            $hasPattern = $seasonInfo['has_pattern'];
                        ?>
                            <div class="lecture-card" data-title="<?php echo strtolower(htmlspecialchars($lecture['title'])); ?>">
                                <div class="lecture-card-header">
                                    <input type="checkbox" name="lecture_ids[]" value="<?php echo $lecture['id']; ?>" 
                                           class="lecture-checkbox" id="lecture_<?php echo $lecture['id']; ?>">
                                    <label for="lecture_<?php echo $lecture['id']; ?>" class="lecture-card-label">
                                        <span class="lecture-title">
                                            <?php echo htmlspecialchars($lecture['title']); ?>
                                            
                                            <?php if ($hasPattern): ?>
                                                <span class="season-badge">
                                                    S<?php echo sprintf('%02d', $seasonInfo['season']); ?><?php echo $seasonInfo['episode'] > 0 ? 'E'.sprintf('%02d', $seasonInfo['episode']) : ''; ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (empty($lectures)): ?>
                        <div class="empty-lectures">
                            <i class="fas fa-exclamation-triangle"></i>
                            <p>Nenhuma palestra encontrada no sistema.</p>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Estado sem resultados de busca -->
                    <div class="no-results" id="no_results" style="display: none;">
                        <i class="fas fa-search"></i>
                        <p>Nenhuma palestra encontrada com este termo de busca.</p>
                    </div>
                </div>
                
                <div class="options-row">
                    <label class="checkbox-label">
                        <input type="checkbox" name="force_generate" value="1"> 
                        Forçar geração (substitui certificados existentes)
                    </label>
                    
                    <!-- Email automático (hidden) -->
                    <input type="hidden" name="send_email" value="1">
                </div>
            </form>
        </div>
    </div>

    <!-- Lista de Certificados -->
    <div class="video-card glass-card">
        <h2><i class="fas fa-list"></i> Certificados Existentes</h2>
        
        <?php if (empty($certificates)): ?>
            <div class="empty-state">
                <i class="fas fa-certificate"></i>
                <p>Nenhum certificado encontrado.</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table class="certificates-table">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Palestra</th>
                            <th>Data de Emissão</th>
                            <th>Duração</th>
                            <th>Status do Arquivo</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($certificates as $cert): ?>
                            <tr>
                                <td>
                                    <div class="user-info">
                                        <strong><?php echo htmlspecialchars($cert['user_name']); ?></strong>
                                        <br><small><?php echo htmlspecialchars($cert['email']); ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div class="lecture-info">
                                        <strong><?php echo htmlspecialchars($cert['lecture_title']); ?></strong>
                                    </div>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($cert['issued_at'])); ?></td>
                                <td class="duration-col">
                                    <?php echo $cert['duration_hours'] ?? '1.0'; ?>h
                                </td>
                                <td>
                                    <?php 
                                    $file_path = __DIR__ . '/../certificates/certificate_' . $cert['id'] . '.png';
                                    if (file_exists($file_path)): 
                                    ?>
                                        <span class="file-status exists">✅ Arquivo T101</span>
                                    <?php else: ?>
                                        <span class="file-status missing">❌ Arquivo Faltando</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="../view_certificate_files.php?id=<?php echo $cert['id']; ?>" 
                                           target="_blank" class="action-btn view-btn" title="Ver Certificado T101">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <a href="../download_certificate_files.php?id=<?php echo $cert['id']; ?>" 
                                           class="action-btn download-btn" title="Download T101">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        
                                        <a href="../verificar_certificado.php?id=<?php echo $cert['id']; ?>" 
                                           target="_blank" class="action-btn verify-btn" title="Verificar Autenticidade">
                                            <i class="fas fa-shield-check"></i>
                                        </a>
                                        
                                        <button type="button" 
                                                onclick="regenerateCertificate('<?php echo $cert['id']; ?>', '<?php echo addslashes($cert['user_name']); ?>')"
                                                class="action-btn regen-btn" title="Regenerar T101">
                                            <i class="fas fa-redo"></i>
                                        </button>
                                        
                                        <button type="button" 
                                                onclick="deleteCertificate('<?php echo $cert['id']; ?>', '<?php echo addslashes($cert['user_name']); ?>', '<?php echo addslashes($cert['lecture_title']); ?>')"
                                                class="action-btn delete-btn" title="Deletar">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal para Regenerar Certificado -->
<div id="regenerateModal" class="modal">
    <div class="modal-content glass-modal">
        <h3><i class="fas fa-redo"></i> Regenerar Certificado T101</h3>
        <p>Tem certeza que deseja regenerar o certificado para <strong id="regenUserName"></strong>?</p>
        <p><small>Isso irá atualizar a data de emissão e gerar um novo arquivo PNG com o design T101.</small></p>
        
        <form method="POST" id="regenerateForm">
            <input type="hidden" name="certificate_id" id="regenCertId">
            <div class="modal-actions">
                <button type="button" onclick="closeModal('regenerateModal')" class="modal-btn secondary">Cancelar</button>
                <button type="submit" name="regenerate_certificate" class="modal-btn primary">
                    <i class="fas fa-redo"></i> Regenerar T101
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para Deletar Certificado -->
<div id="deleteModal" class="modal">
    <div class="modal-content glass-modal">
        <h3><i class="fas fa-trash"></i> Deletar Certificado</h3>
        <p>Tem certeza que deseja deletar o certificado de:</p>
        <p><strong id="deleteUserName"></strong> - <strong id="deleteLectureName"></strong></p>
        <p class="warning-text"><strong>⚠️ Esta ação não pode ser desfeita!</strong></p>
        <p><small>O arquivo físico T101 também será removido permanentemente.</small></p>
        
        <form method="POST" id="deleteForm">
            <input type="hidden" name="certificate_id" id="deleteCertId">
            <div class="form-group">
                <label for="confirm_delete">Digite "DELETE" para confirmar:</label>
                <input type="text" name="confirm_delete" id="confirm_delete" placeholder="DELETE" class="form-control">
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closeModal('deleteModal')" class="modal-btn secondary">Cancelar</button>
                <button type="submit" name="delete_certificate" class="modal-btn danger">
                    <i class="fas fa-trash"></i> Deletar Definitivamente
                </button>
            </div>
        </form>
    </div>
</div>

<style>
/* Vision UI - Fundo escuro com texto branco */
.glass-card {
    background: rgba(25, 25, 25, 0.8) !important;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: white;
}

.glass-card h2, .glass-card h3 {
    color: white;
}

/* Alerts com fundo Vision */
.success-alert {
    background: rgba(16, 185, 129, 0.2);
    border: 1px solid rgba(16, 185, 129, 0.5);
    color: #10f981;
    padding: 15px 20px;
    border-radius: 12px;
    margin: 20px 0;
    font-weight: 500;
    backdrop-filter: blur(10px);
}

.error-alert {
    background: rgba(239, 68, 68, 0.2);
    border: 1px solid rgba(239, 68, 68, 0.5);
    color: #ff6b6b;
    padding: 15px 20px;
    border-radius: 12px;
    margin: 20px 0;
    font-weight: 500;
    backdrop-filter: blur(10px);
}

/* Estatísticas com Vision */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.stat-item {
    text-align: center;
    padding: 25px 20px;
    background: rgba(142, 68, 173, 0.2);
    border-radius: 15px;
    border: 1px solid rgba(142, 68, 173, 0.3);
    transition: transform 0.2s ease;
    backdrop-filter: blur(10px);
}

.stat-item:hover {
    transform: translateY(-2px);
    background: rgba(142, 68, 173, 0.3);
}

.stat-number {
    font-size: 2.8rem;
    font-weight: bold;
    color: #c084fc;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 0.95rem;
    color: rgba(255, 255, 255, 0.8);
    font-weight: 500;
}

/* Formulário com Vision */
.admin-form-section {
    margin: 30px 0;
    padding: 30px;
    background: rgba(30, 30, 30, 0.6);
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    backdrop-filter: blur(10px);
}

.admin-form-section h3 {
    color: #c084fc;
    margin-bottom: 25px;
}

.form-group.full-width {
    grid-column: 1 / -1;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: white;
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    font-size: 1rem;
    background: rgba(0, 0, 0, 0.3);
    color: white;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #c084fc;
    box-shadow: 0 0 0 3px rgba(192, 132, 252, 0.1);
    background: rgba(0, 0, 0, 0.5);
}

.form-control option {
    background: #1a1a1a;
    color: white;
}

/* Botão de gerar no topo */
.generate-section {
    text-align: center;
    margin-bottom: 30px;
    padding: 25px;
    background: rgba(192, 132, 252, 0.1);
    border: 1px solid rgba(192, 132, 252, 0.2);
    border-radius: 15px;
}

.generate-btn {
    font-size: 1.1rem;
    padding: 18px 35px;
    min-width: 300px;
    transition: all 0.3s ease;
}

.generate-btn:disabled {
    background: rgba(100, 100, 100, 0.3) !important;
    color: rgba(255, 255, 255, 0.5) !important;
    cursor: not-allowed;
    transform: none !important;
    box-shadow: none !important;
}

.generate-info {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.9rem;
    margin-top: 10px;
    margin-bottom: 0;
}

.generate-info i {
    color: #c084fc;
    margin-right: 8px;
}

/* Campo de busca */
.search-section {
    margin-bottom: 20px;
}

.search-box {
    position: relative;
    display: flex;
    align-items: center;
    background: rgba(0, 0, 0, 0.3);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
    padding: 0 15px;
    transition: all 0.3s ease;
}

.search-box:focus-within {
    border-color: #c084fc;
    box-shadow: 0 0 0 3px rgba(192, 132, 252, 0.1);
    background: rgba(0, 0, 0, 0.5);
}

.search-box i.fa-search {
    color: rgba(255, 255, 255, 0.5);
    margin-right: 12px;
}

.search-input {
    flex: 1;
    background: transparent;
    border: none;
    outline: none;
    color: white;
    font-size: 1rem;
    padding: 15px 0;
}

.search-input::placeholder {
    color: rgba(255, 255, 255, 0.5);
}

.clear-search {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.5);
    cursor: pointer;
    padding: 5px;
    border-radius: 5px;
    transition: all 0.2s ease;
    display: none;
}

.clear-search:hover {
    background: rgba(255, 255, 255, 0.1);
    color: white;
}

.clear-search.visible {
    display: block;
}

/* Estado sem resultados */
.no-results {
    text-align: center;
    padding: 40px 20px;
    color: rgba(255, 255, 255, 0.7);
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
}

.no-results i {
    font-size: 2.5rem;
    color: #f39c12;
    margin-bottom: 15px;
    display: block;
}

.no-results p {
    font-size: 1rem;
    margin: 0;
}

/* Controles de seleção de palestras */
.select-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px 20px;
    background: rgba(142, 68, 173, 0.2);
    border: 1px solid rgba(142, 68, 173, 0.3);
    border-radius: 10px;
    margin-bottom: 20px;
}

.lectures-count {
    color: #c084fc;
    font-size: 0.9rem;
    font-weight: 600;
}

/* Grid de cards das palestras */
.lectures-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 15px;
    max-height: 400px;
    overflow-y: auto;
    padding: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    background: rgba(0, 0, 0, 0.2);
}

/* Card individual da palestra */
.lecture-card {
    background: rgba(25, 25, 25, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    transition: all 0.3s ease;
    cursor: pointer;
    backdrop-filter: blur(10px);
}

.lecture-card:hover {
    background: rgba(40, 40, 40, 0.9);
    border-color: rgba(192, 132, 252, 0.3);
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(192, 132, 252, 0.1);
}

.lecture-card-header {
    padding: 15px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
}

.lecture-card input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #c084fc;
    margin-top: 2px;
    flex-shrink: 0;
}

.lecture-card-label {
    flex: 1;
    cursor: pointer;
    display: block;
}

.lecture-title {
    color: white;
    font-size: 0.95rem;
    font-weight: 500;
    line-height: 1.4;
    display: block;
}

/* Badge para padrão S##E## */
.season-badge {
    display: inline-block;
    background: rgba(52, 152, 219, 0.3);
    color: #3498db;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    margin-left: 8px;
    vertical-align: top;
}

/* Estado vazio */
.empty-lectures {
    text-align: center;
    padding: 40px 20px;
    color: rgba(255, 255, 255, 0.7);
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
}

.empty-lectures i {
    font-size: 2.5rem;
    color: #f39c12;
    margin-bottom: 15px;
    display: block;
}

.empty-lectures p {
    font-size: 1rem;
    margin: 0;
}

.options-row {
    display: flex;
    gap: 30px;
    margin-bottom: 25px;
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 0.95rem;
    color: white;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #c084fc;
}

/* Tabela com Vision */
.table-container {
    overflow-x: auto;
    margin-top: 25px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(0, 0, 0, 0.2);
}

.certificates-table {
    width: 100%;
    border-collapse: collapse;
}

.certificates-table th {
    background: rgba(142, 68, 173, 0.6);
    color: white;
    padding: 18px 15px;
    text-align: left;
    font-weight: 600;
    font-size: 0.95rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.certificates-table td {
    padding: 18px 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    vertical-align: middle;
}

.certificates-table tbody tr {
    background: rgba(0, 0, 0, 0.1);
    transition: background-color 0.2s ease;
}

.certificates-table tbody tr:nth-child(even) {
    background: rgba(255, 255, 255, 0.02);
}

.certificates-table tbody tr:hover {
    background: rgba(142, 68, 173, 0.1);
}

.user-info strong {
    color: white;
    font-weight: 600;
}

.user-info small {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.85rem;
}

.lecture-info strong {
    color: #c084fc;
    font-weight: 600;
    line-height: 1.4;
}

.duration-col {
    font-weight: 600;
    color: white;
    font-size: 0.95rem;
}

/* Status do arquivo */
.file-status {
    font-size: 0.85rem;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 12px;
}

.file-status.exists {
    color: #10b981;
    background: rgba(16, 185, 129, 0.1);
}

.file-status.missing {
    color: #ef4444;
    background: rgba(239, 68, 68, 0.1);
}

/* Botões de ação */
.action-buttons {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    justify-content: center;
}

.action-btn {
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    font-size: 0.9rem;
}

.view-btn {
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    color: white;
}

.view-btn:hover {
    background: linear-gradient(135deg, #1d4ed8, #1e40af);
    transform: translateY(-1px);
}

.download-btn {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.download-btn:hover {
    background: linear-gradient(135deg, #059669, #047857);
    transform: translateY(-1px);
}

.verify-btn {
    background: linear-gradient(135deg, #8b5cf6, #7c3aed);
    color: white;
}

.verify-btn:hover {
    background: linear-gradient(135deg, #7c3aed, #6d28d9);
    transform: translateY(-1px);
}

.regen-btn {
    background: linear-gradient(135deg, #f59e0b, #d97706);
    color: white;
}

.regen-btn:hover {
    background: linear-gradient(135deg, #d97706, #b45309);
    transform: translateY(-1px);
}

.delete-btn {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.delete-btn:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
    transform: translateY(-1px);
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: rgba(255, 255, 255, 0.7);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.3;
    color: #c084fc;
}

.empty-state p {
    font-size: 1.1rem;
    font-weight: 500;
}

/* Modais com Vision */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(8px);
}

.glass-modal {
    background: rgba(25, 25, 25, 0.95) !important;
    backdrop-filter: blur(20px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    color: white;
}

.modal-content {
    margin: 8% auto;
    padding: 35px;
    border-radius: 20px;
    width: 90%;
    max-width: 500px;
    position: relative;
    box-shadow: 0 25px 50px rgba(0, 0, 0, 0.5);
}

.modal-content h3 {
    color: #c084fc;
    margin-bottom: 20px;
    font-size: 1.4rem;
}

.warning-text {
    color: #ff6b6b;
    font-weight: 600;
    margin: 15px 0;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 15px;
    margin-top: 30px;
}

.modal-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 10px;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
}

.modal-btn.secondary {
    background: rgba(255, 255, 255, 0.1);
    color: rgba(255, 255, 255, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.modal-btn.secondary:hover {
    background: rgba(255, 255, 255, 0.2);
    color: white;
}

.modal-btn.primary {
    background: linear-gradient(135deg, #c084fc, #a855f7);
    color: white;
}

.modal-btn.primary:hover {
    background: linear-gradient(135deg, #a855f7, #9333ea);
}

.modal-btn.danger {
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
}

.modal-btn.danger:hover {
    background: linear-gradient(135deg, #dc2626, #b91c1c);
}

/* Responsivo */
@media (max-width: 768px) {
    .options-row {
        flex-direction: column;
        gap: 15px;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 5px;
    }
    
    .action-btn {
        width: 100%;
        height: 40px;
        justify-content: flex-start;
        padding-left: 15px;
    }
    
    .modal-content {
        margin: 20% auto;
        width: 95%;
        padding: 25px;
    }
    
    .modal-actions {
        flex-direction: column;
        gap: 10px;
    }
    
    .certificates-table {
        font-size: 0.9rem;
    }
    
    .certificates-table th,
    .certificates-table td {
        padding: 12px 8px;
    }
    
    /* Cards responsivos */
    .lectures-cards-grid {
        grid-template-columns: 1fr;
        max-height: 300px;
    }
    
    .select-controls {
        flex-direction: column;
        gap: 10px;
        text-align: center;
    }
    
    /* Botão de gerar responsivo */
    .generate-btn {
        font-size: 1rem;
        padding: 15px 25px;
        min-width: 250px;
    }
    
    /* Campo de busca responsivo */
    .search-box {
        padding: 0 12px;
    }
    
    .search-input {
        padding: 12px 0;
        font-size: 0.95rem;
    }
}

@media (max-width: 480px) {
    .admin-form-section {
        padding: 20px;
        margin: 20px 0;
    }
    
    .stat-number {
        font-size: 2.2rem;
    }
}
</style>

<script>
// Função para atualizar contador do botão
function updateGenerateButton() {
    const selectedCheckboxes = document.querySelectorAll('.lecture-checkbox:checked');
    const generateBtn = document.getElementById('generate_btn');
    const count = selectedCheckboxes.length;
    
    if (count > 0) {
        generateBtn.disabled = false;
        generateBtn.innerHTML = `<i class="fas fa-certificate"></i> Gerar ${count} certificado${count > 1 ? 's' : ''}`;
    } else {
        generateBtn.disabled = true;
        generateBtn.innerHTML = `<i class="fas fa-certificate"></i> Selecione palestras para gerar certificados`;
    }
}

// Função para atualizar contadores
function updateCounters() {
    const visibleCards = document.querySelectorAll('.lecture-card:not([style*="display: none"])');
    const visibleCount = visibleCards.length;
    const totalCount = document.querySelectorAll('.lecture-card').length;
    
    document.getElementById('visible_count').textContent = visibleCount;
    
    // Atualizar texto do select all
    const selectAllLabel = document.querySelector('label[for="select_all_lectures"]');
    selectAllLabel.innerHTML = `
        <input type="checkbox" id="select_all_lectures"> 
        Selecionar todas as palestras ${visibleCount < totalCount ? 'visíveis' : ''}
    `;
}

// Funcionalidade de busca
document.getElementById('lecture_search').addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase().trim();
    const cards = document.querySelectorAll('.lecture-card');
    const noResults = document.getElementById('no_results');
    const clearBtn = document.getElementById('clear_search');
    let visibleCount = 0;
    
    // Mostrar/ocultar botão limpar
    clearBtn.classList.toggle('visible', searchTerm.length > 0);
    
    cards.forEach(card => {
        const title = card.dataset.title;
        const isVisible = searchTerm === '' || title.includes(searchTerm);
        
        card.style.display = isVisible ? 'block' : 'none';
        if (isVisible) visibleCount++;
    });
    
    // Mostrar/ocultar estado "sem resultados"
    noResults.style.display = visibleCount === 0 && searchTerm !== '' ? 'block' : 'none';
    
    // Atualizar contadores
    updateCounters();
    
    // Resetar select all quando busca
    document.getElementById('select_all_lectures').checked = false;
});

// Limpar busca
document.getElementById('clear_search').addEventListener('click', function() {
    const searchInput = document.getElementById('lecture_search');
    searchInput.value = '';
    searchInput.dispatchEvent(new Event('input'));
    searchInput.focus();
});

// Controle de seleção múltipla de palestras (apenas visíveis)
document.getElementById('select_all_lectures').addEventListener('change', function() {
    const visibleCheckboxes = document.querySelectorAll('.lecture-card:not([style*="display: none"]) .lecture-checkbox');
    visibleCheckboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
    updateGenerateButton();
});

// Se qualquer checkbox individual for desmarcado, desmarcar o "Selecionar todas"
document.querySelectorAll('.lecture-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const visibleCheckboxes = document.querySelectorAll('.lecture-card:not([style*="display: none"]) .lecture-checkbox');
        const allVisibleChecked = Array.from(visibleCheckboxes).every(cb => cb.checked);
        const anyVisibleChecked = Array.from(visibleCheckboxes).some(cb => cb.checked);
        
        document.getElementById('select_all_lectures').checked = allVisibleChecked && anyVisibleChecked;
        updateGenerateButton();
    });
});

function regenerateCertificate(certId, userName) {
    document.getElementById('regenCertId').value = certId;
    document.getElementById('regenUserName').textContent = userName;
    document.getElementById('regenerateModal').style.display = 'block';
}

function deleteCertificate(certId, userName, lectureName) {
    document.getElementById('deleteCertId').value = certId;
    document.getElementById('deleteUserName').textContent = userName;
    document.getElementById('deleteLectureName').textContent = lectureName;
    document.getElementById('confirm_delete').value = '';
    document.getElementById('deleteModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Fechar modal ao clicar fora
window.onclick = function(event) {
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    });
}

// Validação do formulário de delete
document.getElementById('deleteForm').addEventListener('submit', function(e) {
    const confirmText = document.getElementById('confirm_delete').value;
    if (confirmText !== 'DELETE') {
        e.preventDefault();
        alert('Digite "DELETE" para confirmar a exclusão.');
        return false;
    }
});

// Validação do formulário de geração
document.querySelector('form[method="POST"]').addEventListener('submit', function(e) {
    if (e.submitter && e.submitter.name === 'generate_certificates') {
        const selectedLectures = document.querySelectorAll('.lecture-checkbox:checked');
        if (selectedLectures.length === 0) {
            e.preventDefault();
            alert('Selecione pelo menos uma palestra para gerar o certificado.');
            return false;
        }
        
        // Confirmação para múltiplos certificados
        if (selectedLectures.length > 5) {
            const userName = document.querySelector('#user_id option:checked').textContent;
            if (!confirm(`Tem certeza que deseja gerar ${selectedLectures.length} certificados para ${userName}?\n\nOs emails serão enviados automaticamente.`)) {
                e.preventDefault();
                return false;
            }
        }
    }
});

// Melhorar UX dos selects e inicialização
document.addEventListener('DOMContentLoaded', function() {
    const userSelect = document.getElementById('user_id');
    
    if (userSelect && userSelect.options.length <= 1) {
        userSelect.innerHTML = '<option value="">Nenhum usuário encontrado</option>';
        userSelect.disabled = true;
    }
    
    const lectureCheckboxes = document.querySelectorAll('.lecture-checkbox');
    if (lectureCheckboxes.length === 0) {
        document.querySelector('.lectures-cards-grid').innerHTML = '<p style="color: rgba(255,255,255,0.7); padding: 20px; text-align: center;">Nenhuma palestra encontrada</p>';
    }
    
    // Inicializar contadores
    updateCounters();
    updateGenerateButton();
    
    // Adicionar interatividade aos cards (clique fora do checkbox)
    document.querySelectorAll('.lecture-card').forEach(card => {
        card.addEventListener('click', function(e) {
            // Não fazer nada se clicaram no checkbox ou label
            if (e.target.type === 'checkbox' || e.target.tagName === 'LABEL' || e.target.closest('label')) {
                return;
            }
            
            const checkbox = this.querySelector('input[type="checkbox"]');
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                checkbox.dispatchEvent(new Event('change'));
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>