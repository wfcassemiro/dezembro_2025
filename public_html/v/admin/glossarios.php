<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Verificar se é admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Gerenciar Glossários - Admin';
$message = '';
$error = '';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
    switch ($action) {
    case 'add_glossary':
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = trim($_POST['category']);
    $download_url = trim($_POST['download_url']);
    $file_type = trim($_POST['file_type']);

    // Gerar UUID para o ID
    $id = bin2hex(random_bytes(16));
    $formatted_id = sprintf('%s-%s-%s-%s-%s',
        substr($id, 0, 8),
        substr($id, 8, 4),
        substr($id, 12, 4),
        substr($id, 16, 4),
        substr($id, 20, 12)
    );

    $stmt = $pdo->prepare("INSERT INTO glossary_files (id, title, description, category, file_type, download_url, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, NOW())");
    $stmt->execute([$formatted_id, $title, $description, $category, $file_type, $download_url]);
    $message = 'Glossário adicionado com sucesso!';
    break;

    case 'toggle_glossary':
    $glossary_id = trim($_POST['glossary_id']);
    $stmt = $pdo->prepare("UPDATE glossary_files SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$glossary_id]);
    $message = 'Status do glossário atualizado!';
    break;

    case 'delete_glossary':
    $glossary_id = trim($_POST['glossary_id']);
    $stmt = $pdo->prepare("DELETE FROM glossary_files WHERE id = ?");
    $stmt->execute([$glossary_id]);
    $message = 'Glossário removido com sucesso!';
    break;
    }
    } catch (PDOException $e) {
    $error = 'Erro ao processar ação: ' . $e->getMessage();
    }
}

// Buscar glossários
$search = $_GET['search'] ?? '';
$category_filter = $_GET['category'] ?? '';

try {
    $where_conditions = [];
    $params = [];

    if ($search) {
    $where_conditions[] = "(title LIKE ? OR description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    }

    if ($category_filter) {
    $where_conditions[] = "category = ?";
    $params[] = $category_filter;
    }

    $where_clause = '';
    if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    }

    $stmt = $pdo->prepare("SELECT * FROM glossary_files $where_clause ORDER BY created_at DESC");
    $stmt->execute($params);
    $glossaries = $stmt->fetchAll();

    // Buscar categorias
    $stmt = $pdo->query("SELECT DISTINCT category FROM glossary_files WHERE category IS NOT NULL AND category != '' ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Estatísticas
    $stmt = $pdo->query("SELECT COUNT(*) FROM glossary_files WHERE is_active = 1");
    $active_glossaries = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT SUM(download_count) FROM glossary_files");
    $total_downloads = (int) $stmt->fetchColumn();

} catch (PDOException $e) {
    $glossaries = [];
    $categories = [];
    $active_glossaries = 0;
    $total_downloads = 0;
    $error = 'Erro ao carregar glossários: ' . $e->getMessage();
}

// Varredura de arquivos não cadastrados
$uploadDir = __DIR__ . '/../uploads/glossarios/';
$existingPaths = [];
$unregisteredFiles = [];

try {
    $stmt = $pdo->query("SELECT download_url FROM glossary_files");
    $existingPaths = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (is_dir($uploadDir)) {
        $files = scandir($uploadDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['pdf', 'csv', 'xlsx'])) {
                $url = "/uploads/glossarios/" . $file;
                if (!in_array($url, $existingPaths)) {
                    $filePath = $uploadDir . $file;
                    $unregisteredFiles[] = [
                        'name' => $file,
                        'size' => file_exists($filePath) ? filesize($filePath) : 0,
                        'type' => strtoupper($ext),
                        'modified' => file_exists($filePath) ? filemtime($filePath) : 0
                    ];
                }
            }
        }
    }
} catch (Exception $e) {
    // Silenciar erros de varredura para não quebrar a página principal
}

