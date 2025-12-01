<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo '<h1>🔐 Teste Login - wrbl.traduz@gmail.com</h1>';

// Incluir banco
require_once 'config/database.php';
echo '<p>✅ Banco conectado</p>';

// Verificar especificamente o usuário wrbl.traduz@gmail.com
try {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute(['wrbl.traduz@gmail.com']);
    $user_target = $stmt->fetch();
    
    if ($user_target) {
        echo '<h2>✅ Usuário wrbl.traduz@gmail.com encontrado!</h2>';
        echo '<p><strong>Nome:</strong> ' . htmlspecialchars($user_target['name']) . '</p>';
        echo '<p><strong>ID:</strong> ' . $user_target['id'] . '</p>';
        echo '<p><strong>Email:</strong> ' . htmlspecialchars($user_target['email']) . '</p>';
        echo '<p><strong>Role:</strong> ' . ($user_target['role'] ?? 'N/A') . '</p>';
        
        $password_field = $user_target['password_hash'] ?? $user_target['password'] ?? '';
        echo '<p><strong>Senha no banco:</strong> ' . (strlen($password_field) > 0 ? 'Existe (' . strlen($password_field) . ' caracteres)' : 'Campo vazio') . '</p>';
        
        if ($password_field) {
            echo '<p><strong>Tipo de hash:</strong> ';
            if (strlen($password_field) === 32 && ctype_xdigit($password_field)) {
                echo 'Provavelmente MD5</p>';
            } elseif (strlen($password_field) === 40 && ctype_xdigit($password_field)) {
                echo 'Provavelmente SHA1</p>';
            } elseif (substr($password_field, 0, 4) === '$2y$') {
                echo 'password_hash moderno</p>';
            } else {
                echo 'Formato desconhecido ou texto puro</p>';
            }
        }
    } else {
        echo '<h2>❌ Usuário wrbl.traduz@gmail.com NÃO encontrado!</h2>';
        
        // Mostrar usuários similares
        $stmt = $pdo->prepare('SELECT email FROM users WHERE email LIKE ?');
        $stmt->execute(['%wrbl%']);
        echo '<h3>📧 Emails similares encontrados:</h3>';
        while ($row = $stmt->fetch()) {
            echo '<p>• ' . htmlspecialchars($row['email']) . '</p>';
        }
    }
    
} catch (Exception $e) {
    echo '<p>❌ Erro: ' . $e->getMessage() . '</p>';
}

// Processar formulário se enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo '<hr><h2>📝 PROCESSANDO FORMULÁRIO...</h2>';
    
    $email = trim($_POST['email'] ?? '');
    $senha = $_POST['senha'] ?? '';
    
    echo '<p><strong>Email recebido:</strong> [' . htmlspecialchars($email) . ']</p>';
    echo '<p><strong>Senha recebida:</strong> ' . (strlen($senha) > 0 ? 'Preenchida (' . strlen($senha) . ' caracteres)' : 'Vazia') . '</p>';
    
    if (!empty($email) && !empty($senha)) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            
            if ($user) {
                echo '<p>✅ Usuário encontrado no banco</p>';
                
                $password_field = $user['password_hash'] ?? $user['password'] ?? '';
                
                // Testar diferentes formatos de senha
                $login_success = false;
                
                // Teste 1: password_verify
                if (password_verify($senha, $password_field)) {
                    echo '<p style=\"color: green;\">✅ LOGIN SUCESSO - password_hash</p>';
                    $login_success = true;
                }
                // Teste 2: MD5
                elseif (md5($senha) === $password_field) {
                    echo '<p style=\"color: orange;\">⚠️ LOGIN SUCESSO - MD5 (formato antigo)</p>';
                    $login_success = true;
                }
                // Teste 3: SHA1  
                elseif (sha1($senha) === $password_field) {
                    echo '<p style=\"color: orange;\">⚠️ LOGIN SUCESSO - SHA1 (formato antigo)</p>';
                    $login_success = true;
                }
                // Teste 4: Texto puro
                elseif ($senha === $password_field) {
                    echo '<p style=\"color: red;\">🚨 LOGIN SUCESSO - Texto puro (INSEGURO)</p>';
                    $login_success = true;
                } else {
                    echo '<p style=\"color: red;\">❌ SENHA INCORRETA</p>';
                    echo '<p>Hash MD5 da senha digitada: ' . md5($senha) . '</p>';
                    echo '<p>Hash SHA1 da senha digitada: ' . sha1($senha) . '</p>';
                    echo '<p>Primeiros 30 chars do hash no banco: ' . substr($password_field, 0, 30) . '...</p>';
                }
                
                if ($login_success) {
                    echo '<div style=\"background: lightgreen; padding: 15px; margin: 10px 0;\">';
                    echo '<h3>🎉 LOGIN REALIZADO COM SUCESSO!</h3>';
                    echo '<p>Agora sabemos que o problema não é com suas credenciais!</p>';
                    echo '<p><a href=\"login.php\" style=\"background: blue; color: white; padding: 10px; text-decoration: none;\">Testar Login Original</a></p>';
                    echo '</div>';
                }
            } else {
                echo '<p style=\"color: red;\">❌ Email não encontrado</p>';
            }
            
        } catch (Exception $e) {
            echo '<p style=\"color: red;\">❌ Erro na consulta: ' . $e->getMessage() . '</p>';
        }
    } else {
        echo '<p style=\"color: red;\">❌ Email e senha são obrigatórios</p>';
    }
    echo '<hr>';
}
?>

<form method=\"POST\" style=\"background: #f0f0f0; padding: 20px; margin: 20px 0; border-radius: 5px;\">
    <h3>🔑 Teste de Login</h3>
    <p>
        <label><strong>Email:</strong></label><br>
        <input type="email" name="email" value="wrbl.traduz@gmail.com" style=\"width: 300px; padding: 8px; font-size: 14px;\">
    </p>
    <p>
        <label><strong>Sua senha:</strong></label><br>
        <input type="password" name="senha" placeholder="Digite sua senha aqui" style=\"width: 300px; padding: 8px; font-size: 14px;\">
    </p>
    <p>
        <button type=\"submit\" style=\"background: #28a745; color: white; padding: 12px 25px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;\">🔐 TESTAR LOGIN</button>
    </p>
</form>

<div style=\"background: #e9ecef; padding: 15px; margin: 20px 0; border-radius: 5px;\">
    <h4>💡 Instruções:</h4>
    <p>1. <strong>O email já está preenchido</strong> com wrbl.traduz@gmail.com</p>
    <p>2. <strong>Digite sua senha</strong> no campo senha</p>
    <p>3. <strong>Clique em TESTAR LOGIN</strong></p>
    <p>4. <strong>O sistema vai testar</strong> todos os formatos de senha possíveis</p>
</div>