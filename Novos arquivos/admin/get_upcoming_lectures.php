<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Verificar se é admin
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

header('Content-Type: application/json');

try {
    $lectures = [];
    
    // Tentar buscar da tabela upcoming_announcements primeiro
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                title,
                speaker,
                announcement_date as date,
                duration_hours
            FROM upcoming_announcements 
            WHERE announcement_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ORDER BY announcement_date DESC
            LIMIT 50
        ");
        
        $stmt->execute();
        $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($upcoming as $lecture) {
            if (!empty($lecture['date'])) {
                $date = new DateTime($lecture['date']);
                $lectures[] = [
                    'id' => $lecture['id'],
                    'title' => $lecture['title'] ?? 'Sem título',
                    'speaker' => $lecture['speaker'] ?? 'Sem palestrante',
                    'formatted_date' => $date->format('d/m/Y'),
                    'date_input' => $date->format('Y-m-d'),
                    'duration_hours' => floatval($lecture['duration_hours'] ?? 1)
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao buscar upcoming_announcements: " . $e->getMessage());
    }
    
    // Se não encontrou nenhuma, tentar buscar da tabela lectures
    if (empty($lectures)) {
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    title,
                    speaker,
                    created_at as date,
                    duration_minutes
                FROM lectures 
                ORDER BY created_at DESC
                LIMIT 20
            ");
            
            $stmt->execute();
            $stored = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($stored as $lecture) {
                $date = !empty($lecture['date']) ? new DateTime($lecture['date']) : new DateTime();
                $duration_hours = ($lecture['duration_minutes'] ?? 60) / 60;
                
                $lectures[] = [
                    'id' => $lecture['id'],
                    'title' => $lecture['title'] ?? 'Sem título',
                    'speaker' => $lecture['speaker'] ?? 'Sem palestrante',
                    'formatted_date' => $date->format('d/m/Y'),
                    'date_input' => $date->format('Y-m-d'),
                    'duration_hours' => $duration_hours
                ];
            }
        } catch (Exception $e) {
            error_log("Erro ao buscar lectures: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'lectures' => $lectures,
        'count' => count($lectures)
    ]);
    
} catch (Exception $e) {
    error_log("Erro geral: " . $e->getMessage());
    echo json_encode([
        'success' => true,
        'lectures' => [],
        'count' => 0,
        'debug' => $e->getMessage()
    ]);
}
?>
