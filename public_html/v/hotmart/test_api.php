<?php
/**
 * Script de teste manual da API Hotmart
 * Use para depuração e verificação de credenciais
 */

// Configurar exibição de erros
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Incluir arquivos necessários
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/hotmart.php';
require_once __DIR__ . '/../hotmart.php';

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste API Hotmart</title>
    <style>
        body {
            font-family: monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            color: #4ec9b0;
            border-bottom: 2px solid #4ec9b0;
            padding-bottom: 10px;
        }
        h2 {
            color: #569cd6;
            margin-top: 30px;
        }
        .success {
            color: #4ec9b0;
        }
        .error {
            color: #f48771;
        }
        .warning {
            color: #dcdcaa;
        }
        pre {
            background: #2d2d2d;
            padding: 15px;
            border-radius: 5px;
            overflow-x: auto;
            border-left: 3px solid #569cd6;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            margin-left: 10px;
        }
        .badge-success {
            background: #4ec9b0;
            color: #000;
        }
        .badge-error {
            background: #f48771;
            color: #000;
        }
        .test-section {
            background: #252526;
            padding: 20px;
            margin: 20px 0;
            border-radius: 8px;
            border: 1px solid #3e3e42;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Teste API Hotmart - Diagnóstico</h1>
        
        <div class="test-section">
            <h2>1. Verificar Credenciais</h2>
            <?php
            echo "<strong>CLIENT_ID:</strong> " . (defined('HOTMART_CLIENT_ID') ? '✓ Definido' : '<span class="error">✗ Não definido</span>') . "<br>";
            echo "<strong>CLIENT_SECRET:</strong> " . (defined('HOTMART_CLIENT_SECRET') ? '✓ Definido' : '<span class="error">✗ Não definido</span>') . "<br>";
            echo "<strong>HOT_TOKEN:</strong> " . (defined('HOTMART_HOT_TOKEN') ? '✓ Definido' : '<span class="error">✗ Não definido</span>') . "<br>";
            echo "<strong>SUBDOMAIN:</strong> " . (defined('HOTMART_SUBDOMAIN') ? HOTMART_SUBDOMAIN : '<span class="error">Não definido</span>') . "<br>";
            ?>
        </div>
        
        <div class="test-section">
            <h2>2. Testar Autenticação (OAuth Token)</h2>
            <?php
            try {
                $api = new HotmartAPI();
                $token = $api->getAccessToken();
                
                if ($token) {
                    echo '<span class="success">✓ Token obtido com sucesso!</span>';
                    echo '<pre>' . substr($token, 0, 50) . '...</pre>';
                } else {
                    echo '<span class="error">✗ Falha ao obter token</span>';
                }
            } catch (Exception $e) {
                echo '<span class="error">✗ Erro: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>3. Testar Club Users API</h2>
            <?php
            try {
                $api = new HotmartAPI();
                $result = $api->getClubUsers('t101');
                
                if ($result['success']) {
                    $users = $result['data']['items'] ?? $result['data'] ?? [];
                    echo '<span class="success">✓ Club Users obtidos com sucesso!</span><br>';
                    echo '<strong>Total de usuários:</strong> ' . count($users) . '<br>';
                    
                    if (!empty($users)) {
                        echo '<br><strong>Exemplo (primeiro usuário):</strong>';
                        echo '<pre>' . json_encode($users[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                    }
                } else {
                    echo '<span class="warning">⚠ Falha ao obter Club Users</span><br>';
                    echo '<pre>' . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                }
            } catch (Exception $e) {
                echo '<span class="error">✗ Erro: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>4. Testar Subscriptions API (Fallback)</h2>
            <?php
            try {
                $api = new HotmartAPI();
                $result = $api->getSubscriptions('ACTIVE');
                
                if ($result['success']) {
                    $subs = $result['data']['items'] ?? $result['data'] ?? [];
                    echo '<span class="success">✓ Assinaturas obtidas com sucesso!</span><br>';
                    echo '<strong>Total de assinaturas ativas:</strong> ' . count($subs) . '<br>';
                    
                    if (!empty($subs)) {
                        echo '<br><strong>Exemplo (primeira assinatura):</strong>';
                        echo '<pre>' . json_encode($subs[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                    }
                } else {
                    echo '<span class="warning">⚠ Falha ao obter assinaturas</span><br>';
                    echo '<pre>' . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                }
            } catch (Exception $e) {
                echo '<span class="error">✗ Erro: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>5. Testar User Progress API</h2>
            <?php
            try {
                // Tentar obter um usuário primeiro
                $api = new HotmartAPI();
                $clubResult = $api->getClubUsers('t101');
                
                if ($clubResult['success']) {
                    $users = $clubResult['data']['items'] ?? $clubResult['data'] ?? [];
                    
                    if (!empty($users)) {
                        $testUser = $users[0];
                        $userId = $testUser['ucode'] 
                                ?? $testUser['subscriber']['ucode'] ?? null
                                ?? $testUser['subscriber']['subscriber_code'] ?? null
                                ?? null;
                        
                        if ($userId) {
                            echo '<strong>Testando com usuário:</strong> ' . ($testUser['email'] ?? 'Email não disponível') . '<br>';
                            echo '<strong>User ID:</strong> ' . $userId . '<br><br>';
                            
                            $progressResult = $api->getUserProgress($userId);
                            
                            if ($progressResult['success']) {
                                echo '<span class="success">✓ Progresso obtido com sucesso!</span><br>';
                                echo '<pre>' . json_encode($progressResult['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                            } else {
                                echo '<span class="warning">⚠ Nenhum progresso encontrado para este usuário</span><br>';
                                echo '<pre>' . json_encode($progressResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                            }
                        } else {
                            echo '<span class="warning">⚠ Não foi possível obter ID do usuário</span>';
                        }
                    } else {
                        echo '<span class="warning">⚠ Nenhum usuário disponível para teste</span>';
                    }
                } else {
                    echo '<span class="warning">⚠ Não foi possível obter usuários para testar progresso</span>';
                }
            } catch (Exception $e) {
                echo '<span class="error">✗ Erro: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>6. Verificar Banco de Dados</h2>
            <?php
            try {
                // Verificar se tabelas existem
                $tables = [
                    'hotmart_user_progress',
                    'hotmart_lecture_mapping',
                    'hotmart_sync_logs',
                    'users',
                    'lectures'
                ];
                
                foreach ($tables as $table) {
                    $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
                    $exists = $stmt->fetch();
                    
                    if ($exists) {
                        // Contar registros
                        $countStmt = $pdo->query("SELECT COUNT(*) as total FROM {$table}");
                        $count = $countStmt->fetch()['total'];
                        
                        echo "<span class='success'>✓</span> <strong>{$table}</strong>: {$count} registros<br>";
                    } else {
                        echo "<span class='error'>✗</span> <strong>{$table}</strong>: Tabela não existe<br>";
                    }
                }
                
                // Verificar usuários com hotmart_ucode
                echo "<br><strong>Usuários com hotmart_ucode definido:</strong><br>";
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE hotmart_ucode IS NOT NULL AND hotmart_ucode != ''");
                $count = $stmt->fetch()['total'];
                echo "Total: {$count} usuários<br>";
                
            } catch (Exception $e) {
                echo '<span class="error">✗ Erro ao verificar banco: ' . htmlspecialchars($e->getMessage()) . '</span>';
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>7. Logs da API Hotmart</h2>
            <?php
            $logFile = __DIR__ . '/../logs/hotmart_api_debug.log';
            
            if (file_exists($logFile)) {
                echo '<span class="success">✓ Arquivo de log encontrado</span><br>';
                echo '<strong>Últimas 20 linhas:</strong>';
                
                $lines = file($logFile);
                $lastLines = array_slice($lines, -20);
                
                echo '<pre style="max-height: 400px; overflow-y: auto;">';
                echo htmlspecialchars(implode('', $lastLines));
                echo '</pre>';
            } else {
                echo '<span class="warning">⚠ Arquivo de log não encontrado</span><br>';
                echo 'Esperado em: ' . $logFile;
            }
            ?>
        </div>
        
        <div class="test-section">
            <h2>📋 Resumo</h2>
            <p>Este teste verificou:</p>
            <ul>
                <li>✓ Credenciais da API Hotmart</li>
                <li>✓ Autenticação OAuth</li>
                <li>✓ Endpoint de Club Users</li>
                <li>✓ Endpoint de Subscriptions</li>
                <li>✓ Endpoint de User Progress</li>
                <li>✓ Estrutura do banco de dados</li>
                <li>✓ Logs da API</li>
            </ul>
            
            <p><strong>Próximo passo:</strong> Se todos os testes passaram, você pode usar o <a href="test_sync.php" style="color: #4ec9b0;">test_sync.php</a> para iniciar a sincronização completa.</p>
        </div>
    </div>
</body>
</html>