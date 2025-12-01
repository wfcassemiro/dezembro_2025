<?php
session_start();
require_once __DIR__ . '/../config/database.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Login - Watchlist</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; background: #f5f5f5; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        .result { margin-top: 20px; padding: 15px; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; }
        .error { background: #f8d7da; color: #721c24; }
        .info { background: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Teste de Autenticação - Watchlist</h1>
        
        <div class="info">
            <h3>Status da Sessão:</h3>
            <p><strong>Logado:</strong> <?php echo isLoggedIn() ? 'SIM ✅' : 'NÃO ❌'; ?></p>
            <p><strong>User ID:</strong> <?php echo $_SESSION['user_id'] ?? 'Não definido'; ?></p>
            <p><strong>Session ID:</strong> <?php echo session_id(); ?></p>
            <p><strong>Dados da Sessão:</strong></p>
            <pre><?php print_r($_SESSION); ?></pre>
        </div>
        
        <hr>
        
        <h3>Teste da API Watchlist</h3>
        <p>Digite um ID de palestra para testar:</p>
        <input type="text" id="lectureId" placeholder="ID da palestra" value="test-123">
        <button onclick="testAddWatchlist()" class="btn">Adicionar à Watchlist</button>
        <button onclick="testRemoveWatchlist()" class="btn">Remover da Watchlist</button>
        <button onclick="testDebugSession()" class="btn">Debug Sessão</button>
        
        <div id="result"></div>
    </div>

    <script>
        function showResult(data, isError = false) {
            const result = document.getElementById('result');
            result.className = `result ${isError ? 'error' : 'success'}`;
            result.innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
        }
        
        function testAddWatchlist() {
            const lectureId = document.getElementById('lectureId').value;
            
            fetch('api_watchlist_tst.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    lecture_id: lectureId,
                    action: 'add'
                })
            })
            .then(response => response.json())
            .then(data => showResult(data, !data.success))
            .catch(error => showResult({error: error.message}, true));
        }
        
        function testRemoveWatchlist() {
            const lectureId = document.getElementById('lectureId').value;
            
            fetch('api_watchlist_tst.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    lecture_id: lectureId,
                    action: 'remove'
                })
            })
            .then(response => response.json())
            .then(data => showResult(data, !data.success))
            .catch(error => showResult({error: error.message}, true));
        }
        
        function testDebugSession() {
            fetch('debug_session_tst.php', {
                method: 'GET',
                credentials: 'same-origin'
            })
            .then(response => response.json())
            .then(data => showResult(data))
            .catch(error => showResult({error: error.message}, true));
        }
    </script>
</body>
</html>