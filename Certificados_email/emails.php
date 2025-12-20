<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// CORREÇÃO: Usar o sistema de email corrigido
require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/email.php';

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
    
    if ($action === 'send_email') {
        $recipient_type = $_POST['recipient_type'];
        $subject = trim($_POST['subject']);
        $message_body = trim($_POST['message']);
        $access_link = trim($_POST['access_link'] ?? '');
        $lecture_id = $_POST['lecture_id'] ?? null;
        
        if (empty($subject) || empty($message_body)) {
            $error = 'Assunto e mensagem são obrigatórios.';
        } else {
            try {
                // Buscar destinatários
                if ($recipient_type === 'all') {
                    $stmt = $pdo->query("SELECT email, name FROM users WHERE is_active = 1");
                } elseif ($recipient_type === 'subscribers') {
                    $stmt = $pdo->query("
                        SELECT email, name FROM users 
                        WHERE is_active = 1 
                        AND (
                            is_subscriber = 1 
                            OR role = 'subscriber' 
                            OR (subscription_expires IS NOT NULL AND subscription_expires > NOW())
                        )
                    ");
                } else {
                    $stmt = $pdo->query("
                        SELECT email, name FROM users 
                        WHERE is_active = 1 
                        AND (is_subscriber = 0 OR is_subscriber IS NULL)
                        AND role != 'subscriber'
                        AND (subscription_expires IS NULL OR subscription_expires <= NOW())
                    ");
                }
                
                $recipients = $stmt->fetchAll();
                
                if (empty($recipients)) {
                    $error = 'Nenhum destinatário encontrado para esta seleção.';
                } else {
                    // CORREÇÃO: Verificar se o email está configurado corretamente
                    if (isEmailConfigured()) {
                        // Enviar emails usando o sistema corrigido
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
                                
                                // Pequena pausa para não sobrecarregar o servidor SMTP
                                usleep(100000); // 100ms
                                
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
                        // Sistema de email não configurado
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

// Buscar estatísticas
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
    $total_users = $stmt->fetch()['total'];
    
    $stmt = $pdo->query("
        SELECT COUNT(*) as total FROM users 
        WHERE is_active = 1 AND (
            is_subscriber = 1 OR role = 'subscriber' 
            OR (subscription_expires IS NOT NULL AND subscription_expires > NOW())
        )
    ");
    $total_subscribers = $stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM email_logs WHERE status = 'sent'");
    $total_sent = $stmt->fetch()['total'];
    
    $stmt = $pdo->query("SELECT * FROM email_logs ORDER BY created_at DESC LIMIT 10");
    $recent_emails = $stmt->fetchAll();
    
    $stmt = $pdo->query("SELECT * FROM lectures WHERE announcement_date >= CURDATE() ORDER BY announcement_date ASC LIMIT 1");
    $next_lecture = $stmt->fetch();
} catch (Exception $e) {
    $total_users = 0;
    $total_subscribers = 0;
    $total_sent = 0;
    $recent_emails = [];
    $next_lecture = null;
}

// Verificar status da configuração de email
$email_configured = isEmailConfigured();

?>

<?php include __DIR__ . '/../vision/includes/header.php'; ?>

<div class="content-wrapper">
    <div class="glass-card">
        <h2><i class="fas fa-envelope"></i> Sistema de E-mails</h2>
        
        <?php if ($message): ?>
        <div class="success-alert"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="error-alert"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <!-- Status da Configuração -->
        <div class="config-status" style="margin-bottom: 20px; padding: 15px; border-radius: 10px; background: <?php echo $email_configured ? 'rgba(16, 185, 129, 0.1)' : 'rgba(239, 68, 68, 0.1)'; ?>; border: 1px solid <?php echo $email_configured ? 'rgba(16, 185, 129, 0.3)' : 'rgba(239, 68, 68, 0.3)'; ?>;">
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
        <div class="admin-form-section" style="margin-bottom: 20px;">
            <h3><i class="fas fa-flask"></i> Testar Configuração</h3>
            <form method="POST" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <input type="hidden" name="action" value="test_email">
                <div class="form-group" style="flex: 1; min-width: 250px;">
                    <label>Email para teste:</label>
                    <input type="email" name="test_email" class="form-control" placeholder="seu@email.com" required>
                </div>
                <button type="submit" class="vision-btn" style="padding: 12px 25px;">
                    <i class="fas fa-paper-plane"></i> Enviar Teste
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- Estatísticas -->
        <div class="stats-grid">
            <div class="glass-card stats-card">
                <div class="stats-content">
                    <div class="stats-info">
                        <h3>Total de Usuários</h3>
                        <div class="stats-number"><?php echo $total_users; ?></div>
                    </div>
                    <i class="fas fa-users stats-icon stats-icon-blue"></i>
                </div>
            </div>
            
            <div class="glass-card stats-card">
                <div class="stats-content">
                    <div class="stats-info">
                        <h3>Assinantes</h3>
                        <div class="stats-number"><?php echo $total_subscribers; ?></div>
                    </div>
                    <i class="fas fa-crown stats-icon stats-icon-green"></i>
                </div>
            </div>
            
            <div class="glass-card stats-card">
                <div class="stats-content">
                    <div class="stats-info">
                        <h3>Não Assinantes</h3>
                        <div class="stats-number"><?php echo $total_users - $total_subscribers; ?></div>
                    </div>
                    <i class="fas fa-user stats-icon stats-icon-red"></i>
                </div>
            </div>
            
            <div class="glass-card stats-card">
                <div class="stats-content">
                    <div class="stats-info">
                        <h3>Emails Enviados</h3>
                        <div class="stats-number"><?php echo $total_sent; ?></div>
                    </div>
                    <i class="fas fa-paper-plane stats-icon stats-icon-purple"></i>
                </div>
            </div>
        </div>
        
        <!-- Formulário de Envio -->
        <div class="glass-card" style="margin-top: 30px;">
            <h3><i class="fas fa-edit"></i> Enviar Novo E-mail</h3>
            
            <form method="POST" class="vision-form">
                <input type="hidden" name="action" value="send_email">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label><i class="fas fa-users"></i> Destinatários</label>
                        <select name="recipient_type" class="form-control" required>
                            <option value="all">Todos os Usuários (<?php echo $total_users; ?>)</option>
                            <option value="subscribers">Apenas Assinantes (<?php echo $total_subscribers; ?>)</option>
                            <option value="non_subscribers">Não Assinantes (<?php echo $total_users - $total_subscribers; ?>)</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-chalkboard-teacher"></i> Palestra Relacionada (opcional)</label>
                        <select name="lecture_id" class="form-control">
                            <option value="">Nenhuma</option>
                            <?php
                            $lectures = $pdo->query("SELECT id, title FROM lectures ORDER BY announcement_date DESC LIMIT 20")->fetchAll();
                            foreach ($lectures as $lecture):
                            ?>
                            <option value="<?php echo $lecture['id']; ?>"><?php echo htmlspecialchars($lecture['title']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-heading"></i> Assunto</label>
                    <input type="text" name="subject" class="form-control" placeholder="Assunto do e-mail" required>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-link"></i> Link de Acesso (opcional)</label>
                    <input type="url" name="access_link" class="form-control" placeholder="https://...">
                    <small style="color: rgba(255,255,255,0.6);">Use [LINK] no corpo do email para inserir este link</small>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Mensagem</label>
                    <textarea name="message" class="form-control" rows="10" placeholder="Digite sua mensagem aqui...&#10;&#10;Use [NOME] para personalizar com o nome do destinatário.&#10;Use [LINK] para inserir o link de acesso." required></textarea>
                </div>
                
                <div style="text-align: center; margin-top: 20px;">
                    <button type="submit" class="vision-btn vision-btn-primary" style="padding: 15px 40px; font-size: 1.1rem;" <?php echo !$email_configured ? 'disabled title="Configure o email primeiro"' : ''; ?>>
                        <i class="fas fa-paper-plane"></i> Enviar E-mails
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Templates Rápidos -->
        <div class="glass-card" style="margin-top: 30px;">
            <h3><i class="fas fa-magic"></i> Templates Rápidos</h3>
            
            <div class="quick-actions-grid">
                <div class="quick-action-card" onclick="useTemplate('welcome')" style="cursor: pointer;">
                    <div class="quick-action-icon quick-action-icon-blue">
                        <i class="fas fa-hand-wave"></i>
                    </div>
                    <h3>Boas-vindas</h3>
                    <p>Novos usuários</p>
                </div>
                
                <div class="quick-action-card" onclick="useTemplate('newsletter')" style="cursor: pointer;">
                    <div class="quick-action-icon quick-action-icon-purple">
                        <i class="fas fa-newspaper"></i>
                    </div>
                    <h3>Newsletter</h3>
                    <p>Novidades da semana</p>
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
        
        <!-- Histórico de Emails -->
        <?php if (!empty($recent_emails)): ?>
        <div class="glass-card" style="margin-top: 30px;">
            <h3><i class="fas fa-history"></i> Últimos E-mails Enviados</h3>
            
            <div class="table-container">
                <table class="vision-table">
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
</div>

<script>
function useTemplate(type) {
    const templates = {
        welcome: {
            subject: 'Bem-vindo(a) à Translators101!',
            message: 'Olá [NOME],\n\nSeja bem-vindo(a) à nossa plataforma educacional para profissionais de tradução!\n\nAqui você encontrará palestras exclusivas, glossários especializados e muito conteúdo para aprimorar suas habilidades profissionais.\n\nComece explorando nossa videoteca e não perca nenhuma novidade!\n\nEquipe Translators101'
        },
        newsletter: {
            subject: 'Translators101 - Novidades da Semana',
            message: 'Olá [NOME],\n\nConfira as principais novidades desta semana:\n\n• Nova palestra adicionada\n• Glossário atualizado\n• Certificados disponíveis para download\n\nAcesse nossa plataforma e aproveite todo o conteúdo!\n\nEquipe Translators101'
        },
        promotion: {
            subject: 'Oferta Especial - Translators101',
            message: 'Olá [NOME],\n\nTemos uma oferta especial para você!\n\n[Detalhes da promoção]\n\nEsta oferta é válida por tempo limitado. Não perca!\n\nAcesse: [LINK]\n\nEquipe Translators101'
        },
        reminder: {
            subject: 'Lembrete Importante - Translators101',
            message: 'Olá [NOME],\n\nEste é um lembrete importante sobre:\n\n[Conteúdo do lembrete]\n\nPara mais informações, acesse nossa plataforma.\n\nEquipe Translators101'
        }
    };
    
    if (templates[type]) {
        document.querySelector('input[name="subject"]').value = templates[type].subject;
        document.querySelector('textarea[name="message"]').value = templates[type].message;
        document.querySelector('input[name="access_link"]').value = '';
        document.querySelector('select[name="lecture_id"]').value = '';
    }
}
</script>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
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
    padding: 12px;
    border-radius: 8px;
    border: 1px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.05);
    color: #fff;
    font-size: 1rem;
}

.form-group textarea {
    resize: vertical;
    min-height: 150px;
}

.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
}

.quick-action-card {
    padding: 25px 15px;
    text-align: center;
    background: rgba(255,255,255,0.03);
    border-radius: 12px;
    transition: all 0.3s ease;
    border: 1px solid rgba(255,255,255,0.1);
}

.quick-action-card:hover {
    transform: translateY(-3px);
    background: rgba(255,255,255,0.06);
    border-color: rgba(255,255,255,0.2);
}

.quick-action-icon {
    font-size: 2rem;
    margin-bottom: 10px;
}

.quick-action-icon-blue { color: #3b82f6; }
.quick-action-icon-purple { color: #8b5cf6; }
.quick-action-icon-green { color: #10b981; }
.quick-action-icon-red { color: #ef4444; }

.quick-action-card h3 {
    margin: 10px 0 5px 0;
    font-size: 1rem;
}

.quick-action-card p {
    margin: 0;
    font-size: 0.85rem;
    color: rgba(255,255,255,0.6);
}

.success-alert {
    background: rgba(16, 185, 129, 0.1);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #10b981;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.error-alert {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}

.table-container {
    overflow-x: auto;
}

.vision-table {
    width: 100%;
    border-collapse: collapse;
}

.vision-table th,
.vision-table td {
    padding: 12px 15px;
    text-align: left;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.vision-table th {
    background: rgba(255,255,255,0.05);
    font-weight: 600;
}

.admin-form-section {
    background: rgba(255,255,255,0.03);
    padding: 20px;
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.1);
}

.admin-form-section h3 {
    margin-top: 0;
    margin-bottom: 15px;
    color: #c084fc;
}

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>