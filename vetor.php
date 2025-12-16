<?php
session_start();

// ==========================================
// 1. CONFIGURAÇÕES GERAIS
// ==========================================

// Caminhos
$path_includes = __DIR__ . '/vision/includes/';
$db_file = __DIR__ . '/../config/database.php';

// Conexão DB
if (file_exists($db_file)) {
    require_once $db_file;
} else {
    // Fallback
    $host = 'localhost'; $db = 'u335416710_t101_db'; $user = 'u335416710_t101'; $pass = 'Pa392ap!';
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    } catch (PDOException $e) { die("Erro DB: " . $e->getMessage()); }
}

// ==========================================
// 2. CONTROLE DE ACESSO E SESSÃO
// ==========================================

if (!function_exists('isLoggedIn')) { function isLoggedIn() { return isset($_SESSION['user_id']); } }
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
    }
}

$is_logged_in = isLoggedIn();
$user_id = $_SESSION['user_id'] ?? null;
$is_subscriber = false;

if ($is_logged_in) {
    if (function_exists('hasVideotecaAccess')) {
        $is_subscriber = hasVideotecaAccess();
    } else {
        $role = $_SESSION['role'] ?? 'free';
        $is_subscriber = ($role === 'subscriber' || $role === 'admin');
    }
}

// Carrega Minha Lista (Watchlist)
$user_watchlist = [];
$user_watched = [];
if ($is_logged_in && isset($pdo)) {
    try {
        $stmt = $pdo->prepare('SELECT lecture_id FROM user_watchlist WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $watchlist_items = $stmt->fetchAll();
        foreach ($watchlist_items as $item) $user_watchlist[] = $item['lecture_id'];
    } catch (Exception $e) {}
    
    // Palestras já assistidas (com certificado)
    try {
        $stmt = $pdo->prepare('SELECT DISTINCT lecture_id FROM certificates WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $watched_items = $stmt->fetchAll();
        foreach ($watched_items as $item) {
            $user_watched[] = $item['lecture_id'];
        }
    } catch (Exception $e) {}
}

// ==========================================
// 3. MOTOR DE RECOMENDAÇÃO + PAGINAÇÃO
// ==========================================

// Inputs
$roles = $_POST['roles'] ?? [];       
$specs = $_POST['specs'] ?? [];       
$themes = $_POST['themes'] ?? [];     // Novo: Temas/Tecnologias
$level = $_POST['level'] ?? '';       
$interest = $_POST['interest'] ?? ''; 
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 18; // Máximo por página

$results = [];
$searched = false;
$total_results = 0;
$total_pages = 0;
$paged_results = [];

// Mantém filtros na sessão para paginação
if ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['page'])) {
    $searched = true;
    
    // Recupera filtros da sessão se for navegação de página
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['vetor_filters'] = [
            'roles' => $roles,
            'specs' => $specs,
            'themes' => $themes,
            'level' => $level,
            'interest' => $interest
        ];
    } elseif (isset($_SESSION['vetor_filters'])) {
        $roles = $_SESSION['vetor_filters']['roles'];
        $specs = $_SESSION['vetor_filters']['specs'];
        $themes = $_SESSION['vetor_filters']['themes'] ?? [];
        $level = $_SESSION['vetor_filters']['level'];
        $interest = $_SESSION['vetor_filters']['interest'];
    }

    $sql = "SELECT *, 0 as relevance FROM lectures WHERE 1=1";
    $params = [];

    if (!empty($level)) {
        $sql .= " AND level = ?"; 
        $params[] = $level;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();

        // ==========================================
        // ALGORITMO DE PONTUAÇÃO AJUSTADO
        // ==========================================
        foreach ($candidates as $row) {
            $score = 0;
            $corpus = mb_strtolower($row['title'] . ' ' . $row['description'] . ' ' . $row['tags'] . ' ' . $row['category']);

            // ⚠️ LÓGICA E PARA PALAVRA-CHAVE (OBRIGATÓRIA)
            // Se o usuário digitou uma palavra-chave, ela DEVE estar presente
            if (!empty($interest)) {
                $interest_lower = mb_strtolower(trim($interest));
                if (strpos($corpus, $interest_lower) === false) {
                    // Palavra-chave não encontrada = DESCARTA este resultado
                    continue; // Pula para a próxima palestra
                }
                // Se chegou aqui, a palavra-chave está presente
                $score += 5;
            }

            // Pontuação baseada em Roles (Área de Atuação) - Peso 10
            foreach ($roles as $role) {
                if (strpos($corpus, mb_strtolower($role)) !== false) $score += 10;
            }
            
            // Pontuação baseada em Specs (Especialidade) - Peso 15
            foreach ($specs as $spec) {
                if (strpos($corpus, mb_strtolower($spec)) !== false) $score += 15;
            }
            
            // Pontuação baseada em Themes (Temas/Tecnologias) - Peso 8
            foreach ($themes as $theme) {
                if (strpos($corpus, mb_strtolower($theme)) !== false) $score += 8;
            }

            // ⚠️ THRESHOLD MÍNIMO DE 20 PONTOS
            // Só inclui resultados com pontuação >= 20
            if ($score >= 20) {
                $row['relevance'] = $score;
                $results[] = $row;
            }
        }

        // Ordenar por relevância
        usort($results, function($a, $b) { return $b['relevance'] <=> $a['relevance']; });

        // Paginação Array Slice
        $total_results = count($results);
        $total_pages = ceil($total_results / $limit);
        $offset = ($page - 1) * $limit;
        $paged_results = array_slice($results, $offset, $limit);

    } catch (Exception $e) {
        $error_msg = "Erro ao buscar palestras.";
    }
}

