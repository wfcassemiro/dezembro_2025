<?php
// Videoteca.php de teste - funciona sem autenticação para debug
require_once __DIR__ . '/../config/database.php';

// Para teste: simular usuário logado se não estiver
if (!isLoggedIn()) {
    $_SESSION['user_id'] = 'test_user_' . uniqid();
    $_SESSION['user_name'] = 'Usuário Teste';
    $_SESSION['user_email'] = 'teste@translators101.com';
    $_SESSION['user_role'] = 'subscriber';
    $_SESSION['is_subscriber'] = 1;
}

// Define o titulo e a descricao para a pagina
$page_title = 'Videoteca - Translators101';
$page_description = 'Explore nossa biblioteca de palestras especializadas em traducao e interpretacao';

// Obtem os parametros de busca e filtros da URL
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$sort_order = $_GET['sort'] ?? 'season_desc';

// Inicializa a consulta SQL principal
$sql = 'SELECT * FROM lectures WHERE 1=1';
$params = [];

// Adiciona filtros de busca
if (!empty($search)) {
    $sql .= ' AND (title LIKE ? OR speaker LIKE ? OR description LIKE ?)';
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

// Adiciona filtro de categoria
if (!empty($category)) {
    $sql .= ' AND category = ?';
    $params[] = $category;
}

// Executa a consulta SQL para buscar as palestras
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lectures = $stmt->fetchAll();
} catch (Exception $e) {
    $lectures = [];
}

// Buscar palestras que estao na watchlist do usuario logado
$user_watchlist = [];
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'] ?? '';
    if (!empty($user_id)) {
        try {
            $stmt = $pdo->prepare('SELECT lecture_id FROM user_watchlist WHERE user_id = ?');
            $stmt->execute([$user_id]);
            $watchlist_items = $stmt->fetchAll();
            foreach ($watchlist_items as $item) {
                $user_watchlist[] = $item['lecture_id'];
            }
        } catch (Exception $e) {
            // Ignorar erro
        }
    }
}

// Busca as categorias unicas das palestras
try {
    $stmt = $pdo->prepare('SELECT DISTINCT category FROM lectures WHERE category IS NOT NULL AND category != \'\' ORDER BY category');
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $categories = ['Traducao', 'Interpretacao', 'Linguas', 'Tecnologia', 'Negocios'];
}

// Função para extrair temporada e episódio
function extractSeasonEpisode($title) {
    if (preg_match('/S(\d+)E(\d+)/i', $title, $matches)) {
        return [
            'season' => (int)$matches[1],
            'episode' => (int)$matches[2],
            'has_pattern' => true
        ];
    }
    return ['season' => 0, 'episode' => 0, 'has_pattern' => false];
}

// Função de ordenação
function sortLectures($lectures, $sort_order) {
    $withPattern = [];
    $withoutPattern = [];
    
    foreach ($lectures as $lecture) {
        $seasonEpisode = extractSeasonEpisode($lecture['title']);
        if ($seasonEpisode['has_pattern']) {
            $lecture['season'] = $seasonEpisode['season'];
            $lecture['episode'] = $seasonEpisode['episode'];
            $withPattern[] = $lecture;
        } else {
            $withoutPattern[] = $lecture;
        }
    }
    
    switch ($sort_order) {
        case 'season_desc':
            usort($withPattern, function($a, $b) {
                if ($a['season'] != $b['season']) {
                    return $b['season'] - $a['season'];
                }
                return $b['episode'] - $a['episode'];
            });
            usort($withoutPattern, function($a, $b) {
                return strcmp($b['title'], $a['title']);
            });
            return array_merge($withPattern, $withoutPattern);
            
        case 'date_desc':
            $all = array_merge($withPattern, $withoutPattern);
            usort($all, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });
            return $all;
            
        default:
            return array_merge($withPattern, $withoutPattern);
    }
}

