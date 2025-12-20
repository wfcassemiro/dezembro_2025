<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// CORREÇÃO: Usar o sistema de email corrigido
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/../config/email.php';

// Verificar se é admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Sistema de E-mails - Admin';
$message = '';
$error = '';

// Processar envio de email
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Envio de email (modo normal ou seleção individual)
    if ($action === 'send_email') {
        $recipient_type = $_POST['recipient_type'] ?? 'all';
        $selected_users = $_POST['selected_users'] ?? [];
        $subject = trim($_POST['subject'] ?? '');
        $message_body = trim($_POST['message'] ?? '');
        $access_link = trim($_POST['access_link'] ?? '');
        $lecture_id = $_POST['lecture_id'] ?? null;
        
        if (empty($subject) || empty($message_body)) {
            $error = 'Assunto e mensagem são obrigatórios.';
        } else {
            try {
                // Buscar destinatários baseado no tipo de seleção
                if ($recipient_type === 'selected' && !empty($selected_users)) {
                    // Usuários selecionados individualmente
                    $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
                    $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE id IN ($placeholders) AND is_active = 1");
                    $stmt->execute($selected_users);
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'all') {
                    $stmt = $pdo->query("SELECT id, email, name FROM users WHERE is_active = 1");
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'subscribers') {
                    $stmt = $pdo->query("
                        SELECT id, email, name FROM users 
                        WHERE is_active = 1 
                        AND (
                            is_subscriber = 1 
                            OR role = 'subscriber' 
                            OR (subscription_expires IS NOT NULL AND subscription_expires > NOW())
                        )
                    ");
                    $recipients = $stmt->fetchAll();
                } else {
                    $stmt = $pdo->query("
                        SELECT id, email, name FROM users 
                        WHERE is_active = 1 
                        AND (is_subscriber = 0 OR is_subscriber IS NULL)
                        AND role != 'subscriber'
                        AND (subscription_expires IS NULL OR subscription_expires <= NOW())
                    ");
                    $recipients = $stmt->fetchAll();
                }
                
                if (empty($recipients)) {
                    $error = 'Nenhum destinatário encontrado para esta seleção.';
                } else {
                    // Verificar se o email está configurado
                    if (isEmailConfigured()) {
                        $emailSender = new EmailSender();
                        
                        $sent_count = 0;
                        $failed_count = 0;
                        $failed_emails = [];
                        
                        foreach ($recipients as $recipient) {
                            try {
                                // Personalizar mensagem
                                $personalized_message = str_replace('[NOME]', $recipient['name'], $message_body);
                                if (!empty($access_link)) {
                                    $personalized_message = str_replace('[LINK]', $access_link, $personalized_message);
                                }
                                
                                // Criar HTML do email
                                $html_content = EmailTemplates::getCustomEmailTemplate($subject, nl2br(htmlspecialchars($personalized_message)));
                                
                                // Enviar email
                                $result = $emailSender->sendEmail(
                                    $recipient['email'],
                                    $recipient['name'],
                                    $subject,
                                    $html_content
                                );
                                
                                if ($result) {
                                    $sent_count++;
                                } else {
                                    $failed_count++;
                                    $failed_emails[] = $recipient['email'];
                                }
                                
                                usleep(100000); // 100ms de pausa
                                
                            } catch (Exception $e) {
                                $failed_count++;
                                $failed_emails[] = $recipient['email'];
                                error_log("[Emails] Erro ao enviar para {$recipient['email']}: " . $e->getMessage());
                            }
                        }
                        
                        if ($sent_count > 0 && $failed_count == 0) {
                            $message = "✅ Todos os {$sent_count} e-mail(s) foram enviados com sucesso!";
                        } elseif ($sent_count > 0 && $failed_count > 0) {
                            $message = "📧 {$sent_count} e-mail(s) enviado(s), ⚠️ {$failed_count} falha(s).";
                            if (count($failed_emails) <= 5) {
                                $error = "Falhas: " . implode(', ', $failed_emails);
                            }
                        } else {
                            $error = "❌ Não foi possível enviar os e-mails. Verifique as configurações SMTP.";
                        }
                        
                        $log_status = ($sent_count > 0) ? 'sent' : 'failed';
                        
                    } else {
                        $error = "⚠️ Sistema de email não configurado. Configure as credenciais SMTP em email_config.php";
                        $log_status = 'config_error';
                        $sent_count = 0;
                    }
                    
                    // Log do envio
                    try {
                        $stmt = $pdo->prepare("
                            INSERT INTO email_logs 
                            (subject, message, recipient_count, recipient_type, sent_by, status, lecture_id, access_link, created_at) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $subject, 
                            $message_body, 
                            count($recipients), 
                            $recipient_type, 
                            $_SESSION['user_id'], 
                            $log_status,
                            $lecture_id,
                            $access_link
                        ]);
                    } catch (Exception $e) {
                        error_log("[Emails] Erro ao salvar log: " . $e->getMessage());
                    }
                }
            } catch (Exception $e) {
                $error = 'Erro ao processar envio: ' . $e->getMessage();
            }
        }
    }
    
    // Teste de configuração de email
    if ($action === 'test_email') {
        $test_email = trim($_POST['test_email'] ?? '');
        
        if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Por favor, informe um email válido para teste.';
        } else {
            if (isEmailConfigured()) {
                try {
                    $emailSender = new EmailSender();
                    $html_content = EmailTemplates::getCustomEmailTemplate(
                        'Teste de Email - Translators101',
                        '<h2>🎉 Teste bem-sucedido!</h2>
                        <p>Se você está lendo este email, significa que o sistema de envio está funcionando corretamente.</p>
                        <p><strong>Configurações detectadas:</strong></p>
                        <ul>
                            <li>Host SMTP: ' . SMTP_HOST . '</li>
                            <li>Porta: ' . SMTP_PORT . '</li>
                            <li>Remetente: ' . SMTP_FROM_EMAIL . '</li>
                        </ul>
                        <p>Data/Hora do teste: ' . date('d/m/Y H:i:s') . '</p>'
                    );
                    
                    $result = $emailSender->sendEmail(
                        $test_email,
                        'Administrador',
                        '🧪 Teste de Email - Translators101',
                        $html_content
                    );
                    
                    if ($result) {
                        $message = "✅ Email de teste enviado com sucesso para: {$test_email}";
                    } else {
                        $error = "❌ Falha ao enviar email de teste. Verifique os logs do servidor.";
                    }
                } catch (Exception $e) {
                    $error = "❌ Erro no teste: " . $e->getMessage();
                }
            } else {
                $error = "⚠️ Sistema de email não configurado. Configure as credenciais SMTP primeiro.";
            }
        }
    }
}

