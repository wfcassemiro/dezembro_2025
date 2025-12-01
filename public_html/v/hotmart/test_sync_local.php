<?php
/**
 * Teste de sincronização LOCAL - Baseada em usuários do banco
 * Método alternativo quando Club API não retorna usuários
 */

// Configurar exibição de erros
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Aumentar timeout para 10 minutos (processamento pode demorar)
set_time_limit(600);
ini_set('max_execution_time', 600);

// Aumentar timeout do MySQL
ini_set('mysql.connect_timeout', 300);
ini_set('default_socket_timeout', 300);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/hotmart.php';
require_once __DIR__ . '/../hotmart.php';
require_once __DIR__ . '/HotmartProgressSyncLocal.php';

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$action = $_GET['action'] ?? 'view';

if ($action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $hotmartApi = new HotmartAPI();
        $syncManager = new HotmartProgressSyncLocal($pdo, $hotmartApi);
        
        $result = $syncManager->syncAllProgress();
        
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode($result);
            exit;
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
}

// Estatísticas
try {
    $hotmartApi = new HotmartAPI();
    $syncManager = new HotmartProgressSyncLocal($pdo, $hotmartApi);
    $stats = $syncManager->getProgressStats();
} catch (Exception $e) {
    $stats = null;
    $statsError = $e->getMessage();
}

// Usuários locais
$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE is_active = 1");
$totalUsers = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE hotmart_subscription_id IS NOT NULL OR hotmart_ucode IS NOT NULL");
$usersWithHotmart = $stmt->fetch()['total'];

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronização LOCAL - Hotmart</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .header h1 { color: #333; font-size: 28px; margin-bottom: 10px; }
        .header p { color: #666; font-size: 14px; }
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert-info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .alert-warning { background: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        .stat-card .value { color: #667eea; font-size: 36px; font-weight: bold; }
        .action-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn:hover { transform: translateY(-2px); }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            margin-left: 10px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔄 Sincronização LOCAL - Baseada em Usuários do Banco</h1>
            <p>Método alternativo: busca usuários do banco local e sincroniza progresso</p>
        </div>
        
        <div class="alert alert-info">
            <strong>💡 Por que usar este método?</strong><br>
            O Club API está retornando vazio. Este método usa os <?= $totalUsers ?> usuários que <strong>JÁ EXISTEM</strong> no seu banco de dados e tenta buscar o progresso de cada um.
        </div>
        
        <?php if (isset($result)): ?>
            <?php if ($result['success']): ?>
                <div class="alert alert-success">
                    <strong>✅ Sincronização concluída!</strong><br>
                    Usuários processados: <?= $result['users_processed'] ?><br>
                    Usuários com progresso: <?= $result['users_with_progress'] ?? 0 ?><br>
                    Registros de progresso: <?= $result['progress_records'] ?? 0 ?><br>
                    Erros: <?= $result['errors'] ?? 0 ?><br>
                    Duração: <?= $result['duration'] ?? 0 ?>s
                </div>
            <?php else: ?>
                <div class="alert alert-error">
                    <strong>❌ Erro:</strong> <?= htmlspecialchars($result['message'] ?? 'Erro desconhecido') ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <h3>Usuários Ativos</h3>
                <div class="value"><?= number_format($totalUsers) ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Com Dados Hotmart</h3>
                <div class="value"><?= number_format($usersWithHotmart) ?></div>
            </div>
            
            <?php if ($stats): ?>
            <div class="stat-card">
                <h3>Registros de Progresso</h3>
                <div class="value"><?= number_format($stats['total_records']) ?></div>
            </div>
            
            <div class="stat-card">
                <h3>Palestras Completadas</h3>
                <div class="value"><?= number_format($stats['completed']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="action-card">
            <h2>🚀 Iniciar Sincronização LOCAL</h2>
            <p style="color: #666; margin-bottom: 20px;">
                Este processo irá:<br>
                1. Buscar os <?= $totalUsers ?> usuários ativos do banco<br>
                2. Para cada usuário com dados Hotmart, buscar o progresso na API<br>
                3. Salvar os dados de progresso encontrados<br><br>
                <strong>Vantagem:</strong> Não depende do Club API que está vazio!<br>
                <strong>⏱️ Tempo estimado:</strong> ~5-10 minutos para todos os usuários
            </p>
            <form method="POST" action="?action=sync" id="syncForm">
                <button type="submit" class="btn" id="syncBtn">
                    Iniciar Sincronização LOCAL
                </button>
            </form>
            <div id="syncProgress" style="margin-top: 20px;"></div>
            <div id="syncResult"></div>
        </div>
        
        <div style="margin-top: 20px; text-align: center;">
            <a href="test_sync.php" style="color: white; text-decoration: none; opacity: 0.8;">Método Original (Club API)</a> | 
            <a href="debug_api_raw.php" style="color: white; text-decoration: none; opacity: 0.8;">Debug API</a> |
            <a href="../logs/hotmart_progress_sync_local.log" target="_blank" style="color: white; text-decoration: none; opacity: 0.8;">Ver Logs LOCAL</a>
        </div>
    </div>
    
    <script>
        document.getElementById('syncForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const btn = document.getElementById('syncBtn');
            const resultDiv = document.getElementById('syncResult');
            const progressDiv = document.getElementById('syncProgress');
            
            btn.disabled = true;
            btn.innerHTML = 'Sincronizando... <span class="loading"></span>';
            progressDiv.innerHTML = '<div class="alert alert-info">⏳ Processando usuários locais, isso pode levar alguns minutos...<br>Aguarde, não feche esta página.</div>';
            resultDiv.innerHTML = '';
            
            // Fazer requisição com timeout maior
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 600000); // 10 minutos
            
            fetch('?action=sync', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal
            })
            .then(response => {
                clearTimeout(timeoutId);
                return response.json();
            })
            .then(data => {
                progressDiv.innerHTML = '';
                
                if (data.success) {
                    resultDiv.innerHTML = `
                        <div class="alert alert-success">
                            <strong>✅ Sincronização concluída!</strong><br>
                            Usuários processados: ${data.users_processed}<br>
                            Usuários com progresso: ${data.users_with_progress || 0}<br>
                            Registros de progresso: ${data.progress_records || 0}<br>
                            Erros: ${data.errors || 0}<br>
                            Duração: ${data.duration || 0}s
                        </div>
                    `;
                    setTimeout(() => location.reload(), 3000);
                } else {
                    resultDiv.innerHTML = `
                        <div class="alert alert-error">
                            <strong>❌ Erro:</strong><br>
                            ${data.message || 'Erro desconhecido'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                progressDiv.innerHTML = '';
                
                if (error.name === 'AbortError') {
                    resultDiv.innerHTML = `
                        <div class="alert alert-warning">
                            <strong>⏱️ Timeout:</strong><br>
                            A sincronização demorou muito. Verifique os logs para ver o progresso:<br>
                            <a href="../logs/hotmart_progress_sync_local.log" target="_blank" style="color: #856404; text-decoration: underline;">Ver Logs</a>
                        </div>
                    `;
                } else {
                    resultDiv.innerHTML = `
                        <div class="alert alert-error">
                            <strong>❌ Erro de conexão:</strong><br>
                            ${error.message}<br><br>
                            Verifique os logs para mais detalhes:<br>
                            <a href="../logs/hotmart_progress_sync_local.log" target="_blank" style="color: #721c24; text-decoration: underline;">Ver Logs</a>
                        </div>
                    `;
                }
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = 'Iniciar Sincronização LOCAL';
            });
        });
    </script>
</body>
</html>