$page_title = 'Recomendador Inteligente - Translators101';
$page_description = 'Encontre o conteúdo ideal com nossa IA de recomendação';

include __DIR__ . '/vision/includes/head.php';
?>

<style>
/* =========================================
   IDENTIDADE VISUAL DO VIDEOTECA.PHP
   ========================================= */

/* Estilos base - Glassmorphism */
.vetor-hero {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 40px 30px;
    margin-bottom: 30px;
    text-align: center;
}

.vetor-hero h1 {
    display: flex;
    align-items: center;
    gap: 15px;
    justify-content: center;
    font-size: 2.5rem;
    color: #ffffff;
    margin-bottom: 15px;
}

.vetor-hero p {
    color: var(--text-secondary);
    font-size: 1.15rem;
    margin: 0;
}

/* Seção de Filtros com identidade visual do videoteca */
.videoteca-filtros {
    margin-bottom: 30px;
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 30px;
}

.filter-section-title {
    color: var(--brand-purple-light);
    font-weight: 700;
    text-transform: uppercase;
    font-size: 0.9rem;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--brand-purple);
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 25px;
    margin-bottom: 25px;
}

@media (max-width: 1200px) {
    .filter-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
}

.filter-column {
    display: flex;
    flex-direction: column;
}

/* Checkboxes customizados estilo videoteca */
.custom-checkbox-wrapper {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.custom-checkbox {
    display: flex;
    align-items: center;
    cursor: pointer;
    font-size: 0.95rem;
    color: #ffffff;
    user-select: none;
    padding: 8px;
    border-radius: 8px;
    transition: background 0.3s ease;
}

.custom-checkbox:hover {
    background: rgba(255, 255, 255, 0.05);
}

.custom-checkbox input[type="checkbox"],
.custom-checkbox input[type="radio"] {
    display: none;
}

.checkbox-mark {
    height: 20px;
    width: 20px;
    background: rgba(255, 255, 255, 0.2);
    border: 2px solid rgba(255, 255, 255, 0.4);
    border-radius: 4px;
    margin-right: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
    position: relative;
    flex-shrink: 0;
}

.checkbox-mark:before {
    content: '';
    position: absolute;
    left: 4px;
    top: 1px;
    width: 5px;
    height: 10px;
    border: solid #ffffff;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
    opacity: 0;
    transition: opacity 0.3s ease;
}

.custom-checkbox input:checked + .checkbox-mark {
    background: linear-gradient(135deg, var(--brand-purple), #5e3370);
    border-color: var(--brand-purple);
}

.custom-checkbox input:checked + .checkbox-mark:before {
    opacity: 1;
}

/* Input de texto e select */
.search-input-large,
.form-select-custom {
    width: 100%;
    padding: 12px 16px;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, 0.3);
    background: rgba(255, 255, 255, 0.18);
    color: #ffffff;
    font-size: 1rem;
    font-weight: 600;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.6);
    transition: all 0.3s ease;
}

.search-input-large::placeholder {
    color: rgba(255, 255, 255, 0.6);
}

.search-input-large:focus,
.form-select-custom:focus {
    outline: none;
    border-color: var(--brand-purple);
    background: rgba(255, 255, 255, 0.25);
    box-shadow: 0 0 0 3px rgba(142, 68, 173, 0.3);
}

.form-select-custom option {
    background: #2c3e50;
    color: #ffffff;
}

