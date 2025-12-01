<?php
echo '<h1>🔍 Debug CSS Path</h1>';

// Simular o código do head.php
$script_path = $_SERVER['SCRIPT_NAME'];
$path_parts = explode('/', trim($script_path, '/'));
$depth = count($path_parts) - 1;
$base_path = str_repeat('../', $depth);

echo '<h2>Informações de Caminho:</h2>';
echo '<p><strong>Script Name:</strong> ' . htmlspecialchars($script_path) . '</p>';
echo '<p><strong>Path Parts:</strong> ' . print_r($path_parts, true) . '</p>';
echo '<p><strong>Depth:</strong> ' . $depth . '</p>';
echo '<p><strong>Base Path:</strong> [' . htmlspecialchars($base_path) . ']</p>';
echo '<p><strong>CSS URL gerada:</strong> ' . htmlspecialchars($base_path . 'vision/assets/css/style.css') . '</p>';

echo '<h2>Teste de Caminhos Absolutos:</h2>';
echo '<p><strong>CSS Absoluto:</strong> /vision/assets/css/style.css</p>';

// Verificar se o CSS existe
$css_file = '/app/public_html/vision/assets/css/style.css';
if (file_exists($css_file)) {
    echo '<p>✅ Arquivo CSS existe no servidor</p>';
    echo '<p>Tamanho: ' . filesize($css_file) . ' bytes</p>';
} else {
    echo '<p>❌ Arquivo CSS NÃO existe no servidor</p>';
}

// Testar acesso HTTP ao CSS
echo '<h2>Teste de Acesso HTTP:</h2>';
echo '<p>Teste estes links:</p>';
echo '<ul>';
echo '<li><a href=\"/vision/assets/css/style.css\" target=\"_blank\">CSS Absoluto</a></li>';
echo '<li><a href=\"' . $base_path . 'vision/assets/css/style.css\" target=\"_blank\">CSS Relativo (atual)</a></li>';
echo '</ul>';
?>