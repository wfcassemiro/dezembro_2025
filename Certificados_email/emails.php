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

// Configurações de envio em lote
$BATCH_SIZE = 50; // Emails por lote
$BATCH_DELAY = 2; // Segundos entre lotes
$EMAIL_DELAY = 150000; // Microsegundos entre emails (150ms)

// Arquivo para salvar templates personalizados
$templates_file = __DIR__ . '/../config/email_templates.json';

// Carregar templates salvos
$saved_templates = [];
if (file_exists($templates_file)) {
    $saved_templates = json_decode(file_get_contents($templates_file), true) ?: [];
}

// Processar salvamento de template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_template') {
    $template_name = trim($_POST['template_name'] ?? '');
    $template_subject = trim($_POST['template_subject'] ?? '');
    $template_message = trim($_POST['template_message'] ?? '');
    
    if (!empty($template_name) && !empty($template_subject) && !empty($template_message)) {
        $saved_templates[$template_name] = [
            'subject' => $template_subject,
            'message' => $template_message,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        if (file_put_contents($templates_file, json_encode($saved_templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
            $message = "✅ Template '$template_name' salvo com sucesso!";
        } else {
            $error = "❌ Erro ao salvar template. Verifique as permissões do diretório.";
        }
    } else {
        $error = "❌ Nome, assunto e mensagem do template são obrigatórios.";
    }
}

// Processar exclusão de template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_template') {
    $template_name = $_POST['template_name'] ?? '';
    if (isset($saved_templates[$template_name])) {
        unset($saved_templates[$template_name]);
        file_put_contents($templates_file, json_encode($saved_templates, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $message = "🗑️ Template '$template_name' excluído!";
    }
}

// Processar envio de email
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // Envio de email (modo normal ou seleção individual)
    if ($action === 'send_email') {
        $recipient_type = $_POST['recipient_type'] ?? 'all';
        $selected_users = $_POST['selected_users'] ?? [];
        $custom_emails = trim($_POST['custom_emails'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $message_body = trim($_POST['message'] ?? '');
        $access_link = trim($_POST['access_link'] ?? '');
        $lecture_id = $_POST['lecture_id'] ?? null;
        $batch_size = intval($_POST['batch_size'] ?? $BATCH_SIZE);
        
        if (empty($subject) || empty($message_body)) {
            $error = 'Assunto e mensagem são obrigatórios.';
        } else {
            try {
                $recipients = [];
                
                // Buscar destinatários baseado no tipo de seleção
                if ($recipient_type === 'selected' && !empty($selected_users)) {
                    $placeholders = implode(',', array_fill(0, count($selected_users), '?'));
                    $stmt = $pdo->prepare("SELECT id, email, name FROM users WHERE id IN ($placeholders) AND is_active = 1");
                    $stmt->execute($selected_users);
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'custom') {
                    // Emails personalizados apenas
                } elseif ($recipient_type === 'all') {
                    $stmt = $pdo->query("SELECT id, email, name FROM users WHERE is_active = 1");
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'subscribers') {
                    $stmt = $pdo->query("
                        SELECT id, email, name FROM users 
                        WHERE is_active = 1 
                        AND (is_subscriber = 1 OR role = 'subscriber' OR (subscription_expires IS NOT NULL AND subscription_expires > NOW()))
                    ");
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'non_subscribers') {
                    $stmt = $pdo->query("
                        SELECT id, email, name FROM users 
                        WHERE is_active = 1 
                        AND (is_subscriber = 0 OR is_subscriber IS NULL)
                        AND role != 'subscriber'
                        AND (subscription_expires IS NULL OR subscription_expires <= NOW())
                    ");
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'with_password') {
                    $stmt = $pdo->query("SELECT id, email, name FROM users WHERE is_active = 1 AND password_hash IS NOT NULL AND password_hash != ''");
                    $recipients = $stmt->fetchAll();
                } elseif ($recipient_type === 'without_password') {
                    $stmt = $pdo->query("SELECT id, email, name FROM users WHERE is_active = 1 AND (password_hash IS NULL OR password_hash = '')");
                    $recipients = $stmt->fetchAll();
                }
                
                // Adicionar emails personalizados
                if (!empty($custom_emails)) {
                    $custom_list = preg_split('/[\s,;]+/', $custom_emails);
                    foreach ($custom_list as $email) {
                        $email = trim($email);
                        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $exists = false;
                            foreach ($recipients as $r) {
                                if (strtolower($r['email']) === strtolower($email)) {
                                    $exists = true;
                                    break;
                                }
                            }
                            if (!$exists) {
                                $recipients[] = [
                                    'id' => null,
                                    'email' => $email,
                                    'name' => explode('@', $email)[0]
                                ];
                            }
                        }
                    }
                }
                
                if (empty($recipients)) {
                    $error = 'Nenhum destinatário encontrado. Verifique a seleção ou adicione emails manualmente.';
                } else {
                    if (isEmailConfigured()) {
                        $emailSender = new EmailSender();
                        
                        $sent_count = 0;
                        $failed_count = 0;
                        $failed_emails = [];
                        $total_recipients = count($recipients);
                        $batch_count = 0;
                        
                        $batches = array_chunk($recipients, $batch_size);
                        
                        foreach ($batches as $batch_index => $batch) {
                            if ($batch_index > 0) {
                                sleep($BATCH_DELAY);
                            }
                            
                            foreach ($batch as $recipient) {
                                try {
                                    $personalized_message = str_replace('[NOME]', $recipient['name'], $message_body);
                                    if (!empty($access_link)) {
                                        $personalized_message = str_replace('[LINK]', $access_link, $personalized_message);
                                    }
                                    
                                    $html_content = EmailTemplates::getCustomEmailTemplate($subject, nl2br(htmlspecialchars($personalized_message)));
                                    
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
                                    
                                    usleep($EMAIL_DELAY);
                                    
                                } catch (Exception $e) {
                                    $failed_count++;
                                    $failed_emails[] = $recipient['email'];
                                    error_log("[Emails] Erro ao enviar para {$recipient['email']}: " . $e->getMessage());
                                }
                            }
                            $batch_count++;
                        }
                        
                        if ($sent_count > 0 && $failed_count == 0) {
                            $message = "✅ Todos os {$sent_count} e-mail(s) foram enviados com sucesso! ({$batch_count} lote(s) processado(s))";
                        } elseif ($sent_count > 0 && $failed_count > 0) {
                            $message = "📧 {$sent_count} e-mail(s) enviado(s), ⚠️ {$failed_count} falha(s). ({$batch_count} lote(s))";
                            if (count($failed_emails) <= 10) {
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

// Inicializar variáveis
$total_users = 0;
$total_subscribers = 0;
$total_sent = 0;
$users_with_password = 0;
$users_without_password = 0;
$recent_emails = [];
$next_lecture = null;
$all_lectures = [];
$all_users = [];
$db_errors = [];

// Buscar estatísticas
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
    $result = $stmt->fetch();
    $total_users = $result ? $result['total'] : 0;
} catch (PDOException $e) {
    $db_errors[] = "Erro ao contar usuários: " . $e->getMessage();
}

try {
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM users 
        WHERE is_active = 1 AND (is_subscriber = 1 OR role = 'subscriber' OR (subscription_expires IS NOT NULL AND subscription_expires > NOW()))
    ");
    $result = $stmt->fetch();
    $total_subscribers = $result ? $result['total'] : 0;
} catch (PDOException $e) {
    $db_errors[] = "Erro ao contar assinantes: " . $e->getMessage();
}

// Contar usuários com e sem senha
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1 AND password IS NOT NULL AND password != ''");
    $result = $stmt->fetch();
    $users_with_password = $result ? $result['total'] : 0;
} catch (PDOException $e) {
    $db_errors[] = "Erro ao contar usuários com senha: " . $e->getMessage();
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1 AND (password IS NULL OR password = '')");
    $result = $stmt->fetch();
    $users_without_password = $result ? $result['total'] : 0;
} catch (PDOException $e) {
    $db_errors[] = "Erro ao contar usuários sem senha: " . $e->getMessage();
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM email_logs WHERE status = 'sent'");
    $result = $stmt->fetch();
    $total_sent = $result ? $result['total'] : 0;
} catch (PDOException $e) {
    $db_errors[] = "Erro ao contar emails: " . $e->getMessage();
}

try {
    $stmt = $pdo->query("SELECT * FROM email_logs ORDER BY created_at DESC LIMIT 10");
    $recent_emails = $stmt->fetchAll() ?: [];
} catch (PDOException $e) {
    $db_errors[] = "Erro ao buscar histórico de emails: " . $e->getMessage();
}

// Buscar próxima palestra agendada
try {
    $stmt = $pdo->query("
        SELECT id, title, speaker, description, announcement_date, lecture_time, image_path
        FROM upcoming_announcements 
        WHERE announcement_date >= CURDATE() AND is_active = 1
        ORDER BY announcement_date ASC, lecture_time ASC 
        LIMIT 1
    ");
    $next_lecture = $stmt->fetch();
} catch (PDOException $e) {
    $db_errors[] = "Erro ao buscar palestra agendada: " . $e->getMessage();
}

// Buscar palestras agendadas para o dropdown
try {
    $stmt = $pdo->query("
        SELECT id, title, speaker, announcement_date, lecture_time, description
        FROM upcoming_announcements 
        WHERE is_active = 1
        ORDER BY announcement_date DESC
    ");
    $all_lectures = $stmt->fetchAll() ?: [];
} catch (PDOException $e) {
    $db_errors[] = "Erro ao buscar palestras agendadas: " . $e->getMessage();
}

// Buscar todos os usuários ativos
try {
    $stmt = $pdo->query("
        SELECT id, name, email, role, 
               COALESCE(is_subscriber, 0) as is_subscriber,
               CASE WHEN password IS NOT NULL AND password != '' THEN 1 ELSE 0 END as has_password,
               created_at,
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
    
    <!-- Estatísticas - 2 linhas, 3 cards cada -->
    <div class="video-card glass-card">
        <h3 class="section-title"><i class="fas fa-chart-bar"></i> Estatísticas</h3>
        
        <div class="stats-row">
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
        </div>
        
        <div class="stats-row">
            <div class="stat-item stat-green">
                <div class="stat-number"><?php echo $users_with_password; ?></div>
                <div class="stat-label">Com Senha</div>
            </div>
            <div class="stat-item stat-red">
                <div class="stat-number"><?php echo $users_without_password; ?></div>
                <div class="stat-label">Sem Senha</div>
            </div>
            <div class="stat-item stat-blue">
                <div class="stat-number"><?php echo $total_sent; ?></div>
                <div class="stat-label">Emails Enviados</div>
            </div>
        </div>
    </div>
    
    <!-- Status SMTP, Teste de Email e Remetente - 3 cards na mesma linha -->
    <div class="three-column-grid">
        <!-- Status da Configuração -->
        <div class="video-card glass-card compact-card">
            <h3 class="section-title"><i class="fas fa-cog"></i> Status SMTP</h3>
            <div class="config-status-mini <?php echo $email_configured ? 'configured' : 'not-configured'; ?>">
                <i class="fas <?php echo $email_configured ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <span><?php echo $email_configured ? 'Configurado' : 'Não Configurado'; ?></span>
            </div>
            <?php if ($email_configured): ?>
            <p class="smtp-info"><?php echo SMTP_HOST; ?>:<?php echo SMTP_PORT; ?></p>
            <?php endif; ?>
        </div>
        
        <!-- Teste de Email -->
        <div class="video-card glass-card compact-card">
            <h3 class="section-title"><i class="fas fa-flask"></i> Testar Email</h3>
            <form method="POST" class="test-form">
                <input type="hidden" name="action" value="test_email">
                <input type="email" name="test_email" class="form-control" placeholder="seu@email.com" required>
                <button type="submit" class="cta-btn btn-small" <?php echo !$email_configured ? 'disabled' : ''; ?>>
                    <i class="fas fa-paper-plane"></i> Testar
                </button>
            </form>
        </div>
        
        <!-- Remetente -->
        <div class="video-card glass-card compact-card">
            <h3 class="section-title"><i class="fas fa-user-circle"></i> Remetente</h3>
            <?php if ($email_configured): ?>
            <p class="sender-info"><?php echo SMTP_FROM_NAME; ?></p>
            <p class="sender-email"><?php echo SMTP_FROM_EMAIL; ?></p>
            <?php else: ?>
            <p class="sender-info">Não configurado</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Próxima Palestra -->
    <?php if ($next_lecture): ?>
    <div class="video-card glass-card">
        <h3 class="section-title"><i class="fas fa-calendar-alt"></i> Próxima Palestra Agendada</h3>
        <div class="next-lecture-info">
            <div class="lecture-details">
                <h4><?php echo htmlspecialchars($next_lecture['title']); ?></h4>
                <p><i class="fas fa-user"></i> <?php echo htmlspecialchars($next_lecture['speaker']); ?></p>
                <p><i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($next_lecture['announcement_date'])); ?>
                    <?php if (isset($next_lecture['lecture_time'])): ?>
                    às <?php echo date('H:i', strtotime($next_lecture['lecture_time'])); ?>h
                    <?php endif; ?>
                </p>
            </div>
            <button type="button" class="cta-btn" onclick="useNextLectureTemplate()">
                <i class="fas fa-envelope"></i> Criar Email
            </button>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Enviar Novo Email e Emails Adicionais - 2 cards na mesma linha -->
    <div class="two-column-grid">
        <div class="video-card glass-card">
            <h3 class="section-title"><i class="fas fa-edit"></i> Enviar Novo E-mail</h3>
            <div class="form-group">
                <label><i class="fas fa-users"></i> Tipo de Destinatários</label>
                <select name="recipient_type" id="recipient_type" form="emailForm" class="form-control" onchange="toggleUserSelection()">
                    <option value="all">Todos os Usuários (<?php echo $total_users; ?>)</option>
                    <option value="subscribers">Apenas Assinantes (<?php echo $total_subscribers; ?>)</option>
                    <option value="non_subscribers">Não Assinantes (<?php echo $total_users - $total_subscribers; ?>)</option>
                    <option value="with_password">Com Senha (<?php echo $users_with_password; ?>)</option>
                    <option value="without_password">Sem Senha (<?php echo $users_without_password; ?>)</option>
                    <option value="selected">Selecionar da Lista</option>
                    <option value="custom">Apenas Emails Personalizados</option>
                </select>
            </div>
        </div>
        
        <div class="video-card glass-card">
            <h3 class="section-title"><i class="fas fa-at"></i> Emails Adicionais/Personalizados</h3>
            <div class="form-group">
                <label><i class="fas fa-envelope-open-text"></i> Emails Avulsos</label>
                <textarea name="custom_emails" id="custom_emails" form="emailForm" class="form-control" rows="3" placeholder="email1@dominio.com, email2@dominio.com&#10;Separados por vírgula, espaço ou quebra de linha"></textarea>
            </div>
        </div>
    </div>
    
    <!-- Formulário Principal -->
    <form method="POST" class="admin-form" id="emailForm">
        <input type="hidden" name="action" value="send_email">
        
        <!-- Seleção Individual de Usuários -->
        <div id="userSelectionContainer" class="video-card glass-card" style="display: none;">
            <h3 class="section-title"><i class="fas fa-user-check"></i> Selecionar Usuários</h3>
            <div class="two-column-grid">
                <div class="form-group">
                    <label><i class="fas fa-search"></i> Buscar Usuário</label>
                    <input type="text" id="userSearch" class="form-control" placeholder="Nome ou email...">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-filter"></i> Filtrar por Tipo</label>
                    <select id="filterUserType" class="form-control" onchange="filterUsers()">
                        <option value="">Todos</option>
                        <option value="admin">Admins</option>
                        <option value="assinante">Assinantes</option>
                        <option value="free">Free</option>
                    </select>
                </div>
            </div>
            
            <div class="selection-controls">
                <button type="button" class="btn-secondary" onclick="selectAllUsers()">
                    <i class="fas fa-check-square"></i> Selecionar Visíveis
                </button>
                <button type="button" class="btn-secondary" onclick="deselectAllUsers()">
                    <i class="fas fa-square"></i> Desmarcar
                </button>
                <span class="selected-count"><span id="selectedCount">0</span> selecionado(s)</span>
            </div>
            
            <div class="users-list" id="usersList">
                <?php foreach ($all_users as $user): ?>
                <div class="user-item" 
                     data-name="<?php echo strtolower(htmlspecialchars($user['name'])); ?>" 
                     data-email="<?php echo strtolower(htmlspecialchars($user['email'])); ?>"
                     data-type="<?php echo strtolower($user['user_type']); ?>">
                    <label class="user-checkbox-label">
                        <input type="checkbox" name="selected_users[]" value="<?php echo $user['id']; ?>" class="user-checkbox" onchange="updateSelectedCount()">
                        <div class="user-info">
                            <span class="user-name"><?php echo htmlspecialchars($user['name']); ?></span>
                            <span class="user-email"><?php echo htmlspecialchars($user['email']); ?></span>
                        </div>
                        <span class="user-type-badge <?php echo strtolower($user['user_type']); ?>"><?php echo $user['user_type']; ?></span>
                        <?php if ($user['has_password']): ?>
                        <span class="password-badge has-password" title="Tem senha"><i class="fas fa-key"></i></span>
                        <?php else: ?>
                        <span class="password-badge no-password" title="Sem senha"><i class="fas fa-key"></i></span>
                        <?php endif; ?>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Tamanho do Lote e Palestra Agendada - 2 cards na mesma linha -->
        <div class="two-column-grid">
            <div class="video-card glass-card">
                <h3 class="section-title"><i class="fas fa-layer-group"></i> Tamanho do Lote</h3>
                <div class="form-group">
                    <label><i class="fas fa-cubes"></i> Emails por Lote</label>
                    <select name="batch_size" class="form-control">
                        <option value="25">25 emails/lote (seguro)</option>
                        <option value="50" selected>50 emails/lote (recomendado)</option>
                        <option value="100">100 emails/lote (rápido)</option>
                    </select>
                </div>
            </div>
            
            <div class="video-card glass-card">
                <h3 class="section-title"><i class="fas fa-chalkboard-teacher"></i> Palestra Agendada</h3>
                <div class="form-group">
                    <label><i class="fas fa-video"></i> Selecionar Palestra</label>
                    <select name="lecture_id" id="lecture_id" class="form-control" onchange="fillLectureTemplate()">
                        <option value="">Selecione para preencher template...</option>
                        <?php foreach ($all_lectures as $lecture): ?>
                        <option value="<?php echo $lecture['id']; ?>"
                                data-title="<?php echo htmlspecialchars($lecture['title'], ENT_QUOTES); ?>"
                                data-speaker="<?php echo htmlspecialchars($lecture['speaker'], ENT_QUOTES); ?>"
                                data-date="<?php echo date('d/m/Y', strtotime($lecture['announcement_date'])); ?>"
                                data-time="<?php echo date('H:i', strtotime($lecture['lecture_time'])); ?>"
                                data-description="<?php echo htmlspecialchars($lecture['description'] ?? '', ENT_QUOTES); ?>">
                            <?php echo date('d/m/Y', strtotime($lecture['announcement_date'])); ?> - <?php echo htmlspecialchars($lecture['title']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Assunto e Link de Acesso - 2 cards na mesma linha -->
        <div class="two-column-grid">
            <div class="video-card glass-card">
                <h3 class="section-title"><i class="fas fa-heading"></i> Assunto do Email</h3>
                <div class="form-group">
                    <label><i class="fas fa-pen"></i> Assunto</label>
                    <input type="text" name="subject" id="subject" class="form-control" placeholder="Assunto do e-mail" required>
                </div>
            </div>
            
            <div class="video-card glass-card">
                <h3 class="section-title"><i class="fas fa-link"></i> Link de Acesso</h3>
                <div class="form-group">
                    <label><i class="fas fa-external-link-alt"></i> URL (use [LINK] na mensagem)</label>
                    <input type="url" name="access_link" id="access_link" class="form-control" placeholder="https://...">
                </div>
            </div>
        </div>
        
        <!-- Mensagem -->
        <div class="video-card glass-card">
            <h3 class="section-title"><i class="fas fa-align-left"></i> Mensagem</h3>
            <div class="form-group">
                <label><i class="fas fa-envelope-open-text"></i> Corpo do Email (use [NOME] para nome e [LINK] para o link)</label>
                <textarea name="message" id="message" class="form-control" rows="12" placeholder="Digite a mensagem do email aqui..." required></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="cta-btn" <?php echo !$email_configured ? 'disabled' : ''; ?>>
                    <i class="fas fa-paper-plane"></i> Enviar E-mails
                </button>
                <button type="button" class="cta-btn btn-secondary-large" onclick="openSaveTemplateModal()">
                    <i class="fas fa-save"></i> Salvar como Template
                </button>
            </div>
        </div>
    </form>
    
    <!-- Templates Rápidos -->
    <div class="video-card glass-card">
        <h3 class="section-title">
            <i class="fas fa-magic"></i> Templates
            <button type="button" class="btn-edit-templates" onclick="openManageTemplatesModal()">
                <i class="fas fa-edit"></i> Editar Templates
            </button>
        </h3>
        
        <div class="quick-actions-grid">
            <div class="quick-action-card" onclick="useTemplate('welcome')">
                <div class="quick-action-icon" style="color: #3b82f6;"><i class="fas fa-hand-wave"></i></div>
                <h4>Boas-vindas</h4>
            </div>
            <div class="quick-action-card" onclick="useTemplate('newsletter')">
                <div class="quick-action-icon" style="color: #8b5cf6;"><i class="fas fa-newspaper"></i></div>
                <h4>Newsletter</h4>
            </div>
            <div class="quick-action-card" onclick="useTemplate('promotion')">
                <div class="quick-action-icon" style="color: #10b981;"><i class="fas fa-percentage"></i></div>
                <h4>Promoção</h4>
            </div>
            <div class="quick-action-card" onclick="useTemplate('reminder')">
                <div class="quick-action-icon" style="color: #ef4444;"><i class="fas fa-bell"></i></div>
                <h4>Lembrete</h4>
            </div>
            <div class="quick-action-card" onclick="useTemplate('lecture')">
                <div class="quick-action-icon" style="color: #f59e0b;"><i class="fas fa-video"></i></div>
                <h4>Palestra</h4>
            </div>
            <div class="quick-action-card" onclick="useTemplate('password_reminder')">
                <div class="quick-action-icon" style="color: #ec4899;"><i class="fas fa-key"></i></div>
                <h4>Senha</h4>
            </div>
            
            <!-- Templates salvos -->
            <?php foreach ($saved_templates as $name => $template): ?>
            <div class="quick-action-card saved-template" onclick="useSavedTemplate('<?php echo htmlspecialchars($name, ENT_QUOTES); ?>')">
                <div class="quick-action-icon" style="color: #06b6d4;"><i class="fas fa-bookmark"></i></div>
                <h4><?php echo htmlspecialchars($name); ?></h4>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Histórico -->
    <?php if (!empty($recent_emails)): ?>
    <div class="video-card glass-card">
        <h3 class="section-title"><i class="fas fa-history"></i> Últimos E-mails</h3>
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
                        <td><?php echo htmlspecialchars(substr($email['subject'], 0, 40)); ?><?php echo strlen($email['subject']) > 40 ? '...' : ''; ?></td>
                        <td><?php echo $email['recipient_count']; ?></td>
                        <td>
                            <?php if ($email['status'] === 'sent'): ?>
                            <span class="status-sent"><i class="fas fa-check"></i></span>
                            <?php else: ?>
                            <span class="status-failed"><i class="fas fa-times"></i></span>
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

<!-- Modal Salvar Template -->
<div id="saveTemplateModal" class="modal">
    <div class="modal-content glass-modal">
        <span class="close" onclick="closeSaveTemplateModal()">&times;</span>
        <h3><i class="fas fa-save"></i> Salvar Template</h3>
        <form method="POST">
            <input type="hidden" name="action" value="save_template">
            <div class="form-group">
                <label>Nome do Template</label>
                <input type="text" name="template_name" class="form-control" placeholder="Ex: Lembrete Palestra" required>
            </div>
            <div class="form-group">
                <label>Assunto</label>
                <input type="text" name="template_subject" id="save_template_subject" class="form-control" required>
            </div>
            <div class="form-group">
                <label>Mensagem</label>
                <textarea name="template_message" id="save_template_message" class="form-control" rows="8" required></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeSaveTemplateModal()">Cancelar</button>
                <button type="submit" class="cta-btn">Salvar Template</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Gerenciar Templates -->
<div id="manageTemplatesModal" class="modal">
    <div class="modal-content glass-modal modal-large">
        <span class="close" onclick="closeManageTemplatesModal()">&times;</span>
        <h3><i class="fas fa-edit"></i> Editar Templates Salvos</h3>
        
        <?php if (empty($saved_templates)): ?>
        <p style="text-align: center; color: rgba(255,255,255,0.6); padding: 20px;">
            Nenhum template salvo ainda. Use "Salvar como Template" para criar seus templates personalizados.
        </p>
        <?php else: ?>
        <div class="templates-list">
            <?php foreach ($saved_templates as $name => $template): ?>
            <div class="template-item" id="template-item-<?php echo md5($name); ?>">
                <div class="template-info">
                    <strong><?php echo htmlspecialchars($name); ?></strong>
                    <span><?php echo htmlspecialchars(substr($template['subject'], 0, 50)); ?><?php echo strlen($template['subject']) > 50 ? '...' : ''; ?></span>
                    <?php if (isset($template['updated_at'])): ?>
                    <small>Atualizado: <?php echo date('d/m/Y H:i', strtotime($template['updated_at'])); ?></small>
                    <?php endif; ?>
                </div>
                <div class="template-actions">
                    <button type="button" class="btn-icon btn-primary" onclick="editTemplate('<?php echo htmlspecialchars($name, ENT_QUOTES); ?>')" title="Editar">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="btn-icon" onclick="useSavedTemplate('<?php echo htmlspecialchars($name, ENT_QUOTES); ?>'); closeManageTemplatesModal();" title="Usar">
                        <i class="fas fa-play"></i>
                    </button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Excluir o template &quot;<?php echo htmlspecialchars($name, ENT_QUOTES); ?>&quot;?')">
                        <input type="hidden" name="action" value="delete_template">
                        <input type="hidden" name="template_name" value="<?php echo htmlspecialchars($name); ?>">
                        <button type="submit" class="btn-icon btn-danger" title="Excluir">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="closeManageTemplatesModal()">Fechar</button>
        </div>
    </div>
</div>

<script>
// Templates padrão
const defaultTemplates = {
    welcome: {
        subject: 'Bem-vindo(a) à Translators101!',
        message: `Olá [NOME],

Seja bem-vindo(a) à nossa plataforma!

Equipe Translators101`
    },
    newsletter: {
        subject: 'Translators101 - Novidades da Semana',
        message: `Olá [NOME],

Confira as novidades desta semana!

Equipe Translators101`
    },
    promotion: {
        subject: 'Oferta Especial - Translators101',
        message: `Olá [NOME],

Temos uma oferta especial para você!

Acesse: [LINK]

Equipe Translators101`
    },
    reminder: {
        subject: 'Lembrete Importante - Translators101',
        message: `Olá [NOME],

Este é um lembrete importante.

Equipe Translators101`
    },
    lecture: {
        subject: '🎬 Nova Palestra - Translators101',
        message: `Olá [NOME],

Temos uma nova palestra para você!

Equipe Translators101`
    },
    password_reminder: {
        subject: '🔑 Registre sua Senha - Translators101',
        message: `Olá [NOME],

Notamos que você ainda não registrou sua senha no nosso novo site.

Para acessar todo o conteúdo da plataforma, registre sua senha em: translators101.com

Se tiver dificuldades, entre em contato pelo WhatsApp (+55 19 98260 0771).

Um abraço.

William Cassemiro`
    }
};

// Templates salvos (do PHP)
const savedTemplates = <?php echo json_encode($saved_templates); ?>;

// Próxima palestra
<?php if ($next_lecture): ?>
const nextLecture = {
    title: <?php echo json_encode($next_lecture['title']); ?>,
    speaker: <?php echo json_encode($next_lecture['speaker']); ?>,
    date: <?php echo json_encode(date('d/m/Y', strtotime($next_lecture['announcement_date']))); ?>,
    time: <?php echo json_encode(date('H:i', strtotime($next_lecture['lecture_time']))); ?>
};
<?php else: ?>
const nextLecture = null;
<?php endif; ?>

function useTemplate(type) {
    if (defaultTemplates[type]) {
        document.getElementById('subject').value = defaultTemplates[type].subject;
        document.getElementById('message').value = defaultTemplates[type].message;
        document.getElementById('message').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function useSavedTemplate(name) {
    if (savedTemplates[name]) {
        document.getElementById('subject').value = savedTemplates[name].subject;
        document.getElementById('message').value = savedTemplates[name].message;
        document.getElementById('message').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function fillLectureTemplate() {
    const select = document.getElementById('lecture_id');
    const opt = select.options[select.selectedIndex];
    
    if (!opt.value) return;
    
    const title = opt.dataset.title || '';
    const speaker = opt.dataset.speaker || '';
    const date = opt.dataset.date || '';
    const time = opt.dataset.time || '';
    const description = opt.dataset.description || '';
    
    document.getElementById('subject').value = `🎬 ${title} - Hoje às ${time}h`;
    document.getElementById('message').value = `Olá!

Hoje, ${date}, às ${time}h, teremos a palestra "${title}", com ${speaker}.

Descrição da palestra:
${description}

A transmissão será no novo site da Translators101: translators101.com.

Após fazer login, clique em "Ao vivo", no menu lateral. Entre uns 10 minutos antes, já estará rolando uma musiquinha. ;-)

Se você ainda não registrou sua senha no nosso novo site, entre em contato pelo nosso WhatsApp (+55 19 98260 0771), até às 17h e ajudaremos a resolver rapidamente. Depois das 17h, não teremos como responder a tempo de liberar seu acesso.

Um abraço.

William Cassemiro`;
    
    document.getElementById('message').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function useNextLectureTemplate() {
    if (nextLecture) {
        document.getElementById('subject').value = `🎬 ${nextLecture.title} - Hoje às ${nextLecture.time}h`;
        document.getElementById('message').value = `Olá!

Hoje, ${nextLecture.date}, às ${nextLecture.time}h, teremos a palestra "${nextLecture.title}", com ${nextLecture.speaker}.

Descrição da palestra:
[Adicione a descrição aqui]

A transmissão será no novo site da Translators101: translators101.com.

Após fazer login, clique em "Ao vivo", no menu lateral. Entre uns 10 minutos antes, já estará rolando uma musiquinha. ;-)

Se você ainda não registrou sua senha no nosso novo site, entre em contato pelo nosso WhatsApp (+55 19 98260 0771), até às 17h e ajudaremos a resolver rapidamente. Depois das 17h, não teremos como responder a tempo de liberar seu acesso.

Um abraço.

William Cassemiro`;
        
        document.getElementById('message').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}

function toggleUserSelection() {
    const type = document.getElementById('recipient_type').value;
    const container = document.getElementById('userSelectionContainer');
    container.style.display = (type === 'selected') ? 'block' : 'none';
    if (type !== 'selected') {
        document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
        updateSelectedCount();
    }
}

function selectAllUsers() {
    document.querySelectorAll('.user-item:not([style*="display: none"]) .user-checkbox').forEach(cb => cb.checked = true);
    updateSelectedCount();
}

function deselectAllUsers() {
    document.querySelectorAll('.user-checkbox').forEach(cb => cb.checked = false);
    updateSelectedCount();
}

function updateSelectedCount() {
    document.getElementById('selectedCount').textContent = document.querySelectorAll('.user-checkbox:checked').length;
}

function filterUsers() {
    const search = (document.getElementById('userSearch')?.value || '').toLowerCase();
    const type = (document.getElementById('filterUserType')?.value || '').toLowerCase();
    
    document.querySelectorAll('.user-item').forEach(item => {
        const name = item.dataset.name || '';
        const email = item.dataset.email || '';
        const userType = item.dataset.type || '';
        
        const matchSearch = !search || name.includes(search) || email.includes(search);
        const matchType = !type || userType === type;
        
        item.style.display = (matchSearch && matchType) ? 'block' : 'none';
    });
}

document.getElementById('userSearch')?.addEventListener('input', filterUsers);

// Modais
function openSaveTemplateModal() {
    document.getElementById('save_template_subject').value = document.getElementById('subject').value;
    document.getElementById('save_template_message').value = document.getElementById('message').value;
    document.getElementById('saveTemplateModal').style.display = 'flex';
}

function closeSaveTemplateModal() {
    document.getElementById('saveTemplateModal').style.display = 'none';
}

function openManageTemplatesModal() {
    document.getElementById('manageTemplatesModal').style.display = 'flex';
}

function closeManageTemplatesModal() {
    document.getElementById('manageTemplatesModal').style.display = 'none';
}

function editTemplate(name) {
    if (savedTemplates[name]) {
        document.getElementById('subject').value = savedTemplates[name].subject;
        document.getElementById('message').value = savedTemplates[name].message;
        closeManageTemplatesModal();
        openSaveTemplateModal();
        document.querySelector('#saveTemplateModal input[name="template_name"]').value = name;
    }
}

// Validação
document.getElementById('emailForm')?.addEventListener('submit', function(e) {
    const type = document.getElementById('recipient_type').value;
    const custom = document.getElementById('custom_emails').value.trim();
    
    if (type === 'selected') {
        const count = document.querySelectorAll('.user-checkbox:checked').length;
        if (count === 0 && !custom) {
            e.preventDefault();
            alert('Selecione pelo menos um usuário ou adicione emails.');
            return false;
        }
    } else if (type === 'custom' && !custom) {
        e.preventDefault();
        alert('Adicione pelo menos um email.');
        return false;
    }
    
    const recipientText = document.querySelector(`#recipient_type option[value="${type}"]`)?.textContent || type;
    if (!confirm(`Enviar email para: ${recipientText}?`)) {
        e.preventDefault();
        return false;
    }
});

// Fechar modal ao clicar fora
window.onclick = function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
    }
};
</script>

<style>
/* Títulos das seções com gap de 20px */
.section-title {
    margin: 0 0 15px 0;
    padding-left: 20px;
    color: #c084fc;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-title i {
    color: #c084fc;
}

/* Grid de 3 colunas */
.three-column-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

/* Grid de 2 colunas */
.two-column-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

/* Cards compactos para 3 colunas */
.compact-card {
    padding: 15px 20px !important;
}

.compact-card .section-title {
    margin-bottom: 12px;
    font-size: 1rem;
}

/* Cards padrão */
.video-card.glass-card {
    padding: 20px;
    margin-bottom: 20px;
}

/* Status SMTP mini */
.config-status-mini {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 0.9rem;
}

.config-status-mini.configured {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
}

.config-status-mini.not-configured {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.smtp-info {
    margin: 8px 0 0 0;
    font-size: 0.8rem;
    color: rgba(255,255,255,0.6);
    padding-left: 20px;
}

/* Teste form */
.test-form {
    display: flex;
    gap: 10px;
}

.test-form .form-control {
    flex: 1;
    padding: 8px 12px;
}

.btn-small {
    padding: 8px 12px !important;
    font-size: 0.85rem !important;
}

/* Sender info */
.sender-info {
    margin: 0;
    font-weight: 600;
    color: white;
    padding-left: 20px;
    font-size: 0.95rem;
}

.sender-email {
    margin: 5px 0 0 0;
    font-size: 0.8rem;
    color: rgba(255,255,255,0.6);
    padding-left: 20px;
}

/* Estatísticas em linhas */
.stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 15px;
}

.stats-row:last-child {
    margin-bottom: 0;
}

.stat-item {
    text-align: center;
    padding: 18px 12px;
    background: rgba(142, 68, 173, 0.2);
    border-radius: 12px;
    border: 1px solid rgba(142, 68, 173, 0.3);
}

.stat-item.stat-green {
    background: rgba(16, 185, 129, 0.15);
    border-color: rgba(16, 185, 129, 0.3);
}

.stat-item.stat-green .stat-number {
    color: #10b981;
}

.stat-item.stat-red {
    background: rgba(239, 68, 68, 0.15);
    border-color: rgba(239, 68, 68, 0.3);
}

.stat-item.stat-red .stat-number {
    color: #ef4444;
}

.stat-item.stat-blue {
    background: rgba(59, 130, 246, 0.15);
    border-color: rgba(59, 130, 246, 0.3);
}

.stat-item.stat-blue .stat-number {
    color: #3b82f6;
}

.stat-number {
    font-size: 1.8rem;
    font-weight: bold;
    color: #c084fc;
}

.stat-label {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.7);
    margin-top: 5px;
}

/* Próxima Palestra */
.next-lecture-info {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    padding: 15px 20px;
    background: rgba(245, 158, 11, 0.1);
    border: 1px solid rgba(245, 158, 11, 0.3);
    border-radius: 10px;
}

.lecture-details h4 {
    color: #f59e0b;
    margin: 0 0 8px 0;
    font-size: 1.1rem;
}

.lecture-details p {
    margin: 3px 0;
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.9rem;
}

/* Form */
.form-group {
    margin-bottom: 0;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    font-weight: 600;
    color: white;
    font-size: 0.9rem;
    padding-left: 20px;
}

.form-group label i {
    margin-right: 6px;
    color: #c084fc;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    font-size: 0.95rem;
    background: rgba(0, 0, 0, 0.3);
    color: white;
    box-sizing: border-box;
}

.form-control:focus {
    outline: none;
    border-color: #c084fc;
    box-shadow: 0 0 0 2px rgba(192, 132, 252, 0.1);
}

textarea.form-control {
    resize: vertical;
    min-height: 120px;
}

/* Form actions */
.form-actions {
    text-align: center;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid rgba(255,255,255,0.1);
}

/* Selection controls */
.selection-controls {
    display: flex;
    gap: 10px;
    align-items: center;
    margin: 10px 0;
    padding: 10px 20px;
    background: rgba(0,0,0,0.2);
    border-radius: 8px;
}

.btn-secondary {
    padding: 6px 12px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 6px;
    color: white;
    cursor: pointer;
    font-size: 0.85rem;
}

.selected-count {
    margin-left: auto;
    color: #c084fc;
    font-weight: 600;
    font-size: 0.9rem;
}

/* Users list */
.users-list {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    background: rgba(0, 0, 0, 0.2);
}

.user-item {
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.user-checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    cursor: pointer;
    margin: 0;
}

.user-info {
    flex: 1;
}

.user-name {
    display: block;
    color: white;
    font-weight: 500;
    font-size: 0.9rem;
}

.user-email {
    display: block;
    color: rgba(255, 255, 255, 0.5);
    font-size: 0.8rem;
}

.user-type-badge {
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 0.7rem;
    font-weight: 600;
}

.user-type-badge.admin { background: rgba(239, 68, 68, 0.2); color: #ef4444; }
.user-type-badge.assinante { background: rgba(16, 185, 129, 0.2); color: #10b981; }
.user-type-badge.free { background: rgba(156, 163, 175, 0.2); color: #9ca3af; }

.password-badge {
    font-size: 0.8rem;
    padding: 3px;
}

.password-badge.has-password { color: #10b981; }
.password-badge.no-password { color: #ef4444; opacity: 0.5; }

/* Templates */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 10px;
}

.quick-action-card {
    padding: 15px 10px;
    text-align: center;
    background: rgba(30, 30, 30, 0.6);
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    cursor: pointer;
    transition: all 0.2s;
}

.quick-action-card:hover {
    transform: translateY(-2px);
    border-color: rgba(192, 132, 252, 0.3);
}

.quick-action-icon {
    font-size: 1.5rem;
    margin-bottom: 8px;
}

.quick-action-card h4 {
    margin: 0;
    font-size: 0.8rem;
    color: white;
}

.saved-template {
    border-color: rgba(6, 182, 212, 0.3);
}

.btn-edit-templates {
    margin-left: auto;
    padding: 6px 12px;
    background: rgba(192, 132, 252, 0.2);
    border: 1px solid rgba(192, 132, 252, 0.4);
    border-radius: 6px;
    color: #c084fc;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 600;
    transition: all 0.2s;
}

.btn-edit-templates:hover {
    background: rgba(192, 132, 252, 0.3);
}

/* CTA buttons */
.cta-btn {
    padding: 12px 25px;
}

.btn-secondary-large {
    background: rgba(255,255,255,0.1) !important;
    border: 1px solid rgba(255,255,255,0.2) !important;
    margin-left: 10px;
}

/* Alerts */
.success-alert, .error-alert {
    padding: 12px 20px;
    border-radius: 10px;
    margin: 15px 0;
}

.success-alert {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #10b981;
}

.error-alert {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

/* Table */
.table-container {
    overflow-x: auto;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.status-sent { color: #10b981; }
.status-failed { color: #ef4444; }

/* Modal */
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: rgba(25, 25, 25, 0.95);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 15px;
    padding: 25px;
    width: 90%;
    max-width: 500px;
    max-height: 80vh;
    overflow-y: auto;
    position: relative;
}

.modal-content.modal-large {
    max-width: 600px;
}

.modal-content h3 {
    color: #c084fc;
    margin: 0 0 20px 0;
    padding-left: 20px;
}

.close {
    position: absolute;
    right: 15px;
    top: 10px;
    font-size: 28px;
    color: rgba(255,255,255,0.5);
    cursor: pointer;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 20px;
}

/* Templates list */
.templates-list {
    max-height: 400px;
    overflow-y: auto;
}

.template-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: rgba(0,0,0,0.2);
    border-radius: 8px;
    margin-bottom: 10px;
    border: 1px solid rgba(255,255,255,0.05);
}

.template-info {
    flex: 1;
}

.template-info strong {
    display: block;
    color: white;
    margin-bottom: 4px;
}

.template-info span {
    font-size: 0.85rem;
    color: rgba(255,255,255,0.5);
    display: block;
}

.template-info small {
    font-size: 0.75rem;
    color: rgba(255,255,255,0.4);
    display: block;
    margin-top: 4px;
}

.template-actions {
    display: flex;
    gap: 8px;
}

.btn-icon {
    width: 34px;
    height: 34px;
    border: none;
    border-radius: 6px;
    background: rgba(255,255,255,0.1);
    color: white;
    cursor: pointer;
    transition: all 0.2s;
}

.btn-icon:hover {
    background: rgba(255,255,255,0.2);
}

.btn-icon.btn-primary {
    background: rgba(192, 132, 252, 0.2);
    color: #c084fc;
}

.btn-icon.btn-primary:hover {
    background: rgba(192, 132, 252, 0.3);
}

.btn-icon.btn-danger {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
}

.btn-icon.btn-danger:hover {
    background: rgba(239, 68, 68, 0.3);
}

/* Responsive */
@media (max-width: 992px) {
    .three-column-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .three-column-grid > *:last-child {
        grid-column: span 2;
    }
    
    .stats-row {
        grid-template-columns: repeat(3, 1fr);
    }
    
    .quick-actions-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 768px) {
    .three-column-grid, .two-column-grid, .stats-row {
        grid-template-columns: 1fr;
    }
    
    .three-column-grid > *:last-child {
        grid-column: span 1;
    }
    
    .quick-actions-grid {
        grid-template-columns: repeat(3, 1fr);
    }
    
    .next-lecture-info {
        flex-direction: column;
        text-align: center;
    }
    
    .form-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .btn-secondary-large {
        margin-left: 0;
    }
}
</style>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>
