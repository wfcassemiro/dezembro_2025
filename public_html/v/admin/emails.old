<?php
session_start();

date_default_timezone_set('America/Sao_Paulo');

// Adicionar as classes do PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP; 

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Verificar se é admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Sistema de E-mails - Admin';
$message = '';
$error = '';
$debug_output = ''; // Variável mantida mas não será preenchida

// Processar envio de email
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'send_email') {
        $recipient_type = $_POST['recipient_type'];
        $subject = trim($_POST['subject']);
        $message_body = trim($_POST['message']);
        $access_link = trim($_POST['access_link'] ?? '');
        $lecture_id = $_POST['lecture_id'] ?? null;
        $selected_users = $_POST['selected_users'] ?? [];
        $external_emails = trim($_POST['external_emails'] ?? '');
        
        if (empty($subject) || empty($message_body)) {
            $error = 'Assunto e mensagem são obrigatórios.';
        } else {
            try {
                $recipients = [];
                
                // Opção 1: Usuários selecionados individualmente
                if ($recipient_type === 'selected' && !empty($selected_users)) {
                    $placeholders = str_repeat('?,', count($selected_users) - 1) . '?';
                    $stmt = $pdo->prepare("
                        SELECT email, name FROM users 
                        WHERE id IN ($placeholders) AND is_active = 1
                    ");
                    $stmt->execute($selected_users);
                    $recipients = $stmt->fetchAll();
                    
                // Opção 2: Grupos predefinidos
                } elseif ($recipient_type === 'all') {
                    $stmt = $pdo->query("SELECT email, name FROM users WHERE is_active = 1");
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'subscribers') {
                    // Assinantes: is_subscriber = 1 OU role = 'subscriber' OU subscription_expires > NOW()
                    $stmt = $pdo->query("
                        SELECT email, name FROM users 
                        WHERE is_active = 1 
                        AND (
                            is_subscriber = 1 
                            OR role = 'subscriber' 
                            OR (subscription_expires IS NOT NULL AND subscription_expires > NOW())
                        )
                    ");
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'non_subscribers') {
                    // Não assinantes
                    $stmt = $pdo->query("
                        SELECT email, name FROM users 
                        WHERE is_active = 1 
                        AND (is_subscriber = 0 OR is_subscriber IS NULL)
                        AND role != 'subscriber'
                        AND (subscription_expires IS NULL OR subscription_expires <= NOW())
                    ");
                    $recipients = $stmt->fetchAll();
                }
                
                // Adicionar emails externos (não cadastrados)
                if (!empty($external_emails)) {
                    $external_list = array_map('trim', explode(',', $external_emails));
                    foreach ($external_list as $ext_email) {
                        if (filter_var($ext_email, FILTER_VALIDATE_EMAIL)) {
                            $recipients[] = [
                                'email' => $ext_email,
                                'name' => 'Destinatário' 
                            ];
                        }
                    }
                }
                
                if (empty($recipients)) {
                    $error = 'Nenhum destinatário encontrado para esta seleção.';
                } else {
                    // Verificar se PHPMailer está disponível
                    $phpmailer_available = class_exists(PHPMailer::class);
                    
                    if ($phpmailer_available && isEmailConfigured()) {
                        
                        $sent_count = 0;
                        $failed_count = 0;
                        
                        // Desabilita a exibição de erros de SMTP na tela para produção
                        ini_set('display_errors', 0);
                        error_reporting(0);

                        foreach ($recipients as $recipient) {
                            try {
                                $mail = new PHPMailer(true);
                                
                                // REMOÇÃO DO DEBUG:
                                // $mail->SMTPDebug = SMTP::DEBUG_SERVER; 
                                // $mail->Debugoutput = function($str, $level) use (&$debug_output) { 
                                //     $debug_output .= "[DEBUG LEVEL: $level] " . htmlspecialchars($str) . " <br>"; 
                                // };

                                // Configurações do servidor 
                                $mail->isSMTP();
                                $mail->Host = SMTP_HOST;
                                $mail->SMTPAuth = true;
                                $mail->Username = SMTP_USERNAME;
                                $mail->Password = SMTP_PASSWORD;
                                $mail->SMTPSecure = SMTP_SECURE;
                                $mail->Port = SMTP_PORT;
                                $mail->CharSet = 'UTF-8';
                                $mail->Timeout = 10; // Timeout mantido para evitar 503

                                // Destinatário
                                $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
                                $mail->addAddress($recipient['email'], $recipient['name']);
                                
                                // Conteúdo
                                $mail->isHTML(true);
                                $mail->Subject = $subject;
                                
                                $recipient_name = $recipient['name'] === 'Destinatário' ? 'Prezado(a)' : $recipient['name'];
                                $personalized_message = str_replace('[NOME]', $recipient_name, $message_body);
                                
                                if (!empty($access_link)) {
                                    $personalized_message = str_replace('[LINK]', $access_link, $personalized_message);
                                }
                                
                                $mail->Body = nl2br($personalized_message);
                                $mail->AltBody = strip_tags($personalized_message);
                                
                                $mail->send();
                                $sent_count++;
                                
                            } catch (Exception $e) {
                                $failed_count++;
                                // Loga o erro detalhado do PHPMailer no servidor, sem exibir na tela
                                $error_detail = "Erro ao enviar email para {$recipient['email']}. Detalhe: " . $mail->ErrorInfo;
                                error_log($error_detail);

                                // Se houver falha, apenas define uma mensagem genérica de erro
                                $error = "Falha no envio de um ou mais e-mails. Verifique os logs do servidor.";
                            }
                        }
                        
                        if (empty($error)) {
                           $message = "E-mails enviados: {$sent_count} sucesso, {$failed_count} falhas.";
                        }
                        $log_status = 'sent';
                        
                    } else {
                        // Modo de simulação (se PHPMailer não estiver disponível ou config não estiver OK)
                        $sent_count = count($recipients);
                        $message = "⚠️ MODO SIMULAÇÃO: {$sent_count} e-mails seriam enviados. ";
                        if (!$phpmailer_available) {
                             $message .= "O autoloader do PHPMailer (vendor/autoload.php) não foi encontrado ou falhou ao carregar.";
                        } elseif (!isEmailConfigured()) {
                             $message .= "As credenciais de e-mail não estão configuradas (isEmailConfigured() = false).";
                        } else {
                            $message .= "Verifique a configuração do PHPMailer para envio real.";
                        }
                        $log_status = 'pending';
                    }
                    
                    // Determinar tipo de destinatário para o log
                    $log_recipient_type = $recipient_type;
                    if (!empty($external_emails)) {
                        $log_recipient_type = ($recipient_type === 'selected' || empty($recipients)) ? 'selected' : $recipient_type;
                    }
                    
                    // Log do envio
                    $stmt = $pdo->prepare("
                        INSERT INTO email_logs 
                        (subject, message, recipient_count, recipient_type, sent_by, status, lecture_id, access_link, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $subject, 
                        $message_body, 
                        $sent_count, 
                        $log_recipient_type, 
                        $_SESSION['user_id'], 
                        $log_status,
                        $lecture_id,
                        $access_link
                    ]);
                }
                
            } catch (PDOException $e) {
                $error = 'Erro ao processar e-mail: ' . $e->getMessage();
            }
        }
    }
}

// Buscar logs de email
try {
    $stmt = $pdo->query("
        SELECT el.*, u.name as sender_name 
        FROM email_logs el 
        LEFT JOIN users u ON el.sent_by = u.id 
        ORDER BY el.created_at DESC 
        LIMIT 50
    ");
    $email_logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $email_logs = [];
}

// Estatísticas de usuários
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
    $total_users = $stmt->fetchColumn();
    
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM users 
        WHERE is_active = 1 
        AND (
            is_subscriber = 1 
            OR role = 'subscriber' 
            OR (subscription_expires IS NOT NULL AND subscription_expires > NOW())
        )
    ");
    $subscribers = $stmt->fetchColumn();
    
    $non_subscribers = $total_users - $subscribers;
} catch (PDOException $e) {
    $total_users = 0;
    $subscribers = 0;
    $non_subscribers = 0;
}

// Buscar próxima palestra agendada
try {
    $stmt = $pdo->query("
        SELECT * FROM upcoming_announcements 
        WHERE announcement_date >= CURDATE() 
        AND is_active = 1
        ORDER BY announcement_date ASC, lecture_time ASC 
        LIMIT 1
    ");
    $next_lecture = $stmt->fetch();
} catch (PDOException $e) {
    $next_lecture = null;
}

include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<div class="main-content">

    <div class="video-card profile-header-card" style="background: linear-gradient(135deg, var(--brand-purple), #4a148c); border: none; margin-bottom: 24px; height: auto; display: flex; flex-direction: row; align-items: center; justify-content: space-between; padding: 5px 5px; margin-left: 5px; position: relative; z-index: 1;">
        
        <div style="display: flex; align-items: center;">
            <div class="header-icon-container" style="background: rgba(255, 255, 255, 0.1); border-radius: 50%; width: 60px; height: 60px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                <i class="fas fa-envelope-open-text" style="font-size: 24px; color: #fff;"></i>
            </div>
            <div class="header-text-container" style="margin-left: 20px; align-items: center;">
                <h2 style="margin: 0 0 5px 0; padding: 0; font-size: 1.5rem; color: #fff; font-weight: 600; border: none;"> Sistema de E-mails</h2>
                <p style="margin: 0; color: rgba(255, 255, 255, 0.8); font-size: 1rem;">Comunicação com usuários da plataforma</p>
            </div>
        </div>
    </div>

    <div class="report-nav-buttons" style="display: flex; gap: 10px; align-items: center;">
        <a href="index.php" class="vision-btn vision-btn-secondary">
            <i class="fas fa-arrow-left"></i>
            <span>Voltar ao Dash-T101</span>
        </a>
        <a href="?clear=1" class="vision-btn vision-btn-secondary" onclick="return confirm('Limpar este orçamento e começar um novo?')">
            <i class="fas fa-envelope-open-text"></i>
            <span>Novo email</span>
        </a>
    </div>





    <?php if ($message): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert-error" style="background: rgba(239, 68, 68, 0.2); border: 2px solid #ef4444; padding: 15px; border-radius: 10px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>ERRO DE ENVIO:</strong>
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($debug_output)): ?>
        <div class="alert-warning" style="background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); padding: 15px; border-radius: 10px; margin-bottom: 20px; font-family: monospace; font-size: 0.8rem; overflow-x: auto;">
            <i class="fas fa-terminal"></i>
            <strong>LOG DE DEBUG SMTP:</strong>
            <hr style="border-color: rgba(255, 255, 255, 0.2); margin: 10px 0;">
            <p style="white-space: pre-wrap; word-wrap: break-word;">
                <?php echo $debug_output; // Estará vazio, mas mantemos o código para não quebrar a lógica ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (!isEmailConfigured()): ?>
        <div class="alert-warning" style="background: rgba(255, 193, 7, 0.1); border: 1px solid rgba(255, 193, 7, 0.3); padding: 15px; border-radius: 10px; margin-bottom: 20px;">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Atenção:</strong> PHPMailer não está configurado. Os emails serão apenas simulados. 
            Configure as credenciais SMTP em <code>/config/email_config.php</code> para envio real.
        </div>
    <?php endif; ?>

    <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
        <div class="video-card stats-card">
            <div class="stats-content">
                <div class="stats-info">
                    <h3>Total de Usuários</h3>
                    <span class="stats-number"><?php echo number_format($total_users); ?></span>
                </div>
                <div class="stats-icon stats-icon-blue">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>

        <div class="video-card stats-card">
            <div class="stats-content">
                <div class="stats-info">
                    <h3>Assinantes</h3>
                    <span class="stats-number"><?php echo number_format($subscribers); ?></span>
                </div>
                <div class="stats-icon stats-icon-green">
                    <i class="fas fa-star"></i>
                </div>
            </div>
        </div>

        <div class="video-card stats-card">
            <div class="stats-content">
                <div class="stats-info">
                    <h3>Não Assinantes</h3>
                    <span class="stats-number"><?php echo number_format($non_subscribers); ?></span>
                </div>
                <div class="stats-icon stats-icon-red">
                    <i class="fas fa-user-times"></i>
                </div>
            </div>
        </div>

        <div class="video-card stats-card">
            <div class="stats-content">
                <div class="stats-info">
                    <h3>E-mails Enviados</h3>
                    <span class="stats-number"><?php echo count($email_logs); ?></span>
                </div>
                <div class="stats-icon stats-icon-purple">
                    <i class="fas fa-paper-plane"></i>
                </div>
            </div>
        </div>
    </div>

    <?php if ($next_lecture): ?>
    <div class="video-card" style="background: linear-gradient(135deg, rgba(123, 97, 255, 0.1), rgba(72, 61, 139, 0.1)); border: 2px solid rgba(123, 97, 255, 0.3); margin-bottom: 30px;">
        <h2><i class="fas fa-calendar-check"></i> Próxima Palestra Agendada</h2>
        <div style="padding: 20px; background: rgba(0,0,0,0.2); border-radius: 10px; margin-top: 15px;">
            <div style="display: grid; grid-template-columns: auto 1fr; gap: 20px; align-items: center;">
                <?php if ($next_lecture['image_path']): ?>
                <img src="<?php echo htmlspecialchars($next_lecture['image_path']); ?>" 
                     style="width: 200px; height: 112px; object-fit: cover; border-radius: 8px;" 
                     alt="Imagem da palestra">
                <?php endif; ?>
                <div>
                    <h3 style="color: #FFD700; font-size: 1.3rem; margin-bottom: 10px;">
                        <?php echo htmlspecialchars($next_lecture['title']); ?>
                    </h3>
                    <p style="margin: 5px 0;"><strong>Palestrante:</strong> <?php echo htmlspecialchars($next_lecture['speaker']); ?></p>
                    <p style="margin: 5px 0;"><strong>Data:</strong> <?php echo date('d/m/Y', strtotime($next_lecture['announcement_date'])); ?></p>
                    <p style="margin: 5px 0;"><strong>Horário:</strong> <?php echo date('H:i', strtotime($next_lecture['lecture_time'])); ?>h</p>
                    <?php if ($next_lecture['description']): ?>
                    <p style="margin-top: 10px; color: #ccc;"><?php echo htmlspecialchars($next_lecture['description']); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <button onclick="useNextLectureTemplate()" class="cta-btn" style="margin-top: 20px;">
                <i class="fas fa-magic"></i> Usar template de convite para esta palestra
            </button>
        </div>
    </div>
    <?php endif; ?>

    <div class="video-card">
        <h2><i class="fas fa-paper-plane"></i> Enviar e-mail</h2>
        
        <form method="POST" class="vision-form">
            <input type="hidden" name="action" value="send_email">
            <input type="hidden" name="lecture_id" id="lecture_id" value="">
            
            <div class="form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="recipient_type">
                        <i class="fas fa-users"></i> Destinatários *
                    </label>
                    <select id="recipient_type" name="recipient_type" required onchange="toggleRecipientOptions()">
                        <option value="all">Todos os usuários (<?php echo $total_users; ?>)</option>
                        <option value="subscribers">Apenas assinantes (<?php echo $subscribers; ?>)</option>
                        <option value="non_subscribers">Não assinantes (<?php echo $non_subscribers; ?>)</option>
                        <option value="selected">Selecionar usuários individualmente</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="subject">
                        <i class="fas fa-heading"></i> Assunto *
                    </label>
                    <input type="text" id="subject" name="subject" required>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label for="access_link">
                        <i class="fas fa-link"></i> Link de acesso (Opcional)
                    </label>
                    <input type="url" id="access_link" name="access_link" 
                           placeholder="https://zoom.us/j/... ou link da plataforma">
                    <small style="color: #999; display: block; margin-top: 5px;">
                        Use [LINK] no corpo da mensagem para inserir este link automaticamente
                    </small>
                </div>

                <div class="form-group" style="grid-column: span 2; display: none;" id="user_selection_container">
                    <label>
                        <i class="fas fa-user-check"></i> Selecione os usuários
                        <button type="button" onclick="selectAllUsers(true)" class="page-btn" style="margin-left: 10px; padding: 5px 10px; font-size: 0.85rem;">
                            <i class="fas fa-check-double"></i> Marcar todos
                        </button>
                        <button type="button" onclick="selectAllUsers(false)" class="page-btn" style="margin-left: 5px; padding: 5px 10px; font-size: 0.85rem;">
                            <i class="fas fa-times"></i> Desmarcar todos
                        </button>
                    </label>
                    <div id="users_list" style="max-height: 300px; overflow-y: auto; background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px; margin-top: 10px;">
                        <?php
                        try {
                            $stmt = $pdo->query("
                                SELECT id, name, email, 
                                       CASE 
                                           WHEN is_subscriber = 1 OR role = 'subscriber' THEN 'Assinante'
                                           ELSE 'Não assinante'
                                       END as user_type
                                FROM users 
                                WHERE is_active = 1 
                                ORDER BY name ASC
                            ");
                            $all_users = $stmt->fetchAll();
                            
                            foreach ($all_users as $user) {
                                $badge_color = $user['user_type'] === 'Assinante' ? '#10b981' : '#6b7280';
                                echo '<div style="padding: 8px; border-bottom: 1px solid rgba(255,255,255,0.1);">';
                                echo '<label style="display: flex; align-items: center; cursor: pointer;">';
                                echo '<input type="checkbox" name="selected_users[]" value="' . htmlspecialchars($user['id']) . '" style="margin-right: 10px;">';
                                echo '<span style="flex: 1;">' . htmlspecialchars($user['name']) . ' <small style="color: #999;">(' . htmlspecialchars($user['email']) . ')</small></span>';
                                echo '<span style="background: ' . $badge_color . '; color: white; padding: 3px 8px; border-radius: 12px; font-size: 0.75rem;">' . $user['user_type'] . '</span>';
                                echo '</label>';
                                echo '</div>';
                            }
                        } catch (PDOException $e) {
                            echo '<p style="color: #ef4444;">Erro ao carregar usuários</p>';
                        }
                        ?>
                    </div>
                    <small style="color: #999; display: block; margin-top: 10px;">
                        <strong>Total de usuários disponíveis:</strong> <?php echo count($all_users ?? []); ?>
                    </small>
                </div>

                <div class="form-group" style="grid-column: span 2;">
                    <label for="external_emails">
                        <i class="fas fa-envelope-open-text"></i> Emails externos (Opcional)
                    </label>
                    <textarea id="external_emails" name="external_emails" rows="3"
                              style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.05); color: #fff; font-family: monospace;"
                              placeholder="email1@exemplo.com, email2@exemplo.com, email3@exemplo.com"></textarea>
                    <small style="color: #999; display: block; margin-top: 5px;">
                        <strong>Separe múltiplos emails por vírgula.</strong> Estes emails receberão a mensagem mesmo que não sejam usuários cadastrados.
                    </small>
                </div>

                <div class="form-group form-group-wide" style="grid-column: span 2;">
                    <label for="message">
                        <i class="fas fa-edit"></i> Mensagem *
                    </label>
                    <textarea id="message" name="message" rows="10" required 
                              placeholder="Digite sua mensagem aqui... Use [NOME] para personalizar com o nome do destinatário"></textarea>
                    <small style="color: #999; display: block; margin-top: 5px;">
                        <strong>Dicas:</strong> Use [NOME] para inserir o nome do destinatário | Use [LINK] para inserir o link de acesso
                    </small>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="cta-btn" onclick="return validateAndConfirm()">
                    <i class="fas fa-paper-plane"></i> Enviar e-mail
                </button>
            </div>
        </form>
    </div>

    <div class="video-card">
        <div class="card-header">
            <h2><i class="fas fa-history"></i> Histórico de envios</h2>
        </div>

        <?php if (empty($email_logs)): ?>
            <div class="alert-warning">
                <i class="fas fa-info-circle"></i>
                Nenhum e-mail enviado ainda.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><i class="fas fa-calendar"></i> Data</th>
                            <th><i class="fas fa-heading"></i> Assunto</th>
                            <th><i class="fas fa-users"></i> Destinatários</th>
                            <th><i class="fas fa-toggle-on"></i> Status</th>
                            <th><i class="fas fa-user"></i> Enviado por</th>
                            <th><i class="fas fa-eye"></i> Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($email_logs as $log): ?>
                            <tr>
                                <td><?php echo date('d/m/Y H:i', strtotime($log['created_at'])); ?></td>
                                <td>
                                    <span class="text-primary"><?php echo htmlspecialchars($log['subject']); ?></span>
                                </td>
                                <td><?php echo number_format($log['recipient_count']); ?></td>
                                <td>
                                    <?php 
                                    $status_class = $log['status'] === 'sent' ? 'status-completed' : 
                                                   ($log['status'] === 'pending' ? 'status-pending' : 'status-cancelled');
                                    $status_text = $log['status'] === 'sent' ? 'Enviado' : 
                                                  ($log['status'] === 'pending' ? 'Simulado' : 'Falhou');
                                    ?>
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['sender_name'] ?? 'Usuário removido'); ?></td>
                                <td>
                                    <button class="page-btn" onclick="showEmailContent(<?php echo htmlspecialchars(json_encode([
                                        'subject' => $log['subject'],
                                        'message' => $log['message'],
                                        'access_link' => $log['access_link']
                                    ])); ?>)" title="Ver Conteúdo">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="video-card">
        <h2><i class="fas fa-file-alt"></i> Templates sugeridos</h2>
        
        <div class="quick-actions-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
            <div class="quick-action-card" onclick="useTemplate('welcome')" style="cursor: pointer;">
                <div class="quick-action-icon quick-action-icon-blue">
                    <i class="fas fa-hand-wave"></i>
                </div>
                <h3>Boas-vindas</h3>
                <p>Para novos usuários</p>
            </div>

            <div class="quick-action-card" onclick="useTemplate('newsletter')" style="cursor: pointer;">
                <div class="quick-action-icon quick-action-icon-purple">
                    <i class="fas fa-newspaper"></i>
                </div>
                <h3>Newsletter</h3>
                <p>Novidades da plataforma</p>
            </div>

            <div class="quick-action-card" onclick="useTemplate('promotion')" style="cursor: pointer;">
                <div class="quick-action-icon quick-action-icon-green">
                    <i class="fas fa-percentage"></i>
                </div>
                <h3>Promoção</h3>
                <p>Ofertas especiais</p>
            </div>

            <div class="quick-action-card" onclick="useTemplate('reminder')" style="cursor: pointer;">
                <div class="quick-action-icon quick-action-icon-red">
                    <i class="fas fa-bell"></i>
                </div>
                <h3>Lembrete</h3>
                <p>Informações importantes</p>
            </div>
        </div>
    </div>
