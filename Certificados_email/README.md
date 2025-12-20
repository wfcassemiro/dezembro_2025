# Sistema de Certificados - Correção de Emails

## Problema Identificado

O sistema de certificados não estava enviando emails devido aos seguintes problemas:

1. **`email.php`**: A função `isEmailConfigured()` estava retornando `false` sempre (linha de código: `return false; // Always return false for testing environment`)

2. **`email_config.php`**: Arquivo com configurações incompletas

3. **`certificados.php`**: Usava a função nativa `mail()` do PHP que não funciona corretamente em muitos servidores de hosting

4. **`emails.php`**: Dependia de `isEmailConfigured()` que retornava `false`

## Arquivos Corrigidos

### 1. `email_config.php`
- Configurações SMTP completas
- Função `isEmailConfigured()` corrigida para verificar todas as credenciais
- **Configure suas credenciais SMTP neste arquivo**

### 2. `email.php`
- Classe `EmailSender` com suporte a:
  - PHPMailer (se disponível)
  - SMTP via Socket (fallback)
  - Função `mail()` nativa (último recurso)
- Templates de email profissionais
- Funções helper para envio de diferentes tipos de email
- Logs detalhados para debug

### 3. `certificados.php`
- Função `sendCertificateEmailNotification()` corrigida
- Integração com o novo sistema de email
- Logs de sucesso/falha por email
- Estatísticas detalhadas de envio

### 4. `emails.php`
- Sistema de envio em massa corrigido
- Teste de configuração de email
- Indicador visual do status da configuração
- Tratamento de erros melhorado

## Como Usar

### Passo 1: Configure as Credenciais SMTP

Edite o arquivo `email_config.php` com suas credenciais:

```php
define('SMTP_HOST', 'seu-servidor-smtp.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USERNAME', 'seu-email@dominio.com');
define('SMTP_PASSWORD', 'sua-senha');
define('SMTP_FROM_EMAIL', 'seu-email@dominio.com');
define('SMTP_FROM_NAME', 'Nome do Remetente');
```

### Passo 2: Substitua os Arquivos

Substitua os arquivos originais pelos corrigidos:

1. Faça backup dos arquivos originais
2. Copie os arquivos da pasta `Certificados_email` para os locais corretos:
   - `email_config.php` → `/config/email_config.php`
   - `email.php` → `/config/email.php` ou local original
   - `certificados.php` → `/admin/certificados.php`
   - `emails.php` → `/admin/emails.php`

### Passo 3: Teste a Configuração

1. Acesse o painel de emails (`/admin/emails.php`)
2. Verifique se aparece "Sistema de Email Configurado"
3. Use a opção "Testar Configuração" para enviar um email de teste
4. Verifique os logs do servidor em caso de falha

## Configurações SMTP Comuns

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
// Nota: Use "Senha de App" para Gmail
```

### Outlook/Office 365
```php
define('SMTP_HOST', 'smtp.office365.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
```

## Solução de Problemas

### Emails não estão sendo enviados
1. Verifique se as credenciais SMTP estão corretas
2. Verifique os logs do PHP: `error_log`
3. Teste a conexão SMTP manualmente
4. Verifique se a porta 587 (ou 465) está aberta no firewall

### "Sistema de Email NÃO Configurado"
- Verifique se todas as constantes estão definidas em `email_config.php`
- Confirme que não há valores vazios

### Erros de autenticação
- Confirme usuário e senha
- Para Gmail: use "Senha de App" ao invés da senha normal
- Verifique se autenticação em dois fatores está ativada

### Emails chegando como spam
- Configure SPF e DKIM no DNS
- Use um email do mesmo domínio do site
- Evite palavras de spam no assunto

## Logs

Os logs de email são gravados em:
- Logs do PHP: `/var/log/php/error.log` (ou configuração do servidor)
- Logs do certificado: `/certificate_errors.log`

## Dependências Opcionais

### PHPMailer (Recomendado)

Para melhor compatibilidade, instale o PHPMailer via Composer:

```bash
composer require phpmailer/phpmailer
```

O sistema detecta automaticamente se o PHPMailer está instalado e o usa preferencialmente.

## Suporte

Em caso de dúvidas ou problemas:
1. Verifique os logs de erro
2. Teste com a função de teste de email
3. Confirme as configurações SMTP com seu provedor de hospedagem