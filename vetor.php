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
// 3. MOTOR DE RECOMENDAÇÃO MELHORADO
// ==========================================

// Inputs
$roles = $_POST['roles'] ?? [];       
$specs = $_POST['specs'] ?? [];       
$themes = $_POST['themes'] ?? [];
$interest = $_POST['interest'] ?? ''; 
$trilha = $_GET['trilha'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$limit = 18;

$results = [];
$searched = false;
$total_results = 0;
$total_pages = 0;
$paged_results = [];
$error_msg = '';
$validation_error = '';

// ==========================================
// TRILHAS ESPECIAIS
// ==========================================
if (!empty($trilha)) {
    $searched = true;
    
    // Mapeamento de trilhas para campos booleanos
    $trilha_mapping = [
        'traducao' => 'is_translation = 1',
        'interpretacao' => 'is_interpretation = 1',
        'iniciante' => 'is_beginner = 1',
        'ferramentas' => 'is_tools = 1',
        'literaria' => 'is_literary = 1',
        'bemestar' => 'is_wellness = 1',
        'legendagem' => 'is_subtitling = 1',
        'games' => 'is_gaming = 1',
        'dublagem' => 'is_dubbing = 1',
        'tecnica' => 'is_technical = 1',
        'medica' => 'is_medical = 1',
        'revisao' => 'is_revision = 1',
        'juridica' => 'is_legal = 1',
    ];
    
    if (isset($trilha_mapping[$trilha])) {
        try {
            $sql = "SELECT * FROM lectures WHERE " . $trilha_mapping[$trilha] . " ORDER BY created_at DESC";
            $stmt = $pdo->query($sql);
            $results = $stmt->fetchAll();
            
            // Adicionar relevance para manter compatibilidade
            foreach ($results as &$result) {
                $result['relevance'] = 50; // Relevância alta para trilhas
            }
            
            // Paginação
            $total_results = count($results);
            $total_pages = ceil($total_results / $limit);
            $offset = ($page - 1) * $limit;
            $paged_results = array_slice($results, $offset, $limit);
            
        } catch (Exception $e) {
            $error_msg = "Erro ao buscar palestras da trilha.";
        }
    }
}

// Mantém filtros na sessão para paginação
if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['page']) && empty($trilha))) {
    $searched = true;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $_SESSION['vetor_filters'] = [
            'roles' => $roles,
            'specs' => $specs,
            'themes' => $themes,
            'interest' => $interest
        ];
    } elseif (isset($_SESSION['vetor_filters'])) {
        $roles = $_SESSION['vetor_filters']['roles'];
        $specs = $_SESSION['vetor_filters']['specs'];
        $themes = $_SESSION['vetor_filters']['themes'] ?? [];
        $interest = $_SESSION['vetor_filters']['interest'];
    }

    // ==========================================
    // VALIDAÇÃO: MÍNIMO DE 3 CAMPOS
    // ==========================================
    $total_selections = count($roles) + count($specs) + count($themes);
    if (!empty($interest)) $total_selections++;

    if ($total_selections < 3) {
        $validation_error = "Por favor, selecione pelo menos 3 campos para gerar recomendações mais precisas.";
    } else {
        $sql = "SELECT *, 0 as relevance FROM lectures WHERE 1=1";
        $params = [];

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $candidates = $stmt->fetchAll();

            // ==========================================
            // ALGORITMO DE PONTUAÇÃO APRIMORADO
            // ==========================================
            foreach ($candidates as $row) {
                $score = 0;
                
                // Corpus com peso na descrição
                $title = mb_strtolower($row['title']);
                $description = mb_strtolower($row['description']);
                $tags = mb_strtolower($row['tags']);
                $category = mb_strtolower($row['category']);

                // ⚠️ PALAVRA-CHAVE (Obrigatória se preenchida)
                if (!empty($interest)) {
                    $interest_lower = mb_strtolower(trim($interest));
                    $found_interest = false;
                    
                    // Busca no título (peso maior)
                    if (strpos($title, $interest_lower) !== false) {
                        $score += 15;
                        $found_interest = true;
                    }
                    // Busca na descrição (peso médio)
                    elseif (strpos($description, $interest_lower) !== false) {
                        $score += 10;
                        $found_interest = true;
                    }
                    // Busca nas tags/categoria (peso menor)
                    elseif (strpos($tags . ' ' . $category, $interest_lower) !== false) {
                        $score += 5;
                        $found_interest = true;
                    }
                    
                    // Se não encontrou a palavra-chave, descarta
                    if (!$found_interest) {
                        continue;
                    }
                }

                // Pontuação baseada em Roles (Área de Atuação)
                foreach ($roles as $role) {
                    $role_lower = mb_strtolower($role);
                    if (strpos($title, $role_lower) !== false) {
                        $score += 12; // Título
                    } elseif (strpos($description, $role_lower) !== false) {
                        $score += 8; // Descrição
                    } elseif (strpos($tags . ' ' . $category, $role_lower) !== false) {
                        $score += 5; // Tags/Categoria
                    }
                }
                
                // Pontuação baseada em Specs (Especialidade)
                foreach ($specs as $spec) {
                    $spec_lower = mb_strtolower($spec);
                    if (strpos($title, $spec_lower) !== false) {
                        $score += 20; // Título (peso alto)
                    } elseif (strpos($description, $spec_lower) !== false) {
                        $score += 12; // Descrição
                    } elseif (strpos($tags . ' ' . $category, $spec_lower) !== false) {
                        $score += 8; // Tags/Categoria
                    }
                }
                
                // Pontuação baseada em Themes (Temas/Tecnologias)
                foreach ($themes as $theme) {
                    $theme_lower = mb_strtolower($theme);
                    if (strpos($title, $theme_lower) !== false) {
                        $score += 10; // Título
                    } elseif (strpos($description, $theme_lower) !== false) {
                        $score += 6; // Descrição
                    } elseif (strpos($tags . ' ' . $category, $theme_lower) !== false) {
                        $score += 4; // Tags/Categoria
                    }
                }

                // Threshold mínimo de 15 pontos
                if ($score >= 15) {
                    $row['relevance'] = $score;
                    $results[] = $row;
                }
            }

            // Ordenar por relevância
            usort($results, function($a, $b) { return $b['relevance'] <=> $a['relevance']; });

            // Paginação
            $total_results = count($results);
            $total_pages = ceil($total_results / $limit);
            $offset = ($page - 1) * $limit;
            $paged_results = array_slice($results, $offset, $limit);

        } catch (Exception $e) {
            $error_msg = "Erro ao buscar palestras.";
        }
    }
}