</div>

<script>
// Controlar exibição da seleção de usuários
function toggleRecipientOptions() {
    const recipientType = document.getElementById('recipient_type').value;
    const userSelectionContainer = document.getElementById('user_selection_container');
    
    if (recipientType === 'selected') {
        userSelectionContainer.style.display = 'block';
    } else {
        userSelectionContainer.style.display = 'none';
    }
}

// Marcar/desmarcar todos os usuários
function selectAllUsers(select) {
    const checkboxes = document.querySelectorAll('input[name="selected_users[]"]');
    checkboxes.forEach(checkbox => {
        checkbox.checked = select;
    });
}

// Validar e confirmar envio
function validateAndConfirm() {
    const recipientType = document.getElementById('recipient_type').value;
    const externalEmails = document.getElementById('external_emails').value.trim();
    
    // Se for seleção individual, verificar se há usuários selecionados
    if (recipientType === 'selected') {
        const selectedUsers = document.querySelectorAll('input[name="selected_users[]"]:checked');
        if (selectedUsers.length === 0 && externalEmails === '') {
            alert('Por favor, selecione pelo menos um usuário ou adicione emails externos.');
            return false;
        }
        
        let message = 'Você está prestes a enviar email para:\n\n';
        if (selectedUsers.length > 0) {
            message += '• ' + selectedUsers.length + ' usuário(s) selecionado(s)\n';
        }
        if (externalEmails !== '') {
            const emailCount = externalEmails.split(',').length;
            message += '• ' + emailCount + ' email(s) externo(s)\n';
        }
        message += '\nDeseja continuar?';
        
        return confirm(message);
    }
    
    // Para grupos predefinidos
    let message = 'Tem certeza que deseja enviar este e-mail?';
    if (externalEmails !== '') {
        const emailCount = externalEmails.split(',').length;
        message = 'Você está prestes a enviar para o grupo selecionado + ' + emailCount + ' email(s) externo(s).\n\nDeseja continuar?';
    }
    
    return confirm(message);
}