// Aplica a ordenacao
$lectures = sortLectures($lectures, $sort_order);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <meta name="description" content="<?php echo $page_description; ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --brand-purple: #8b5cf6;
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }
        
        body {
            margin: 0;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            color: white;
        }
        
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .glass-hero {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 40px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .hero-content h1 {
            margin: 0 0 15px 0;
            font-size: 2.5rem;
            font-weight: 700;
        }
        
        .hero-content p {
            margin: 0;
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        .videoteca-title {
            display: flex;
            align-items: center;
            gap: 15px;
            justify-content: center;
        }
        
        .videoteca-filtros {
            margin-bottom: 30px;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 24px;
        }
        
        .filtros-grid-improved {
            display: grid;
            grid-template-columns: 2fr 1.2fr 1.2fr auto;
            gap: 20px;
            align-items: end;
        }
        
        @media (max-width: 900px) {
            .filtros-grid-improved {
                grid-template-columns: 1fr;
            }
        }
        
        .search-field-expanded {
            display: flex;
            flex-direction: column;
        }
        
        .form-label {
            color: white;
            font-weight: 600;
            margin-bottom: 8px;
            display: block;
        }
        
        .search-input-large,
        .category-select,
        .sort-select {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.18);
            color: #ffffff;
            font-size: 1rem;
            font-weight: 600;
            text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
        }
        
        .search-btn-container {
            display: flex;
            align-items: end;
        }
        
        .cta-btn {
            background: linear-gradient(135deg, var(--brand-purple), #7c3aed);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .cta-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }
        
        .video-grid-four {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .video-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 0;
            cursor: pointer;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .video-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
            border-color: var(--brand-purple);
        }
        
        .video-thumb-container {
            position: relative;
            width: 100%;
            padding-bottom: 56.25%; /* 16:9 aspect ratio */
            overflow: hidden;
            background: linear-gradient(135deg, var(--brand-purple), #5e3370);
        }
        
        .video-thumb {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--brand-purple), #5e3370);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .video-placeholder {
            text-align: center;
            color: rgba(255, 255, 255, 0.7);
        }
        
        .placeholder-icon {
            font-size: 3rem;
            margin-bottom: 10px;
        }
        
        .video-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .video-thumb:hover .video-overlay {
            opacity: 1;
        }
        
        .play-button {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--brand-purple);
        }
        
        .video-info {
            padding: 20px;
        }
        
        .video-info h3 {
            margin: 0 0 8px 0;
            color: white;
            font-size: 1.1rem;
            line-height: 1.3;
        }
        
        .video-speaker {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.95rem;
            margin-bottom: 8px;
        }
        
        .video-desc {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.85rem;
            line-height: 1.4;
            margin-bottom: 12px;
        }
        
        .video-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        
        .video-category {
            background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
            color: #ffffff !important;
            padding: 4px 12px !important;
            border-radius: 12px !important;
            border: 2px solid #1e40af !important;
            font-size: 0.75rem !important;
            font-weight: 700 !important;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7) !important;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.4) !important;
            display: inline-block !important;
            text-transform: capitalize !important;
        }
        
        .video-duration {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.8rem;
        }
        
        .featured-tag {
            background: linear-gradient(135deg, #ff6b35, #f7931e) !important;
            color: #ffffff !important;
            border: 2px solid #ff4500 !important;
            font-weight: 700 !important;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7) !important;
            box-shadow: 0 2px 8px rgba(255, 107, 53, 0.4) !important;
            text-transform: capitalize !important;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin-bottom: 8px;
            display: inline-block;
        }
        
        /* Estilos para o checkbox da watchlist */
        .watchlist-section {
            margin-top: 12px;
            padding-top: 8px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .watchlist-checkbox {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 0.85rem;
            color: #ffffff;
            user-select: none;
        }
        
        .watchlist-input {
            display: none;
        }
        
        .checkmark {
            height: 16px;
            width: 16px;
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.4);
            border-radius: 3px;
            margin-right: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .checkmark:before {
            content: '';
            position: absolute;
            left: 3px;
            top: 0px;
            width: 4px;
            height: 8px;
            border: solid #ffffff;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .watchlist-input:checked + .checkmark {
            background: linear-gradient(135deg, #10b981, #059669);
            border-color: #059669;
        }
        
        .watchlist-input:checked + .checkmark:before {
            opacity: 1;
        }
        
        .watchlist-checkbox:hover .checkmark {
            background: rgba(255, 255, 255, 0.3);
            border-color: rgba(255, 255, 255, 0.6);
        }
        
        .watchlist-input:checked + .checkmark:hover {
            background: linear-gradient(135deg, #059669, #047857);
        }
        
        .watchlist-text {
            font-weight: 500;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7);
            transition: color 0.3s ease;
        }
        
        .watchlist-checkbox:hover .watchlist-text {
            color: #f0f0f0;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-icon {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.6;
        }
        
        .empty-title {
            margin-bottom: 15px;
        }
        
        .empty-description {
            margin-bottom: 30px;
            opacity: 0.8;
        }
        
        .debug-info {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>

<!-- Debug Info -->
<div class="debug-info">
    <strong>🔍 Debug Info:</strong>
    Usuário: <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'N/A'); ?> |
    ID: <?php echo htmlspecialchars($_SESSION['user_id'] ?? 'N/A'); ?> |
    Logado: <?php echo isLoggedIn() ? '✅' : '❌'; ?> |
    Palestras: <?php echo count($lectures); ?> |
    Na Lista: <?php echo count($user_watchlist); ?>
</div>

<div class="main-content">
    <!-- Seção Hero -->
    <div class="glass-hero">
        <div class="hero-content" style="text-align: center;">
            <h1 class="videoteca-title">
                <i class="fas fa-video"></i>
                Videoteca (Teste)
            </h1>
            <p>Explore nossa biblioteca de palestras especializadas em traducao e interpretacao</p>
        </div>
    </div>

    <!-- Seção de Filtros -->
    <div class="videoteca-filtros">
        <form method="GET" action="" class="filtros-form">
            <div class="filtros-grid-improved">
                <!-- Campo de Busca -->
                <div class="search-field-expanded">
                    <label class="form-label">Buscar por palavra-chave</label>
                    <input type="text" 
                           name="search" 
                           value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Digite o titulo, palestrante ou descricao..."
                           class="search-input-large">
                </div>
                
                <!-- Campo de Categoria -->
                <div class="category-field">
                    <label class="form-label">Categoria</label>
                    <select name="category" class="category-select">
                        <option value="">Todas as categorias</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" 
                                    <?php echo ($category === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- Campo de Ordenação -->
                <div class="sort-field">
                    <label class="form-label">Ordenar por</label>
                    <select name="sort" class="sort-select">
                        <option value="season_desc" <?php echo ($sort_order === 'season_desc') ? 'selected' : ''; ?>>Temporada (decrescente)</option>
                        <option value="date_desc" <?php echo ($sort_order === 'date_desc') ? 'selected' : ''; ?>>Mais recentes</option>
                    </select>
                </div>
                
                <!-- Botão de Busca -->
                <div class="search-btn-container">
                    <button type="submit" class="cta-btn search-btn">
                        <i class="fas fa-search"></i>
                        Buscar
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Lista de Palestras -->
    <?php if (!empty($lectures)): ?>
        <div class="video-grid video-grid-four">
            <?php foreach ($lectures as $lecture): ?>
                <div class="video-card" onclick="location.href='/palestra.php?id=<?php echo $lecture['id']; ?>'">
                    <!-- Thumbnail -->
                    <div class="video-thumb-container">
                        <div class="video-thumb">
                            <?php if (!empty($lecture['thumbnail_url'])): ?>
                                <img src="<?php echo htmlspecialchars($lecture['thumbnail_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($lecture['title']); ?>"
                                     class="video-image" style="width: 100%; height: 100%; object-fit: cover;">
                            <?php else: ?>
                                <div class="video-placeholder">
                                    <i class="fas fa-video placeholder-icon"></i>
                                    <span class="placeholder-text">Palestra</span>
                                </div>
                            <?php endif; ?>
                            
                            <div class="video-overlay">
                                <div class="play-button">
                                    <i class="fas fa-play"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informações -->
                    <div class="video-info">
                        <h3><?php echo htmlspecialchars($lecture['title']); ?></h3>
                        
                        <?php if (!empty($lecture['speaker'])): ?>
                            <div class="video-speaker"><?php echo htmlspecialchars($lecture['speaker']); ?></div>
                        <?php endif; ?>
                        
                        <?php if (!empty($lecture['description'])): ?>
                            <p class="video-desc"><?php echo htmlspecialchars(substr($lecture['description'], 0, 100)); ?>...</p>
                        <?php endif; ?>
                        
                        <div class="video-meta">
                            <?php if (!empty($lecture['category'])): ?>
                                <div class="video-category">
                                    <?php echo htmlspecialchars($lecture['category']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($lecture['duration_minutes'])): ?>
                                <div class="video-duration">
                                    <i class="fas fa-clock"></i>
                                    <?php echo $lecture['duration_minutes']; ?>min
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Indicador Destaque -->
                        <?php if (!empty($lecture['is_featured']) && $lecture['is_featured']): ?>
                            <div class="featured-section">
                                <span class="tag featured-tag">
                                    <i class="fas fa-star"></i> Destaque
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Checkbox Watchlist -->
                        <?php $isInWatchlist = in_array($lecture['id'], $user_watchlist); ?>
                        <div class="watchlist-section">
                            <label class="watchlist-checkbox" onclick="event.stopPropagation();">
                                <input type="checkbox" 
                                       class="watchlist-input" 
                                       data-lecture-id="<?php echo $lecture['id']; ?>"
                                       <?php echo $isInWatchlist ? 'checked' : ''; ?>
                                       onchange="toggleWatchlist(this, '<?php echo $lecture['id']; ?>')">
                                <span class="checkmark"></span>
                                <span class="watchlist-text">
                                    <?php echo $isInWatchlist ? 'Na minha lista' : 'Colocar na lista'; ?>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fas fa-search"></i>
            </div>
            <h2 class="empty-title">Nenhuma palestra encontrada</h2>
            <p class="empty-description">
                Tente ajustar os filtros de busca ou escolher uma categoria diferente.
            </p>
            <a href="?search=&category=&sort=season_desc" class="cta-btn">
                <i class="fas fa-refresh"></i>
                Limpar filtros
            </a>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleWatchlist(checkbox, lectureId) {
    const isChecked = checkbox.checked;
    const watchlistText = checkbox.parentElement.querySelector('.watchlist-text');
    
    // Atualiza o texto imediatamente para feedback visual
    watchlistText.textContent = isChecked ? 'Na minha lista' : 'Colocar na lista';
    
    console.log('Tentando alterar watchlist para palestra:', lectureId, 'Acao:', isChecked ? 'add' : 'remove');
    
    // Faz a requisição AJAX para atualizar no servidor
    fetch('api_watchlist.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            lecture_id: lectureId,
            action: isChecked ? 'add' : 'remove'
        })
    })
    .then(response => {
        console.log('Resposta da API:', response.status, response.statusText);
        return response.json();
    })
    .then(data => {
        console.log('Dados retornados:', data);
        if (!data.success) {
            // Se houve erro, reverte o estado do checkbox
            checkbox.checked = !isChecked;
            watchlistText.textContent = !isChecked ? 'Na minha lista' : 'Colocar na lista';
            
            // Mostra mensagem de erro
            console.error('Erro ao atualizar watchlist:', data.message);
            alert('Erro: ' + (data.message || 'Erro desconhecido'));
        } else {
            console.log('Watchlist atualizada com sucesso!');
        }
    })
    .catch(error => {
        // Em caso de erro de rede, reverte o estado
        checkbox.checked = !isChecked;
        watchlistText.textContent = !isChecked ? 'Na minha lista' : 'Colocar na lista';
        console.error('Erro de rede:', error);
        alert('Erro de conexão: ' + error.message);
    });
}
</script>

</body>
</html>