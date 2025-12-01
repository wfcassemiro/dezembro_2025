<?php
require_once __DIR__ . '/config/database.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php'; // Se instalou via Composer

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);

    if (!empty($email)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $pdo->prepare("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)")
                ->execute([$user['id'], $token, $expires_at]);

            // 🔗 Link de reset
            $reset_link = "https://v.translators101.com/reset_password.php?token=" . urlencode($token);

            // ✉️ Envio de e-mail com PHPMailer + Fallback
            $mail = new PHPMailer(true);
            $emailSent = false;

            // 🔄 Tentativa 1: SSL/465
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.hostinger.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'contato@translators101.com';
                $mail->Password   = 'Pa392ap!';
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
                $mail->Port       = 465;

                $mail->setFrom('contato@translators101.com', 'Suporte T101');
                $mail->addAddress($email);

                $mail->isHTML(true);
                $mail->Subject = "Redefina sua senha - Translators101";
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;'>
                        <div style='background: linear-gradient(135deg, #1a1a1a, #2d2d2d); padding: 30px; border-radius: 12px; color: white; text-align: center;'>
                            <h2 style='color: #FFD700; margin-bottom: 20px;'>🔐 Redefinição de Senha</h2>
                            <p style='font-size: 16px; line-height: 1.6; margin-bottom: 25px;'>
                                Olá! Você solicitou a redefinição de sua senha na <strong>Translators101</strong>.
                            </p>
                            <p style='margin-bottom: 30px;'>
                                <a href='$reset_link' style='display: inline-block; background: linear-gradient(135deg, #007AFF, #0056CC); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; font-size: 16px;'>
                                    ✨ Redefinir Senha
                                </a>
                            </p>
                            <p style='font-size: 14px; color: #ccc; margin-bottom: 10px;'>
                                ⏰ Este link expira em <strong>1 hora</strong>.
                            </p>
                            <p style='font-size: 14px; color: #ccc;'>
                                Se você não solicitou esta redefinição, ignore este e-mail.
                            </p>
                            <hr style='border: none; border-top: 1px solid #444; margin: 25px 0;'>
                            <p style='font-size: 12px; color: #999;'>
                                Equipe <strong>Translators101</strong><br>
                                <a href='https://v.translators101.com' style='color: #FFD700;'>v.translators101.com</a>
                            </p>
                        </div>
                    </div>
                ";
                $mail->AltBody = "Olá! Clique no link para redefinir sua senha: $reset_link\n\nEsse link expira em 1 hora.\n\nEquipe Translators101";

                $mail->send();
                $emailSent = true;
                error_log("E-mail enviado com sucesso via SSL/465 para: $email");

            } catch (Exception $e) {
                error_log("Tentativa SSL/465 falhou: {$mail->ErrorInfo}");

                // 🔄 Tentativa 2: TLS/587 (Fallback)
                try {
                    $mail->clearAddresses();
                    $mail->clearAttachments();
                    
                    $mail->isSMTP();
                    $mail->Host       = 'smtp.hostinger.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = 'contato@translators101.com';
                    $mail->Password   = 'Pa392ap!';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // TLS
                    $mail->Port       = 587;

                    $mail->setFrom('contato@translators101.com', 'Suporte T101');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->Subject = "Redefinição de senha - Translators101";
                    $mail->Body    = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f9f9f9;'>
                            <div style='background: linear-gradient(135deg, #1a1a1a, #2d2d2d); padding: 30px; border-radius: 12px; color: white; text-align: center;'>
                                <h2 style='color: #FFD700; margin-bottom: 20px;'>🔐 Redefinição de Senha</h2>
                                <p style='font-size: 16px; line-height: 1.6; margin-bottom: 25px;'>
                                    Olá! Você solicitou a redefinição da sua senha na plataforma <strong>Translators101</strong>.
                                </p>
                                <p style='margin-bottom: 30px;'>
                                    <a href='$reset_link' style='display: inline-block; background: linear-gradient(135deg, #007AFF, #0056CC); color: white; padding: 15px 30px; text-decoration: none; border-radius: 25px; font-weight: bold; font-size: 16px;'>
                                        ✨ Redefinir Senha
                                    </a>
                                </p>
                                <p style='font-size: 14px; color: #ccc; margin-bottom: 10px;'>
                                    ⏰ Este link expira em <strong>1 hora</strong>.
                                </p>
                                <p style='font-size: 14px; color: #ccc;'>
                                    Se você não solicitou esta redefinição, ignore este e-mail.
                                </p>
                                <hr style='border: none; border-top: 1px solid #444; margin: 25px 0;'>
                                <p style='font-size: 12px; color: #999;'>
                                    Equipe <strong>Translators101</strong><br>
                                    <a href='https://v.translators101.com' style='color: #FFD700;'>v.translators101.com</a>
                                </p>
                            </div>
                        </div>
                    ";
                    $mail->AltBody = "Olá! Clique no link para redefinir sua senha: $reset_link\n\nEsse link expira em 1 hora.\n\nEquipe Translators101";

                    $mail->send();
                    $emailSent = true;
                    error_log("E-mail enviado com sucesso via TLS/587 (fallback) para: $email");

                } catch (Exception $ex) {
                    error_log("Erro total no envio de e-mail: {$ex->getMessage()}");
                    $message = "Não foi possível enviar o e-mail. Tente novamente mais tarde.";
                    $message_type = 'error';
                }
            }

            if ($emailSent) {
                $message = "Um link de redefinição foi enviado para seu e-mail. Verifique também a caixa de spam.";
                $message_type = 'success';
            }

        } else {
            $message = "Email não encontrado em nossa base de dados.";
            $message_type = 'error';
        }
    } else {
        $message = "Por favor, preencha seu email.";
        $message_type = 'error';
    }
}
?>