function showEmailContent(data) {
    let content = 'Assunto: ' + data.subject + '\n\n';
    content += 'Mensagem:\n' + data.message;
    if (data.access_link) {
        content += '\n\nLink de Acesso: ' + data.access_link;
    }
    alert(content);
}

function useTemplate(type) {
    const templates = {
        welcome: {
            subject: 'Bem-vindo(a) à Translators101!',
            message: 'Olá [NOME],\n\nSeja bem-vindo(a) à nossa plataforma educacional para profissionais de tradução!\n\nAqui você encontrará palestras exclusivas, glossários especializados e muito conteúdo para aprimorar suas habilidades profissionais.\n\nComece explorando nossa videoteca e não perca nenhuma novidade!\n\nEquipe Translators101'
        },
        newsletter: {
            subject: 'Translators101 - Novidades da Semana',
            message: 'Olá [NOME],\n\nConfira as principais novidades desta semana:\n\n• Nova palestra adicionada: [Título]\n• Glossário atualizado: [Área]\n• Certificados disponíveis para download\n\nAcesse nossa plataforma e aproveite todo o conteúdo!\n\nEquipe Translators101'
        },
        promotion: {
            subject: 'Oferta Especial - Translators101',
            message: 'Olá [NOME],\n\nTemos uma oferta especial para você!\n\n[Detalhes da promoção]\n\nEsta oferta é válida por tempo limitado. Não perca!\n\nAcesse nossa plataforma e aproveite.\n\nEquipe Translators101'
        },
        reminder: {
            subject: 'Lembrete Importante - Translators101',
            message: 'Olá [NOME],\n\nEste é um lembrete importante sobre:\n\n[Conteúdo do lembrete]\n\nPara mais informações, acesse nossa plataforma ou entre em contato conosco.\n\nEquipe Translators101'
        }
    };
    
    if (templates[type]) {
        document.getElementById('subject').value = templates[type].subject;
        document.getElementById('message').value = templates[type].message;
        document.getElementById('access_link').value = '';
        document.getElementById('lecture_id').value = '';
    }
}