// Inicializar variáveis com valores padrão
$total_users = 0;
$total_subscribers = 0;
$total_sent = 0;
$recent_emails = [];
$next_lecture = null;
$all_lectures = [];
$all_users = [];
$db_errors = [];

// Buscar estatísticas - com tratamento de erro individual para cada query
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
    $result = $stmt->fetch();
    $total_users = $result ? $result['total'] : 0;
} catch (PDOException $e) {
    $db_errors[] = "Erro ao contar usuários: " . $e->getMessage();
    error_log("[Emails] " . end($db_errors));
}

try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM users 
        WHERE is_active = 1 AND (
            is_subscriber = 1 OR role = 'subscriber' 
            OR (subscription_expires IS NOT NULL AND subscription_expires > NOW())
        )
    ");
    $result = $stmt->fetch();
    $total_subscribers = $result ? $result['total'] : 0;
} catch (PDOException $e) {
    $db_errors[] = "Erro ao contar assinantes: " . $e->getMessage();
    error_log("[Emails] " . end($db_errors));
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM email_logs WHERE status = 'sent'");
    $result = $stmt->fetch();
    $total_sent = $result ? $result['total'] : 0;
} catch (PDOException $e) {
    $db_errors[] = "Erro ao contar emails: " . $e->getMessage();
    error_log("[Emails] " . end($db_errors));
}

try {
    $stmt = $pdo->query("SELECT * FROM email_logs ORDER BY created_at DESC LIMIT 10");
    $recent_emails = $stmt->fetchAll() ?: [];
} catch (PDOException $e) {
    $db_errors[] = "Erro ao buscar histórico de emails: " . $e->getMessage();
    error_log("[Emails] " . end($db_errors));
}