include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="glass-hero">
    <div class="hero-content">
    <h1><i class="fas fa-book"></i> Gerenciar Glossários</h1>
    <p>Administração dos glossários especializados da plataforma</p>
    <a href="index.php" class="cta-btn">
    <i class="fas fa-arrow-left"></i> Voltar ao Admin
    </a>
    </div>
    </div>

    <?php if ($message): ?>
    <div class="alert-success">
    <i class="fas fa-check-circle"></i>
    <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert-error">
    <i class="fas fa-exclamation-triangle"></i>
    <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <!-- Estatísticas -->
    <div class="stats-grid">
    <div class="video-card stats-card">
    <div class="stats-content">
    <div class="stats-info">
    <h3>Glossários Ativos</h3>
    <span class="stats-number"><?php echo number_format($active_glossaries); ?></span>
    </div>
    <div class="stats-icon stats-icon-green">
    <i class="fas fa-book-open"></i>
    </div>
    </div>
    </div>

    <div class="video-card stats-card">
    <div class="stats-content">
    <div class="stats-info">
    <h3>Total Glossários</h3>
    <span class="stats-number"><?php echo count($glossaries); ?></span>
    </div>
    <div class="stats-icon stats-icon-blue">
    <i class="fas fa-book"></i>
    </div>
    </div>
    </div>

    <div class="video-card stats-card">
    <div class="stats-content">
    <div class="stats-info">
    <h3>Downloads Totais</h3>
    <span class="stats-number"><?php echo number_format($total_downloads); ?></span>
    </div>
    <div class="stats-icon stats-icon-purple">
    <i class="fas fa-download"></i>
    </div>
    </div>
    </div>

    <div class="video-card stats-card">
    <div class="stats-content">
    <div class="stats-info">
    <h3>Não Cadastrados</h3>
    <span class="stats-number"><?php echo count($unregisteredFiles); ?></span>
    </div>
    <div class="stats-icon stats-icon-orange">
    <i class="fas fa-folder-open"></i>
    </div>
    </div>
    </div>
    </div>

    <!-- Arquivos não cadastrados -->
    <?php if (!empty($unregisteredFiles)): ?>
    <div class="video-card">
    <h2><i class="fas fa-folder-open"></i> Arquivos Detectados (Não Cadastrados)</h2>
    <p>Estes arquivos estão na pasta <code>/uploads/glossarios/</code> mas ainda não foram registrados no sistema:</p>

    <div class="table-responsive">
    <table class="data-table">
    <thead>
    <tr>
    <th><i class="fas fa-file"></i> Arquivo</th>
    <th><i class="fas fa-weight"></i> Tamanho</th>
    <th><i class="fas fa-tag"></i> Tipo</th>
    <th><i class="fas fa-calendar"></i> Modificado</th>
    <th><i class="fas fa-cogs"></i> Ações</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($unregisteredFiles as $file): ?>
    <tr>
    <td>
    <div class="project-info">
    <span class="text-primary"><?php echo htmlspecialchars($file['name']); ?></span>
    <span class="project-client">Arquivo detectado automaticamente</span>
    </div>
    </td>
    <td><?php echo $file['size'] > 0 ? number_format($file['size'] / 1024, 1) . ' KB' : '-'; ?></td>
    <td>
    <span class="status-badge status-info">
    <?php echo htmlspecialchars($file['type']); ?>
    </span>
    </td>
    <td><?php echo $file['modified'] > 0 ? date('d/m/Y H:i', $file['modified']) : '-'; ?></td>
    <td>
    <a href="glossary/upload_form.php?file_existing=<?php echo urlencode($file['name']); ?>"
       class="cta-btn" title="Cadastrar metadados">
    <i class="fas fa-edit"></i> Cadastrar
    </a>
    </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    </div>
    <?php endif; ?>

    <div class="video-card">
    <h2><i class="fas fa-plus-circle"></i> Adicionar Novo Glossário</h2>

    <form method="POST" style="width: 100%;">
    <input type="hidden" name="action" value="add_glossary">

    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.5rem; margin-bottom: 1.5rem;">
    <div style="display: flex; flex-direction: column; gap: 0.5rem; grid-column: span 2;">
    <label for="title" style="font-weight: 600; font-size: 0.95rem; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
    <i class="fas fa-heading" style="color: #FFD700; font-size: 0.9rem;"></i> Título do Glossário *
    </label>
    <input type="text" id="title" name="title" required
           style="width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2); background: rgba(255, 255, 255, 0.05); color: #fff; font-size: 0.95rem; font-family: 'Inter', sans-serif; transition: all 0.3s ease;"
           onfocus="this.style.borderColor='#7B61FF'; this.style.background='rgba(123, 97, 255, 0.1)'; this.style.boxShadow='0 0 0 3px rgba(123, 97, 255, 0.2)';"
           onblur="this.style.borderColor='rgba(255, 255, 255, 0.2)'; this.style.background='rgba(255, 255, 255, 0.05)'; this.style.boxShadow='none';">
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
    <label for="category" style="font-weight: 600; font-size: 0.95rem; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
    <i class="fas fa-tags" style="color: #FFD700; font-size: 0.9rem;"></i> Categoria *
    </label>
    <select id="category" name="category" required
            style="width: 100%; padding: 0.75rem 1rem; padding-right: 2.5rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2); background: rgba(255, 255, 255, 0.05) url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath fill=%27%23FFD700%27 d=%27M6 9L1 4h10z%27/%3E%3C/svg%3E') no-repeat right 1rem center; color: #fff; font-size: 0.95rem; font-family: 'Inter', sans-serif; cursor: pointer; appearance: none; transition: all 0.3s ease;"
            onfocus="this.style.borderColor='#7B61FF'; this.style.background='rgba(123, 97, 255, 0.1) url(data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath fill=%27%23FFD700%27 d=%27M6 9L1 4h10z%27/%3E%3C/svg%3E) no-repeat right 1rem center'; this.style.boxShadow='0 0 0 3px rgba(123, 97, 255, 0.2)';"
            onblur="this.style.borderColor='rgba(255, 255, 255, 0.2)'; this.style.background='rgba(255, 255, 255, 0.05) url(data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath fill=%27%23FFD700%27 d=%27M6 9L1 4h10z%27/%3E%3C/svg%3E) no-repeat right 1rem center'; this.style.boxShadow='none';">
    <option value="">Selecione uma categoria</option>
    <option value="Jurídico">Jurídico</option>
    <option value="Médico">Médico</option>
    <option value="Técnico">Técnico</option>
    <option value="Financeiro">Financeiro</option>
    <option value="Marketing">Marketing</option>
    <option value="Acadêmico">Acadêmico</option>
    <option value="Geral">Geral</option>
    </select>
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
    <label for="file_type" style="font-weight: 600; font-size: 0.95rem; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
    <i class="fas fa-file" style="color: #FFD700; font-size: 0.9rem;"></i> Tipo de Arquivo *
    </label>
    <select id="file_type" name="file_type" required
            style="width: 100%; padding: 0.75rem 1rem; padding-right: 2.5rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2); background: rgba(255, 255, 255, 0.05) url('data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath fill=%27%23FFD700%27 d=%27M6 9L1 4h10z%27/%3E%3C/svg%3E') no-repeat right 1rem center; color: #fff; font-size: 0.95rem; font-family: 'Inter', sans-serif; cursor: pointer; appearance: none; transition: all 0.3s ease;"
            onfocus="this.style.borderColor='#7B61FF'; this.style.background='rgba(123, 97, 255, 0.1) url(data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath fill=%27%23FFD700%27 d=%27M6 9L1 4h10z%27/%3E%3C/svg%3E) no-repeat right 1rem center'; this.style.boxShadow='0 0 0 3px rgba(123, 97, 255, 0.2)';"
            onblur="this.style.borderColor='rgba(255, 255, 255, 0.2)'; this.style.background='rgba(255, 255, 255, 0.05) url(data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%2712%27 height=%2712%27 viewBox=%270 0 12 12%27%3E%3Cpath fill=%27%23FFD700%27 d=%27M6 9L1 4h10z%27/%3E%3C/svg%3E) no-repeat right 1rem center'; this.style.boxShadow='none';">
    <option value="">Selecione o tipo</option>
    <option value="PDF">PDF</option>
    <option value="XLSX">Excel (XLSX)</option>
    <option value="CSV">CSV</option>
    <option value="TXT">Texto (TXT)</option>
    </select>
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.5rem;">
    <label for="download_url" style="font-weight: 600; font-size: 0.95rem; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
    <i class="fas fa-link" style="color: #FFD700; font-size: 0.9rem;"></i> URL de Download
    </label>
    <input type="url" id="download_url" name="download_url" placeholder="https://exemplo.com/arquivo.pdf"
           style="width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2); background: rgba(255, 255, 255, 0.05); color: #fff; font-size: 0.95rem; font-family: 'Inter', sans-serif; transition: all 0.3s ease;"
           onfocus="this.style.borderColor='#7B61FF'; this.style.background='rgba(123, 97, 255, 0.1)'; this.style.boxShadow='0 0 0 3px rgba(123, 97, 255, 0.2)';"
           onblur="this.style.borderColor='rgba(255, 255, 255, 0.2)'; this.style.background='rgba(255, 255, 255, 0.05)'; this.style.boxShadow='none';">
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.5rem; grid-column: span 2;">
    <label for="description" style="font-weight: 600; font-size: 0.95rem; color: #fff; display: flex; align-items: center; gap: 0.5rem;">
    <i class="fas fa-file-alt" style="color: #FFD700; font-size: 0.9rem;"></i> Descrição
    </label>
    <textarea id="description" name="description" rows="4"
              style="width: 100%; padding: 0.75rem 1rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.2); background: rgba(255, 255, 255, 0.05); color: #fff; font-size: 0.95rem; font-family: 'Inter', sans-serif; resize: vertical; min-height: 100px; line-height: 1.6; transition: all 0.3s ease;"
              onfocus="this.style.borderColor='#7B61FF'; this.style.background='rgba(123, 97, 255, 0.1)'; this.style.boxShadow='0 0 0 3px rgba(123, 97, 255, 0.2)';"
              onblur="this.style.borderColor='rgba(255, 255, 255, 0.2)'; this.style.background='rgba(255, 255, 255, 0.05)'; this.style.boxShadow='none';"></textarea>
    </div>
    </div>

    <div style="display: flex; gap: 1rem; justify-content: flex-start; margin-top: 1.5rem;">
    <button type="submit" 
            style="padding: 0.85rem 2rem; border-radius: 8px; border: none; background: linear-gradient(135deg, #7B61FF, #483D8B); color: #fff; font-weight: 600; font-size: 0.95rem; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 0.5rem;"
            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 12px rgba(123, 97, 255, 0.4)';"
            onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
    <i class="fas fa-plus"></i> Adicionar Glossário
    </button>
    </div>
    </form>
    </div>

    <div class="video-card">
    <div class="card-header">
    <h2><i class="fas fa-list"></i> Lista de Glossários</h2>

    <div class="search-filters">
    <form method="GET" class="search-form">
    <div class="search-group">
    <input type="text" name="search" placeholder="Buscar glossários..."
    value="<?php echo htmlspecialchars($search); ?>">
    <select name="category">
    <option value="">Todas as categorias</option>
    <?php foreach ($categories as $cat): ?>
    <option value="<?php echo htmlspecialchars($cat); ?>"
    <?php echo $category_filter == $cat ? 'selected' : ''; ?>>
    <?php echo htmlspecialchars($cat); ?>
    </option>
    <?php endforeach; ?>
    </select>
    <button type="submit" class="page-btn">
    <i class="fas fa-search"></i>
    </button>
    <?php if ($search || $category_filter): ?>
    <a href="glossarios.php" class="page-btn">
    <i class="fas fa-times"></i>
    </a>
    <?php endif; ?>
    </div>
    </form>
    </div>
    </div>

    <?php if (empty($glossaries)): ?>
    <div class="alert-warning">
    <i class="fas fa-info-circle"></i>
    <?php echo ($search || $category_filter) ? 'Nenhum glossário encontrado com os critérios de busca.' : 'Nenhum glossário cadastrado ainda.'; ?>
    </div>
    <?php else: ?>
    <div class="table-responsive">
    <table class="data-table">
    <thead>
    <tr>
    <th><i class="fas fa-book"></i> Glossário</th>
    <th><i class="fas fa-tags"></i> Categoria</th>
    <th><i class="fas fa-file"></i> Tipo</th>
    <th><i class="fas fa-download"></i> Downloads</th>
    <th><i class="fas fa-calendar"></i> Criado</th>
    <th><i class="fas fa-toggle-on"></i> Status</th>
    <th><i class="fas fa-cogs"></i> Ações</th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($glossaries as $glossary): ?>
    <tr>
    <td>
    <div class="project-info">
    <span class="text-primary"><?php echo htmlspecialchars($glossary['title']); ?></span>
    <span class="project-client"><?php echo htmlspecialchars(substr($glossary['description'] ?? '', 0, 100)) . (strlen($glossary['description'] ?? '') > 100 ? '...' : ''); ?></span>
    </div>
    </td>
    <td><?php echo htmlspecialchars($glossary['category'] ?? '-'); ?></td>
    <td>
    <span class="status-badge status-info">
    <?php echo htmlspecialchars($glossary['file_type'] ?? '-'); ?>
    </span>
    </td>
    <td><?php echo number_format($glossary['download_count'] ?? 0); ?></td>
    <td><?php echo date('d/m/Y', strtotime($glossary['created_at'])); ?></td>
    <td>
    <span class="status-badge status-<?php echo $glossary['is_active'] ? 'completed' : 'cancelled'; ?>">
    <?php echo $glossary['is_active'] ? 'Ativo' : 'Inativo'; ?>
    </span>
    </td>
    <td>
    <div class="action-buttons">
    <form method="POST" style="display: inline;">
    <input type="hidden" name="action" value="toggle_glossary">
    <input type="hidden" name="glossary_id" value="<?php echo htmlspecialchars($glossary['id']); ?>">
    <button type="submit" class="page-btn" title="Toggle Status">
    <i class="fas fa-toggle-<?php echo $glossary['is_active'] ? 'on' : 'off'; ?>"></i>
    </button>
    </form>

    <?php if ($glossary['download_url']): ?>
    <a href="<?php echo htmlspecialchars($glossary['download_url']); ?>"
    class="page-btn" title="Download" target="_blank">
    <i class="fas fa-download"></i>
    </a>
    <?php endif; ?>

    <form method="POST" style="display: inline;"
    onsubmit="return confirm('Tem certeza que deseja excluir este glossário?')">
    <input type="hidden" name="action" value="delete_glossary">
    <input type="hidden" name="glossary_id" value="<?php echo htmlspecialchars($glossary['id']); ?>">
    <button type="submit" class="page-btn btn-danger" title="Excluir">
    <i class="fas fa-trash"></i>
    </button>
    </form>
    </div>
    </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>
    </div>

    <!-- Upload System -->
    <div class="video-card">
    <h2><i class="fas fa-upload"></i> Sistema de Upload</h2>

    <div class="dashboard-sections">
    <div>
    <h3><i class="fas fa-cloud-upload-alt"></i> <strong>Upload de Arquivos</strong></h3>
    <p>• Formatos aceitos: PDF, XLSX, CSV, TXT</p>
    <p>• Tamanho máximo: 10MB por arquivo</p>
    <p>• Organização por categorias</p>

    <div style="margin-top: 20px;">
    <a href="glossary/upload_form.php" class="cta-btn">
    <i class="fas fa-upload"></i> Fazer Upload
    </a>
    </div>
    </div>

    <div>
    <h3><i class="fas fa-chart-bar"></i> <strong>Relatórios</strong></h3>
    <p>• Downloads por glossário</p>
    <p>• Glossários mais populares</p>
    <p>• Estatísticas de uso</p>

    <div style="margin-top: 20px;">
    <a href="glossary/download_csv.php" class="page-btn">
    <i class="fas fa-table"></i> Exportar CSV
    </a>
    </div>
    </div>
    </div>
    </div>
</div>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>