function useNextLectureTemplate() {
    <?php if ($next_lecture): ?>
    const template = {
        subject: 'Convite: <?php echo addslashes($next_lecture['title']); ?>',
        message: `Olá [NOME],

Temos o prazer de convidá-lo(a) para a nossa próxima palestra:

📌 Título: <?php echo addslashes($next_lecture['title']); ?>
👤 Palestrante: <?php echo addslashes($next_lecture['speaker']); ?>
📅 Data: <?php echo date('d/m/Y', strtotime($next_lecture['announcement_date'])); ?>
🕐 Horário: <?php echo date('H:i', strtotime($next_lecture['lecture_time'])); ?>h

<?php if ($next_lecture['description']): ?>
📝 Sobre a palestra:
<?php echo addslashes($next_lecture['description']); ?>
<?php endif; ?>

🔗 Link de acesso: [LINK]

Não perca esta oportunidade de aprendizado!

Equipe Translators101`,
        lecture_id: '<?php echo $next_lecture['id']; ?>'
    };
    
    document.getElementById('subject').value = template.subject;
    document.getElementById('message').value = template.message;
    document.getElementById('lecture_id').value = template.lecture_id;
    document.getElementById('access_link').focus();
    
    // Scroll para o formulário
    document.querySelector('.vision-form').scrollIntoView({ behavior: 'smooth', block: 'start' });
    <?php endif; ?>
}
</script>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stats-card {
    padding: 20px;
}

