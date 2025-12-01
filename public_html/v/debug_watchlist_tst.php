<?php
session_start();
require_once __DIR__ . '/../config/database.php';

if (!isLoggedIn()) {
    die('Faça login primeiro');
}

$user_id = $_SESSION['user_id'];

header('Content-Type: application/json');

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
    }
    
    // 4. Verificar se existem palestras na tabela lectures
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM lectures");
    $lectures_result = $stmt->fetch();
    $lectures_count = $lectures_result['total'];
    
    // 5. Buscar algumas palestras de exemplo
    $stmt = $pdo->query("SELECT id, title FROM lectures LIMIT 5");
    $sample_lectures = $stmt->fetchAll();
    
    $debug_data = [
        'user_id' => $user_id,
        'table_exists' => $table_exists,
        'watchlist_count' => $watchlist_count,
        'watchlist_items' => $watchlist_items,
        'lectures_count' => $lectures_count,
        'sample_lectures' => $sample_lectures,
        'status' => 'success'
    ];

} catch (Exception $e) {
    $debug_data = [
        'error' => $e->getMessage(),
        'status' => 'error'
    ];
}

echo json_encode($debug_data, JSON_PRETTY_PRINT);
?>