# Sistema de Certificados - Correção de Emails com PHPMailer/SMTP

## 📋 Resumo das Correções

O sistema de certificados não estava enviando emails porque:

1. A função `isEmailConfigured()` **sempre retornava `false`**
2. O código usava `mail()` nativo do PHP ao invés de PHPMailer/SMTP
3. As configurações SMTP estavam incompletas

## 📁 Arquivos Corrigidos

| Arquivo | Descrição |
|---------|----------|
| `email_config.php` | Configurações SMTP centralizadas |
| `email.php` | Classe EmailSender usando PHPMailer |
| `certificados.php` | Sistema de certificados corrigido |
| `emails.php` | Painel de envio de emails em massa |

## 🚀 Instalação

### Passo 1: Instalar PHPMailer via Composer

```bash
cd /seu-projeto
composer require phpmailer/phpmailer
```

Ou adicione ao `composer.json`:
```json
{
    "require": {
        "phpmailer/phpmailer": "^6.8"
    }
}
```

### Passo 2: Configurar Credenciais SMTP

Edite `email_config.php`:

```php
// Servidor SMTP
define('SMTP_HOST', 'br1189.hostgator.com.br');
define('SMTP_PORT', 587);        // 587 para TLS, 465 para SSL
define('SMTP_SECURE', 'tls');    // 'tls' ou 'ssl'

// Credenciais
define('SMTP_USERNAME', 'contato@translators101.com');
define('SMTP_PASSWORD', 'SUA_SENHA_AQUI');

// Remetente
define('SMTP_FROM_EMAIL', 'contato@translators101.com');
define('SMTP_FROM_NAME', 'Translators101');

// Debug (0=off, 2=verbose)
define('SMTP_DEBUG', 0);
```

### Passo 3: Substituir Arquivos

1. Faça backup dos arquivos originais
2. Copie os arquivos corrigidos para os locais:
   - `email_config.php` → `/config/email_config.php`
   - `email.php` → `/config/email.php`
   - `certificados.php` → `/admin/certificados.php`
   - `emails.php` → `/admin/emails.php`

### Passo 4: Ajustar Caminhos

Verifique se o `require` do autoload está correto em `email.php`:

```php
// O arquivo procura automaticamente em:
// - __DIR__ . '/vendor/autoload.php'
// - __DIR__ . '/../vendor/autoload.php'
// - __DIR__ . '/../../vendor/autoload.php'
```

## ⚙️ Configurações SMTP por Provedor

### Hostgator
```php
define('SMTP_HOST', 'br1189.hostgator.com.br');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
```

### Hostinger
```php
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
```

### Gmail
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
// ⚠️ Use "Senha de App", não a senha normal
// Ative em: Google Account → Security → 2FA → App passwords
```

### Outlook / Office 365
```php
define('SMTP_HOST', 'smtp.office365.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
```

### Amazon SES
```php
define('SMTP_HOST', 'email-smtp.us-east-1.amazonaws.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
```

## 🧪 Testando

### Via Painel Admin

1. Acesse `/admin/emails.php`
2. Verifique se aparece "Sistema de Email Configurado" ✅
3. Use "Testar Configuração" para enviar email de teste

### Via Código

```php
<?php
require_once 'email_config.php';
require_once 'email.php';

$emailSender = new EmailSender();

// Verificar se PHPMailer está instalado
if (!$emailSender->isPHPMailerAvailable()) {
    die("PHPMailer não está instalado!");
}

// Verificar configurações
if (!isEmailConfigured()) {
    die("Configurações SMTP incompletas!");
}

// Enviar teste
$result = $emailSender->sendEmail(
    'teste@email.com',
    'Nome Teste',
    'Teste de Email',
    '<h1>Teste</h1><p>Email de teste funcionando!</p>'
);

echo $result ? "✅ Enviado!" : "❌ Falhou";
```

## 🔍 Debug

### Ativar logs detalhados

Em `email_config.php`:
```php
define('SMTP_DEBUG', 2); // Mostra toda comunicação SMTP
```

### Verificar logs

```bash
# Logs do PHP
tail -f /var/log/php/error.log

# Logs do Apache
tail -f /var/log/apache2/error.log

# Logs específicos do sistema
tail -f /path/to/certificate_errors.log
```

## 🔧 Solução de Problemas

### "PHPMailer não está instalado"
```bash
composer require phpmailer/phpmailer
```

### "Connection timed out"
- Verifique se a porta 587 (ou 465) está aberta no firewall
- Teste: `telnet smtp.host.com 587`

### "Authentication failed"
- Verifique usuário e senha
- Para Gmail: use "Senha de App"
- Alguns hosts bloqueiam SMTP de scripts

### "Certificate verify failed"
Adicione em `email.php` após `$mail = new PHPMailer(true);`:
```php
$mail->SMTPOptions = [
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    ]
];
```

### Emails chegando como SPAM
1. Configure SPF no DNS:
   ```
   v=spf1 include:_spf.hostgator.com.br ~all
   ```
2. Configure DKIM
3. Use email do mesmo domínio do site
4. Evite palavras de spam no assunto

## 📧 Como Usar no Código

### Enviar email de certificado
```php
require_once 'email.php';

sendCertificateEmail(
    'usuario@email.com',
    'Nome do Usuário',
    'uuid-do-certificado',
    'Título da Palestra'
);
```

### Enviar email personalizado
```php
$emailSender = new EmailSender();

$html = EmailTemplates::getCustomEmailTemplate(
    'Assunto',
    'Conteúdo do email em HTML'
);

$emailSender->sendEmail(
    'destinatario@email.com',
    'Nome',
    'Assunto',
    $html
);
```

### Enviar com anexo
```php
$emailSender = new EmailSender();

$emailSender->sendEmailWithAttachment(
    'destinatario@email.com',
    'Nome',
    'Certificado em anexo',
    '<p>Segue seu certificado em anexo.</p>',
    '/path/to/certificate.pdf',
    'certificado.pdf'
);
```

## ✅ Checklist Final

- [ ] PHPMailer instalado (`composer require phpmailer/phpmailer`)
- [ ] Credenciais SMTP configuradas em `email_config.php`
- [ ] Arquivos substituídos nos locais corretos
- [ ] Caminho do autoload verificado
- [ ] Teste de email funcionando
- [ ] SPF/DKIM configurados (para evitar spam)

---

**Desenvolvido para Translators101** | Sistema de Certificados T101