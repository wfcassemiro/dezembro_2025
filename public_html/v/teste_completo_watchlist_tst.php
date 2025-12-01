<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn()) {
    die('Faça login primeiro');
}

$user_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Completo Watchlist</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1000px; margin: 0 auto; }
        .test-card { background: white; margin: 20px 0; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { border-left: 5px solid #28a745; }
        .error { border-left: 5px solid #dc3545; }
        .btn { background: #007bff; color: white; padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #0056b3; }
        .result { margin-top: 15px; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 0.9em; white-space: pre-wrap; }
        .result.success { background: #d4edda; color: #155724; }
        .result.error { background: #f8d7da; color: #721c24; }
        input[type="text"] { padding: 8px; margin: 5px; width: 300px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🧪 Teste Completo da Watchlist</h1>
        <p><strong>Usuário:</strong> <?php echo htmlspecialchars($user_id); ?></p>

        <!-- 1. Verificar Estrutura -->
        <div class="test-card">
            <h3>1. 📊 Verificar Estrutura do Banco</h3>
            <button class="btn" onclick="checkDatabase()">Verificar Banco</button>
            <div id="dbResult"></div>
        </div>

        <!-- 2. Testar Adição -->
        <div class="test-card">
            <h3>2. ➕ Testar Adição à Watchlist</h3>
            <input type="text" id="addLectureId" placeholder="ID da palestra" value="">
            <button class="btn" onclick="testAdd()">Adicionar</button>
            <div id="addResult"></div>
        </div>

        <!-- 3. Verificar Lista Atual -->
        <div class="test-card">
            <h3>3. 📋 Lista Atual da Watchlist</h3>
            <button class="btn" onclick="checkWatchlist()">Verificar Lista</button>
            <div id="listResult"></div>
        </div>

        <!-- 4. Testar Remoção -->
        <div class="test-card">
            <h3>4. ➖ Testar Remoção</h3>
            <input type="text" id="removeLectureId" placeholder="ID da palestra">
            <button class="btn" onclick="testRemove()">Remover</button>
            <div id="removeResult"></div>
        </div>

        <!-- 5. Buscar Palestras Disponíveis -->
        <div class="test-card">
            <h3>5. 🎥 Palestras Disponíveis para Testar</h3>
            <button class="btn" onclick="getLectures()">Buscar Palestras</button>
            <div id="lecturesResult"></div>
        </div>
    </div>

    <script>
        function showResult(elementId, data, isError = false) {
            const element = document.getElementById(elementId);
            element.className = `result ${isError ? 'error' : 'success'}`;
            element.textContent = JSON.stringify(data, null, 2);
        }

        // 1. Verificar estrutura do banco
        function checkDatabase() {
            fetch('/debug_watchlist_tst.php')
                .then(response => response.json())
                .then(data => showResult('dbResult', data, data.status === 'error'))
                .catch(error => showResult('dbResult', {error: error.message}, true));
        }

        // 2. Testar adição
        function testAdd() {
            const lectureId = document.getElementById('addLectureId').value;
            if (!lectureId) {
                alert('Digite um ID de palestra');
                return;
            }

            fetch('/api_watchlist_tst.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    lecture_id: lectureId,
                    action: 'add'
                })
            })
            .then(response => response.json())
            .then(data => {
                showResult('addResult', data, !data.success);
                if (data.success) {
                    // Auto-atualizar lista após sucesso
                    setTimeout(checkWatchlist, 500);
                }
            })
            .catch(error => showResult('addResult', {error: error.message}, true));
        }

        // 3. Verificar lista atual
        function checkWatchlist() {
            // Fazer uma requisição para verificar quantos itens estão na lista
            fetch('/debug_watchlist_tst.php')
                .then(response => response.json())
                .then(data => {
                    const listInfo = {
                        total_items: data.watchlist_count || 0,
                        items: data.watchlist_items || [],
                        table_exists: data.table_exists
                    };
                    showResult('listResult', listInfo);
                })
                .catch(error => showResult('listResult', {error: error.message}, true));
        }

        // 4. Testar remoção
        function testRemove() {
            const lectureId = document.getElementById('removeLectureId').value;
            if (!lectureId) {
                alert('Digite um ID de palestra');
                return;
            }

            fetch('/api_watchlist_tst.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    lecture_id: lectureId,
                    action: 'remove'
                })
            })
            .then(response => response.json())
            .then(data => {
                showResult('removeResult', data, !data.success);
                if (data.success) {
                    setTimeout(checkWatchlist, 500);
                }
            })
            .catch(error => showResult('removeResult', {error: error.message}, true));
        }

        // 5. Buscar palestras disponíveis
        function getLectures() {
            fetch('/debug_watchlist_tst.php')
                .then(response => response.json())
                .then(data => {
                    const lectureInfo = {
                        total_lectures: data.lectures_count || 0,
                        sample_lectures: data.sample_lectures || []
                    };
                    showResult('lecturesResult', lectureInfo);
                    
                    // Auto-preencher primeiro ID disponível
                    if (data.sample_lectures && data.sample_lectures.length > 0) {
                        document.getElementById('addLectureId').value = data.sample_lectures[0].id;
                        document.getElementById('removeLectureId').value = data.sample_lectures[0].id;
                    }
                })
                .catch(error => showResult('lecturesResult', {error: error.message}, true));
        }

        // Auto-executar verificações iniciais
        window.onload = function() {
            checkDatabase();
            getLectures();
        };
    </script>
</body>
</html>