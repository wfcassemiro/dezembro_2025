<?php
// Script para debug das imagens

require_once __DIR__ . '/../config/database.php';

echo "<h2>🖼️ Debug - Imagens dos Anúncios</h2>";

// Verificar pasta de upload
$uploadDir = __DIR__ . '/../images/announcements/';
echo "<h3>📁 Verificação de Pastas:</h3>";
echo "Pasta de upload: <strong>$uploadDir</strong><br>";
echo "Pasta existe: " . (is_dir($uploadDir) ? "✅ Sim" : "❌ Não") . "<br>";
echo "Pasta tem permissão de escrita: " . (is_writable($uploadDir) ? "✅ Sim" : "❌ Não") . "<br>";

// Listar arquivos na pasta
if (is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    $imageFiles = array_filter($files, function($file) {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp']);
    });
    
    echo "Arquivos de imagem encontrados: <strong>" . count($imageFiles) . "</strong><br>";
    if (!empty($imageFiles)) {
        foreach ($imageFiles as $file) {
            $fullPath = $uploadDir . $file;
            echo "- $file (tamanho: " . filesize($fullPath) . " bytes)<br>";
        }
    }
} else {
    // Tentar criar a pasta
    if (mkdir($uploadDir, 0755, true)) {
        echo "✅ Pasta criada com sucesso!<br>";
    } else {
        echo "❌ Erro ao criar pasta!<br>";
    }
}

echo "<br><h3>🗄️ Dados no Banco:</h3>";

try {
    $stmt = $pdo->query("SELECT id, title, speaker, image_path, created_at FROM upcoming_announcements ORDER BY created_at DESC");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Título</th><th>Palestrante</th><th>Caminho da Imagem</th><th>Status da Imagem</th></tr>";
    
    foreach ($announcements as $announcement) {
        echo "<tr>";
        echo "<td>" . substr($announcement['id'], 0, 8) . "...</td>";
        echo "<td>" . htmlspecialchars($announcement['title']) . "</td>";
        echo "<td>" . htmlspecialchars($announcement['speaker']) . "</td>";
        echo "<td>" . htmlspecialchars($announcement['image_path'] ?: 'Nenhuma') . "</td>";
        
        if ($announcement['image_path']) {
            $fullImagePath = __DIR__ . '/../' . $announcement['image_path'];
            if (file_exists($fullImagePath)) {
                echo "<td>✅ Arquivo existe</td>";
            } else {
                echo "<td>❌ Arquivo não encontrado</td>";
            }
        } else {
            echo "<td>⚪ Sem imagem</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<br><h3>🔍 Teste de Imagens:</h3>";
    foreach ($announcements as $announcement) {
        if ($announcement['image_path']) {
            $webPath = $announcement['image_path'];
            $fullPath = __DIR__ . '/../' . $announcement['image_path'];
            
            echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
            echo "<strong>Palestra:</strong> " . htmlspecialchars($announcement['title']) . "<br>";
            echo "<strong>Caminho no banco:</strong> " . htmlspecialchars($webPath) . "<br>";
            echo "<strong>Caminho físico:</strong> " . $fullPath . "<br>";
            echo "<strong>Arquivo existe:</strong> " . (file_exists($fullPath) ? "✅ Sim" : "❌ Não") . "<br>";
            
            if (file_exists($fullPath)) {
                echo "<strong>Preview:</strong><br>";
                echo "<img src='$webPath' style='max-width: 200px; max-height: 150px; border: 1px solid #ddd;' alt='Preview'>";
            }
            echo "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "❌ Erro ao consultar banco: " . $e->getMessage();
}

echo "<br><a href='index.php'>← Voltar para a home</a>";
?>