<?php
// Inicia a sessão se ainda não estiver ativa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CONFIGURAÇÃO DE ERROS PARA DEBUG (Remova em produção se desejar)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Cabeçalhos JSON
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

date_default_timezone_set('America/Sao_Paulo');

// --- TENTATIVA DE CONEXÃO ---
try {
    $dbPath = __DIR__ . '/../config/database.php';
    
    if (!file_exists($dbPath)) {
        throw new Exception("Arquivo de banco de dados não encontrado em: $dbPath");
    }
    require_once $dbPath;

    if (!isset($pdo)) {
        throw new Exception("A variável \$pdo não foi definida no arquivo database.php");
    }
    
    $pdo->exec("SET NAMES utf8mb4");

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro de Conexão: ' . $e->getMessage()]);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    // --- 1. ENVIAR MENSAGEM ---
    if ($action === 'send_message') {
        if (!isset($_SESSION['user_id'])) { 
            http_response_code(401);
            echo json_encode(['error' => 'Você precisa estar logado.']); 
            exit; 
        }
        
        $msg = trim($_POST['message'] ?? '');
        if (empty($msg)) { 
            echo json_encode(['success' => false, 'error' => 'Mensagem vazia']); 
            exit; 
        }

        $sql = "INSERT INTO chat_messages (username, message, created_at) VALUES (?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        
        $username = $_SESSION['user_name'] ?? $_SESSION['nome'] ?? 'Usuário';
        
        if ($stmt->execute([$username, $msg])) {
            echo json_encode([
                'success' => true,
                'message_id' => $pdo->lastInsertId()
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao salvar mensagem']);
        }
        exit;
    }

    // --- 2. POLLING (MENSAGENS + OVERLAY + STATUS + PRESENÇA) ---
    if ($action === 'poll') {
        $lastId = intval($_GET['last_id'] ?? 0);
        
        // REGISTRAR/ATUALIZAR PRESENÇA
        if (isset($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];
            $userName = $_SESSION['user_name'] ?? $_SESSION['nome'] ?? 'Usuário';
            $userEmail = $_SESSION['user_email'] ?? '';
            $today = date('Y-m-d');
            
            $checkSql = "SELECT id, UNIX_TIMESTAMP(first_access) as first_timestamp 
                        FROM live_presence 
                        WHERE user_id = ? AND live_date = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$userId, $today]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                $firstTimestamp = intval($existing['first_timestamp']);
                $nowTimestamp = time();
                $diffSeconds = $nowTimestamp - $firstTimestamp;
                $diffMinutes = floor($diffSeconds / 60);
                
                if ($diffMinutes < 0) {
                    $diffMinutes = 0;
                }
                
                $updateSql = "UPDATE live_presence 
                            SET last_activity = NOW(), total_minutes = ? 
                            WHERE id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([$diffMinutes, $existing['id']]);
            } else {
                $insertSql = "INSERT INTO live_presence 
                            (user_id, user_name, user_email, first_access, last_activity, live_date, total_minutes) 
                            VALUES (?, ?, ?, NOW(), NOW(), ?, 0)";
                $insertStmt = $pdo->prepare($insertSql);
                $insertStmt->execute([$userId, $userName, $userEmail, $today]);
            }
        }
        
        // Buscar novas mensagens
        $sql = "SELECT id, username, message, created_at 
                FROM chat_messages 
                WHERE id > ? 
                ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$lastId]);
        $messages = $stmt->fetchAll();
        
        foreach ($messages as &$msg) {
            $isMessageFromAdmin = false;
            
            $sessionIsAdmin = ($_SESSION['is_admin'] ?? 0) == 1 || 
                             ($_SESSION['user_role'] ?? '') === 'admin' ||
                             ($_SESSION['role'] ?? '') === 'admin';
            
            if ($sessionIsAdmin) {
                $currentUsername = $_SESSION['user_name'] ?? $_SESSION['nome'] ?? '';
                if ($msg['username'] === $currentUsername) {
                    $isMessageFromAdmin = true;
                }
            }
            $msg['is_admin'] = $isMessageFromAdmin ? 1 : 0;
            $msg['user_name'] = $msg['username'];
        }
        unset($msg);
        
        // Overlay Status
        $stmtOverlay = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'active_overlay'");
        $stmtOverlay->execute();
        $overlayRaw = $stmtOverlay->fetchColumn();
        
        // Live Status
        $stmtStatus = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'live_status'");
        $stmtStatus->execute();
        $liveStatus = $stmtStatus->fetchColumn();
        
        // Lista de presença (apenas para admin)
        $presenceList = [];
        if (isAdmin()) {
            $today = date('Y-m-d');
            $presenceSql = "SELECT user_name, total_minutes, last_activity 
                          FROM live_presence 
                          WHERE live_date = ? 
                          ORDER BY user_name ASC";
            $presenceStmt = $pdo->prepare($presenceSql);
            $presenceStmt->execute([$today]);
            $presenceList = $presenceStmt->fetchAll();
        }

        echo json_encode([
            'messages' => $messages,
            'overlay' => $overlayRaw ? json_decode($overlayRaw, true) : null,
            'live_status' => $liveStatus,
            'presence' => $presenceList
        ]);
        exit;
    }

    // --- 3. DEFINIR OVERLAY (ADMIN) ---
    if ($action === 'set_overlay') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Você precisa estar logado.']); 
            exit;
        }
        
        if (!isAdmin()) { 
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado. Apenas admins podem definir overlay.']); 
            exit; 
        }
        
        $data = $_POST['data'] ?? ''; 
        
        $sql = "INSERT INTO site_settings (setting_key, setting_value, updated_at) 
                VALUES ('active_overlay', ?, NOW()) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$data]);
        
        echo json_encode(['success' => true]);
        exit;
    }

    // --- 4. LIMPAR TODAS AS MENSAGENS (ADMIN) ---
    if ($action === 'clear_all_messages') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Você precisa estar logado.']); 
            exit;
        }
        
        if (!isAdmin()) { 
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado. Apenas admins podem limpar mensagens.']); 
            exit; 
        }
        
        $sql = "DELETE FROM chat_messages";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute()) {
            $pdo->exec("ALTER TABLE chat_messages AUTO_INCREMENT = 1");
            
            echo json_encode([
                'success' => true,
                'message' => 'Todas as mensagens foram apagadas do banco de dados.'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Erro ao apagar mensagens']);
        }
        exit;
    }

    // --- 5. OBTER DETALHES COMPLETOS DE PRESENÇA (ADMIN) ---
    if ($action === 'get_presence_details') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Você precisa estar logado.']); 
            exit;
        }
        
        if (!isAdmin()) { 
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado.']); 
            exit; 
        }
        
        $date = $_GET['date'] ?? date('Y-m-d');
        
        $sql = "SELECT id, user_id, user_name, user_email, 
                      first_access, last_activity, total_minutes 
                FROM live_presence 
                WHERE live_date = ? 
                ORDER BY user_name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$date]);
        $presence = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'participants' => $presence,
            'total_count' => count($presence)
        ]);
        exit;
    }

    // --- 6. VERIFICAR CERTIFICADOS GERADOS (ADMIN) ---
    if ($action === 'check_certificates') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Você precisa estar logado.']); 
            exit;
        }
        
        if (!isAdmin()) { 
            http_response_code(403);
            echo json_encode(['error' => 'Acesso negado.']); 
            exit; 
        }
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $live_lecture_id = 'live-' . $date;
        
        $sql = "SELECT user_id FROM certificates WHERE lecture_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$live_lecture_id]);
        $users_with_cert = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode([
            'success' => true,
            'certificates' => $users_with_cert
        ]);
        exit;
    }

    // --- 7. BUSCAR PALESTRA DO DIA (NOVA FUNÇÃO) ---
    if ($action === 'get_today_lecture') {
        $today = date('Y-m-d');
        
        // Buscar palestra agendada para hoje
        $sql = "SELECT id, title, speaker, announcement_date, lecture_time 
                FROM upcoming_announcements 
                WHERE announcement_date = ? AND is_active = 1 
                ORDER BY lecture_time ASC 
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$today]);
        $lecture = $stmt->fetch();
        
        if ($lecture) {
            echo json_encode([
                'success' => true,
                'lecture' => $lecture
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Nenhuma palestra agendada para hoje'
            ]);
        }
        exit;
    }

    // Ação não reconhecida
    http_response_code(400);
    echo json_encode(['error' => 'Ação não reconhecida']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro SQL: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro Geral: ' . $e->getMessage()]);
}
?>