$page_title = 'Vetor-T101 - Translators101';
$page_description = 'Encontre o conteúdo ideal com nossa IA de recomendação';

include __DIR__ . '/vision/includes/head.php';
?>

<style>
/* Identidade visual do videoteca.php mantida */
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

/* Perguntas guiadas */
.guided-questions {
    background: linear-gradient(135deg, rgba(142, 68, 173, 0.2), rgba(94, 51, 112, 0.2));
    border: 2px solid var(--brand-purple);
    border-radius: 16px;
    padding: 25px;
    margin-bottom: 30px;
}

.guided-questions h3 {
    color: var(--accent-gold);
    font-size: 1.3rem;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.question-item {
    background: rgba(255, 255, 255, 0.05);
    border-left: 4px solid var(--brand-purple);
    padding: 15px 20px;
    margin-bottom: 15px;
    border-radius: 8px;
    color: #ffffff;
    font-size: 1rem;
    line-height: 1.6;
}

.question-item strong {
    color: var(--accent-gold);
}

/* Alerta de validação */
.validation-alert {
    background: linear-gradient(135deg, rgba(231, 76, 60, 0.2), rgba(192, 57, 43, 0.2));
    border: 2px solid #e74c3c;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    color: #ffffff;
    font-size: 1.1rem;
    display: flex;
    align-items: center;
    gap: 15px;
}

.validation-alert i {
    font-size: 2rem;
    color: #e74c3c;
}

/* Seção de Filtros */
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
    grid-template-columns: repeat(3, 1fr);
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

.custom-checkbox-wrapper {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.custom-checkbox-wrapper.two-columns {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px 15px;
}

@media (max-width: 768px) {
    .custom-checkbox-wrapper.two-columns {
        grid-template-columns: 1fr;
    }
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

.search-input-large {
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

.search-input-large:focus {
    outline: none;
    border-color: var(--brand-purple);
    background: rgba(255, 255, 255, 0.25);
    box-shadow: 0 0 0 3px rgba(142, 68, 173, 0.3);
}

/* Botões de ação */
.action-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

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

.clear-btn {
    width: 100%;
    padding: 14px;
    font-size: 1.1rem;
    font-weight: bold;
    border-radius: 12px;
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: #fff;
    border: none;
    cursor: pointer;
    box-shadow: 0 6px 18px rgba(231, 76, 60, 0.6);
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.clear-btn:hover {
    background: linear-gradient(135deg, #c0392b, #e74c3c);
    box-shadow: 0 8px 22px rgba(231, 76, 60, 0.8);
    transform: translateY(-2px);
}

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
    position: relative;
}

.video-card:hover {
    border-color: var(--brand-purple);
    box-shadow: 0 20px 40px rgba(142, 68, 173, 0.6);
    transform: translateY(-5px);
}

/* BOLINHA DE RELEVÂNCIA */
.relevance-badge {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    z-index: 20;
    cursor: help;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.4);
    border: 2px solid rgba(255, 255, 255, 0.3);
}

.relevance-badge[data-level="very-low"] {
    background: #3498db;
}

.relevance-badge[data-level="low"] {
    background: #1abc9c;
}

.relevance-badge[data-level="medium"] {
    background: #2ecc71;
}

.relevance-badge[data-level="high"] {
    background: #f39c12;
}

.relevance-badge[data-level="very-high"] {
    background: #e74c3c;
}

.relevance-tooltip {
    position: absolute;
    top: 12px;
    right: 38px;
    background: rgba(0, 0, 0, 0.9);
    color: white;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.75rem;
    font-weight: 700;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
    z-index: 25;
    white-space: nowrap;
}

.relevance-badge:hover + .relevance-tooltip {
    opacity: 1;
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
    margin: 0 auto 30px;
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
            Vetor-T101
        </h1>
        <p>Encontre o conteúdo ideal com nossa IA de recomendação aprimorada</p>
    </div>

    <!-- Trilhas Especiais -->
    <?php
    // Buscar contagem de palestras por trilha
    $trilhas = [];
    try {
        $stmt = $pdo->query("SELECT 
            SUM(is_translation) as trilha_traducao,
            SUM(is_interpretation) as trilha_interpretacao,
            SUM(is_beginner) as trilha_iniciante,
            SUM(is_tools) as trilha_ferramentas,
            SUM(is_literary) as trilha_literaria,
            SUM(is_wellness) as trilha_bemestar,
            SUM(is_subtitling) as trilha_legendagem,
            SUM(is_gaming) as trilha_games,
            SUM(is_dubbing) as trilha_dublagem,
            SUM(is_technical) as trilha_tecnica,
            SUM(is_medical) as trilha_medica,
            SUM(is_revision) as trilha_revisao,
            SUM(is_legal) as trilha_juridica
        FROM lectures");
        $trilhas = $stmt->fetch();
    } catch (Exception $e) {
        // Fallback se der erro
        $trilhas = [
            'trilha_traducao' => 277,
            'trilha_interpretacao' => 129,
            'trilha_iniciante' => 250,
            'trilha_ferramentas' => 86,
            'trilha_literaria' => 52,
            'trilha_bemestar' => 48,
            'trilha_legendagem' => 36,
            'trilha_games' => 28,
            'trilha_dublagem' => 21,
            'trilha_tecnica' => 16,
            'trilha_medica' => 14,
            'trilha_revisao' => 14,
            'trilha_juridica' => 10,
        ];
    }
    ?>
    
    <div class="trilhas-container fade-item">
        <h3 class="trilhas-title">
            <i class="fas fa-route"></i>
            Trilhas Especiais
        </h3>
        <p class="trilhas-subtitle">Explore coleções curadas de palestras por tema</p>
        
        <div class="trilhas-grid">
            <a href="vetor.php?trilha=traducao" class="trilha-btn" data-trilha="traducao">
                <i class="fas fa-language"></i>
                <span class="trilha-name">Tradução</span>
                <span class="trilha-count"><?= $trilhas['trilha_traducao'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=iniciante" class="trilha-btn trilha-destaque" data-trilha="iniciante">
                <i class="fas fa-seedling"></i>
                <span class="trilha-name">Iniciante</span>
                <span class="trilha-count"><?= $trilhas['trilha_iniciante'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=interpretacao" class="trilha-btn" data-trilha="interpretacao">
                <i class="fas fa-microphone"></i>
                <span class="trilha-name">Interpretação</span>
                <span class="trilha-count"><?= $trilhas['trilha_interpretacao'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=ferramentas" class="trilha-btn" data-trilha="ferramentas">
                <i class="fas fa-tools"></i>
                <span class="trilha-name">Ferramentas</span>
                <span class="trilha-count"><?= $trilhas['trilha_ferramentas'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=literaria" class="trilha-btn" data-trilha="literaria">
                <i class="fas fa-book"></i>
                <span class="trilha-name">Literária</span>
                <span class="trilha-count"><?= $trilhas['trilha_literaria'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=bemestar" class="trilha-btn trilha-wellness" data-trilha="bemestar">
                <i class="fas fa-spa"></i>
                <span class="trilha-name">Bem-estar</span>
                <span class="trilha-count"><?= $trilhas['trilha_bemestar'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=legendagem" class="trilha-btn" data-trilha="legendagem">
                <i class="fas fa-closed-captioning"></i>
                <span class="trilha-name">Legendagem</span>
                <span class="trilha-count"><?= $trilhas['trilha_legendagem'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=games" class="trilha-btn trilha-gaming" data-trilha="games">
                <i class="fas fa-gamepad"></i>
                <span class="trilha-name">Games</span>
                <span class="trilha-count"><?= $trilhas['trilha_games'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=dublagem" class="trilha-btn" data-trilha="dublagem">
                <i class="fas fa-film"></i>
                <span class="trilha-name">Dublagem</span>
                <span class="trilha-count"><?= $trilhas['trilha_dublagem'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=tecnica" class="trilha-btn" data-trilha="tecnica">
                <i class="fas fa-cogs"></i>
                <span class="trilha-name">Técnica</span>
                <span class="trilha-count"><?= $trilhas['trilha_tecnica'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=medica" class="trilha-btn trilha-medical" data-trilha="medica">
                <i class="fas fa-heartbeat"></i>
                <span class="trilha-name">Médica/Saúde</span>
                <span class="trilha-count"><?= $trilhas['trilha_medica'] ?></span>
            </a>
            
            <a href="vetor.php?trilha=juridica" class="trilha-btn trilha-legal" data-trilha="juridica">
                <i class="fas fa-gavel"></i>
                <span class="trilha-name">Jurídica</span>
                <span class="trilha-count"><?= $trilhas['trilha_juridica'] ?></span>
            </a>
        </div>
    </div>

    <!-- Perguntas Guiadas -->
    <?php if (!$searched && empty($_GET['trilha'])): ?>
    <div class="guided-questions fade-item">
        <h3><i class="fas fa-lightbulb"></i> Dicas para melhores recomendações</h3>
        <div class="question-item">
            <strong>1.</strong> Em qual área você atua principalmente? (Tradução, Interpretação, Localização...)
        </div>
        <div class="question-item">
            <strong>2.</strong> Qual é sua especialidade? (Jurídica, Médica, Literária, Games...)
        </div>
        <div class="question-item">
            <strong>3.</strong> Quais temas interessam você? (IA, Ferramentas, Carreira, Negócios...)
        </div>
        <div class="question-item">
            <strong>💡 Selecione pelo menos 3 campos</strong> para receber recomendações mais precisas e relevantes!
        </div>
    </div>
    <?php endif; ?>

    <!-- Alerta de Validação -->
    <?php if (!empty($validation_error)): ?>
    <div class="validation-alert fade-item">
        <i class="fas fa-exclamation-triangle"></i>
        <div><?= htmlspecialchars($validation_error) ?></div>
    </div>
    <?php endif; ?>

    <!-- Seção de Filtros -->
    <div class="videoteca-filtros fade-item">
        <form method="POST" action="vetor.php" id="filterForm">
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
                        <label class="custom-checkbox">
                            <input type="checkbox" name="roles[]" value="Localização" <?= in_array('Localização', $roles) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Localização
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="roles[]" value="Revisão" <?= in_array('Revisão', $roles) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Revisão
                        </label>
                    </div>
                </div>

                <!-- Coluna 2: Especialidade -->
                <div class="filter-column">
                    <span class="filter-section-title">
                        <i class="fas fa-star"></i>
                        Especialidade
                    </span>
                    <div class="custom-checkbox-wrapper two-columns">
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Jurídica" <?= in_array('Jurídica', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Jurídica
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Médica" <?= in_array('Médica', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Saúde
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Literária" <?= in_array('Literária', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Literária
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Games" <?= in_array('Games', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Games
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Audiovisual" <?= in_array('Audiovisual', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Audiovisual
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Marketing" <?= in_array('Marketing', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Marketing
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Técnica" <?= in_array('Técnica', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Técnica
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Científica" <?= in_array('Científica', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Científica
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Turismo" <?= in_array('Turismo', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Turismo
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="specs[]" value="Financeira" <?= in_array('Financeira', $specs) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Financeira
                        </label>
                    </div>
                </div>

                <!-- Coluna 3: Temas/Tecnologias e Palavra-Chave -->
                <div class="filter-column">
                    <span class="filter-section-title">
                        <i class="fas fa-microchip"></i>
                        Temas / Tecnologias
                    </span>
                    <div class="custom-checkbox-wrapper">
                        <label class="custom-checkbox">
                            <input type="checkbox" name="themes[]" value="IA" <?= in_array('IA', $themes) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Inteligência Artificial
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="themes[]" value="Ferramentas" <?= in_array('Ferramentas', $themes) || in_array('CAT', $themes) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Ferramentas
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="themes[]" value="Carreira" <?= in_array('Carreira', $themes) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Carreira
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="themes[]" value="Negócios" <?= in_array('Negócios', $themes) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Negócios
                        </label>
                        <label class="custom-checkbox">
                            <input type="checkbox" name="themes[]" value="Gestão" <?= in_array('Gestão', $themes) ? 'checked' : '' ?>>
                            <span class="checkbox-mark"></span>
                            Gestão de projetos
                        </label>
                    </div>
                    
                    <span class="filter-section-title" style="margin-top: 20px;">
                        <i class="fas fa-key"></i>
                        Palavra-Chave (Opcional)
                    </span>
                    <input type="text" 
                           name="interest" 
                           class="search-input-large" 
                           placeholder="Ex: Marketing, Tecnologia..." 
                           value="<?= htmlspecialchars($interest) ?>">
                </div>

            </div>

            <!-- Botões de Ação -->
            <div class="action-buttons">
                <button type="submit" class="search-btn-full">
                    <i class="fas fa-search"></i>
                    Buscar recomendações
                </button>
                <button type="button" class="clear-btn" onclick="clearFilters()">
                    <i class="fas fa-times-circle"></i>
                    Limpar tudo
                </button>
            </div>
        </form>
    </div>

    <!-- Seção de Resultados -->
    <?php if ($searched && empty($validation_error)): ?>
        
        <?php if (count($paged_results) > 0): ?>
            
            <!-- Header de Resultados -->
            <div class="results-header fade-item">
                <div class="results-count">
                    Encontramos <span><?php echo $total_results; ?></span> <?php echo $total_results == 1 ? 'resultado' : 'resultados'; ?>
                </div>
            </div>

            <!-- Grid de Vídeos -->
            <div class="video-grid video-grid-four">
                <?php foreach ($paged_results as $lecture): 
                    $max_score = 60;
                    $percentage = min(100, ($lecture['relevance'] / $max_score) * 100);
                    
                    if ($lecture['relevance'] < 15) {
                        $color_level = 'very-low';
                    } elseif ($lecture['relevance'] < 25) {
                        $color_level = 'low';
                    } elseif ($lecture['relevance'] < 35) {
                        $color_level = 'medium';
                    } elseif ($lecture['relevance'] < 45) {
                        $color_level = 'high';
                    } else {
                        $color_level = 'very-high';
                    }
                ?>
                    <div class="video-card" onclick="location.href='/palestra.php?id=<?php echo $lecture['id']; ?>'">
                        
                        <div class="video-thumb-container">
                            <!-- Bolinha de Relevância -->
                            <div class="relevance-badge" data-level="<?= $color_level ?>"></div>
                            <div class="relevance-tooltip">
                                <?= $lecture['relevance'] ?> pontos
                            </div>
                            
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
            <div class="empty-state fade-item">
                <div class="empty-icon">
                    <i class="fas fa-search"></i>
                </div>
                <h2 class="empty-title">Nenhum resultado encontrado</h2>
                <p class="empty-description">
                    Não encontramos palestras que correspondam aos seus critérios. Tente ajustar os filtros.
                </p>
            </div>
        <?php endif; ?>
        
    <?php elseif (!$searched): ?>
        <div class="initial-message fade-item">
            <i class="fas fa-magic"></i>
            <h2>Pronto para descobrir conteúdo incrível?</h2>
            <p>Responda às perguntas acima e selecione pelo menos 3 campos para receber recomendações personalizadas!</p>
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

function clearFilters() {
    // Desmarca todos os checkboxes
    document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
    
    // Limpa o campo de texto
    document.querySelector('input[name="interest"]').value = '';
    
    // Recarrega a página para estado inicial
    window.location.href = 'vetor.php';
}
</script>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>
