<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Verificar se é admin
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado']);
    exit;
}

header('Content-Type: application/json');

try {
    // Buscar palestras futuras ou recentes (últimos 30 dias)
    $stmt = $pdo->prepare("
        SELECT 
            id,
            title,
            speaker,
            announcement_date,
            duration_hours
        FROM upcoming_announcements 
        WHERE announcement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY announcement_date DESC
        LIMIT 50
    ");
    
    $stmt->execute();
    $lectures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatar datas para o formato brasileiro
    foreach ($lectures as &$lecture) {
        $date = new DateTime($lecture['announcement_date']);
        $lecture['formatted_date'] = $date->format('d/m/Y');
        $lecture['date_input'] = $date->format('Y-m-d');
    }
    
    echo json_encode([
        'success' => true,
        'lectures' => $lectures
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar palestras: ' . $e->getMessage()
    ]);
}
?>
