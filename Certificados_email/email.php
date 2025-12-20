<?php
/**
 * Configurações de Email - SMTP
 * Sistema de envio de emails da Translators101
 * 
 * ARQUIVO CORRIGIDO - Sistema de email funcionando
 */

// Incluir configurações se ainda não foram incluídas
if (!defined('SMTP_HOST')) {
    require_once __DIR__ . '/email_config.php';
}

/**
 * Classe para envio de emails via SMTP
 */
class EmailSender {
    private $smtp_host;
    private $smtp_port;
    private $smtp_username;
    private $smtp_password;
    private $from_email;
    private $from_name;
    
    public function __construct() {
        $this->smtp_host = SMTP_HOST;
        $this->smtp_port = SMTP_PORT;
        $this->smtp_username = SMTP_USERNAME;
        $this->smtp_password = SMTP_PASSWORD;
        $this->from_email = SMTP_FROM_EMAIL;
        $this->from_name = SMTP_FROM_NAME;
    }
    
    /**
     * Verificar se PHPMailer está disponível
     */
    private function isPHPMailerAvailable() {
        return class_exists('PHPMailer\PHPMailer\PHPMailer');
    }
    
    /**
     * Enviar email usando PHPMailer (se disponível) ou mail() nativo
     */
    public function sendEmail($to, $to_name, $subject, $html_content, $text_content = '') {
        // Log de tentativa de envio
        error_log("[EmailSender] Tentando enviar email para: $to");
        
        if ($this->isPHPMailerAvailable()) {
            error_log("[EmailSender] Usando PHPMailer");
            return $this->sendWithPHPMailer($to, $to_name, $subject, $html_content, $text_content);
        } else {
            error_log("[EmailSender] PHPMailer não disponível, usando método alternativo");
            return $this->sendWithSocketSMTP($to, $to_name, $subject, $html_content, $text_content);
        }
    }
    
    /**
     * Enviar email usando PHPMailer
     */
    private function sendWithPHPMailer($to, $to_name, $subject, $html_content, $text_content) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Configurações SMTP
            $mail->isSMTP();
            $mail->Host = $this->smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->smtp_username;
            $mail->Password = $this->smtp_password;
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $this->smtp_port;
            $mail->CharSet = EMAIL_CHARSET;
            
            // Debug (descomente para depuração)
            // $mail->SMTPDebug = 2;
            
            // Configurações do email
            $mail->setFrom($this->from_email, $this->from_name);
            $mail->addAddress($to, $to_name);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html_content;
            
