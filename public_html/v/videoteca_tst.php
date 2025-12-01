<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Verificar acesso
if (!function_exists('hasVideotecaAccess') || !hasVideotecaAccess()) {
    header('Location: /index.php');
    exit;
}

$page_title = 'Videoteca - Translators101';
$page_description = 'Explore nossa biblioteca de palestras especializadas em traducao e interpretacao';

// Parâmetros de busca e filtros
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$sort_order = $_GET['sort'] ?? 'season_desc';

// Query SQL principal
$sql = 'SELECT * FROM lectures WHERE 1=1';
$params = [];

if (!empty($search)) {
    $sql .= ' AND (title LIKE ? OR speaker LIKE ? OR description LIKE ?)';
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($category)) {
    $sql .= ' AND category = ?';
    $params[] = $category;
}

// Buscar palestras
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lectures = $stmt->fetchAll();
} catch (Exception $e) {
    $lectures = [];
}

// Buscar watchlist do usuário logado
$user_watchlist = [];
$user_watched = [];
if (isLoggedIn()) {
    $user_id = $_SESSION['user_id'];
    
    // Palestras na watchlist
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
    
    // Palestras já assistidas (com certificado)
    try {
        $stmt = $pdo->prepare('SELECT DISTINCT lecture_id FROM certificates WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $watched_items = $stmt->fetchAll();
        foreach ($watched_items as $item) {
            $user_watched[] = $item['lecture_id'];
        }
    } catch (Exception $e) {
        // Ignorar erro
    }
}

// Buscar categorias
try {
    $stmt = $pdo->prepare('SELECT DISTINCT category FROM lectures WHERE category IS NOT NULL AND category != \'\' ORDER BY category');
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $categories = ['Traducao', 'Interpretacao', 'Linguas', 'Tecnologia', 'Negocios'];
}

// Funções de ordenação
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
        case 'season_asc':
            usort($withPattern, function($a, $b) {
                if ($a['season'] != $b['season']) {
                    return $a['season'] - $b['season'];
                }
                return $a['episode'] - $b['episode'];
            });
            usort($withoutPattern, function($a, $b) {
                return strcmp($a['title'], $b['title']);
            });
            return array_merge($withPattern, $withoutPattern);
            
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
            
        case 'alpha_asc':
            $all = array_merge($withPattern, $withoutPattern);
            usort($all, function($a, $b) {
                return strcmp($a['title'], $b['title']);
            });
            return $all;
            
        case 'alpha_desc':
            $all = array_merge($withPattern, $withoutPattern);
            usort($all, function($a, $b) {
                return strcmp($b['title'], $a['title']);
            });
            return $all;
            
        case 'date_asc':
            $all = array_merge($withPattern, $withoutPattern);
            usort($all, function($a, $b) {
                return strtotime($a['created_at']) - strtotime($b['created_at']);
            });
            return $all;
            
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

$lectures = sortLectures($lectures, $sort_order);

include __DIR__ . '/vision/includes/head.php';
?>

<style>
/* Estilos específicos para a videoteca */
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

.search-btn {
    white-space: nowrap;
    padding: 12px 20px;
    height: fit-content;
}

.video-grid-four {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 25px;
    margin-bottom: 40px;
    max-width: 100%;
}

.video-thumb-container {
    position: relative;
    width: 100%;
    padding-bottom: 56.25%;
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

.video-image {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: center;
    display: block;
}

.video-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
    border-color: var(--brand-purple);
}

.video-info h3 {
    font-size: 1rem;
    line-height: 1.3;
}

.video-speaker {
    font-size: 0.95rem;
}

.video-desc {
    font-size: 0.8rem;
    line-height: 1.4;
}

.video-meta {
    font-size: 0.8rem;
}

/* Estilo melhorado para o indicador "Destaque" */
.featured-tag {
    background: linear-gradient(135deg, #ff6b35, #f7931e) !important;
    color: #ffffff !important;
    border: 2px solid #ff4500 !important;
    font-weight: 700 !important;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7) !important;
    box-shadow: 0 2px 8px rgba(255, 107, 53, 0.4) !important;
    text-transform: capitalize !important;
}

.featured-tag:hover {
    background: linear-gradient(135deg, #ff4500, #ff6b35) !important;
    transform: scale(1.05) !important;
    box-shadow: 0 4px 12px rgba(255, 107, 53, 0.6) !important;
}

/* Estilo melhorado para categoria */
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
    margin-bottom: 8px !important;
    text-transform: capitalize !important;
}

/* Estilos para watchlist/assistida */
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

/* Estilo para palestra assistida */
.watched-indicator {
    display: flex;
    align-items: center;
    font-size: 0.85rem;
    color: #10b981;
    font-weight: 600;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7);
}

.watched-indicator i {
    margin-right: 6px;
}