// Buscar próxima palestra agendada (da tabela upcoming_announcements)
try {
    $stmt = $pdo->query("
        SELECT id, title, speaker, description, announcement_date, lecture_time, image_path
        FROM upcoming_announcements 
        WHERE announcement_date >= CURDATE() 
        AND is_active = 1
        ORDER BY announcement_date ASC, lecture_time ASC 
        LIMIT 1
    ");
    $next_lecture = $stmt->fetch();
} catch (PDOException $e) {
    $db_errors[] = "Erro ao buscar palestra agendada: " . $e->getMessage();
    error_log("[Emails] " . end($db_errors));
}

// Buscar TODAS as palestras agendadas para o dropdown (upcoming_announcements)
try {
    $stmt = $pdo->query("
        SELECT id, title, speaker, announcement_date, lecture_time
        FROM upcoming_announcements 
        WHERE is_active = 1
        ORDER BY announcement_date DESC
    ");
    $all_lectures = $stmt->fetchAll() ?: [];
} catch (PDOException $e) {
    $db_errors[] = "Erro ao buscar palestras agendadas: " . $e->getMessage();
    error_log("[Emails] " . end($db_errors));
}

// Buscar todos os usuários ativos para a lista de seleção
try {
    $stmt = $pdo->query("
        SELECT id, name, email, role, 
               COALESCE(is_subscriber, 0) as is_subscriber,
               CASE 
                   WHEN role = 'admin' THEN 'Admin'
                   WHEN COALESCE(is_subscriber, 0) = 1 OR role = 'subscriber' THEN 'Assinante'
                   ELSE 'Free'
               END as user_type
        FROM users 
        WHERE COALESCE(is_active, 1) = 1 
        ORDER BY name ASC
    ");
    $all_users = $stmt->fetchAll() ?: [];
} catch (PDOException $e) {
    $db_errors[] = "Erro ao buscar usuários: " . $e->getMessage();
    error_log("[Emails] " . end($db_errors));
    
    // Tentar query mais simples
    try {
        $stmt = $pdo->query("SELECT id, name, email, role FROM users ORDER BY name ASC");
        $result = $stmt->fetchAll();
        if ($result) {
            $all_users = [];
            foreach ($result as $user) {
                $user['is_subscriber'] = 0;
                $user['user_type'] = ($user['role'] == 'admin') ? 'Admin' : (($user['role'] == 'subscriber') ? 'Assinante' : 'Free');
                $all_users[] = $user;
            }
        }
    } catch (PDOException $e2) {
        $db_errors[] = "Erro na query simplificada de usuários: " . $e2->getMessage();
        error_log("[Emails] " . end($db_errors));
    }
}

// Verificar status da configuração de email
$email_configured = isEmailConfigured();

// Incluir os arquivos do template Vision
include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-envelope"></i> Sistema de E-mails</h1>
            <p>Envio de emails em massa para usuários da plataforma</p>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="success-alert">
        <i class="fas fa-check-circle"></i> <?php echo $message; ?>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="error-alert">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($db_errors)): ?>
    <div class="error-alert" style="background: rgba(245, 158, 11, 0.2); border-color: rgba(245, 158, 11, 0.5); color: #f59e0b;">
        <i class="fas fa-database"></i> <strong>Avisos do banco de dados:</strong>
        <ul style="margin: 10px 0 0 20px; padding: 0;">
            <?php foreach ($db_errors as $db_error): ?>
            <li><?php echo htmlspecialchars($db_error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- Status da Configuração -->
    <div class="video-card glass-card">
        <div class="config-status" style="padding: 15px; border-radius: 10px; background: <?php echo $email_configured ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; border: 1px solid <?php echo $email_configured ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)'; ?>;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fas <?php echo $email_configured ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>" style="color: <?php echo $email_configured ? '#10b981' : '#ef4444'; ?>; font-size: 1.5rem;"></i>
                <div>
                    <strong style="color: <?php echo $email_configured ? '#10b981' : '#ef4444'; ?>;">
                        <?php echo $email_configured ? 'Sistema de Email Configurado' : 'Sistema de Email NÃO Configurado'; ?>
                    </strong>
                    <p style="margin: 5px 0 0 0; color: rgba(255,255,255,0.7); font-size: 0.9rem;">
                        <?php if ($email_configured): ?>
                            SMTP: <?php echo SMTP_HOST; ?>:<?php echo SMTP_PORT; ?> | Remetente: <?php echo SMTP_FROM_EMAIL; ?>
                        <?php else: ?>
                            Configure as credenciais SMTP em email_config.php para habilitar o envio de emails.
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Teste de Email -->
        <?php if ($email_configured): ?>
        <div class="admin-form-section" style="margin-top: 20px;">
            <h3><i class="fas fa-flask"></i> Testar Configuração</h3>
            <form method="POST" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <input type="hidden" name="action" value="test_email">
                <div class="form-group" style="flex: 1; min-width: 250px; margin-bottom: 0;">
                    <label>Email para teste:</label>
                    <input type="email" name="test_email" class="form-control" placeholder="seu@email.com" required>
                </div>
                <button type="submit" class="cta-btn" style="padding: 12px 25px;">
                    <i class="fas fa-paper-plane"></i> Enviar Teste
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Estatísticas -->
    <div class="video-card glass-card">
        <h3><i class="fas fa-chart-bar"></i> Estatísticas</h3>
        <div class="stats-grid">
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_users; ?></div>
                <div class="stat-label">Total de Usuários</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_subscribers; ?></div>
                <div class="stat-label">Assinantes</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_users - $total_subscribers; ?></div>
                <div class="stat-label">Não Assinantes</div>
            </div>
            <div class="stat-item">
                <div class="stat-number"><?php echo $total_sent; ?></div>
                <div class="stat-label">Emails Enviados</div>
            </div>
        </div>
    </div>
    
    <!-- Debug Info (remover em produção) -->
    <div class="video-card glass-card" style="background: rgba(59, 130, 246, 0.1); border-color: rgba(59, 130, 246, 0.3);">
        <h3 style="color: #3b82f6;"><i class="fas fa-bug"></i> Debug Info</h3>
        <p style="color: rgba(255,255,255,0.8);">
            <strong>Usuários carregados:</strong> <?php echo count($all_users); ?><br>
            <strong>Palestras carregadas:</strong> <?php echo count($all_lectures); ?><br>
            <strong>Próxima palestra:</strong> <?php echo $next_lecture ? htmlspecialchars($next_lecture['title']) : 'Nenhuma'; ?><br>
            <strong>Conexão BD:</strong> <?php echo isset($pdo) ? 'OK' : 'ERRO'; ?>
        </p>
    </div>
    
    <!-- Próxima Palestra -->
    <?php if ($next_lecture): ?>
    <div class="video-card glass-card">
        <h3><i class="fas fa-calendar-alt"></i> Próxima Palestra Agendada</h3>
        <div class="next-lecture-info">
            <div class="lecture-details">
                <h4><?php echo htmlspecialchars($next_lecture['title']); ?></h4>
                <p><i class="fas fa-user"></i> <?php echo htmlspecialchars($next_lecture['speaker']); ?></p>
                <?php if (isset($next_lecture['announcement_date'])): ?>
                <p><i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($next_lecture['announcement_date'])); ?>
                    <?php if (isset($next_lecture['lecture_time'])): ?>
                    às <?php echo date('H:i', strtotime($next_lecture['lecture_time'])); ?>h
                    <?php endif; ?>
                </p>
                <?php endif; ?>
            </div>
            <button type="button" class="cta-btn" onclick="useNextLectureTemplate()">
                <i class="fas fa-envelope"></i> Criar Email sobre esta Palestra
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Formulário de Envio -->
    <div class="video-card glass-card">
        <h3><i class="fas fa-edit"></i> Enviar Novo E-mail</h3>
        
        <form method="POST" class="admin-form" id="emailForm">
            <input type="hidden" name="action" value="send_email">
            
            <!-- Tipo de Destinatário -->
            <div class="form-group">
                <label><i class="fas fa-users"></i> Tipo de Destinatários</label>
                <select name="recipient_type" id="recipient_type" class="form-control" onchange="toggleUserSelection()">
                    <option value="all">Todos os Usuários (<?php echo $total_users; ?>)</option>
                    <option value="subscribers">Apenas Assinantes (<?php echo $total_subscribers; ?>)</option>
                    <option value="non_subscribers">Não Assinantes (<?php echo $total_users - $total_subscribers; ?>)</option>
                    <option value="selected">Selecionar Usuários Individualmente</option>
                </select>
            </div>
            
            <!-- Seleção Individual de Usuários -->
            <div id="userSelectionContainer" style="display: none;">
                <div class="form-group">
                    <label><i class="fas fa-search"></i> Buscar Usuário</label>
                    <input type="text" id="userSearch" class="form-control" placeholder="Digite o nome ou email para buscar...">
                </div>
                
                <div class="selection-controls">
                    <button type="button" class="btn-secondary" onclick="selectAllUsers()">
                        <i class="fas fa-check-square"></i> Selecionar Todos
                    </button>
                    <button type="button" class="btn-secondary" onclick="deselectAllUsers()">
                        <i class="fas fa-square"></i> Desmarcar Todos
                    </button>
                    <span class="selected-count">
                        <span id="selectedCount">0</span> usuário(s) selecionado(s)
                    </span>
                </div>
                
                <div class="users-list" id="usersList">
                    <?php if (empty($all_users)): ?>
                    <div style="padding: 20px; text-align: center; color: rgba(255,255,255,0.6);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 10px; display: block;"></i>
                        Nenhum usuário encontrado no banco de dados.
                    </div>
                    <?php else: ?>
                    <?php foreach ($all_users as $user): ?>
                    <div class="user-item" data-name="<?php echo strtolower(htmlspecialchars($user['name'])); ?>" data-email="<?php echo strtolower(htmlspecialchars($user['email'])); ?>">
                        <label class="user-checkbox-label">
                            <input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="user-checkbox" onchange="updateSelectedCount()">
                            <div class="user-info">
                                <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
                                <span class="user-email"><?php echo htmlspecialchars($user['email']); ?></span>
                            </div>
                            <span class="user-type-badge <?php echo strtolower($user['user_type']); ?>"><?php echo $user['user_type']; ?></span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Palestra Relacionada (Palestras Agendadas) -->
            <div class="form-group">
                <label><i class="fas fa-chalkboard-teacher"></i> Palestra Agendada (opcional)</label>
                <select name="lecture_id" id="lecture_id" class="form-control">
                    <option value="">Nenhuma</option>
                    <?php foreach ($all_lectures as $lecture): ?>
                    <option value="<?php echo $lecture['id']; ?>">
                        <?php echo date('d/m/Y', strtotime($lecture['announcement_date'])); ?> - 
                        <?php echo htmlspecialchars($lecture['title']); ?> 
                        (<?php echo htmlspecialchars($lecture['speaker']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Assunto -->
            <div class="form-group">
                <label><i class="fas fa-heading"></i> Assunto</label>
                <input type="text" name="subject" id="subject" class="form-control" placeholder="Assunto do e-mail" required>
            </div>
            
            <!-- Link de Acesso -->
            <div class="form-group">
                <label><i class="fas fa-link"></i> Link de Acesso (opcional)</label>
                <input type="url" name="access_link" id="access_link" class="form-control" placeholder="https://...">
                <small style="color: rgba(255,255,255,0.6); display: block; margin-top: 5px;">Use [LINK] no corpo do email para inserir este link</small>
            </div>
            
            <!-- Mensagem -->
            <div class="form-group">
                <label><i class="fas fa-align-left"></i> Mensagem</label>
                <textarea name="message" id="message" class="form-control" rows="12" placeholder="Digite sua mensagem aqui...

Use [NOME] para personalizar com o nome do destinatário.
Use [LINK] para inserir o link de acesso." required></textarea>
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <button type="submit" class="cta-btn" style="padding: 15px 40px; font-size: 1.1rem;" <?php echo !$email_configured ? 'disabled title="Configure o email primeiro"' : ''; ?>>
                    <i class="fas fa-paper-plane"></i> Enviar E-mails
                </button>
            </div>
        </form>
    </div>
    
    <!-- Templates Rápidos -->
    <div class="video-card glass-card">
        <h3><i class="fas fa-magic"></i> Templates Rápidos</h3>
        
        <div class="quick-actions-grid">
            <div class="quick-action-card" onclick="useTemplate('welcome')">
                <div class="quick-action-icon" style="color: #3b82f6;">
                    <i class="fas fa-hand-wave"></i>
                </div>
                <h4>Boas-vindas</h4>
                <p>Novos usuários</p>
            </div>
            
            <div class="quick-action-card" onclick="useTemplate('newsletter')">
                <div class="quick-action-icon" style="color: #8b5cf6;">
                    <i class="fas fa-newspaper"></i>
                </div>
                <h4>Newsletter</h4>
                <p>Novidades da semana</p>
            </div>
            
            <div class="quick-action-card" onclick="useTemplate('promotion')">
                <div class="quick-action-icon" style="color: #10b981;">
                    <i class="fas fa-percentage"></i>
                </div>
                <h4>Promoção</h4>
                <p>Ofertas especiais</p>
            </div>
            
            <div class="quick-action-card" onclick="useTemplate('reminder')">
                <div class="quick-action-icon" style="color: #ef4444;">
                    <i class="fas fa-bell"></i>
                </div>
                <h4>Lembrete</h4>
                <p>Informações importantes</p>
            </div>
            
            <div class="quick-action-card" onclick="useTemplate('lecture')">
                <div class="quick-action-icon" style="color: #f59e0b;">
                    <i class="fas fa-video"></i>
                </div>
                <h4>Nova Palestra</h4>
                <p>Anúncio de palestra</p>
            </div>
            
            <div class="quick-action-card" onclick="useTemplate('certificate')">
                <div class="quick-action-icon" style="color: #ec4899;">
                    <i class="fas fa-certificate"></i>
                </div>
                <h4>Certificado</h4>
                <p>Certificado disponível</p>
            </div>
        </div>
    </div>
    
    <!-- Histórico de Emails -->
    <?php if (!empty($recent_emails)): ?>
    <div class="video-card glass-card">
        <h3><i class="fas fa-history"></i> Últimos E-mails Enviados</h3>
        
        <div class="table-container">
            <table class="certificates-table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Assunto</th>
                        <th>Destinatários</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_emails as $email): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i', strtotime($email['created_at'])); ?></td>
                        <td><?php echo htmlspecialchars(substr($email['subject'], 0, 50)); ?><?php echo strlen($email['subject']) > 50 ? '...' : ''; ?></td>
                        <td><?php echo $email['recipient_count']; ?> (<?php echo $email['recipient_type']; ?>)</td>
                        <td>
                            <?php if ($email['status'] === 'sent'): ?>
                            <span style="color: #10b981;"><i class="fas fa-check"></i> Enviado</span>
                            <?php elseif ($email['status'] === 'failed'): ?>
                            <span style="color: #ef4444;"><i class="fas fa-times"></i> Falhou</span>
                            <?php else: ?>
                            <span style="color: #f59e0b;"><i class="fas fa-clock"></i> <?php echo $email['status']; ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Templates de email
const templates = {
    welcome: {
        subject: 'Bem-vindo(a) à Translators101!',
        message: `Olá [NOME],

Seja bem-vindo(a) à nossa plataforma educacional para profissionais de tradução!

Aqui você encontrará palestras exclusivas, glossários especializados e muito conteúdo para aprimorar suas habilidades profissionais.

Comece explorando nossa videoteca e não perca nenhuma novidade!

Equipe Translators101`
    },
    newsletter: {
        subject: 'Translators101 - Novidades da Semana',
        message: `Olá [NOME],

Confira as principais novidades desta semana:

• Nova palestra adicionada
• Glossário atualizado
• Certificados disponíveis para download

Acesse nossa plataforma e aproveite todo o conteúdo!

Equipe Translators101`
    },
    promotion: {
        subject: 'Oferta Especial - Translators101',
        message: `Olá [NOME],

Temos uma oferta especial para você!

[Detalhes da promoção]

Esta oferta é válida por tempo limitado. Não perca!

Acesse: [LINK]

Equipe Translators101`
    },
    reminder: {
        subject: 'Lembrete Importante - Translators101',
        message: `Olá [NOME],

Este é um lembrete importante sobre:

[Conteúdo do lembrete]

Para mais informações, acesse nossa plataforma.

Equipe Translators101`
    },
    lecture: {
        subject: '🎬 Nova Palestra Disponível - Translators101',
        message: `Olá [NOME],

Uma nova palestra foi adicionada à plataforma!

📚 [Título da Palestra]
👤 Palestrante: [Nome do Palestrante]
⏱️ Duração: [XX] minutos

Não perca a oportunidade de aprender com os melhores profissionais do mercado.

Acesse agora: [LINK]

Equipe Translators101`
    },
    certificate: {
        subject: '🎓 Seu Certificado está Disponível - Translators101',
        message: `Olá [NOME],

Parabéns! Seu certificado de participação está disponível para download.

Acesse sua área de certificados para baixar:
[LINK]

Continue participando das nossas palestras e amplie seu portfólio de certificados!

Equipe Translators101`
    }
};

// Dados da próxima palestra (se existir)
<?php if ($next_lecture): ?>
const nextLecture = {
    title: <?php echo json_encode($next_lecture['title']); ?>,
    speaker: <?php echo json_encode($next_lecture['speaker']); ?>,
    date: <?php echo isset($next_lecture['announcement_date']) ? json_encode(date('d/m/Y', strtotime($next_lecture['announcement_date']))) : '""'; ?>,
    time: <?php echo isset($next_lecture['announcement_time']) ? json_encode($next_lecture['announcement_time']) : '""'; ?>
};
<?php else: ?>
const nextLecture = null;
<?php endif; ?>

function useTemplate(type) {
    if (templates[type]) {
        document.getElementById('subject').value = templates[type].subject;
        document.getElementById('message').value = templates[type].message;
        document.getElementById('access_link').value = '';
        document.getElementById('lecture_id').value = '';
        
        // Scroll para o formulário
        document.getElementById('emailForm').scrollIntoView({ behavior: 'smooth' });
    }
}

function useNextLectureTemplate() {
    if (nextLecture) {
        let dateInfo = '';
        if (nextLecture.date) {
            dateInfo = `📅 Data: ${nextLecture.date}`;
            if (nextLecture.time) {
                dateInfo += ` às ${nextLecture.time}`;
            }
        }
        
        document.getElementById('subject').value = `🎬 ${nextLecture.title} - Translators101`;
        document.getElementById('message').value = `Olá [NOME],

Temos uma palestra especial chegando!

📚 ${nextLecture.title}
👤 Palestrante: ${nextLecture.speaker}
${dateInfo}

Não perca a oportunidade de participar e aprender com os melhores profissionais do mercado.

Mais informações em: [LINK]

Equipe Translators101`;
        
        // Scroll para o formulário
        document.getElementById('emailForm').scrollIntoView({ behavior: 'smooth' });
    }
}

// Controle de seleção de usuários
function toggleUserSelection() {
    const recipientType = document.getElementById('recipient_type').value;
    const container = document.getElementById('userSelectionContainer');
    
    if (recipientType === 'selected') {
        container.style.display = 'block';
    } else {
        container.style.display = 'none';
        // Desmarcar todos quando mudar para outro tipo
        document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
        updateSelectedCount();
    }
}

function selectAllUsers() {
    const visibleItems = document.querySelectorAll('.user-item:not([style*="display: none"]) .user-checkbox');
    visibleItems.forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function deselectAllUsers() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function updateSelectedCount() {
    const count = document.querySelectorAll('.user-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = count;
}

// Busca de usuários
document.getElementById('userSearch')?.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase().trim();
    const items = document.querySelectorAll('.user-item');
    
    items.forEach(item => {
        const name = item.dataset.name || '';
        const email = item.dataset.email || '';
        
        if (searchTerm === '' || name.includes(searchTerm) || email.includes(searchTerm)) {
            item.style.display = 'block';
        } else {
            item.style.display = 'none';
        }
    });
});

// Validação do formulário
document.getElementById('emailForm')?.addEventListener('submit', function(e) {
    const recipientType = document.getElementById('recipient_type').value;
    
    if (recipientType === 'selected') {
        const selectedCount = document.querySelectorAll('.user-checkbox:checked').length;
        if (selectedCount === 0) {
            e.preventDefault();
            alert('Por favor, selecione pelo menos um usuário para enviar o email.');
            return false;
        }
        
        if (!confirm(`Enviar email para ${selectedCount} usuário(s) selecionado(s)?`)) {
            e.preventDefault();
            return false;
        }
    } else {
        const recipientText = document.querySelector(`#recipient_type option[value="${recipientType}"]`).textContent;
        if (!confirm(`Enviar email para: ${recipientText}?`)) {
            e.preventDefault();
            return false;
        }
    }
});
</script>

<style>
/* Estatísticas */
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

/* Próxima Palestra */
.next-lecture-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    margin-top: 15px;
    padding: 20px;
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 12px;
}

.lecture-details h4 {
    color: #f59e0b;
    margin: 0 0 10px 0;
    font-size: 1.2rem;
}

.lecture-details p {
    margin: 5px 0;
    color: rgba(255, 255, 255, 0.8);
}

.lecture-details i {
    width: 20px;
    color: #f59e0b;
}

/* Formulário */
.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: white;
}

.form-group label i {
    margin-right: 8px;
    color: #c084fc;
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

textarea.form-control {
    resize: vertical;
    min-height: 200px;
    font-family: inherit;
}

/* Seleção de Usuários */
#userSelectionContainer {
    background: rgba(30, 30, 30, 0.6);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.selection-controls {
    display: flex;
    gap: 15px;
    align-items: center;
    flex-wrap: wrap;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.btn-secondary {
    padding: 8px 16px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    color: white;
    cursor: pointer;
    transition: all 0.2s ease;
    font-size: 0.9rem;
}

.btn-secondary:hover {
    background: rgba(255, 255, 255, 0.2);
}

.selected-count {
    margin-left: auto;
    color: #c084fc;
    font-weight: 600;
}

.users-list {
    max-height: 400px;
    overflow-y: auto;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.2);
}

.user-item {
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    transition: background 0.2s ease;
}

.user-item:last-child {
    border-bottom: none;
}

.user-item:hover {
    background: rgba(192, 132, 252, 0.1);
}

.user-checkbox-label {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 12px 15px;
    cursor: pointer;
    margin: 0;
}

.user-checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    accent-color: #c084fc;
    cursor: pointer;
}

.user-info {
    flex: 1;
}

.user-name {
    display: block;
    color: white;
    font-weight: 500;
}

.user-email {
    display: block;
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.85rem;
}

.user-type-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.user-type-badge.admin {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.user-type-badge.assinante {
    background: rgba(16, 185, 129, 0.2);
    color: #10b981;
}

.user-type-badge.free {
    background: rgba(156, 163, 175, 0.2);
    color: #9ca3af;
}

/* Templates Rápidos */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.quick-action-card {
    padding: 20px 15px;
    text-align: center;
    background: rgba(30, 30, 30, 0.6);
    border-radius: 12px;
    transition: all 0.3s ease;
    border: 1px solid rgba(255, 255, 255, 0.1);
    cursor: pointer;
}

.quick-action-card:hover {
    transform: translateY(-3px);
    background: rgba(40, 40, 40, 0.8);
    border-color: rgba(192, 132, 252, 0.3);
    box-shadow: 0 5px 20px rgba(192, 132, 252, 0.1);
}

.quick-action-icon {
    font-size: 2rem;
    margin-bottom: 10px;
}

.quick-action-card h4 {
    margin: 10px 0 5px 0;
    font-size: 0.95rem;
    color: white;
}

.quick-action-card p {
    margin: 0;
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.6);
}

/* Alerts */
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

/* Admin Form Section */
.admin-form-section {
    padding: 20px;
    background: rgba(30, 30, 30, 0.6);
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.admin-form-section h3 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #c084fc;
}

/* Tabela */
.table-container {
    overflow-x: auto;
    margin-top: 20px;
    border-radius: 15px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(0, 0, 0, 0.2);
}

/* Scrollbar customizado */
.users-list::-webkit-scrollbar {
    width: 8px;
}

.users-list::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
}

.users-list::-webkit-scrollbar-thumb {
    background: rgba(192, 132, 252, 0.5);
    border-radius: 10px;
}

.users-list::-webkit-scrollbar-thumb:hover {
    background: rgba(192, 132, 252, 0.7);
}

/* Responsivo */
@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .quick-actions-grid {
        grid-template-columns: 1fr 1fr 1fr;
    }
    
    .next-lecture-info {
        flex-direction: column;
        text-align: center;
    }
    
    .selection-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .selected-count {
        margin-left: 0;
        text-align: center;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .quick-actions-grid {
        grid-template-columns: 1fr 1fr;
    }
    
    .stat-number {
        font-size: 2.2rem;
    }
}
</style>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>
