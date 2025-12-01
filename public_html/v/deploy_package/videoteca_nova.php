<?php
session_start();
require_once 'config/database.php';

$page_title = 'Videoteca de Palestras';
$page_description = 'Acesse nossa videoteca com quase 400 palestras especializadas em tradução, interpretação e revisão.';

// CORREÇÃO: Verificar acesso (admin tem acesso completo, assinantes também)
if (!hasVideotecaAccess()) {
    header('Location: /planos.php?redirect=videoteca');
    exit;
}

// Parâmetros de busca e filtros
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$sort_order = $_GET['sort'] ?? 'recent'; // 'recent' ou 'oldest'
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 15; // 15 palestras por página (3 por linha)
$offset = ($page - 1) * $per_page;

// Construir query de busca
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(title LIKE ? OR speaker LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $where_conditions[] = "category = ?";
    $params[] = $category;
}

$where_sql = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Ordenação simplificada e corrigida
if ($sort_order === 'recent') {
    $order_sql = "ORDER BY 
    CASE 
    WHEN title LIKE 'S08%' THEN 0 
    ELSE 1 
    END,
    CASE 
    WHEN title LIKE 'S08%' THEN title 
    ELSE '' 
    END DESC,
    created_at DESC";
} else {
    $order_sql = "ORDER BY created_at ASC";
}

