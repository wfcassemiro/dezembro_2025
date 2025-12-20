<?php
session_start();

// Configurar timezone para Brasil (GMT-3)
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/certificate_generator_helper.php';

// ============================================
// CORREÇÃO: Incluir sistema de email PHPMailer
// ============================================
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/../config/email.php';

/**
 * Função de envio de email de certificado - CORRIGIDA
 * Usa PHPMailer com SMTP ao invés de mail() nativo
 */
function sendCertificateEmailNotification($user_email, $user_name, $certificate_id, $lecture_title) {
    try {
        // Verificar se o sistema de email está configurado
        if (!isEmailConfigured()) {
            error_log("[Certificados] Sistema de email não configurado. Verifique email_config.php");
            return false;
        }
        
        // Log de tentativa
        error_log("[Certificados] Enviando email de certificado para: $user_email");
        
        // Usar a função centralizada sendCertificateEmail do email.php
        $result = sendCertificateEmail($user_email, $user_name, $certificate_id, $lecture_title);
        
        if ($result) {
            error_log("[Certificados] ✓ Email enviado com sucesso para: $user_email");
            return true;
        } else {
            error_log("[Certificados] ✗ Falha ao enviar email para: $user_email");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("[Certificados] Erro no envio de email: " . $e->getMessage());
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
                    $email_failed_count = 0;
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
                                    } else {
                                        $email_failed_count++;
                                        logCertificateAction('EMAIL_FAILED', $certificate_id, $user_id, $lecture_id, $_SESSION['user_id'], "Falha ao enviar email para {$user['email']}");
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
                    $result_message = "📊 Processamento concluído: ";
                    $result_parts = [];
                    
                    if ($generated_count > 0) {
                        $result_parts[] = "✅ {$generated_count} certificado(s) gerado(s)";
                    }
                    if ($skipped_count > 0) {
                        $result_parts[] = "⏭️ {$skipped_count} ignorado(s) (já existiam)";
                    }
                    if ($error_count > 0) {
                        $result_parts[] = "❌ {$error_count} erro(s)";
                    }
                    
                    $message = $result_message . implode(', ', $result_parts) . " para {$user['name']}";
                    
                    if ($send_email) {
                        if ($email_sent_count > 0 && $email_failed_count == 0) {
                            $message .= " | 📧 {$email_sent_count} email(s) enviado(s) com sucesso!";
                        } elseif ($email_sent_count > 0 && $email_failed_count > 0) {
                            $message .= " | 📧 {$email_sent_count} enviado(s), ⚠️ {$email_failed_count} falha(s)";
                        } elseif ($email_failed_count > 0) {
                            $message .= " | ⚠️ Certificados gerados mas {$email_failed_count} email(s) não puderam ser enviados.";
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'Erro ao gerar certificados: ' . $e->getMessage();
            }
        }
    }
    
    // ---- IMPORTAÇÃO DE CSV PARA GERAÇÃO EM LOTE ----
    elseif (isset($_POST['import_csv'])) {
        $csv_date = $_POST['csv_date'] ?? '';
        $lecture_title_manual = trim($_POST['lecture_title_manual'] ?? '');
        $speaker_name_manual = trim($_POST['speaker_name_manual'] ?? '');
        $duration_minutes_manual = intval($_POST['duration_minutes_manual'] ?? 60);
        $selected_emails = $_POST['participant_emails'] ?? [];
        
        if (empty($csv_date)) {
            $error = 'Data do CSV é obrigatória.';
        } elseif (empty($lecture_title_manual) || empty($speaker_name_manual)) {
            $error = 'Título da palestra e nome do palestrante são obrigatórios.';
        } elseif (empty($selected_emails)) {
            $error = 'Nenhum participante selecionado.';
        } else {
            try {
                // Criar lecture_id único para a live
                $live_lecture_id = 'live-' . $csv_date;
                
                // Verificar/criar registro na tabela lectures
                $stmtCheckLecture = $pdo->prepare("SELECT id FROM lectures WHERE id = ?");
                $stmtCheckLecture->execute([$live_lecture_id]);
                $existingLecture = $stmtCheckLecture->fetch();
                
                if (!$existingLecture) {
                    $insertLectureSql = "INSERT INTO lectures (id, title, speaker, duration_minutes, description, created_at) 
                                        VALUES (?, ?, ?, ?, ?, NOW())
                                        ON DUPLICATE KEY UPDATE title = VALUES(title), speaker = VALUES(speaker)";
                    $stmtInsertLecture = $pdo->prepare($insertLectureSql);
                    $stmtInsertLecture->execute([
                        $live_lecture_id,
                        $lecture_title_manual,
                        $speaker_name_manual,
                        $duration_minutes_manual,
                        'Transmissão ao vivo - ' . date('d/m/Y', strtotime($csv_date))
                    ]);
                }
                
                // Calcular duração em horas
                $duration_hours = $duration_minutes_manual / 60;
                if ($duration_hours <= 0.5) {
                    $duration_hours = 0.5;
                } elseif ($duration_hours <= 1.0) {
                    $duration_hours = 1.0;
                } elseif ($duration_hours <= 1.5) {
                    $duration_hours = 1.5;
                } else {
                    $duration_hours = ceil($duration_hours * 2) / 2;
                }
                
                // Gerar certificados APENAS para emails selecionados
                $generated_count = 0;
                $skipped_count = 0;
                $error_count = 0;
                $email_sent_count = 0;
                $email_failed_count = 0;
                
                foreach ($selected_emails as $participant_email) {
                    try {
                        // Buscar usuário por email
                        $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE email = ?");
                        $stmt->execute([$participant_email]);
                        $user = $stmt->fetch();
                        
                        if (!$user) {
                            writeToCustomLog("AVISO CSV: Usuário não encontrado - Email: {$participant_email}");
                            $error_count++;
                            continue;
                        }
                        
                        // Verificar se já existe certificado
                        $stmt = $pdo->prepare("SELECT id FROM certificates WHERE user_id = ? AND lecture_id = ?");
                        $stmt->execute([$user['id'], $live_lecture_id]);
                        $existing = $stmt->fetch();
                        
                        if ($existing) {
                            $skipped_count++;
                            continue;
                        }
                        
                        // Gerar certificado
                        $certificate_id = generateUUID();
                        $issued_at = date('Y-m-d H:i:s');
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO certificates (id, user_id, lecture_id, user_name, lecture_title, speaker_name, duration_hours, issued_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $certificate_id,
                            $user['id'],
                            $live_lecture_id,
                            $user['name'],
                            $lecture_title_manual,
                            $speaker_name_manual,
                            $duration_hours,
                            $issued_at
                        ]);
                        
                        // Gerar arquivo físico
                        $certificate_data = [
                            'user_name' => $user['name'],
                            'lecture_title' => $lecture_title_manual,
                            'speaker_name' => $speaker_name_manual,
                            'duration_minutes' => $duration_minutes_manual
                        ];
                        
                        $physical_file_path = generateAndSaveCertificatePng(
                            $certificate_id,
                            $certificate_data,
                            'CSV_IMPORT',
                            'writeToCustomLog'
                        );
                        
                        if ($physical_file_path) {
                            logCertificateAction('GENERATE_CSV', $certificate_id, $user['id'], $live_lecture_id, $_SESSION['user_id'], "Gerado via CSV: {$user['name']} - {$lecture_title_manual}");
                            
                            $generated_count++;
                            
                            // Enviar email
                            $email_sent = sendCertificateEmailNotification($user['email'], $user['name'], $certificate_id, $lecture_title_manual);
                            if ($email_sent) {
                                $email_sent_count++;
                            } else {
                                $email_failed_count++;
                            }
                        } else {
                            $stmt = $pdo->prepare("DELETE FROM certificates WHERE id = ?");
                            $stmt->execute([$certificate_id]);
                            $error_count++;
                        }
                        
                    } catch (Exception $e) {
                        writeToCustomLog("ERRO CSV: {$participant_email} - " . $e->getMessage());
                        $error_count++;
                    }
                }
                
                // Mensagem de resultado
                $message = "✅ Importação CSV concluída: {$generated_count} certificado(s) gerado(s)";
                if ($skipped_count > 0) {
                    $message .= ", {$skipped_count} já existiam";
                }
                if ($error_count > 0) {
                    $message .= ", {$error_count} erro(s)";
                }
                $message .= " - Palestra: {$lecture_title_manual}";
                
                if ($email_sent_count > 0) {
                    $message .= " | 📧 {$email_sent_count} email(s) enviado(s)";
                }
                if ($email_failed_count > 0) {
                    $message .= " | ⚠️ {$email_failed_count} email(s) falharam";
                }
                
            } catch (Exception $e) {
                $error = 'Erro ao processar CSV: ' . $e->getMessage();
                writeToCustomLog("ERRO ao processar CSV: " . $e->getMessage());
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
                    
                    $message = "🗑️ Certificado deletado: {$cert['user_name']} - {$cert['lecture_title']}";
                }
            } catch (Exception $e) {
                $error = 'Erro ao deletar certificado: ' . $e->getMessage();
            }
        }
    }
    
    // REGERAR CERTIFICADO
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
                    
                    // Regerar arquivo físico usando sistema T101
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
                        logCertificateAction('REGENERATE', $certificate_id, $cert['user_id'], $cert['lecture_id'], $_SESSION['user_id'], "Regerado: {$cert['user_name']} - {$cert['lecture_title']} - Arquivo: " . basename($physical_file_path));
                        
                        $message = "🔄 Certificado regerado: {$cert['user_name']} - {$cert['lecture_title']}";
                    } else {
                        $error = 'Certificado atualizado no banco, mas erro ao gerar arquivo físico.';
                    }
                }
            } catch (Exception $e) {
                $error = 'Erro ao regerar certificado: ' . $e->getMessage();
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
                <div class="stat-label">Total de certificados</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $unique_users; ?></div>
                <div class="stat-label">Usuários com certificados</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $today_certificates; ?></div>
                <div class="stat-label">Emitidos hoje</div>
            </div>
        </div>
    </div>

    <!-- Ações Administrativas -->
    <div class="video-card glass-card">
        <h2><i class="fas fa-tools"></i> Ações administrativas</h2>
        
        <!-- Formulário Gerar Certificado -->
        <div class="admin-form-section">
            <h3><i class="fas fa-plus-circle"></i> Gerar certificados T101</h3>
            <form method="POST" class="admin-form">
                
                <!-- Botão de Gerar no Topo -->
                <div class="generate-section">
                    <button type="submit" name="generate_certificates" class="cta-btn generate-btn" id="generate_btn" disabled>
                        <i class="fas fa-certificate"></i> Selecione as palestras para gerar certificados
                    </button>
                    <p class="generate-info">
                        <i class="fas fa-info-circle"></i> 
                        Um e-mail será enviado automaticamente após a geração
                    </p>
                </div>
                
                <div class="form-group">
                    <label for="user_id">Selecionar usuário *</label>
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
                    <label for="lecture_ids">Selecionar palestras * - Ordenação S##E## Decrescente</label>
                    
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
