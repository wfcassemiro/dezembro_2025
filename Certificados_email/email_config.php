<?php
/**
 * Configuração do PHPMailer/Email
 * Sistema de certificados Translators101
 * 
 * ARQUIVO CORRIGIDO - Configure suas credenciais SMTP abaixo
 */

// ============================================
// CONFIGURAÇÕES SMTP - PREENCHA COM SEUS DADOS
// ============================================

// Host SMTP (Hostgator/Hostinger/Gmail/etc)
define('SMTP_HOST', 'br1189.hostgator.com.br');

// Porta SMTP (587 para TLS, 465 para SSL)
define('SMTP_PORT', 587);

// Tipo de segurança ('tls' ou 'ssl')
define('SMTP_SECURE', 'tls');

// Credenciais de autenticação SMTP
define('SMTP_USERNAME', 'contato@translators101.com');
define('SMTP_PASSWORD', 'r:#D$!r=X1'); // IMPORTANTE: Substitua pela senha real

// Email e nome do remetente
define('SMTP_FROM_EMAIL', 'contato@translators101.com');
define('SMTP_FROM_NAME', 'Translators101');

// Configurações de template
define('EMAIL_CHARSET', 'UTF-8');
define('EMAIL_CONTENT_TYPE', 'text/html');

/**
 * Verifica se as credenciais de email estão configuradas
 * CORRIGIDO: Agora verifica corretamente as configurações
 */
function isEmailConfigured() {
    // Verificar se as constantes estão definidas e não estão vazias
    $host_ok = defined('SMTP_HOST') && !empty(SMTP_HOST);
    $port_ok = defined('SMTP_PORT') && SMTP_PORT > 0;
    $user_ok = defined('SMTP_USERNAME') && !empty(SMTP_USERNAME);
    $pass_ok = defined('SMTP_PASSWORD') && !empty(SMTP_PASSWORD);
    $from_ok = defined('SMTP_FROM_EMAIL') && !empty(SMTP_FROM_EMAIL);
    
    return $host_ok && $port_ok && $user_ok && $pass_ok && $from_ok;
}
?>