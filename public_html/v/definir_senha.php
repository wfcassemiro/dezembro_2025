<?php
session_start();
require_once __DIR__ . '/config/database.php';

$token = $_GET['token'] ?? '';
$message = '';
$error = '';
$user = null;

// Verificar se o token foi fornecido
if (empty($token)) {
    $error = 'Token inválido ou não fornecido.';
} else {
    // Buscar usuário pelo token e verificar se não expirou
    try {
        $stmt = $pdo->prepare('
            SELECT * FROM users 
            WHERE password_reset_token = ? 
            AND password_reset_expires > NOW()
        ');
        $stmt->execute([$token]);
        $user = $stmt->fetch();
        
        if (!$user) {
            $error = 'Token inválido ou expirado. Solicite um novo link.';
        }
    } catch (Exception $e) {
        $error = 'Erro interno. Tente novamente mais tarde.';
    }
}

// Processar definição da nova senha
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (empty($password)) {
        $error = 'Por favor, digite uma senha.';
    } elseif (strlen($password) < 6) {
        $error = 'A senha deve ter pelo menos 6 caracteres.';
    } elseif ($password !== $confirm_password) {
        $error = 'As senhas não conferem.';
    } else {
        try {
            // Atualizar senha e limpar token
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('
                UPDATE users 
                SET password_hash = ?, 
                    password_reset_token = NULL, 
                    password_reset_expires = NULL,
                    first_login = FALSE
                WHERE id = ?
            ');
            $stmt->execute([$password_hash, $user['id']]);
            
            $message = 'Senha definida com sucesso! Você já pode fazer login.';
            
            // Limpar dados do usuário para evitar nova tentativa
            $user = null;
            
        } catch (Exception $e) {
            $error = 'Erro ao definir senha. Tente novamente.';
        }
    }
}

$page_title = 'Definir Senha - Translators101';
$page_description = 'Defina sua nova senha de acesso';

include __DIR__ . '/vision/includes/head.php';
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-key"></i> Definir Nova Senha</h1>
            <p>Crie uma senha segura para sua conta</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($message); ?>
            <br><br>
            <a href="login.php" class="cta-btn">
                <i class="fas fa-sign-in-alt"></i> Fazer Login
            </a>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo htmlspecialchars($error); ?>
            <?php if (strpos($error, 'Token inválido') !== false): ?>
                <br><br>
                <a href="contato.php" class="page-btn">
                    <i class="fas fa-envelope"></i> Solicitar Novo Link
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($user && empty($message)): ?>
        <div class="video-card">
            <h2><i class="fas fa-user-circle"></i> Olá, <?php echo htmlspecialchars($user['name']); ?>!</h2>
            <p class="text-light">Defina uma senha segura para sua conta.</p>
            
            <form method="POST" class="vision-form">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                
                <div class="form-grid">
                    <div class="form-group form-group-wide">
                        <label for="password">
                            <i class="fas fa-lock"></i> Nova Senha
                        </label>
                        <input type="password" id="password" name="password" required
                               placeholder="Digite sua nova senha (mín. 6 caracteres)"
                               minlength="6">
                    </div>

                    <div class="form-group form-group-wide">
                        <label for="confirm_password">
                            <i class="fas fa-lock"></i> Confirmar Nova Senha
                        </label>
                        <input type="password" id="confirm_password" name="confirm_password" required
                               placeholder="Digite novamente sua senha"
                               minlength="6">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="cta-btn">
                        <i class="fas fa-check"></i> Definir Senha
                    </button>
                </div>
            </form>
        </div>

        <div class="video-card">
            <h3><i class="fas fa-shield-alt"></i> Dicas de Segurança</h3>
            <ul class="security-tips">
                <li><i class="fas fa-check-circle"></i> Use pelo menos 6 caracteres</li>
                <li><i class="fas fa-check-circle"></i> Combine letras maiúsculas e minúsculas</li>
                <li><i class="fas fa-check-circle"></i> Inclua números e símbolos</li>
                <li><i class="fas fa-check-circle"></i> Evite informações pessoais óbvias</li>
            </ul>
        </div>
    <?php endif; ?>

    <div class="video-card">
        <h3><i class="fas fa-info-circle"></i> Informações Importantes</h3>
        <ul class="info-list">
            <li>Este link é válido por 7 dias</li>
            <li>Após definir a senha, você poderá fazer login normalmente</li>
            <li>Guarde sua senha em local seguro</li>
            <li>Em caso de problemas, entre em contato conosco</li>
        </ul>
    </div>
</div>

<style>
.security-tips, .info-list {
    list-style: none;
    padding: 0;
}

.security-tips li, .info-list li {
    padding: 0.5rem 0;
    color: var(--text-light);
}

.security-tips li i {
    color: #10b981;
    margin-right: 0.5rem;
}

.form-group-wide {
    grid-column: 1 / -1;
}
</style>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>