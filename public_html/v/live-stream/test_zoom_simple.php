<?php
/**
 * Teste Simples de Autenticação Zoom
 */

require_once 'zoom_auth.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Zoom</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .result {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .success {
            border-left: 5px solid #48bb78;
        }
        .error {
            border-left: 5px solid #e53e3e;
        }
        pre {
            background: #f7fafc;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
        }
        h1 {
            color: #2d3748;
        }
        .btn {
            background: #667eea;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
    </style>
</head>
<body>
    <h1>🧪 Teste de Autenticação Zoom</h1>
    
    <?php
    // Limpar log anterior
    file_put_contents(__DIR__ . '/zoom_debug_log.txt', '');
    
    echo '<div class="result">';
    echo '<h2>1. Testando obtenção de token...</h2>';
    
    $token = getZoomAccessToken(true);
    
    if ($token) {
        echo '<p style="color: #48bb78;">✓ Token obtido com sucesso!</p>';
        echo '<p><strong>Token (primeiros 20 caracteres):</strong> ' . substr($token, 0, 20) . '...</p>';
    } else {
        echo '<p style="color: #e53e3e;">✗ Falha ao obter token</p>';
        echo '<p>Verifique o log abaixo para mais detalhes.</p>';
    }
    echo '</div>';
    
    if ($token) {
        echo '<div class="result">';
        echo '<h2>2. Testando API do Zoom...</h2>';
        
        $result = zoomApiRequest('/users/me');
        
        if ($result['success']) {
            echo '<p style="color: #48bb78;">✓ API funcionando corretamente!</p>';
            echo '<h3>Informações do Usuário:</h3>';
            echo '<pre>' . json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
        } else {
            echo '<p style="color: #e53e3e;">✗ Erro na API: ' . htmlspecialchars($result['error']) . '</p>';
        }
        echo '</div>';
    }
    
    // Mostrar log
    echo '<div class="result">';
    echo '<h2>📋 Log de Debug</h2>';
    $logContent = file_get_contents(__DIR__ . '/zoom_debug_log.txt');
    echo '<pre>' . htmlspecialchars($logContent) . '</pre>';
    echo '</div>';
    ?>
    
    <a href="zoom_manage.php" class="btn">Ir para Gerenciamento de Reuniões</a>
</body>
</html>