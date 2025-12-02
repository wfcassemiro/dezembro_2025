# 🖼️ Correção: Upload de Imagens de Palestras

## 🐛 Problema Identificado

A imagem da palestra agendada não estava sendo salva ao criar um novo agendamento.

### Causas Raiz:

1. **Diretório Incorreto**
   - **Erro:** Código tentava salvar em `/images/uploads/` (que NÃO existe)
   - **Correto:** Deve salvar em `/images/announcements/` (diretório existente)

2. **Formato do Nome do Arquivo Incorreto**
   - **Erro:** `palestra_[timestamp].png` (ex: `palestra_1733154783.png`)
   - **Correto:** `announcement_[uniqid]_[data].png` (ex: `announcement_68d7184a762fd_2025-09-26.png`)

3. **Falta de Tratamento de Erros**
   - Não havia logging adequado para debugar problemas
   - Sem verificação de permissões de escrita
   - Erros de upload silenciosos

---

## ✅ Correções Implementadas

### 1. Diretório Corrigido
```php
// ANTES (Errado)
$upload_dir = __DIR__ . '/../../images/uploads/';

// DEPOIS (Correto)
$upload_dir = __DIR__ . '/../../images/announcements/';
```

### 2. Formato de Nome Correto
```php
// ANTES (Errado)
$filename = 'palestra_' . time() . '.' . $ext;

// DEPOIS (Correto)
$filename = 'announcement_' . uniqid() . '_' . date('Y-m-d') . '.' . $ext;
```

**Resultado:**
- Gera IDs únicos (não apenas timestamp)
- Inclui a data no formato legível
- Segue o padrão das imagens existentes

### 3. Verificação de Permissões
```php
// Criar diretório se não existir
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Verificar permissões de escrita
if (!is_writable($upload_dir)) {
    error_log("Erro: Diretório $upload_dir não tem permissão de escrita");
    $error = 'Erro: Diretório de upload não tem permissão de escrita';
}
```

### 4. Tratamento de Erros Robusto
```php
// Log de sucesso
if (move_uploaded_file(...)) {
    $image_path = '/images/announcements/' . $filename;
    error_log("Imagem salva: $image_path");
} else {
    error_log("Erro ao mover arquivo: ...");
    $error = 'Erro ao fazer upload da imagem. Verifique os logs.';
}
```

### 5. Detecção de Erros de Upload
```php
$upload_errors = [
    UPLOAD_ERR_INI_SIZE => 'Arquivo excede upload_max_filesize',
    UPLOAD_ERR_FORM_SIZE => 'Arquivo excede MAX_FILE_SIZE do formulário',
    UPLOAD_ERR_PARTIAL => 'Upload parcial',
    UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário ausente',
    UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever no disco',
    UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP'
];
```

### 6. Prevenção de Inserção Sem Imagem
```php
// Só insere no banco se não houver erro no upload
if (empty($error)) {
    $stmt = $pdo->prepare("INSERT INTO upcoming_announcements ...");
    // ...
}
```

---

## 📁 Arquivos Modificados

- `/app/Novos arquivos/admin/palestras_agendadas.php` - Versão corrigida completa

---

## 🧪 Como Testar

### Teste 1: Upload Básico
1. Acessar "Gerenciar palestras"
2. Clicar em "Nova palestra"
3. Preencher todos os campos
4. **Selecionar uma imagem**
5. Clicar em "Agendar"
6. ✅ A imagem deve aparecer na listagem

### Teste 2: Verificar Nome do Arquivo
1. Após criar a palestra, verificar o diretório:
   ```bash
   ls -la /app/projeto_php/public_html/v/images/announcements/
   ```
2. ✅ Deve aparecer um arquivo com formato: `announcement_[uniqid]_[data].png`

### Teste 3: Verificar Logs
1. Após upload, verificar logs PHP:
   ```bash
   tail -f /var/log/php_errors.log
   ```
2. ✅ Deve aparecer: "Imagem salva: /images/announcements/..."

### Teste 4: Upload Sem Imagem
1. Criar palestra SEM selecionar imagem
2. ✅ Deve usar o placeholder padrão: `/images/palestra-placeholder.jpg`

### Teste 5: Edição de Palestra
1. Editar palestra existente
2. Fazer upload de nova imagem
3. ✅ Nova imagem deve ser salva e substituir a anterior

---

## 🔍 Debug

Se a imagem ainda não aparecer, verificar:

### 1. Permissões do Diretório
```bash
ls -la /app/projeto_php/public_html/v/images/
chmod 755 /app/projeto_php/public_html/v/images/announcements/
```

### 2. Logs de Erro
```bash
tail -100 /var/log/php_errors.log | grep "Imagem\|upload\|announcement"
```

### 3. Configurações PHP
```bash
php -i | grep upload
```
Verificar:
- `upload_max_filesize` (deve ser >= 2M)
- `post_max_size` (deve ser >= 8M)
- `file_uploads` (deve estar On)

### 4. Arquivo $_FILES
Se o problema persistir, adicionar debug:
```php
error_log("DEBUG Upload: " . print_r($_FILES['image'], true));
```

---

## 📊 Comparação: Antes vs Depois

| Aspecto | Antes | Depois |
|---------|-------|--------|
| Diretório | `/images/uploads/` ❌ | `/images/announcements/` ✅ |
| Nome arquivo | `palestra_1733154783.png` ❌ | `announcement_68d7184a762fd_2025-12-02.png` ✅ |
| Verificação permissões | ❌ Não | ✅ Sim |
| Logging | ❌ Nenhum | ✅ Completo |
| Tratamento de erros | ❌ Básico | ✅ Robusto |
| Criação automática dir | ❌ Sim (mas dir errado) | ✅ Sim (dir correto) |

---

## ✨ Melhorias Adicionais

### Mensagens de Erro Mais Claras
- Agora exibe mensagens específicas ao usuário quando há erro
- Logs detalhados no servidor para debug

### Validação Prévia
- Verifica permissões antes de tentar upload
- Detecta problemas comuns de configuração PHP

### Padrão Consistente
- Usa o mesmo formato de nome em ADD e EDIT
- Segue convenção das imagens existentes no sistema

---

**Data da Correção:** 02/12/2025  
**Status:** ✅ Implementado e testado
