<?php
// Teste de autenticação simples
require_once __DIR__ . '/../config/database.php';

echo "<h1>🔍 Teste de Autenticação</h1>";

echo "<div style='background: #f0f0f0; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
echo "<h3>Informações da Sessão:</h3>";
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Session Status:</strong> " . session_status() . " (1=disabled, 2=active, 3=none)</p>";

if (isset($_SESSION) && is_array($_SESSION)) {
    echo "<p><strong>Dados da sessão:</strong></p>";
    echo "<pre>";
    foreach ($_SESSION as $key => $value) {
        echo htmlspecialchars($key) . " => " . htmlspecialchars(print_r($value, true)) . "\n";
    }
    echo "</pre>";
} else {
    echo "<p style='color: red;'>❌ Nenhum dado de sessão encontrado</p>";
}
echo "</div>";

echo "<div style='background: #e6f3ff; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
echo "<h3>Testes de Função:</h3>";

if (function_exists('isLoggedIn')) {
    $isLoggedIn = isLoggedIn();
    echo "<p><strong>isLoggedIn():</strong> " . ($isLoggedIn ? '✅ true' : '❌ false') . "</p>";
} else {
    echo "<p style='color: red;'>❌ Função isLoggedIn não encontrada</p>";
}

if (function_exists('hasVideotecaAccess')) {
    $hasAccess = hasVideotecaAccess();
    echo "<p><strong>hasVideotecaAccess():</strong> " . ($hasAccess ? '✅ true' : '❌ false') . "</p>";
} else {
    echo "<p style='color: red;'>❌ Função hasVideotecaAccess não encontrada</p>";
}

if (function_exists('isSubscriber')) {
    $isSubscriber = isSubscriber();
    echo "<p><strong>isSubscriber():</strong> " . ($isSubscriber ? '✅ true' : '❌ false') . "</p>";
} else {
    echo "<p style='color: red;'>❌ Função isSubscriber não encontrada</p>";
}

if (function_exists('isAdmin')) {
    $isAdmin = isAdmin();
    echo "<p><strong>isAdmin():</strong> " . ($isAdmin ? '✅ true' : '❌ false') . "</p>";
} else {
    echo "<p style='color: red;'>❌ Função isAdmin não encontrada</p>";
}
echo "</div>";

// Botões de teste
echo "<div style='background: #fff3cd; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
echo "<h3>🧪 Ações de Teste:</h3>";

if (!isLoggedIn()) {
    echo "<p>Para testar a videoteca, você precisa estar logado. Clique no botão abaixo para simular login:</p>";
    echo "<a href='?simulate_login=1' style='background: #007bff; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Simular Login</a>";
}

if (isset($_GET['simulate_login'])) {
    // Simular um login para teste
    $_SESSION['user_id'] = 'test_user_' . time();
    $_SESSION['user_name'] = 'Usuário Teste';
    $_SESSION['user_email'] = 'teste@translators101.com';
    $_SESSION['user_role'] = 'subscriber';
    $_SESSION['is_subscriber'] = 1;
    
    echo "<div style='background: #d4edda; padding: 10px; margin: 10px 0; border: 1px solid #c3e6cb; border-radius: 5px;'>";
    echo "✅ Login simulado com sucesso!";
    echo "</div>";
    
    echo "<script>setTimeout(function(){ location.href = '?'; }, 1000);</script>";
}

echo "<p><a href='videoteca.php' style='background: #28a745; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>Testar Videoteca</a>";
echo "<a href='videoteca.php?debug=1' style='background: #ffc107; color: black; padding: 10px 15px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>Videoteca com Debug</a>";
echo "<a href='videoteca.php?force=1' style='background: #dc3545; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Forçar Acesso</a></p>";
echo "</div>";

// Informações do sistema
echo "<div style='background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 5px;'>";
echo "<h3>ℹ️ Informações do Sistema:</h3>";
echo "<p><strong>PHP Version:</strong> " . PHP_VERSION . "</p>";
echo "<p><strong>Current Directory:</strong> " . __DIR__ . "</p>";
echo "<p><strong>Database Config Path:</strong> " . __DIR__ . '/../config/database.php' . "</p>";
echo "<p><strong>Database Config Exists:</strong> " . (file_exists(__DIR__ . '/../config/database.php') ? '✅ Sim' : '❌ Não') . "</p>";

// Testar conexão com banco
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SELECT 1");
        echo "<p><strong>Conexão com Banco:</strong> ✅ OK</p>";
    } catch (Exception $e) {
        echo "<p><strong>Conexão com Banco:</strong> ❌ Erro - " . htmlspecialchars($e->getMessage()) . "</p>";
    }
} else {
    echo "<p><strong>Conexão com Banco:</strong> ❌ Variável \$pdo não encontrada</p>";
}
echo "</div>";
?>