@media (max-width: 1400px) {
    .video-grid-four {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 1200px) {
    .video-grid-four {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .video-grid-four {
        grid-template-columns: 1fr;
    }
}
</style>

<?php
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<div class="main-content">
    <!-- Seção Hero -->
    <div class="glass-hero">
        <div class="hero-content" style="text-align: center;">
            <h1 class="videoteca-title">
                <i class="fas fa-video"></i>
                Videoteca
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
                        <option value="season_asc" <?php echo ($sort_order === 'season_asc') ? 'selected' : ''; ?>>Temporada (crescente)</option>
                        <option value="date_desc" <?php echo ($sort_order === 'date_desc') ? 'selected' : ''; ?>>Mais recentes</option>
                        <option value="date_asc" <?php echo ($sort_order === 'date_asc') ? 'selected' : ''; ?>>Mais antigas</option>
                        <option value="alpha_asc" <?php echo ($sort_order === 'alpha_asc') ? 'selected' : ''; ?>>Alfabetica (A-Z)</option>
                        <option value="alpha_desc" <?php echo ($sort_order === 'alpha_desc') ? 'selected' : ''; ?>>Alfabetica (Z-A)</option>
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

    <!-- Seção de Administração -->
    <?php if (function_exists('isAdmin') && isAdmin()): ?>
    <div class="admin-section">
        <div class="video-card">
            <h3><i class="fas fa-cog"></i> Administracao</h3>
            <div class="admin-actions">
                <a href="/admin/palestras.php" class="cta-btn admin-btn">
                    <i class="fas fa-plus"></i> Gerenciar palestras
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Lista de Palestras -->
    <?php if (!empty($lectures)): ?>
        <div class="video-grid video-grid-four">
            <?php foreach ($lectures as $lecture): ?>
                <div class="video-card" onclick="location.href='/palestra.php?id=<?php echo $lecture['id']; ?>'">
                    <!-- Container do thumbnail -->
                    <div class="video-thumb-container">
                        <div class="video-thumb">
                            <?php if (!empty($lecture['thumbnail_url'])): ?>
                                <img src="<?php echo htmlspecialchars($lecture['thumbnail_url']); ?>" 
                                     alt="<?php echo htmlspecialchars($lecture['title']); ?>"
                                     class="video-image">
                            <?php else: ?>
                                <div class="video-placeholder">
                                    <i class="fas fa-video placeholder-icon"></i>
                                    <span class="placeholder-text">Palestra</span>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Overlay com botão de play -->
                            <div class="video-overlay">
                                <div class="play-button">
                                    <i class="fas fa-play"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Informações da palestra -->
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
                        
                        <!-- Indicador de destaque -->
                        <?php if (!empty($lecture['is_featured']) && $lecture['is_featured']): ?>
                            <div class="featured-section">
                                <span class="tag featured-tag">
                                    <i class="fas fa-star"></i> Destaque
                                </span>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Watchlist/Assistida (apenas para usuários logados) -->
                        <?php if (isLoggedIn()): ?>
                            <div class="watchlist-section">
                                <?php if (in_array($lecture['id'], $user_watched)): ?>
                                    <!-- Palestra já assistida -->
                                    <div class="watched-indicator">
                                        <i class="fas fa-check-circle"></i>
                                        <span>Assistida</span>
                                    </div>
                                <?php else: ?>
                                    <!-- Checkbox da watchlist -->
                                    <?php $isInWatchlist = in_array($lecture['id'], $user_watchlist); ?>
                                    <label class="watchlist-checkbox" onclick="event.stopPropagation();">
                                        <input type="checkbox" 
                                               class="watchlist-input" 
                                               data-lecture-id="<?php echo $lecture['id']; ?>"
                                               <?php echo $isInWatchlist ? 'checked' : ''; ?>
                                               onchange="toggleWatchlist(this, '<?php echo $lecture['id']; ?>')">
                                        <span class="checkmark"></span>
                                        <span class="watchlist-text">
                                            <?php echo $isInWatchlist ? 'Na minha lista' : 'Colocar na minha lista'; ?>
                                        </span>
                                    </label>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <!-- Estado vazio -->
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
    
    // Atualizar texto imediatamente
    watchlistText.textContent = isChecked ? 'Na minha lista' : 'Colocar na minha lista';
    
    // Fazer requisição AJAX
    fetch('/api_watchlist_tst.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin', // Inclui cookies da sessão
        body: JSON.stringify({
            lecture_id: lectureId,
            action: isChecked ? 'add' : 'remove'
        })
    })
    .then(response => response.json())
    .then(data => {
        console.log('Resposta da API:', data); // Debug
        if (!data.success) {
            // Reverter em caso de erro
            checkbox.checked = !isChecked;
            watchlistText.textContent = !isChecked ? 'Na minha lista' : 'Colocar na minha lista';
            alert('Erro: ' + (data.message || 'Erro desconhecido'));
            console.error('Erro da API:', data);
        } else {
            console.log('✅ Sucesso:', data.message);
        }
    })
    .catch(error => {
        // Reverter em caso de erro de rede
        checkbox.checked = !isChecked;
        watchlistText.textContent = !isChecked ? 'Na minha lista' : 'Colocar na minha lista';
        console.error('Erro de rede:', error);
        console.error('URL tentada: /api_watchlist_tst.php');
        console.error('Base URL:', window.location.origin);
        alert('Erro de conexão: ' + error.message + '\nVerifique o console para mais detalhes.');
    });
}
</script>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>