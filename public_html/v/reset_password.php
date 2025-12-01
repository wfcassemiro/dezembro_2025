<?php
require_once __DIR__ . '/config/database.php';

$token = $_GET['token'] ?? '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'];
    $new_password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([$token]);
    $reset = $stmt->fetch();

    if ($reset) {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hashed, $reset['user_id']]);
        $pdo->prepare("DELETE FROM password_resets WHERE token = ?")->execute([$token]);
        $message = "Senha redefinida com sucesso! Você já pode fazer login.";
    } else {
        $message = "Token inválido ou expirado.";
    }
}
?>

<?php include __DIR__ . '/vision/includes/head.php'; ?>
<?php include __DIR__ . '/vision/includes/header.php'; ?>

<div class="main-content">
    <div class="video-card" style="max-width: 500px; margin: 0 auto;">
        <h2><i class="fas fa-key"></i> Redefinir senha</h2>

        <?php if ($message): ?>
            <div class="alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if (!$message || strpos($message, 'sucesso') === false): ?>
        <form method="POST" class="vision-form">
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Nova senha</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-actions">
                <button type="submit" class="cta-btn">
                    <i class="fas fa-save"></i> Redefinir senha
                </button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>