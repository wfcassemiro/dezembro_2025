<?php
/**
 * Configuração do PHPMailer
 * Sistema: Translators101
 */

// Configurações SMTP da Hostinger
define('SMTP_HOST', 'smtp.hostinger.com.br'); // Host SMTP da Hostinger
define('SMTP_PORT', 465); // Porta SMTP (587 para TLS, 465 para SSL)
define('SMTP_SECURE', 'ssl');  // tls ou ssl
define('SMTP_USERNAME', 'contato@translators101.com'); // Seu email
define('SMTP_PASSWORD', 'r:#D$!r=X1'); // Senha do email (será solicitada para o usuário)
define('SMTP_FROM_EMAIL', 'contato@translators101.com');
define('SMTP_FROM_NAME', 'Translators101');

/**
 * Verifica se as credenciais de email estão configuradas
 */
function isEmailConfigured() {
    return !empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD) && !empty(SMTP_FROM_EMAIL);
}
