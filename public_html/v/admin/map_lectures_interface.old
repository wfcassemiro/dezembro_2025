<?php
session_start();
require_once __DIR__ . '/../config/database.php';

// Verificação de autenticação
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /login.php');
    exit;
}

// Carregar dados
$hotmart_lectures = require __DIR__ . '/data_hotmart.php';
$system_lectures_ids = require __DIR__ . '/data_lectures.php';

// Buscar detalhes completos das palestras do sistema (TODOS os dados disponíveis)
$system_lectures = [];
try {
    $ids = array_column($system_lectures_ids, 'id');
    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $stmt = $pdo->prepare("
        SELECT id, title, speaker, duration_minutes, created_at, category, tags, level, 
               description, is_featured, language, hotmart_page_id, hotmart_lesson_id, 
               hotmart_module_id
        FROM lectures 
        WHERE id IN ($placeholders)
    ");
    $stmt->execute($ids);
    $system_lectures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback para dados básicos se houver erro
    $system_lectures = $system_lectures_ids;
}

// Ordenar alfabeticamente
sort($hotmart_lectures);
usort($system_lectures, function($a, $b) {
    return strcasecmp($a['title'], $b['title']);
});

// Buscar mapeamentos existentes
$existing_mappings = [];
$mapped_hotmart_titles = [];
$mapped_lecture_ids = [];
$hotmart_with_data = []; // Array para armazenar dados extras das palestras Hotmart
try {
    $stmt = $pdo->query("SELECT id, hotmart_title, lecture_id, lecture_title, hotmart_page_id FROM hotmart_lecture_mapping ORDER BY hotmart_title");
    $existing_mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Criar arrays de títulos/IDs já mapeados para fácil verificação
    foreach ($existing_mappings as $mapping) {
        $mapped_hotmart_titles[] = $mapping['hotmart_title'];
        $mapped_lecture_ids[] = $mapping['lecture_id'];
    }
    
    // Buscar dados completos das palestras JÁ MAPEADAS para enriquecer a coluna Hotmart
    if (!empty($mapped_lecture_ids)) {
        $placeholders = str_repeat('?,', count($mapped_lecture_ids) - 1) . '?';
        $stmt = $pdo->prepare("
            SELECT l.id, l.title, l.speaker, l.duration_minutes, l.created_at, l.category, l.tags, l.level,
                   l.hotmart_page_id, l.hotmart_lesson_id, l.hotmart_module_id
            FROM lectures l
            WHERE l.id IN ($placeholders)
        ");
        $stmt->execute($mapped_lecture_ids);
        $lectures_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Criar um mapa de lecture_id => dados para fácil acesso
        $lecture_data_map = [];
        foreach ($lectures_data as $lec) {
            $lecture_data_map[$lec['id']] = $lec;
        }
        
        // Associar dados das lectures com os títulos Hotmart
        foreach ($existing_mappings as $mapping) {
            if (isset($lecture_data_map[$mapping['lecture_id']])) {
                $hotmart_with_data[$mapping['hotmart_title']] = $lecture_data_map[$mapping['lecture_id']];
            }
        }
    }
    
    // Buscar dados da cache de lessons Hotmart
    $stmt = $pdo->query("SELECT page_id, page_name FROM hotmart_lessons_cache");
    $hotmart_cache = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Criar índice por nome para busca rápida
    $hotmart_cache_by_name = [];
    foreach ($hotmart_cache as $cached) {
        if ($cached['page_name']) {
            $hotmart_cache_by_name[$cached['page_name']] = $cached['page_id'];
        }
    }
    
} catch (PDOException $e) {
    $error_message = "Erro ao buscar mapeamentos: " . $e->getMessage();
}

// Contar palestras disponíveis (não mapeadas)
$available_hotmart = count($hotmart_lectures) - count($mapped_hotmart_titles);
$available_lectures = count($system_lectures) - count($mapped_lecture_ids);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mapeamento de Palestras - Hotmart & Sistema</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .main-container { max-width: 1800px; margin: 20px auto; padding: 0 15px; }
        .header-section { background: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .mapping-container { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; }
        .lecture-column { background: white; border-radius: 10px; padding: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); height: 70vh; overflow-y: auto; }
        .lecture-item { padding: 10px; margin-bottom: 8px; border: 1px solid #dee2e6; border-radius: 5px; cursor: pointer; transition: all 0.3s; font-size: 0.9em; }
        .lecture-item:hover { background-color: #e3f2fd; border-color: #2196F3; }
        .lecture-item.selected { background-color: #bbdefb; border-color: #1976D2; border-width: 2px; }
        .lecture-item.mapped { background-color: #c8e6c9; border-color: #4caf50; cursor: not-allowed; opacity: 0.7; }
        .lecture-item.mapped:hover { background-color: #c8e6c9; border-color: #4caf50; }
        .lecture-item.mapped::after { content: ' ✓'; color: #2e7d32; font-weight: bold; }
        .lecture-metadata { font-size: 0.72em; color: #666; margin-top: 6px; display: flex; gap: 8px; flex-wrap: wrap; line-height: 1.4; }
        .lecture-metadata .meta-item { display: inline-flex; align-items: center; gap: 3px; background: #f5f5f5; padding: 2px 6px; border-radius: 3px; }
        .lecture-metadata .meta-item i { font-size: 0.9em; color: #888; }
        .lecture-item > div:first-child { font-weight: 500; }
        .filter-buttons { display: flex; gap: 10px; margin-bottom: 10px; }
        .filter-buttons .btn-filter { padding: 5px 12px; font-size: 0.85em; }
        .search-box { margin-bottom: 15px; }
        .stats { display: flex; gap: 20px; margin-bottom: 15px; }
        .stat-box { flex: 1; padding: 15px; border-radius: 8px; text-align: center; }
        .stat-box.hotmart { background-color: #e3f2fd; }
        .stat-box.system { background-color: #f3e5f5; }
        .stat-box.mapped { background-color: #e8f5e9; }
        .mapping-item { background: #fff3cd; padding: 10px; margin-bottom: 8px; border-radius: 5px; border-left: 4px solid #ffc107; display: flex; justify-content: space-between; align-items: start; }
        .mapping-content { flex: 1; }
        .mapping-title { font-weight: 600; color: #333; margin-bottom: 5px; font-size: 0.9em; }
        .mapping-subtitle { font-size: 0.85em; color: #666; }
        .btn-delete-mapping { padding: 2px 8px; font-size: 0.8em; }
        .column-title { font-weight: bold; color: #1976D2; margin-bottom: 15px; font-size: 1.1em; border-bottom: 2px solid #1976D2; padding-bottom: 8px; }
        .btn-map { position: sticky; top: 0; z-index: 10; margin-bottom: 15px; }
        .alert-fixed { position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px; }
    </style>
</head>
<body>
    <div class="main-container">
        <!-- Header -->
        <div class="header-section">
            <h2><i class="fas fa-link"></i> Mapeamento de Palestras Hotmart ↔ Sistema</h2>
            <p class="mb-0 text-muted">Selecione uma palestra da Hotmart e uma do Sistema, depois clique em "Associar" para criar o mapeamento.</p>
        </div>

        <!-- Stats -->
        <div class="stats">
            <div class="stat-box hotmart">
                <h4><span id="availableHotmart"><?php echo $available_hotmart; ?></span> / <?php echo count($hotmart_lectures); ?></h4>
                <small>Hotmart Disponíveis</small>
            </div>
            <div class="stat-box system">
                <h4><span id="availableSystem"><?php echo $available_lectures; ?></span> / <?php echo count($system_lectures); ?></h4>
                <small>Sistema Disponíveis</small>
            </div>
            <div class="stat-box mapped">
                <h4 id="mappedCount"><?php echo count($existing_mappings); ?></h4>
                <small>Associações Criadas</small>
            </div>
        </div>

        <!-- Mapping Interface -->
        <div class="mapping-container">
            <!-- Palestras Hotmart -->
            <div class="lecture-column">
                <div class="column-title">
                    <i class="fas fa-store"></i> Palestras Hotmart
                </div>
                <div class="filter-buttons">
                    <button class="btn btn-sm btn-outline-primary btn-filter" id="filterHotmart">
                        <i class="fas fa-filter"></i> Mostrar apenas disponíveis
                    </button>
                    <button class="btn btn-sm btn-outline-secondary btn-filter" id="showAllHotmart" style="display:none;">
                        <i class="fas fa-list"></i> Mostrar todas
                    </button>
                </div>
                <input type="text" class="form-control search-box" id="searchHotmart" placeholder="🔍 Buscar palestra Hotmart...">
                
                <!-- Campo para adicionar palestra manualmente -->
                <div class="manual-add-section" style="margin: 10px 0; padding: 10px; background: #e3f2fd; border-radius: 5px; border: 2px dashed #2196f3;">
                    <div style="font-size: 0.85em; font-weight: 600; color: #1976d2; margin-bottom: 5px;">
                        <i class="fas fa-plus-circle"></i> Adicionar Palestra Hotmart Manualmente
                    </div>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control" id="manualHotmartTitle" placeholder="Digite o título da palestra Hotmart...">
                        <button class="btn btn-primary" type="button" id="btnAddManualHotmart">
                            <i class="fas fa-plus"></i> Adicionar
                        </button>
                    </div>
                </div>
                
                <div id="hotmart-list">
                    <?php foreach ($hotmart_lectures as $index => $lecture): 
                        $is_mapped = in_array($lecture, $mapped_hotmart_titles);
                        $extra_data = isset($hotmart_with_data[$lecture]) ? $hotmart_with_data[$lecture] : null;
                        $page_id = isset($hotmart_cache_by_name[$lecture]) ? $hotmart_cache_by_name[$lecture] : null;
                    ?>
                        <div class="lecture-item <?php echo $is_mapped ? 'mapped' : ''; ?>" 
                             data-title="<?php echo htmlspecialchars($lecture); ?>" 
                             data-index="<?php echo $index; ?>"
                             data-mapped="<?php echo $is_mapped ? '1' : '0'; ?>">
                            <div><?php echo htmlspecialchars($lecture); ?></div>
                            
                            <?php if ($extra_data || $page_id): ?>
                                <div class="lecture-metadata">
                                    <?php if ($extra_data && isset($extra_data['duration_minutes'])): ?>
                                        <span class="meta-item">
                                            <i class="fas fa-clock"></i> <?php echo $extra_data['duration_minutes']; ?> min
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($extra_data && isset($extra_data['speaker'])): ?>
                                        <span class="meta-item">
                                            <i class="fas fa-user"></i> <?php echo htmlspecialchars($extra_data['speaker']); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($extra_data && isset($extra_data['created_at'])): ?>
                                        <span class="meta-item">
                                            <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($extra_data['created_at'])); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($extra_data && !empty($extra_data['category'])): ?>
                                        <span class="meta-item">
                                            <i class="fas fa-tag"></i> <?php echo htmlspecialchars($extra_data['category']); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($extra_data && !empty($extra_data['level'])): ?>
                                        <span class="meta-item">
                                            <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($extra_data['level']); ?>
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($extra_data && !empty($extra_data['hotmart_page_id'])): ?>
                                        <span class="meta-item" title="Hotmart Page ID">
                                            <i class="fas fa-link"></i> H:<?php echo htmlspecialchars(substr($extra_data['hotmart_page_id'], 0, 6)); ?>
                                        </span>
                                    <?php elseif ($page_id): ?>
                                        <span class="meta-item" title="ID da página Hotmart (cache)">
                                            <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars(substr($page_id, 0, 8)); ?>...
                                        </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($extra_data && !empty($extra_data['hotmart_lesson_id'])): ?>
                                        <span class="meta-item" title="Hotmart Lesson ID">
                                            <i class="fas fa-book"></i> L:<?php echo htmlspecialchars(substr($extra_data['hotmart_lesson_id'], 0, 6)); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Palestras do Sistema -->
            <div class="lecture-column">
                <div class="column-title">
                    <i class="fas fa-database"></i> Palestras do Sistema
                </div>
                <div class="filter-buttons">
                    <button class="btn btn-sm btn-outline-primary btn-filter" id="filterSystem">
                        <i class="fas fa-filter"></i> Mostrar apenas disponíveis
                    </button>
                    <button class="btn btn-sm btn-outline-secondary btn-filter" id="showAllSystem" style="display:none;">
                        <i class="fas fa-list"></i> Mostrar todas
                    </button>
                </div>
                <input type="text" class="form-control search-box" id="searchSystem" placeholder="🔍 Buscar palestra do sistema...">
                <div id="system-list">
                    <?php foreach ($system_lectures as $lecture): 
                        $is_mapped = in_array($lecture['id'], $mapped_lecture_ids);
                    ?>
                        <div class="lecture-item <?php echo $is_mapped ? 'mapped' : ''; ?>" 
                             data-id="<?php echo htmlspecialchars($lecture['id']); ?>" 
                             data-title="<?php echo htmlspecialchars($lecture['title']); ?>"
                             data-mapped="<?php echo $is_mapped ? '1' : '0'; ?>">
                            <div><?php echo htmlspecialchars($lecture['title']); ?></div>
                            
                            <div class="lecture-metadata">
                                <?php if (!empty($lecture['duration_minutes'])): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-clock"></i> <?php echo $lecture['duration_minutes']; ?> min
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($lecture['speaker'])): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($lecture['speaker']); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($lecture['created_at'])): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-calendar"></i> <?php echo date('d/m/Y', strtotime($lecture['created_at'])); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($lecture['category'])): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-tag"></i> <?php echo htmlspecialchars($lecture['category']); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($lecture['level'])): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($lecture['level']); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($lecture['language'])): ?>
                                    <span class="meta-item">
                                        <i class="fas fa-globe"></i> <?php echo htmlspecialchars($lecture['language']); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($lecture['is_featured']) && $lecture['is_featured'] == 1): ?>
                                    <span class="meta-item" title="Palestra em destaque">
                                        <i class="fas fa-star" style="color: #ffc107;"></i> Destaque
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($lecture['hotmart_page_id'])): ?>
                                    <span class="meta-item" title="Hotmart Page ID">
                                        <i class="fas fa-link"></i> H:<?php echo htmlspecialchars(substr($lecture['hotmart_page_id'], 0, 6)); ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (!empty($lecture['hotmart_lesson_id'])): ?>
                                    <span class="meta-item" title="Hotmart Lesson ID">
                                        <i class="fas fa-book"></i> L:<?php echo htmlspecialchars(substr($lecture['hotmart_lesson_id'], 0, 6)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Associações Criadas -->
            <div class="lecture-column">
                <div class="column-title">
                    <i class="fas fa-check-circle"></i> Associações Criadas
                </div>
                <button class="btn btn-success btn-map w-100 mb-2" id="btnAssociate" disabled>
                    <i class="fas fa-link"></i> Associar Selecionadas
                </button>
                <button class="btn btn-warning btn-map w-100 mb-2" id="btnQuickAssociate">
                    <i class="fas fa-bolt"></i> Associação Manual Rápida
                </button>
                <a href="verificar_certificados_mapeados.php" class="btn btn-info btn-map w-100 mb-2" style="text-decoration: none; display: block; text-align: center;">
                    <i class="fas fa-certificate"></i> Verificar Certificados
                </a>
                <div id="mappings-list">
                    <?php if (empty($existing_mappings)): ?>
                        <p class="text-muted text-center mt-3">Nenhuma associação criada ainda.</p>
                    <?php else: ?>
                        <?php foreach ($existing_mappings as $mapping): ?>
                            <div class="mapping-item" data-mapping-id="<?php echo $mapping['id']; ?>">
                                <div class="mapping-content">
                                    <div class="mapping-title">
                                        <i class="fas fa-store text-primary"></i> <?php echo htmlspecialchars($mapping['hotmart_title']); ?>
                                    </div>
                                    <div class="mapping-subtitle">
                                        <i class="fas fa-arrow-down"></i> <?php echo htmlspecialchars($mapping['lecture_title']); ?>
                                    </div>
                                </div>
                                <button class="btn btn-danger btn-sm btn-delete-mapping" 
                                        data-mapping-id="<?php echo $mapping['id']; ?>"
                                        title="Deletar associação">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Alert Container -->
    <div id="alertContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedHotmart = null;
        let selectedSystem = null;

        // Seleção de palestras
        document.querySelectorAll('#hotmart-list .lecture-item').forEach(item => {
            item.addEventListener('click', function() {
                // Não permite selecionar palestras já mapeadas
                if (this.dataset.mapped === '1') {
                    showAlert('Esta palestra da Hotmart já foi associada!', 'warning');
                    return;
                }
                
                document.querySelectorAll('#hotmart-list .lecture-item').forEach(i => i.classList.remove('selected'));
                this.classList.add('selected');
                selectedHotmart = {
                    title: this.dataset.title
                };
                updateAssociateButton();
            });
        });

        document.querySelectorAll('#system-list .lecture-item').forEach(item => {
            item.addEventListener('click', function() {
                // Não permite selecionar palestras já mapeadas
                if (this.dataset.mapped === '1') {
                    showAlert('Esta palestra do sistema já foi associada!', 'warning');
                    return;
                }
                
                document.querySelectorAll('#system-list .lecture-item').forEach(i => i.classList.remove('selected'));
                this.classList.add('selected');
                selectedSystem = {
                    id: this.dataset.id,
                    title: this.dataset.title
                };
                updateAssociateButton();
            });
        });

        function updateAssociateButton() {
            const btn = document.getElementById('btnAssociate');
            if (selectedHotmart && selectedSystem) {
                btn.disabled = false;
            } else {
                btn.disabled = true;
            }
        }

        // Busca
        document.getElementById('searchHotmart').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('#hotmart-list .lecture-item').forEach(item => {
                const title = item.dataset.title.toLowerCase();
                const matchesSearch = title.includes(searchTerm);
                const isFiltered = document.getElementById('filterHotmart').style.display === 'none';
                
                if (isFiltered) {
                    // Se está filtrando, só mostra se for disponível E combinar busca
                    item.style.display = (item.dataset.mapped === '0' && matchesSearch) ? 'block' : 'none';
                } else {
                    // Se não está filtrando, mostra tudo que combinar busca
                    item.style.display = matchesSearch ? 'block' : 'none';
                }
            });
        });

        document.getElementById('searchSystem').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            document.querySelectorAll('#system-list .lecture-item').forEach(item => {
                const title = item.dataset.title.toLowerCase();
                const matchesSearch = title.includes(searchTerm);
                const isFiltered = document.getElementById('filterSystem').style.display === 'none';
                
                if (isFiltered) {
                    // Se está filtrando, só mostra se for disponível E combinar busca
                    item.style.display = (item.dataset.mapped === '0' && matchesSearch) ? 'block' : 'none';
                } else {
                    // Se não está filtrando, mostra tudo que combinar busca
                    item.style.display = matchesSearch ? 'block' : 'none';
                }
            });
        });

        // Filtro: Mostrar apenas disponíveis - Hotmart
        document.getElementById('filterHotmart').addEventListener('click', function() {
            document.querySelectorAll('#hotmart-list .lecture-item').forEach(item => {
                item.style.display = item.dataset.mapped === '0' ? 'block' : 'none';
            });
            this.style.display = 'none';
            document.getElementById('showAllHotmart').style.display = 'inline-block';
            document.getElementById('searchHotmart').value = '';
            showAlert('Mostrando apenas palestras Hotmart disponíveis (' + 
                document.querySelectorAll('#hotmart-list .lecture-item:not(.mapped)').length + ')', 'info');
        });

        document.getElementById('showAllHotmart').addEventListener('click', function() {
            document.querySelectorAll('#hotmart-list .lecture-item').forEach(item => {
                item.style.display = 'block';
            });
            this.style.display = 'none';
            document.getElementById('filterHotmart').style.display = 'inline-block';
        });

        // Filtro: Mostrar apenas disponíveis - Sistema
        document.getElementById('filterSystem').addEventListener('click', function() {
            document.querySelectorAll('#system-list .lecture-item').forEach(item => {
                item.style.display = item.dataset.mapped === '0' ? 'block' : 'none';
            });
            this.style.display = 'none';
            document.getElementById('showAllSystem').style.display = 'inline-block';
            document.getElementById('searchSystem').value = '';
            showAlert('Mostrando apenas palestras do Sistema disponíveis (' + 
                document.querySelectorAll('#system-list .lecture-item:not(.mapped)').length + ')', 'info');
        });

        document.getElementById('showAllSystem').addEventListener('click', function() {
            document.querySelectorAll('#system-list .lecture-item').forEach(item => {
                item.style.display = 'block';
            });
            this.style.display = 'none';
            document.getElementById('filterSystem').style.display = 'inline-block';
        });

        // Associar
        document.getElementById('btnAssociate').addEventListener('click', function() {
            if (!selectedHotmart || !selectedSystem) return;

            const data = {
                hotmart_title: selectedHotmart.title,
                lecture_id: selectedSystem.id,
                lecture_title: selectedSystem.title
            };

            fetch('save_mapping_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showAlert('Associação criada com sucesso!', 'success');
                    addMappingToList(result.mapping_id, data);
                    markAsAssociated(data.hotmart_title, data.lecture_id);
                    clearSelections();
                    updateMappedCount();
                    updateAvailableCounts();
                } else {
                    showAlert('Erro: ' + result.message, 'danger');
                }
            })
            .catch(error => {
                showAlert('Erro ao salvar associação: ' + error, 'danger');
            });
        });

        // Deletar associação
        document.addEventListener('click', function(e) {
            if (e.target.closest('.btn-delete-mapping')) {
                const btn = e.target.closest('.btn-delete-mapping');
                const mappingId = btn.dataset.mappingId;
                
                if (confirm('Tem certeza que deseja deletar esta associação?')) {
                    fetch('delete_mapping_ajax.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: mappingId })
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            showAlert('Associação deletada com sucesso!', 'success');
                            const mappingElement = document.querySelector(`[data-mapping-id="${mappingId}"]`);
                            
                            // Pegar os dados antes de remover
                            const hotmartTitle = mappingElement.querySelector('.mapping-title').textContent.replace('🏪 ', '').trim();
                            const lectureTitle = mappingElement.querySelector('.mapping-subtitle').textContent.replace('⬇️ ', '').trim();
                            
                            // Remover da lista
                            mappingElement.remove();
                            
                            // Desmarcar as palestras como disponíveis novamente
                            unmarkAsAssociated(hotmartTitle, lectureTitle);
                            
                            updateMappedCount();
                            updateAvailableCounts();
                        } else {
                            showAlert('Erro ao deletar: ' + result.message, 'danger');
                        }
                    })
                    .catch(error => {
                        showAlert('Erro ao deletar associação: ' + error, 'danger');
                    });
                }
            }
        });

        function addMappingToList(mappingId, data) {
            const mappingsList = document.getElementById('mappings-list');
            
            // Remove mensagem "Nenhuma associação" se existir
            const emptyMessage = mappingsList.querySelector('.text-muted');
            if (emptyMessage) emptyMessage.remove();
            
            const mappingItem = document.createElement('div');
            mappingItem.className = 'mapping-item';
            mappingItem.dataset.mappingId = mappingId;
            mappingItem.innerHTML = `
                <div class="mapping-content">
                    <div class="mapping-title">
                        <i class="fas fa-store text-primary"></i> ${escapeHtml(data.hotmart_title)}
                    </div>
                    <div class="mapping-subtitle">
                        <i class="fas fa-arrow-down"></i> ${escapeHtml(data.lecture_title)}
                    </div>
                </div>
                <button class="btn btn-danger btn-sm btn-delete-mapping" 
                        data-mapping-id="${mappingId}"
                        title="Deletar associação">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            mappingsList.insertBefore(mappingItem, mappingsList.firstChild);
        }

        function clearSelections() {
            document.querySelectorAll('.lecture-item.selected').forEach(item => item.classList.remove('selected'));
            selectedHotmart = null;
            selectedSystem = null;
            updateAssociateButton();
        }

        function updateMappedCount() {
            const count = document.querySelectorAll('#mappings-list .mapping-item').length;
            document.getElementById('mappedCount').textContent = count;
        }

        function updateAvailableCounts() {
            // Contar palestras não mapeadas
            const availableHotmart = document.querySelectorAll('#hotmart-list .lecture-item:not(.mapped)').length;
            const availableSystem = document.querySelectorAll('#system-list .lecture-item:not(.mapped)').length;
            
            document.getElementById('availableHotmart').textContent = availableHotmart;
            document.getElementById('availableSystem').textContent = availableSystem;
        }

        function markAsAssociated(hotmartTitle, lectureId) {
            // Marcar palestra Hotmart como mapeada
            document.querySelectorAll('#hotmart-list .lecture-item').forEach(item => {
                if (item.dataset.title === hotmartTitle) {
                    item.classList.add('mapped');
                    item.dataset.mapped = '1';
                }
            });
            
            // Marcar palestra do Sistema como mapeada
            document.querySelectorAll('#system-list .lecture-item').forEach(item => {
                if (item.dataset.id === lectureId) {
                    item.classList.add('mapped');
                    item.dataset.mapped = '1';
                }
            });
        }

        function unmarkAsAssociated(hotmartTitle, lectureTitle) {
            // Desmarcar palestra Hotmart
            document.querySelectorAll('#hotmart-list .lecture-item').forEach(item => {
                if (item.dataset.title.includes(hotmartTitle) || hotmartTitle.includes(item.dataset.title)) {
                    item.classList.remove('mapped');
                    item.dataset.mapped = '0';
                }
            });
            
            // Desmarcar palestra do Sistema
            document.querySelectorAll('#system-list .lecture-item').forEach(item => {
                if (item.dataset.title.includes(lectureTitle) || lectureTitle.includes(item.dataset.title)) {
                    item.classList.remove('mapped');
                    item.dataset.mapped = '0';
                }
            });
        }

        function showAlert(message, type) {
            const alertContainer = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.className = `alert alert-${type} alert-dismissible fade show alert-fixed`;
            alert.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            alertContainer.appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ============================================
        // FUNCIONALIDADE: ADICIONAR PALESTRA HOTMART MANUALMENTE
        // ============================================
        document.getElementById('btnAddManualHotmart').addEventListener('click', function() {
            const titleInput = document.getElementById('manualHotmartTitle');
            const title = titleInput.value.trim();
            
            if (!title) {
                alert('Por favor, digite o título da palestra Hotmart.');
                return;
            }
            
            // Verificar se já existe
            const existingItems = document.querySelectorAll('#hotmart-list .lecture-item');
            for (let item of existingItems) {
                if (item.dataset.title.toLowerCase() === title.toLowerCase()) {
                    alert('Esta palestra já existe na lista!');
                    return;
                }
            }
            
            // Criar novo item na lista
            const newItem = document.createElement('div');
            newItem.className = 'lecture-item';
            newItem.dataset.title = title;
            newItem.dataset.index = 'manual-' + Date.now();
            newItem.dataset.mapped = '0';
            newItem.dataset.manual = 'true'; // Marca como adicionado manualmente
            newItem.innerHTML = `
                <div>${escapeHtml(title)}</div>
                <div class="lecture-metadata">
                    <span class="meta-item" style="background: #ff9800; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.75em;">
                        <i class="fas fa-hand-pointer"></i> MANUAL
                    </span>
                </div>
            `;
            
            // Adicionar no topo da lista
            const hotmartList = document.getElementById('hotmart-list');
            hotmartList.insertBefore(newItem, hotmartList.firstChild);
            
            // Limpar campo
            titleInput.value = '';
            
            // Aplicar evento de clique
            newItem.addEventListener('click', function() {
                if (this.dataset.mapped === '1') {
                    alert('Esta palestra já foi associada!');
                    return;
                }
                
                document.querySelectorAll('#hotmart-list .lecture-item').forEach(item => {
                    item.classList.remove('selected');
                });
                this.classList.add('selected');
                selectedHotmart = {
                    title: this.dataset.title,
                    index: this.dataset.index
                };
                updateAssociateButton();
            });
            
            // Scroll para o item adicionado
            newItem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            
            // Feedback visual
            newItem.style.backgroundColor = '#fff9c4';
            setTimeout(() => {
                newItem.style.backgroundColor = '';
            }, 2000);
            
            showAlert('success', `Palestra "${title}" adicionada! Agora você pode selecioná-la e associar.`);
            
            // Atualizar contador
            updateAvailableCounts();
        });
        
        // ============================================
        // FUNCIONALIDADE: ASSOCIAÇÃO MANUAL RÁPIDA (MODAL)
        // ============================================
        document.getElementById('btnQuickAssociate').addEventListener('click', function() {
            document.getElementById('quickAssociateModal').style.display = 'block';
        });
        
        document.getElementById('closeQuickModal').addEventListener('click', function() {
            document.getElementById('quickAssociateModal').style.display = 'none';
        });
        
        document.getElementById('cancelQuickAssociate').addEventListener('click', function() {
            document.getElementById('quickAssociateModal').style.display = 'none';
        });
        
        // Fechar modal ao clicar fora
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('quickAssociateModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
        
        // Submit do formulário rápido
        document.getElementById('quickAssociateForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const hotmartTitle = document.getElementById('quickHotmartTitle').value.trim();
            const lectureId = document.getElementById('quickLectureSelect').value;
            
            if (!hotmartTitle) {
                alert('Por favor, digite o título da palestra Hotmart.');
                return;
            }
            
            if (!lectureId) {
                alert('Por favor, selecione uma palestra do sistema.');
                return;
            }
            
            // Buscar título da palestra selecionada
            const lectureSelect = document.getElementById('quickLectureSelect');
            const lectureTitle = lectureSelect.options[lectureSelect.selectedIndex].text;
            
            // Salvar associação
            fetch('save_mapping_ajax.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `hotmart_title=${encodeURIComponent(hotmartTitle)}&lecture_id=${encodeURIComponent(lectureId)}&lecture_title=${encodeURIComponent(lectureTitle)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', 'Associação criada com sucesso via modo rápido!');
                    
                    // Adicionar à lista de associações
                    addMappingToList(data.mapping_id, hotmartTitle, lectureId, lectureTitle);
                    
                    // Marcar como associadas (se existirem nas listas)
                    markAsAssociated(hotmartTitle, lectureId);
                    
                    // Limpar formulário
                    document.getElementById('quickHotmartTitle').value = '';
                    document.getElementById('quickLectureSelect').value = '';
                    
                    // Fechar modal
                    document.getElementById('quickAssociateModal').style.display = 'none';
                    
                    // Atualizar contadores
                    updateAvailableCounts();
                } else {
                    showAlert('danger', data.message || 'Erro ao criar associação');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('danger', 'Erro ao processar requisição');
            });
        });
    </script>
    
    <!-- Modal de Associação Rápida -->
    <div id="quickAssociateModal" style="display:none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
        <div style="background-color: #fefefe; margin: 5% auto; padding: 0; border: 1px solid #888; border-radius: 10px; width: 90%; max-width: 600px; box-shadow: 0 4px 6px rgba(0,0,0,0.3);">
            <!-- Header do Modal -->
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px 10px 0 0; position: relative;">
                <h3 style="margin: 0; font-size: 1.5em;">
                    <i class="fas fa-bolt"></i> Associação Manual Rápida
                </h3>
                <p style="margin: 5px 0 0 0; font-size: 0.9em; opacity: 0.9;">
                    Crie uma associação completa em uma única etapa
                </p>
                <button id="closeQuickModal" style="position: absolute; top: 15px; right: 15px; background: transparent; border: none; color: white; font-size: 1.5em; cursor: pointer; opacity: 0.8; transition: opacity 0.3s;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0.8'">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Corpo do Modal -->
            <div style="padding: 30px;">
                <form id="quickAssociateForm">
                    <!-- Campo Hotmart -->
                    <div style="margin-bottom: 20px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                            <i class="fas fa-store" style="color: #e91e63;"></i> Palestra Hotmart
                        </label>
                        <input type="text" 
                               id="quickHotmartTitle" 
                               class="form-control" 
                               placeholder="Digite o título completo da palestra Hotmart..."
                               required
                               style="padding: 12px; font-size: 1em; border: 2px solid #e0e0e0; border-radius: 5px; transition: border-color 0.3s;"
                               onfocus="this.style.borderColor='#667eea'"
                               onblur="this.style.borderColor='#e0e0e0'">
                        <small style="color: #666; font-size: 0.85em; display: block; margin-top: 5px;">
                            <i class="fas fa-info-circle"></i> Digite exatamente como aparece na Hotmart
                        </small>
                    </div>
                    
                    <!-- Select de Palestra do Sistema -->
                    <div style="margin-bottom: 25px;">
                        <label style="display: block; font-weight: 600; margin-bottom: 8px; color: #333;">
                            <i class="fas fa-database" style="color: #2196f3;"></i> Palestra do Sistema
                        </label>
                        <select id="quickLectureSelect" 
                                class="form-control" 
                                required
                                style="padding: 12px; font-size: 1em; border: 2px solid #e0e0e0; border-radius: 5px; transition: border-color 0.3s;"
                                onfocus="this.style.borderColor='#667eea'"
                                onblur="this.style.borderColor='#e0e0e0'">
                            <option value="">-- Selecione uma palestra --</option>
                            <?php foreach ($system_lectures as $lecture): 
                                $is_mapped = in_array($lecture['id'], $mapped_lecture_ids);
                                if ($is_mapped) continue; // Não mostrar já mapeadas
                            ?>
                                <option value="<?php echo htmlspecialchars($lecture['id']); ?>">
                                    <?php echo htmlspecialchars($lecture['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #666; font-size: 0.85em; display: block; margin-top: 5px;">
                            <i class="fas fa-filter"></i> Apenas palestras disponíveis (não mapeadas)
                        </small>
                    </div>
                    
                    <!-- Botões -->
                    <div style="display: flex; gap: 10px; justify-content: flex-end;">
                        <button type="button" 
                                id="cancelQuickAssociate" 
                                class="btn btn-secondary"
                                style="padding: 10px 20px; font-weight: 600;">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="submit" 
                                class="btn btn-success"
                                style="padding: 10px 20px; font-weight: 600; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                            <i class="fas fa-check"></i> Criar Associação
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>
</html>