<?php
/**
 * Configuração do PHPMailer/SMTP
 * Sistema de certificados Translators101
 * 
 * IMPORTANTE: Configure suas credenciais SMTP abaixo
 */

// ============================================
// CONFIGURAÇÕES SMTP - PREENCHA COM SEUS DADOS
// ============================================

// Host SMTP do seu provedor
define('SMTP_HOST', 'br1189.hostgator.com.br');

// Porta SMTP:
// - 587 para TLS (STARTTLS) - Recomendado
// - 465 para SSL
// - 25 para conexão não criptografada (não recomendado)
define('SMTP_PORT', 587);

// Tipo de criptografia:
// - 'tls' para STARTTLS (porta 587)
// - 'ssl' para SSL (porta 465)
define('SMTP_SECURE', 'tls');

// Credenciais de autenticação
define('SMTP_USERNAME', 'contato@translators101.com');
define('SMTP_PASSWORD', 'r:#D$!r=X1'); // SUBSTITUA pela senha real

// Email e nome do remetente
define('SMTP_FROM_EMAIL', 'contato@translators101.com');
define('SMTP_FROM_NAME', 'Translators101');

// Configurações de charset
define('EMAIL_CHARSET', 'UTF-8');

// Debug SMTP (0 = desligado, 1 = erros, 2 = mensagens, 3 = detalhado)
define('SMTP_DEBUG', 0);

/**
 * Verifica se as credenciais de email estão configuradas corretamente
 * @return bool
 */
function isEmailConfigured() {
    // Verificar se todas as constantes obrigatórias estão definidas e não vazias
    $required = [
        'SMTP_HOST' => SMTP_HOST,
        'SMTP_PORT' => SMTP_PORT,
        'SMTP_USERNAME' => SMTP_USERNAME,
        'SMTP_PASSWORD' => SMTP_PASSWORD,
        'SMTP_FROM_EMAIL' => SMTP_FROM_EMAIL
    ];
    
    foreach ($required as $name => $value) {
        if (empty($value)) {
            error_log("[EmailConfig] Configuração ausente: $name");
            return false;
        }
    }
    
    // Verificar se a porta é válida
    if (!in_array(SMTP_PORT, [25, 465, 587, 2525])) {
        error_log("[EmailConfig] Porta SMTP inválida: " . SMTP_PORT);
        return false;
    }
    
    return true;
}
?>