            if (!empty($text_content)) {
                $mail->AltBody = $text_content;
            } else {
                $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $html_content));
            }
            
            $result = $mail->send();
            error_log("[EmailSender] PHPMailer enviou com sucesso para: $to");
            return $result;
            
        } catch (Exception $e) {
            error_log("[EmailSender] Erro PHPMailer: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Enviar email usando SMTP via socket (fallback sem PHPMailer)
     */
    private function sendWithSocketSMTP($to, $to_name, $subject, $html_content, $text_content) {
        try {
            // Tentar conexão SMTP direta
            $socket = @fsockopen($this->smtp_host, $this->smtp_port, $errno, $errstr, 30);
            
            if (!$socket) {
                error_log("[EmailSender] Não foi possível conectar ao SMTP: $errstr ($errno)");
                // Fallback para mail() nativo
                return $this->sendWithNativeMail($to, $to_name, $subject, $html_content, $text_content);
            }
            
            // Ler resposta inicial
            $this->getResponse($socket);
            
            // EHLO
            fwrite($socket, "EHLO " . gethostname() . "\r\n");
            $this->getResponse($socket);
            
            // STARTTLS
            fwrite($socket, "STARTTLS\r\n");
            $response = $this->getResponse($socket);
            
            if (strpos($response, '220') !== false) {
                // Upgrade para TLS
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                
                // EHLO novamente após TLS
                fwrite($socket, "EHLO " . gethostname() . "\r\n");
                $this->getResponse($socket);
            }
            
            // AUTH LOGIN
            fwrite($socket, "AUTH LOGIN\r\n");
            $this->getResponse($socket);
            
            fwrite($socket, base64_encode($this->smtp_username) . "\r\n");
            $this->getResponse($socket);
            
            fwrite($socket, base64_encode($this->smtp_password) . "\r\n");
            $auth_response = $this->getResponse($socket);
            
            if (strpos($auth_response, '235') === false) {
                error_log("[EmailSender] Falha na autenticação SMTP");
                fclose($socket);
                return $this->sendWithNativeMail($to, $to_name, $subject, $html_content, $text_content);
            }
            
            // MAIL FROM
            fwrite($socket, "MAIL FROM:<{$this->from_email}>\r\n");
            $this->getResponse($socket);
            
            // RCPT TO
            fwrite($socket, "RCPT TO:<$to>\r\n");
            $this->getResponse($socket);
            
            // DATA
            fwrite($socket, "DATA\r\n");
            $this->getResponse($socket);
            
            // Headers e corpo do email
            $headers = "From: {$this->from_name} <{$this->from_email}>\r\n";
            $headers .= "To: $to_name <$to>\r\n";
            $headers .= "Subject: $subject\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "\r\n";
            $headers .= $html_content;
            $headers .= "\r\n.\r\n";
            
            fwrite($socket, $headers);
            $data_response = $this->getResponse($socket);
            
            // QUIT
            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            
            if (strpos($data_response, '250') !== false) {
                error_log("[EmailSender] Email enviado via SMTP Socket para: $to");
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            error_log("[EmailSender] Erro SMTP Socket: " . $e->getMessage());
            return $this->sendWithNativeMail($to, $to_name, $subject, $html_content, $text_content);
        }
    }
    
    /**
     * Obter resposta do servidor SMTP
     */
    private function getResponse($socket) {
        $response = '';
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        return $response;
    }
    
    /**
     * Enviar email usando função mail() nativa do PHP (último fallback)
     */
    private function sendWithNativeMail($to, $to_name, $subject, $html_content, $text_content) {
        try {
            // Headers
            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
            $headers[] = 'From: ' . $this->from_name . ' <' . $this->from_email . '>';
            $headers[] = 'Reply-To: ' . $this->from_email;
            $headers[] = 'X-Mailer: PHP/' . phpversion();
            $headers[] = 'X-Priority: 3';
            
            $headers_string = implode("\r\n", $headers);
            
            // Destinatário formatado
            $to_formatted = $to_name ? "$to_name <$to>" : $to;
            
            // Enviar
            $result = mail($to_formatted, $subject, $html_content, $headers_string);
            
            if ($result) {
                error_log("[EmailSender] Email enviado via mail() para: $to");
            } else {
                error_log("[EmailSender] Falha ao enviar via mail() para: $to");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("[EmailSender] Erro mail(): " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Templates de Email
 */
class EmailTemplates {
    /**
     * Template base HTML
     */
    public static function getBaseTemplate($title, $content) {
        return '
        <!DOCTYPE html>
        <html lang="pt-BR">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . htmlspecialchars($title) . '</title>
        </head>
        <body style="margin: 0; padding: 0; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; background-color: #f4f4f4;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                            <!-- Header -->
                            <tr>
                                <td style="background: linear-gradient(135deg, #8e44ad, #9b59b6); padding: 30px; text-align: center;">
                                    <h1 style="color: #ffffff; margin: 0; font-size: 24px;">🎓 Translators101</h1>
                                </td>
                            </tr>
                            <!-- Content -->
                            <tr>
                                <td style="padding: 30px; color: #333333; line-height: 1.6;">
                                    ' . $content . '
                                </td>
                            </tr>
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: #f8f9fa; padding: 20px; text-align: center; color: #666666; font-size: 12px;">
                                    <p style="margin: 0;">© ' . date('Y') . ' Translators101 - Educação Continuada</p>
                                    <p style="margin: 5px 0 0 0;">Este é um email automático, não responda.</p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>';
    }
    
    /**
     * Template de certificado emitido
     */
    public static function getCertificateTemplate($name, $lecture_title, $certificate_id, $view_url, $download_url, $verification_url) {
        $content = '
            <h2 style="color: #8e44ad; margin-top: 0;">🎉 Parabéns, ' . htmlspecialchars($name) . '!</h2>
            
            <p>Seu certificado de participação foi gerado com sucesso!</p>
            
            <div style="background-color: #f8f9fa; border-left: 4px solid #8e44ad; padding: 15px; margin: 20px 0;">
                <h3 style="color: #8e44ad; margin: 0 0 10px 0;">📋 Detalhes do Certificado:</h3>
                <ul style="margin: 0; padding-left: 20px;">
                    <li><strong>Palestra:</strong> ' . htmlspecialchars($lecture_title) . '</li>
                    <li><strong>Data de Emissão:</strong> ' . date('d/m/Y H:i') . '</li>
                    <li><strong>ID:</strong> ' . htmlspecialchars($certificate_id) . '</li>
                </ul>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $view_url . '" style="display: inline-block; background: linear-gradient(135deg, #c084fc, #a855f7); color: #ffffff; text-decoration: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; margin: 5px;">👁️ Visualizar</a>
                <a href="' . $download_url . '" style="display: inline-block; background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; text-decoration: none; padding: 12px 25px; border-radius: 8px; font-weight: bold; margin: 5px;">📥 Baixar</a>
            </div>
            
            <div style="background-color: #eff6ff; border: 1px solid #3b82f6; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #1d4ed8; margin: 0 0 10px 0;">🔍 Verificação de Autenticidade:</h3>
                <p style="margin: 0; font-size: 14px;">Qualquer pessoa pode verificar este certificado em:</p>
                <p style="margin: 10px 0 0 0; text-align: center;">
                    <a href="' . $verification_url . '" style="color: #3b82f6; word-break: break-all;">' . $verification_url . '</a>
                </p>
            </div>
            
            <p style="color: #666; font-size: 13px;"><small>Este link também está disponível via QR Code no certificado.</small></p>';
        
        return self::getBaseTemplate('Seu Certificado T101', $content);
    }
    
    /**
     * Template para definição de senha
     */
    public static function getPasswordSetupTemplate($name, $reset_link) {
        $content = '
            <h2 style="color: #8e44ad; margin-top: 0;">🔑 Defina sua senha</h2>
            
            <p>Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p>
            
            <p>Para acessar a plataforma Translators101, você precisa definir sua senha de acesso.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $reset_link . '" style="display: inline-block; background: linear-gradient(135deg, #c084fc, #a855f7); color: #ffffff; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: bold; font-size: 16px;">🔐 Definir Minha Senha</a>
            </div>
            
            <div style="background-color: #fef3c7; border: 1px solid #f59e0b; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <p style="margin: 0; color: #92400e; font-size: 14px;">⏰ <strong>Importante:</strong> Este link expira em 24 horas.</p>
            </div>
            
            <p style="color: #666; font-size: 13px;">Se você não solicitou este email, pode ignorá-lo com segurança.</p>';
        
        return self::getBaseTemplate('Defina sua Senha', $content);
    }
    
    /**
     * Template de boas-vindas Hotmart
     */
    public static function getWelcomeHotmartTemplate($name, $reset_link) {
        $content = '
            <h2 style="color: #8e44ad; margin-top: 0;">🎉 Bem-vindo(a) à Translators101!</h2>
            
            <p>Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p>
            
            <p>Que alegria ter você conosco! Sua compra foi confirmada e seu acesso à plataforma já está liberado.</p>
            
            <div style="background-color: #f0fdf4; border: 1px solid #10b981; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <h3 style="color: #047857; margin: 0 0 10px 0;">✅ O que você terá acesso:</h3>
                <ul style="margin: 0; padding-left: 20px; color: #065f46;">
                    <li>Palestras exclusivas sobre tradução e interpretação</li>
                    <li>Glossários especializados</li>
                    <li>Certificados de participação</li>
                    <li>Comunidade de profissionais</li>
                </ul>
            </div>
            
            <p>Para começar, você precisa definir sua senha de acesso:</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="' . $reset_link . '" style="display: inline-block; background: linear-gradient(135deg, #c084fc, #a855f7); color: #ffffff; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: bold; font-size: 16px;">🚀 Criar Minha Senha e Acessar</a>
            </div>
            
            <p>Estamos animados para fazer parte da sua jornada profissional! 🌟</p>';
        
        return self::getBaseTemplate('Bem-vindo(a) à Translators101', $content);
    }
    
    /**
     * Template de senha alterada
     */
    public static function getPasswordChangedTemplate($name) {
        $content = '
            <h2 style="color: #10b981; margin-top: 0;">✅ Senha definida com sucesso!</h2>
            
            <p>Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p>
            
            <p>Sua senha foi definida com sucesso. Agora você pode fazer login na plataforma Translators101.</p>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="https://translators101.com/login.php" style="display: inline-block; background: linear-gradient(135deg, #10b981, #059669); color: #ffffff; text-decoration: none; padding: 15px 30px; border-radius: 8px; font-weight: bold; font-size: 16px;">🚀 Acessar Plataforma</a>
            </div>
            
            <div style="background-color: #eff6ff; border: 1px solid #3b82f6; padding: 15px; border-radius: 8px; margin: 20px 0;">
                <p style="margin: 0; color: #1e40af; font-size: 14px;">🔐 <strong>Dica de Segurança:</strong> Nunca compartilhe sua senha com terceiros.</p>
            </div>
            
            <p>Aproveite todo o conteúdo da plataforma! 🌟</p>';
        
        return self::getBaseTemplate('Acesso Liberado', $content);
    }
    
    /**
     * Template personalizado
     */
    public static function getCustomEmailTemplate($subject, $content) {
        return self::getBaseTemplate($subject, $content);
    }
}

// ============================================
// FUNÇÕES DE ENVIO DE EMAIL
// ============================================

/**
 * Enviar email de definição de senha
 */
function sendPasswordSetupEmail($email, $name, $reset_token) {
    try {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'translators101.com';
        $reset_link = "{$protocol}://{$host}/definir_senha.php?token={$reset_token}";
        
        $emailSender = new EmailSender();
        $subject = "🔑 Defina sua senha - Translators101";
        $html_content = EmailTemplates::getPasswordSetupTemplate($name, $reset_link);
        
        $result = $emailSender->sendEmail($email, $name, $subject, $html_content);
        
        if ($result) {
            error_log("[T101] Email de senha enviado para: $email");
        } else {
            error_log("[T101] Falha ao enviar email de senha para: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("[T101] Erro ao enviar email de senha: " . $e->getMessage());
        return false;
    }
}

/**
 * Enviar email de boas-vindas Hotmart
 */
function sendWelcomeHotmartEmail($email, $name, $reset_token) {
    try {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'translators101.com';
        $reset_link = "{$protocol}://{$host}/definir_senha.php?token={$reset_token}";
        
        $emailSender = new EmailSender();
        $subject = "🎉 Boas-vindas à Translators101 - Acesso liberado!";
        $html_content = EmailTemplates::getWelcomeHotmartTemplate($name, $reset_link);
        
        $result = $emailSender->sendEmail($email, $name, $subject, $html_content);
        
        if ($result) {
            error_log("[T101] Email de boas-vindas enviado para: $email");
        } else {
            error_log("[T101] Falha ao enviar email de boas-vindas para: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("[T101] Erro ao enviar email de boas-vindas: " . $e->getMessage());
        return false;
    }
}

/**
 * Enviar notificação de senha alterada
 */
function sendPasswordChangedEmail($email, $name) {
    try {
        $emailSender = new EmailSender();
        $subject = "✅ Senha definida com sucesso - Translators101";
        $html_content = EmailTemplates::getPasswordChangedTemplate($name);
        
        $result = $emailSender->sendEmail($email, $name, $subject, $html_content);
        
        if ($result) {
            error_log("[T101] Email de confirmação enviado para: $email");
        } else {
            error_log("[T101] Falha ao enviar email de confirmação para: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("[T101] Erro ao enviar email de confirmação: " . $e->getMessage());
        return false;
    }
}

/**
 * Enviar email de certificado
 */
function sendCertificateEmail($email, $name, $certificate_id, $lecture_title) {
    try {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'translators101.com';
        
        $view_url = "{$protocol}://{$host}/view_certificate_files.php?id={$certificate_id}";
        $download_url = "{$protocol}://{$host}/download_certificate_files.php?id={$certificate_id}";
        $verification_url = "{$protocol}://{$host}/verificar_certificado.php?id={$certificate_id}";
        
        $emailSender = new EmailSender();
        $subject = "🎓 Seu Certificado T101 - " . $lecture_title;
        $html_content = EmailTemplates::getCertificateTemplate($name, $lecture_title, $certificate_id, $view_url, $download_url, $verification_url);
        
        $result = $emailSender->sendEmail($email, $name, $subject, $html_content);
        
        if ($result) {
            error_log("[T101] Email de certificado enviado para: $email");
        } else {
            error_log("[T101] Falha ao enviar email de certificado para: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("[T101] Erro ao enviar email de certificado: " . $e->getMessage());
        return false;
    }
}
?>