.stats-content {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stats-info h3 {
    font-size: 0.9rem;
    color: #999;
    margin-bottom: 10px;
}

.stats-number {
    font-size: 2rem;
    font-weight: bold;
    color: #fff;
}

.stats-icon {
    font-size: 2.5rem;
    opacity: 0.3;
}

.stats-icon-blue { color: #3b82f6; }
.stats-icon-green { color: #10b981; }
.stats-icon-red { color: #ef4444; }
.stats-icon-purple { color: #8b5cf6; }

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    margin-bottom: 8px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-group input,
.form-group select,
.form-group textarea {
    padding: 10px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.05);
    color: #fff;
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.quick-action-card {
    padding: 30px 20px;
    text-align: center;
    background: rgba(255,255,255,0.03);
    border-radius: 12px;
    transition: all 0.3s ease;
    border: 1px solid rgba(255,255,255,0.1);
}

.quick-action-card:hover {
    transform: translateY(-5px);
    background: rgba(255,255,255,0.05);
    border-color: rgba(255,255,255,0.3);
}

.quick-action-icon {
    font-size: 2.5rem;
    margin-bottom: 15px;
}

.quick-action-icon-blue { color: #3b82f6; }
.quick-action-icon-purple { color: #8b5cf6; }
.quick-action-icon-green { color: #10b981; }
.quick-action-icon-red { color: #ef4444; }

.alert-warning {
    background: rgba(255, 193, 7, 0.1);
    border: 1px solid rgba(255, 193, 7, 0.3);
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
    color: #ffd700;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
}

.vision-btn {
    background: var(--brand-purple);
    color: white;
    border: 1px solid var(--brand-purple);
    border-radius: 20px;
    padding: 12px 24px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    transition: all 0.3s ease;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
}

.vision-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
}

.vision-btn-secondary {
    background: rgba(255, 255, 255, 0.1);
    color: var(--text-primary);
    border-color: rgba(255, 255, 255, 0.2);
}

.report-nav-buttons {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
}

.profile-header-card {
    display: flex;
    align-items: center !important;
    justify-content: center !important;
}

</style>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>