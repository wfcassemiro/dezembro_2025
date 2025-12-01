<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn()) {
    die('Faça login primeiro para usar esta ferramenta de debug');
}

$user_id = $_SESSION['user_id'];

// Executar debug
try {
    // 1. Verificar se a tabela existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'user_watchlist'");
    $table_exists = $stmt->fetch() ? true : false;
    
    // 2. Contar quantos itens o usuário tem na watchlist
    $watchlist_count = 0;
    $watchlist_items = [];
    
    if ($table_exists) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM user_watchlist WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $count_result = $stmt->fetch();
        $watchlist_count = $count_result['total'];
        
        // 3. Buscar todos os itens da watchlist do usuário
        $stmt = $pdo->prepare("
            SELECT w.*, l.title, l.speaker 
            FROM user_watchlist w 
            LEFT JOIN lectures l ON w.lecture_id = l.id 
            WHERE w.user_id = ?
            ORDER BY w.added_at DESC
        ");
        $stmt->execute([$user_id]);
        $watchlist_items = $stmt->fetchAll();
        
        // 4. Buscar estrutura da tabela
        $stmt = $pdo->query("DESCRIBE user_watchlist");
        $table_structure = $stmt->fetchAll();
    }
    
    // 5. Verificar se existem palestras na tabela lectures
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM lectures");
    $lectures_result = $stmt->fetch();
    $lectures_count = $lectures_result['total'];
    
    // 6. Buscar algumas palestras de exemplo
    $stmt = $pdo->query("SELECT id, title FROM lectures LIMIT 10");
    $sample_lectures = $stmt->fetchAll();

} catch (Exception $e) {
    $error_message = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Watchlist - Translators101</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; line-height: 1.6; }
        .container { max-width: 1200px; margin: 0 auto; }
        .debug-card { background: white; margin: 20px 0; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { border-left: 5px solid #28a745; background: #f8fff9; }
        .error { border-left: 5px solid #dc3545; background: #fff8f8; }
        .warning { border-left: 5px solid #ffc107; background: #fffdf0; }
        .info { border-left: 5px solid #17a2b8; background: #f0faff; }
        .btn { background: #007bff; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; text-decoration: none; display: inline-block; }
        .btn:hover { background: #0056b3; }
        .btn-success { background: #28a745; }
        .btn-danger { background: #dc3545; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px 12px; border: 1px solid #ddd; text-align: left; }
        th { background: #f8f9fa; font-weight: bold; }
        .status-ok { color: #28a745; font-weight: bold; }
        .status-error { color: #dc3545; font-weight: bold; }
        .code-block { background: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; padding: 10px; font-family: monospace; font-size: 0.9em; white-space: pre-wrap; }
        .test-section { margin: 20px 0; }
        .quick-actions { display: flex; gap: 10px; flex-wrap: wrap; margin: 15px 0; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Debug da Watchlist - Translators101</h1>
        <p><strong>Usuário logado:</strong> <?php echo htmlspecialchars($user_id); ?></p>
        
        <?php if (isset($error_message)): ?>
        <div class="debug-card error">
            <h2>❌ Erro Fatal</h2>
            <p><?php echo htmlspecialchars($error_message); ?></p>
        </div>
        <?php else: ?>
        
        <!-- Status da Tabela -->
        <div class="debug-card <?php echo $table_exists ? 'success' : 'error'; ?>">
            <h2>📊 Status da Tabela user_watchlist</h2>
            <p><strong>Tabela existe:</strong> 
                <span class="<?php echo $table_exists ? 'status-ok' : 'status-error'; ?>">
                    <?php echo $table_exists ? 'SIM ✅' : 'NÃO ❌'; ?>
                </span>
            </p>
            
            <?php if ($table_exists): ?>
                <p><strong>Itens na watchlist do usuário:</strong> 
                    <span class="<?php echo $watchlist_count > 0 ? 'status-ok' : 'status-error'; ?>">
                        <?php echo $watchlist_count; ?>
                    </span>
                </p>
                
                <h3>Estrutura da Tabela:</h3>
                <table>
                    <tr><th>Campo</th><th>Tipo</th><th>Nulo</th><th>Chave</th><th>Padrão</th></tr>
                    <?php foreach ($table_structure as $column): ?>
                    <tr>
                        <td><?php echo $column['Field']; ?></td>
                        <td><?php echo $column['Type']; ?></td>
                        <td><?php echo $column['Null']; ?></td>
                        <td><?php echo $column['Key']; ?></td>
                        <td><?php echo $column['Default'] ?: 'NULL'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            <?php else: ?>
                <div class="warning">
                    <h3>⚠️ Tabela Não Existe</h3>
                    <p>A tabela <code>user_watchlist</code> precisa ser criada. Execute o SQL:</p>
                    <div class="code-block">CREATE TABLE user_watchlist...</div>
                    <a href="create_watchlist_table_tst.sql" class="btn btn-success" target="_blank">📄 Baixar SQL</a>
                </div>
            <?php endif; ?>
        </div>

        <!-- Dados da Watchlist -->
        <?php if ($table_exists && $watchlist_count > 0): ?>
        <div class="debug-card success">
            <h2>📋 Itens na Sua Watchlist</h2>
            <table>
                <tr><th>ID</th><th>Palestra ID</th><th>Título</th><th>Palestrante</th><th>Adicionado em</th></tr>
                <?php foreach ($watchlist_items as $item): ?>
                <tr>
                    <td><?php echo substr($item['id'], 0, 8); ?>...</td>
                    <td><?php echo substr($item['lecture_id'], 0, 8); ?>...</td>
                    <td><?php echo htmlspecialchars($item['title'] ?: 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($item['speaker'] ?: 'N/A'); ?></td>
                    <td><?php echo date('d/m/Y H:i', strtotime($item['added_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <!-- Status das Palestras -->
        <div class="debug-card info">
            <h2>🎥 Status das Palestras</h2>
            <p><strong>Total de palestras no sistema:</strong> <?php echo $lectures_count; ?></p>
            
            <?php if (count($sample_lectures) > 0): ?>
            <h3>Exemplos de Palestras Disponíveis:</h3>
            <table>
                <tr><th>ID</th><th>Título</th><th>Ações</th></tr>
                <?php foreach ($sample_lectures as $lecture): ?>
                <tr>
                    <td><?php echo substr($lecture['id'], 0, 8); ?>...</td>
                    <td><?php echo htmlspecialchars($lecture['title']); ?></td>
                    <td>
                        <button class="btn" onclick="testAddWatchlist('<?php echo $lecture['id']; ?>')">
                            ➕ Testar Adicionar
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>

        <!-- Ações Rápidas -->
        <div class="debug-card">
            <h2>🔧 Ações Rápidas</h2>
            <div class="quick-actions">
                <a href="videoteca_tst.php" class="btn">📺 Ir para Videoteca</a>
                <a href="perfil_tst.php?debug=1" class="btn">👤 Perfil (com debug)</a>
                <button class="btn btn-success" onclick="refreshData()">🔄 Atualizar Dados</button>
                <button class="btn btn-danger" onclick="clearWatchlist()">🗑️ Limpar Watchlist</button>
            </div>
        </div>

        <!-- Teste da API -->
        <div class="debug-card">
            <h2>🧪 Teste da API</h2>
            <div class="test-section">
                <input type="text" id="testLectureId" placeholder="ID da palestra para testar" style="padding: 8px; margin-right: 10px; width: 300px;">
                <button class="btn" onclick="testAddWatchlist(document.getElementById('testLectureId').value)">➕ Adicionar</button>
                <button class="btn btn-danger" onclick="testRemoveWatchlist(document.getElementById('testLectureId').value)">➖ Remover</button>
            </div>
            <div id="apiResult" style="margin-top: 15px;"></div>
        </div>

        <?php endif; ?>
    </div>

    <script>
        function showResult(data, isError = false) {
            const result = document.getElementById('apiResult');
            result.className = `debug-card ${isError ? 'error' : 'success'}`;
            result.innerHTML = '<h3>' + (isError ? '❌ Erro' : '✅ Sucesso') + '</h3><div class="code-block">' + JSON.stringify(data, null, 2) + '</div>';
        }
        
        function testAddWatchlist(lectureId) {
            if (!lectureId) {
                alert('Digite um ID de palestra primeiro');
                return;
            }
            
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
            .then(data => {
                showResult(data, !data.success);
                if (data.success) {
                    setTimeout(() => location.reload(), 1500);
                }
            })
            .catch(error => showResult({error: error.message}, true));
        }
        
        function testRemoveWatchlist(lectureId) {
            if (!lectureId) {
                alert('Digite um ID de palestra primeiro');
                return;
            }
            
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
            .then(data => {
                showResult(data, !data.success);
                if (data.success) {
                    setTimeout(() => location.reload(), 1500);
                }
            })
            .catch(error => showResult({error: error.message}, true));
        }
        
        function refreshData() {
            location.reload();
        }
        
        function clearWatchlist() {
            if (confirm('Tem certeza que deseja limpar toda a sua watchlist?')) {
                // Implementar limpeza se necessário
                alert('Função não implementada - use o botão remover individual');
            }
        }
    </script>
</body>
</html>