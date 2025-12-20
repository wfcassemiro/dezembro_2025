<?php
session_start();

// Configurar timezone para Brasil (GMT-3)
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/certificate_generator_helper.php';

// CORREÇÃO: Incluir o sistema de email corrigido
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/email.php';

/**
 * Função de envio de email de certificado - CORRIGIDA
 * Usa a classe EmailSender com suporte a SMTP
 */
function sendCertificateEmailNotification($user_email, $user_name, $certificate_id, $lecture_title) {
    try {
        // Verificar se o email está configurado
        if (!isEmailConfigured()) {
            error_log("[Certificados] Sistema de email não configurado. Verifique email_config.php");
            return false;
        }
        
        // Log de tentativa
        error_log("[Certificados] Tentando enviar email de certificado para: $user_email");
        
        // Usar a função centralizada de envio de email de certificado
        $result = sendCertificateEmail($user_email, $user_name, $certificate_id, $lecture_title);
        
        if ($result) {
            error_log("[Certificados] Email T101 enviado com sucesso para: $user_email");
            return true;
        } else {
            error_log("[Certificados] Falha no envio de email T101 para: $user_email");
            return false;
        }
        
    } catch (Exception $e) {
        error_log("[Certificados] Erro no envio de email T101: " . $e->getMessage());
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
                            
                            // Calcular duração em horas
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
                            $issued_at = date('Y-m-d H:i:s');
                            
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
                            
                            // Gerar arquivo físico do certificado
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
                                
                                // CORREÇÃO: Enviar email usando o sistema corrigido
                                if ($send_email) {
                                    $email_sent = sendCertificateEmailNotification(
                                        $user['email'], 
                                        $user['name'], 
                                        $certificate_id, 
                                        $lecture['title']
                                    );
                                    
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
                    
                    // Mensagem de resultado detalhada
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
                    
                    $message = "📊 Processamento concluído para {$user['name']}: " . implode(', ', $result_parts);
                    
                    // Status do envio de email
                    if ($send_email) {
                        if ($email_sent_count > 0 && $email_failed_count == 0) {
                            $message .= " | 📧 {$email_sent_count} email(s) enviado(s) com sucesso!";
                        } elseif ($email_sent_count > 0 && $email_failed_count > 0) {
                            $message .= " | 📧 {$email_sent_count} email(s) enviado(s), ⚠️ {$email_failed_count} falha(s)";
                        } elseif ($email_failed_count > 0) {
                            $message .= " | ⚠️ Certificados gerados mas {$email_failed_count} email(s) não puderam ser enviados. Verifique as configurações SMTP.";
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'Erro ao gerar certificados: ' . $e->getMessage();
            }
        }
    }
}

// ============================================
// O RESTO DO ARQUIVO PERMANECE IGUAL
// (Copiar do arquivo original a partir daqui)
// ============================================

// Aqui continua o restante do código original do certificados.php
// incluindo a importação de CSV, listagem de certificados, etc.
// Este arquivo deve ser mesclado com o original mantendo apenas
// a função sendCertificateEmailNotification() corrigida acima.

?>

<!-- 
    INSTRUÇÕES DE INTEGRAÇÃO:
    
    1. Substitua a função sendCertificateEmailNotification() no seu certificados.php original
       pela versão corrigida acima.
    
    2. Adicione os requires no início do arquivo:
       require_once __DIR__ . '/email_config.php';
       require_once __DIR__ . '/email.php';
    
    3. O restante do arquivo permanece igual.
-->