<?php include __DIR__ . '/vision/includes/head.php'; ?>
<?php include __DIR__ . '/vision/includes/header.php'; ?>

<style>
.alert-success {
    background: linear-gradient(135deg, rgba(52, 199, 89, 0.2), rgba(52, 199, 89, 0.1));
    border: 1px solid rgba(52, 199, 89, 0.3);
    color: #34C759;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-error {
    background: linear-gradient(135deg, rgba(255, 59, 48, 0.2), rgba(255, 59, 48, 0.1));
    border: 1px solid rgba(255, 59, 48, 0.3);
    color: #FF3B30;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.form-actions {
    text-align: center;
    margin-top: 20px;
}

.back-link {
    display: inline-block;
    margin-top: 15px;
    color: var(--accent-gold);
    text-decoration: none;
    font-weight: 600;
}

.back-link:hover {
    color: #fff;
    text-decoration: underline;
}
</style>

<div class="main-content">
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-unlock-alt"></i> Recuperar Senha</h1>
            <p>Enviaremos um link seguro para redefinir sua senha</p>
        </div>
    </div>

    <div class="video-card" style="max-width: 520px; margin: 0 auto; padding: 35px 25px;">
        <h2><i class="fas fa-envelope"></i> Esqueci minha senha</h2>

        <?php if ($message): ?>
        <div class="alert-<?php echo $message_type; ?>">
            <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
            <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="vision-form">
            <div class="form-group">
                <label for="email">
                    <i class="fas fa-envelope"></i> Seu e-mail cadastrado
                </label>
                <input type="email" id="email" name="email" required 
                       placeholder="Digite seu e-mail"
                       value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            
            <div class="form-actions">
                <button type="submit" class="cta-btn">
                    <i class="fas fa-paper-plane"></i> Enviar link de recuperação
                </button>
            </div>
        </form>

        <div style="text-align: center; margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--glass-border);">
            <a href="login.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Voltar ao login
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>