/* Botão de busca */
.search-btn-full {
    width: 100%;
    padding: 14px;
    font-size: 1.1rem;
    font-weight: bold;
    border-radius: 12px;
    background: linear-gradient(135deg, var(--brand-purple), #5e3370);
    color: #fff;
    border: none;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(142, 68, 173, 0.6);
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.search-btn-full:hover {
    background: linear-gradient(135deg, #5e3370, var(--brand-purple));
    box-shadow: 0 8px 22px rgba(142, 68, 173, 0.8);
    transform: translateY(-2px);
}

/* Seção de resultados */
.results-header {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    padding: 20px 30px;
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.results-count {
    font-size: 1.5rem;
    font-weight: 700;
    color: #ffffff;
}

.results-count span {
    color: var(--accent-gold);
}

/* Grid de vídeos - mesmo estilo do videoteca.php */
.video-grid-four {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 25px;
    margin-bottom: 40px;
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

/* Cards de vídeo - identidade videoteca.php */
.video-card {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s ease;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.video-card:hover {
    border-color: var(--brand-purple);
    box-shadow: 0 20px 40px rgba(142, 68, 173, 0.6);
    transform: translateY(-5px);
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
}

.video-placeholder {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    width: 100%;
    height: 100%;
    color: rgba(255, 255, 255, 0.7);
    font-size: 1.5rem;
    text-align: center;
}

.placeholder-icon {
    font-size: 3rem;
    margin-bottom: 10px;
}

/* Badge de relevância */
.relevance-badge {
    position: absolute;
    top: 10px;
    left: 10px;
    background: linear-gradient(135deg, #27ae60, #229954);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 700;
    z-index: 5;
    box-shadow: 0 2px 8px rgba(39, 174, 96, 0.6);
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7);
}

/* Overlay de play */
.video-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.3);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.video-card:hover .video-overlay {
    opacity: 1;
}

.play-button {
    background: rgba(255, 255, 255, 0.9);
    color: var(--brand-purple);
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.4);
    transition: transform 0.3s ease;
}

.play-button:hover {
    transform: scale(1.1);
}

/* Info do vídeo */
.video-info {
    display: flex;
    flex-direction: column;
    flex-grow: 1;
    padding: 20px;
}

.video-info h3 {
    font-size: 1.05rem;
    line-height: 1.35rem;
    color: #ffffff;
    margin-bottom: 10px;
    font-weight: 700;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    min-height: calc(1.35rem * 3);
    max-height: calc(1.35rem * 3);
}

.video-speaker {
    font-size: 0.95rem;
    line-height: 1.2rem;
    color: var(--accent-gold);
    font-weight: 600;
    margin-bottom: 10px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    min-height: calc(1.2rem * 2);
    max-height: calc(1.2rem * 2);
}

.video-desc {
    font-size: 0.85rem;
    line-height: 1.4rem;
    color: var(--text-secondary);
    flex-grow: 1;
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
}

.video-category {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #ffffff;
    padding: 5px 12px;
    border-radius: 12px;
    border: 2px solid #1e40af;
    font-size: 0.75rem;
    font-weight: 700;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.7);
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.4);
    display: inline-block;
    margin-bottom: 8px;
    text-transform: capitalize;
}

/* Watchlist section */
.watchlist-section {
    margin-top: auto;
    padding-top: 15px;
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

/* Paginação */
.pagination-wrapper {
    display: flex;
    justify-content: center;
    margin-top: 40px;
    margin-bottom: 40px;
}

.pagination {
    display: flex;
    gap: 8px;
    align-items: center;
}

.page-link {
    padding: 10px 16px;
    background: var(--glass-bg);
    backdrop-filter: blur(10px);
    border: 1px solid var(--glass-border);
    border-radius: 8px;
    color: #ffffff;
    font-weight: 600;
    text-decoration: none;
    transition: all 0.3s ease;
}

.page-link:hover {
    background: rgba(255, 255, 255, 0.15);
    border-color: var(--brand-purple);
    transform: translateY(-2px);
}

.page-link.active {
    background: linear-gradient(135deg, var(--brand-purple), #5e3370);
    border-color: var(--brand-purple);
    box-shadow: 0 4px 12px rgba(142, 68, 173, 0.6);
}

/* Estado vazio */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    border-radius: 16px;
    background: var(--glass-bg);
    border: 1px solid var(--glass-border);
    backdrop-filter: blur(20px);
    margin-top: 40px;
}

.empty-icon {
    font-size: 5rem;
    color: var(--brand-purple-light);
    margin-bottom: 20px;
    opacity: 0.6;
}

.empty-title {
    font-size: 2rem;
    color: #ffffff;
    margin-bottom: 15px;
}

.empty-description {
    font-size: 1.1rem;
    color: var(--text-secondary);
    max-width: 500px;
    margin: 0 auto 30px;
    line-height: 1.6;
}

/* CTA Premium */
.premium-cta {
    background: linear-gradient(135deg, rgba(142, 68, 173, 0.9), rgba(94, 51, 112, 0.9));
    backdrop-filter: blur(20px);
    border: 2px solid var(--brand-purple);
    border-radius: 20px;
    padding: 40px;
    text-align: center;
    margin-top: 50px;
    box-shadow: 0 10px 30px rgba(142, 68, 173, 0.4);
}

.premium-cta h3 {
    font-size: 2rem;
    color: #ffffff;
    margin-bottom: 15px;
    font-weight: 700;
}

.premium-cta p {
    font-size: 1.2rem;
    color: var(--text-secondary);
    margin-bottom: 25px;
}

.cta-btn {
    display: inline-block;
    padding: 16px 40px;
    font-size: 1.2rem;
    font-weight: bold;
    border-radius: 30px;
    background: linear-gradient(135deg, var(--accent-gold), #e68a00);
    color: #2c3e50;
    text-decoration: none;
    box-shadow: 0 6px 18px rgba(247, 147, 30, 0.6);
    transition: all 0.3s ease;
    border: none;
    cursor: pointer;
}

.cta-btn:hover {
    background: linear-gradient(135deg, #e68a00, var(--accent-gold));
    box-shadow: 0 8px 22px rgba(247, 147, 30, 0.8);
    transform: translateY(-3px);
}

/* Mensagem inicial (antes de buscar) */
.initial-message {
    text-align: center;
    padding: 80px 20px;
    color: #ffffff;
}

.initial-message i {
    font-size: 6rem;
    color: var(--brand-purple-light);
    margin-bottom: 30px;
    opacity: 0.8;
}

.initial-message h2 {
    font-size: 2.5rem;
    margin-bottom: 20px;
    font-weight: 700;
}

.initial-message p {
    font-size: 1.3rem;
    color: var(--text-secondary);
    max-width: 600px;
    margin: 0 auto;
    line-height: 1.6;
}
</style>

<?php
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<div class="main-content">
    <!-- Hero Section -->
    <div class="vetor-hero fade-item">
        <h1>
            <i class="fas fa-brain"></i>
            Recomendador Inteligente
        </h1>
        <p>Encontre o conteúdo ideal com nossa IA de recomendação</p>
    </div>

    <!-- Seção de Filtros -->
    <div class="videoteca-filtros fade-item">
        <form method="POST" action="vetor.php">
            <div class="filter-grid">
                
                <!-- Coluna 1: Área de Atuação -->
                <div class="filter-column">
                    <span class="filter-section-title">
                        <i class="fas fa-briefcase"></i>
                        Área de Atuação
                    </span>
                    <div class="custom-checkbox-wrapper">
                        <label class="custom-checkbox">
                            <input type="checkbox" name="roles[]" value="Tradução" <?= in_array('Tradução', $roles) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Tradução
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="roles[]" value="Interpretação" <?= in_array('Interpretação', $roles) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Interpretação
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="roles[]" value="Legendagem" <?= in_array('Legendagem', $roles) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Legendagem
                        </label>
                    </div>
                </div>

                <!-- Coluna 2: Especialidade -->
                <div class="filter-column">
                    <span class="filter-section-title">
                        <i class="fas fa-star"></i>
                        Especialidade
                    </span>
                    <div class="custom-checkbox-wrapper">
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Jurídica" <?= in_array('Jurídica', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Jurídica
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Médica" <?= in_array('Médica', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Médica / Saúde
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Literária" <?= in_array('Literária', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Literária
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Games" <?= in_array('Games', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Games / Loc
                        </label>
                    </div>
                </div>

                <!-- Coluna 3: Nível -->
                <div class="filter-column">
                    <span class="filter-section-title">
                        <i class="fas fa-layer-group"></i>
                        Nível
                    </span>
                    <select name="level" class="form-select-custom">
                        <option value="">Todos os níveis</option>
                        <option value="Iniciante" <?= $level=='Iniciante'?'selected':'' ?>>Iniciante</option>
                        <option value="Intermediário" <?= $level=='Intermediário'?'selected':'' ?>>Intermediário</option>
                        <option value="Avançado" <?= $level=='Avançado'?'selected':'' ?>>Avançado</option>
                    </select>
                </div>

                <!-- Coluna 4: Palavra-Chave -->
                <div class="filter-column">
                    <span class="filter-section-title">
                        <i class="fas fa-key"></i>
                        Palavra-Chave (Obrigatória)
                    </span>
                    <input type="text" 
                           name="interest" 
                           class="search-input-large" 
                           placeholder="Ex: Marketing, Tecnologia..." 
                           value="<?= htmlspecialchars($interest) ?>">
                </div>

            </div>

            <!-- Botão de Busca -->
            <button type="submit" class="search-btn-full">
                <i class="fas fa-search"></i>
                Buscar Recomendações
            </button>
        </form>
    </div>

    <!-- Seção de Resultados -->
    <?php if ($searched): ?>
        
        <?php if (count($paged_results) > 0): ?>
            
            <!-- Header de Resultados -->
            <div class="results-header fade-item">
                <div class="results-count">
                    Encontramos <span><?php echo $total_results; ?></span> <?php echo $total_results == 1 ? 'resultado' : 'resultados'; ?>
                </div>
            </div>

            <!-- Grid de Vídeos -->
            <div class="video-grid video-grid-four">
                <?php foreach ($paged_results as $lecture): ?>
                    <div class="video-card" onclick="location.href='/palestra.php?id=<?php echo $lecture['id']; ?>'">
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
                                
                                <!-- Badge de Relevância -->
                                <span class="relevance-badge">
                                    <i class="fas fa-star"></i> <?= $lecture['relevance'] ?> pts
                                </span>
                                
                                <div class="video-overlay">
                                    <div class="play-button">
                                        <i class="fas fa-play"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="video-info">
                            <h3><?php echo htmlspecialchars($lecture['title']); ?></h3>
                            
                            <?php if (!empty($lecture['speaker'])): ?>
                                <div class="video-speaker">
                                    <i class="fas fa-user"></i> <?php echo htmlspecialchars($lecture['speaker']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($lecture['description'])): ?>
                                <p class="video-desc"><?php echo htmlspecialchars(substr($lecture['description'], 0, 120)); ?>...</p>
                            <?php endif; ?>
                            
                            <?php if (!empty($lecture['category'])): ?>
                                <div class="video-category">
                                    <?php echo htmlspecialchars($lecture['category']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($is_logged_in): ?>
                                <div class="watchlist-section">
                                    <?php if (in_array($lecture['id'], $user_watched)): ?>
                                        <div class="watched-indicator">
                                            <i class="fas fa-check-circle"></i>
                                            <span>Assistida</span>
                                        </div>
                                    <?php else: ?>
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

            <!-- Paginação -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination">
                    <?php for($i=1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?= $i ?>" class="page-link <?= ($i == $page) ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- CTA Premium -->
            <?php if (!$is_subscriber): ?>
                <div class="premium-cta fade-item">
                    <h3><i class="fas fa-crown"></i> Gostou das sugestões?</h3>
                    <p>Assine o Premium e tenha acesso imediato a todas essas palestras exclusivas</p>
                    <a href="/planos.php" class="cta-btn">
                        <i class="fas fa-star"></i> Assinar Premium Agora
                    </a>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- Estado Vazio - Nenhum resultado -->
            <div class="empty-state fade-item">
                <div class="empty-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h2 class="empty-title">Nenhum resultado encontrado</h2>
                <p class="empty-description">
                    Não encontramos palestras que correspondam aos seus critérios. Tente ajustar os filtros ou remover a palavra-chave obrigatória.
                </p>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <!-- Mensagem Inicial - Antes de buscar -->
        <div class="initial-message fade-item">
            <i class="fas fa-magic"></i>
            <h2>Pronto para descobrir conteúdo incrível?</h2>
            <p>Use os filtros acima para receber recomendações personalizadas baseadas nos seus interesses e necessidades profissionais.</p>
        </div>
    <?php endif; ?>

</div>

<script>
function toggleWatchlist(checkbox, lectureId) {
    const isChecked = checkbox.checked;
    const watchlistText = checkbox.parentElement.querySelector('.watchlist-text');
    
    watchlistText.textContent = isChecked ? 'Na minha lista' : 'Colocar na minha lista';
    
    fetch('/api_watchlist.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
            lecture_id: lectureId,
            action: isChecked ? 'add' : 'remove'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            checkbox.checked = !isChecked;
            watchlistText.textContent = !isChecked ? 'Na minha lista' : 'Colocar na minha lista';
            alert('Erro: ' + (data.message || 'Erro desconhecido'));
        }
    })
    .catch(error => {
        checkbox.checked = !isChecked;
        watchlistText.textContent = !isChecked ? 'Na minha lista' : 'Colocar na minha lista';
        alert('Erro de conexão: ' + error.message);
    });
}
</script>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>