try {
    $newest_stmt = $pdo->prepare("SELECT id FROM lectures ORDER BY created_at DESC LIMIT 1");
    $newest_stmt->execute();
    $newest_lecture_id = $newest_stmt->fetchColumn();

    $count_sql = "SELECT COUNT(*) FROM lectures $where_sql";
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_lectures = $stmt->fetchColumn();

    $sql = "SELECT * FROM lectures $where_sql $order_sql LIMIT $per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lectures = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT DISTINCT category FROM lectures ORDER BY category");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $total_pages = ceil($total_lectures / $per_page);

} catch(PDOException $e) {
    $lectures = [];
    $categories = [];
    $total_lectures = 0;
    $total_pages = 0;
    $newest_lecture_id = null;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<?php include 'includes/head.php'; ?>
<body class="bg-gray-950 text-white font-inter">
<div class="flex min-h-screen">

    <!-- Menu lateral -->
    <?php $active_page = 'videoteca'; include 'sidebar.php'; ?>

    <!-- Conteúdo principal -->
    <div class="flex-1 px-4 py-8">
        <div class="max-w-7xl mx-auto">
        <!-- Header com indicação de acesso admin -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h1 class="text-4xl font-bold">Videoteca de Palestras</h1>
                <p class="text-gray-400 mt-2">
                    Acesse nosso catálogo completo com quase 400 palestras especializadas.
                </p>
            </div>

            <?php if (isAdmin()): ?>
            <div class="text-right">
                <span class="bg-purple-600 text-purple-100 px-3 py-1 rounded-full text-sm">
                    <i class="fas fa-crown mr-1"></i>Acesso Admin
                </span>
                <div class="mt-2">
                    <a href="/admin/palestras.php" class="text-purple-400 hover:text-purple-300 text-sm">
                        <i class="fas fa-cog mr-1"></i>Gerenciar Palestras
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Filtros e Busca -->
        <div class="bg-gray-900 rounded-lg p-6 mb-8">
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="search" class="block text-sm font-medium mb-2">Buscar</label>
                    <input
                        type="text"
                        name="search"
                        id="search"
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Título, palestrante ou descrição..."
                        class="w-full p-3 bg-gray-800 border border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none"
                    />
                </div>

                <div>
                    <label for="category" class="block text-sm font-medium mb-2">Categoria</label>
                    <select
                        name="category"
                        id="category"
                        class="w-full p-3 bg-gray-800 border border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none"
                    >
                        <option value="">Todas as categorias</option>
                        <?php foreach($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="sort" class="block text-sm font-medium mb-2">Ordenação</label>
                    <select
                        name="sort"
                        id="sort"
                        class="w-full p-3 bg-gray-800 border border-gray-700 rounded-lg focus:border-purple-500 focus:outline-none"
                    >
                        <option value="recent" <?php echo $sort_order === 'recent' ? 'selected' : ''; ?>>
                            Mais Recentes (S08 primeiro)
                        </option>
                        <option value="oldest" <?php echo $sort_order === 'oldest' ? 'selected' : ''; ?>>
                            Mais Antigas
                        </option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" class="bg-purple-600 hover:bg-purple-700 px-6 py-3 rounded-lg font-semibold transition-colors w-full">
                        <i class="fas fa-search mr-2"></i>Buscar
                    </button>
                </div>
            </form>
        </div>

        <!-- Botões de Ordenação Rápida -->
        <div class="flex justify-center mb-6">
            <div class="bg-gray-900 rounded-lg p-2 flex gap-2">
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'recent', 'page' => 1])); ?>" 
                    class="<?php echo $sort_order === 'recent' ? 'bg-purple-600 text-white' : 'bg-gray-800 hover:bg-gray-700 text-gray-300'; ?> px-4 py-2 rounded-lg transition-colors flex items-center">
                    <i class="fas fa-clock mr-2"></i>Mais Recentes
                    <?php if ($sort_order === 'recent'): ?>
                    <span class="ml-2 text-xs bg-purple-500 px-2 py-1 rounded-full">S08 primeiro</span>
                    <?php endif; ?>
                </a>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['sort' => 'oldest', 'page' => 1])); ?>" 
                    class="<?php echo $sort_order === 'oldest' ? 'bg-purple-600 text-white' : 'bg-gray-800 hover:bg-gray-700 text-gray-300'; ?> px-4 py-2 rounded-lg transition-colors flex items-center">
                    <i class="fas fa-history mr-2"></i>Mais Antigas
                </a>
            </div>
        </div>

        <!-- Resultados -->
        <div class="mb-6">
            <p class="text-gray-400">
            <?php if ($total_lectures > 0): ?>
                Mostrando <?php echo count($lectures); ?> de <?php echo $total_lectures; ?> palestras
                <?php if (!empty($search) || !empty($category)): ?>
                    <?php if (!empty($search)): ?>
                        para "<strong><?php echo htmlspecialchars($search); ?></strong>"
                    <?php endif; ?>
                    <?php if (!empty($category)): ?>
                        na categoria "<strong><?php echo htmlspecialchars($category); ?></strong>"
                    <?php endif; ?>
                <?php endif; ?>
                - <strong>15 por página</strong> - Ordenação: <strong><?php echo $sort_order === 'recent' ? 'Mais Recentes (S08 primeiro)' : 'Mais Antigas'; ?></strong>
            <?php else: ?>
                Nenhuma palestra encontrada
            <?php endif; ?>
            </p>
        </div>

        <!-- Grid de Palestras - 3 por linha -->
        <?php if (!empty($lectures)): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-8">
            <?php foreach($lectures as $lecture): ?>
            <div class="lecture-card bg-gray-900 rounded-lg overflow-hidden hover:scale-105 transition-transform cursor-pointer"
                onclick="window.location.href='/palestra.php?id=<?php echo $lecture['id']; ?>'">
                <div class="lecture-thumbnail relative aspect-video bg-gradient-to-br from-purple-600 to-purple-800 flex items-center justify-center">
                    <?php if ($lecture['thumbnail_url']): ?>
                    <img 
                        src="<?php echo htmlspecialchars($lecture['thumbnail_url']); ?>" 
                        alt="<?php echo htmlspecialchars($lecture['title']); ?>"
                        class="w-full h-full object-cover"
                    />
                    <?php else: ?>
                    <div class="text-center">
                        <div class="text-4xl mb-2">🎥</div>
                        <div class="text-sm opacity-80">Palestra</div>
                    </div>
                    <?php endif; ?>

                    <!-- Badge NOVA apenas para a palestra mais recente -->
                    <?php if ($lecture['id'] == $newest_lecture_id): ?>
                    <div class="absolute top-3 left-3">
                        <span class="bg-green-600 text-white px-3 py-1 rounded-full text-xs font-bold animate-pulse">
                            <i class="fas fa-star mr-1"></i>NOVA
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- Badge especial para S08 (exceto a mais recente que já tem NOVA) -->
                    <?php if (strpos($lecture['title'], 'S08') === 0 && $lecture['id'] != $newest_lecture_id): ?>
                    <div class="absolute top-3 left-3">
                        <span class="bg-blue-600 text-white px-3 py-1 rounded-full text-xs font-bold">
                            <i class="fas fa-fire mr-1"></i>S08
                        </span>
                    </div>
                    <?php endif; ?>

                    <!-- Play button overlay -->
                    <div class="absolute inset-0 flex items-center justify-center opacity-0 hover:opacity-100 transition-opacity bg-black bg-opacity-50">
                        <div class="bg-purple-600 rounded-full p-4">
                            <i class="fas fa-play text-2xl ml-1"></i>
                        </div>
                    </div>
                </div>

                <div class="p-6">
                    <h3 class="font-semibold text-xl mb-3 line-clamp-2">
                        <?php echo htmlspecialchars($lecture['title']); ?>
                    </h3>
                    <p class="text-purple-400 text-sm mb-3">
                        <?php echo htmlspecialchars($lecture['speaker']); ?>
                    </p>
                    <p class="text-gray-400 text-sm line-clamp-3 mb-4">
                        <?php echo htmlspecialchars(substr($lecture['description'], 0, 120)) . '...'; ?>
                    </p>

                    <div class="flex justify-between items-center">
                        <span class="bg-purple-600 text-white px-3 py-1 rounded text-xs">
                            <?php echo htmlspecialchars($lecture['category']); ?>
                        </span>
                        <span class="text-xs text-gray-400">
                            <i class="fas fa-clock mr-1"></i><?php echo $lecture['duration_minutes']; ?> min
                        </span>
                    </div>

                    <?php if ($lecture['is_featured']): ?>
                    <div class="mt-3">
                        <span class="text-xs bg-yellow-600 text-yellow-100 px-3 py-1 rounded">
                            <i class="fas fa-star mr-1"></i>Destaque
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
        <div class="flex justify-center">
            <nav class="flex space-x-2">
                <?php if ($page > 1): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                    class="bg-gray-800 hover:bg-gray-700 px-4 py-2 rounded-lg">
                    <i class="fas fa-chevron-left"></i>
                </a>
                <?php endif; ?>

                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);

                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                    class="<?php echo $i === $page ? 'bg-purple-600' : 'bg-gray-800 hover:bg-gray-700'; ?> px-4 py-2 rounded-lg">
                    <?php echo $i; ?>
                </a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                    class="bg-gray-800 hover:bg-gray-700 px-4 py-2 rounded-lg">
                    <i class="fas fa-chevron-right"></i>
                </a>
                <?php endif; ?>
            </nav>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-16">
            <div class="text-6xl mb-4">🔍</div>
            <h3 class="text-2xl font-semibold mb-4">Nenhuma palestra encontrada</h3>
            <p class="text-gray-400 mb-8">
                Tente ajustar os filtros ou termos de busca.
            </p>
            <a href="/videoteca.php" class="bg-purple-600 hover:bg-purple-700 px-6 py-3 rounded-lg font-semibold transition-colors">
                Ver Todas as Palestras
            </a>
        </div>
        <?php endif; ?>
        </div>
    </div>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.lecture-card:hover .lecture-thumbnail img {
    transform: scale(1.05);
    transition: transform 0.3s ease;
}

/* Animação para os cards */
.lecture-card {
    animation: fadeInUp 0.3s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Animação para o badge NOVA */
@keyframes pulse {
    0%, 100% {
        opacity: 1;
    }
    50% {
        opacity: .7;
    }
}

.animate-pulse {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Aspect ratio para melhor proporção das imagens */
.aspect-video {
    aspect-ratio: 16 / 9;
}

/* Melhor espaçamento para 3 colunas */
@media (min-width: 1024px) {
    .grid.lg\:grid-cols-3 > * {
        min-height: 400px;
    }
}
</style>

<?php include 'includes/footer.php'; ?>
</body>
</html>