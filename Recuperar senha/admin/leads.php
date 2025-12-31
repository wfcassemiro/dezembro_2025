<?php
/**
 * Painel de Leads - Área Administrativa
 * Acesso restrito a administradores
 */

session_start();
date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../config/database.php';

// Verificar se é admin
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Bloquear acesso se não for admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Gerenciar Leads - Admin';

// Processar exclusão de lead
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete_lead' && isset($_POST['lead_id'])) {
        try {
            $stmt = $pdo->prepare("DELETE FROM leads WHERE id = ?");
            $stmt->execute([$_POST['lead_id']]);
            $success_message = "Lead excluído com sucesso!";
        } catch (PDOException $e) {
            $error_message = "Erro ao excluir lead: " . $e->getMessage();
        }
    }
    
    // Exportar para CSV
    if ($_POST['action'] === 'export_csv') {
        try {
            $stmt = $pdo->query("SELECT nome, email, whatsapp, fonte, created_at FROM leads ORDER BY created_at DESC");
            $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="leads_' . date('Y-m-d_H-i-s') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // BOM para Excel reconhecer UTF-8
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Cabeçalho
            fputcsv($output, ['Nome', 'E-mail', 'WhatsApp', 'Fonte', 'Data/Hora Cadastro'], ';');
            
            // Dados
            foreach ($leads as $lead) {
                fputcsv($output, [
                    $lead['nome'],
                    $lead['email'],
                    $lead['whatsapp'],
                    $lead['fonte'],
                    date('d/m/Y H:i:s', strtotime($lead['created_at']))
                ], ';');
            }
            
            fclose($output);
            exit;
        } catch (PDOException $e) {
            $error_message = "Erro ao exportar: " . $e->getMessage();
        }
    }
}

// Buscar leads com filtros
$filter_fonte = $_GET['fonte'] ?? '';
$filter_data_inicio = $_GET['data_inicio'] ?? '';
$filter_data_fim = $_GET['data_fim'] ?? '';
$search = $_GET['search'] ?? '';

$sql = "SELECT * FROM leads WHERE 1=1";
$params = [];

if (!empty($filter_fonte)) {
    $sql .= " AND fonte = ?";
    $params[] = $filter_fonte;
}

if (!empty($filter_data_inicio)) {
    $sql .= " AND DATE(created_at) >= ?";
    $params[] = $filter_data_inicio;
}

if (!empty($filter_data_fim)) {
    $sql .= " AND DATE(created_at) <= ?";
    $params[] = $filter_data_fim;
}

if (!empty($search)) {
    $sql .= " AND (nome LIKE ? OR email LIKE ? OR whatsapp LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY created_at DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $leads = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $leads = [];
    $error_message = "Erro ao buscar leads: " . $e->getMessage();
}

// Estatísticas
try {
    $stats = [];
    
    // Total de leads
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM leads");
    $stats['total'] = $stmt->fetch()['total'];
    
    // Leads hoje
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM leads WHERE DATE(created_at) = CURDATE()");
    $stats['hoje'] = $stmt->fetch()['total'];
    
    // Leads esta semana
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM leads WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['semana'] = $stmt->fetch()['total'];
    
    // Leads este mês
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM leads WHERE MONTH(created_at) = MONTH(NOW()) AND YEAR(created_at) = YEAR(NOW())");
    $stats['mes'] = $stmt->fetch()['total'];
    
    // Fontes distintas
    $stmt = $pdo->query("SELECT fonte, COUNT(*) as total FROM leads GROUP BY fonte ORDER BY total DESC");
    $stats['fontes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $stats = ['total' => 0, 'hoje' => 0, 'semana' => 0, 'mes' => 0, 'fontes' => []];
}

// Incluir templates
include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<style>
.leads-container {
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    gap: 15px;
}

.page-header h1 {
    color: #c084fc;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.page-header h1 i {
    color: #FFD700;
}

.header-actions {
    display: flex;
    gap: 10px;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: rgba(30, 30, 30, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 20px;
    text-align: center;
}

.stat-card .stat-number {
    font-size: 2.5rem;
    font-weight: bold;
    color: #c084fc;
    margin-bottom: 5px;
}

.stat-card .stat-label {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.9rem;
}

.stat-card.highlight {
    background: linear-gradient(135deg, rgba(192, 132, 252, 0.2), rgba(139, 92, 246, 0.1));
    border-color: rgba(192, 132, 252, 0.3);
}

.stat-card.highlight .stat-number {
    color: #FFD700;
}

/* Filters */
.filters-card {
    background: rgba(30, 30, 30, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 25px;
}

.filters-card h3 {
    color: #c084fc;
    margin: 0 0 15px 0;
    font-size: 1rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filters-form {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.filter-group {
    flex: 1;
    min-width: 150px;
}

.filter-group label {
    display: block;
    margin-bottom: 5px;
    color: rgba(255, 255, 255, 0.8);
    font-size: 0.85rem;
}

.filter-group input,
.filter-group select {
    width: 100%;
    padding: 10px 12px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    background: rgba(0, 0, 0, 0.3);
    color: white;
    font-size: 0.9rem;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: #c084fc;
}

.filter-buttons {
    display: flex;
    gap: 10px;
}

/* Buttons */
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-primary {
    background: linear-gradient(135deg, #c084fc, #8b5cf6);
    color: white;
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(192, 132, 252, 0.3);
}

.btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.btn-success:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(16, 185, 129, 0.3);
}

.btn-secondary {
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.btn-secondary:hover {
    background: rgba(255, 255, 255, 0.2);
}

.btn-danger {
    background: rgba(239, 68, 68, 0.2);
    color: #ef4444;
    border: 1px solid rgba(239, 68, 68, 0.3);
    padding: 6px 10px;
}

.btn-danger:hover {
    background: rgba(239, 68, 68, 0.3);
}

/* Table */
.leads-card {
    background: rgba(30, 30, 30, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    overflow: hidden;
}

.leads-card-header {
    padding: 20px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.leads-card-header h3 {
    color: #c084fc;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.leads-count {
    background: rgba(192, 132, 252, 0.2);
    color: #c084fc;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
}

.table-container {
    overflow-x: auto;
}

.leads-table {
    width: 100%;
    border-collapse: collapse;
}

.leads-table th,
.leads-table td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.leads-table th {
    background: rgba(0, 0, 0, 0.3);
    color: #c084fc;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.leads-table tr:hover {
    background: rgba(255, 255, 255, 0.03);
}

.leads-table td {
    color: rgba(255, 255, 255, 0.9);
    font-size: 0.95rem;
}

.lead-name {
    font-weight: 600;
    color: white;
}

.lead-email {
    color: #c084fc;
}

.lead-whatsapp {
    color: #25D366;
    display: flex;
    align-items: center;
    gap: 6px;
}

.lead-whatsapp a {
    color: #25D366;
    text-decoration: none;
}

.lead-whatsapp a:hover {
    text-decoration: underline;
}

.fonte-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: 600;
    background: rgba(139, 92, 246, 0.2);
    color: #a78bfa;
}

.date-time {
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.85rem;
}

.date-time .date {
    display: block;
    color: white;
}

.date-time .time {
    font-size: 0.8rem;
}

/* Messages */
.alert {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: rgba(16, 185, 129, 0.15);
    border: 1px solid rgba(16, 185, 129, 0.3);
    color: #10b981;
}

.alert-error {
    background: rgba(239, 68, 68, 0.15);
    border: 1px solid rgba(239, 68, 68, 0.3);
    color: #ef4444;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: rgba(255, 255, 255, 0.5);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    color: rgba(192, 132, 252, 0.3);
}

.empty-state h3 {
    margin: 0 0 10px 0;
    color: rgba(255, 255, 255, 0.7);
}

/* Responsive */
@media (max-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .page-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .filters-form {
        flex-direction: column;
    }
    
    .filter-group {
        width: 100%;
    }
}
</style>

<div class="main-content">
    <div class="leads-container">
        <!-- Header -->
        <div class="page-header">
            <h1><i class="fas fa-users"></i> Gerenciar Leads</h1>
            <div class="header-actions">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="export_csv">
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-file-csv"></i> Exportar CSV
                    </button>
                </form>
            </div>
        </div>

        <!-- Messages -->
        <?php if (!empty($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($error_message)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card highlight">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total de Leads</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['hoje']; ?></div>
                <div class="stat-label">Hoje</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['semana']; ?></div>
                <div class="stat-label">Esta Semana</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['mes']; ?></div>
                <div class="stat-label">Este Mês</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters-card">
            <h3><i class="fas fa-filter"></i> Filtros</h3>
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label>Buscar</label>
                    <input type="text" name="search" placeholder="Nome, email ou WhatsApp..." 
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                
                <div class="filter-group">
                    <label>Fonte</label>
                    <select name="fonte">
                        <option value="">Todas</option>
                        <?php foreach ($stats['fontes'] as $fonte): ?>
                        <option value="<?php echo htmlspecialchars($fonte['fonte']); ?>"
                                <?php echo $filter_fonte === $fonte['fonte'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($fonte['fonte']); ?> (<?php echo $fonte['total']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Data Início</label>
                    <input type="date" name="data_inicio" value="<?php echo htmlspecialchars($filter_data_inicio); ?>">
                </div>
                
                <div class="filter-group">
                    <label>Data Fim</label>
                    <input type="date" name="data_fim" value="<?php echo htmlspecialchars($filter_data_fim); ?>">
                </div>
                
                <div class="filter-buttons">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Filtrar
                    </button>
                    <a href="leads.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Limpar
                    </a>
                </div>
            </form>
        </div>

        <!-- Leads Table -->
        <div class="leads-card">
            <div class="leads-card-header">
                <h3><i class="fas fa-list"></i> Lista de Leads</h3>
                <span class="leads-count"><?php echo count($leads); ?> registro(s)</span>
            </div>
            
            <?php if (empty($leads)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>Nenhum lead encontrado</h3>
                <p>Ajuste os filtros ou aguarde novos cadastros.</p>
            </div>
            <?php else: ?>
            <div class="table-container">
                <table class="leads-table">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>WhatsApp</th>
                            <th>Fonte</th>
                            <th>Data/Hora (GMT-3)</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leads as $lead): ?>
                        <tr>
                            <td class="lead-name"><?php echo htmlspecialchars($lead['nome']); ?></td>
                            <td class="lead-email">
                                <a href="mailto:<?php echo htmlspecialchars($lead['email']); ?>">
                                    <?php echo htmlspecialchars($lead['email']); ?>
                                </a>
                            </td>
                            <td class="lead-whatsapp">
                                <i class="fab fa-whatsapp"></i>
                                <?php 
                                $whatsapp_number = preg_replace('/[^0-9]/', '', $lead['whatsapp']);
                                if (strlen($whatsapp_number) === 11) {
                                    $whatsapp_number = '55' . $whatsapp_number;
                                }
                                ?>
                                <a href="https://wa.me/<?php echo $whatsapp_number; ?>" target="_blank">
                                    <?php echo htmlspecialchars($lead['whatsapp']); ?>
                                </a>
                            </td>
                            <td>
                                <span class="fonte-badge"><?php echo htmlspecialchars($lead['fonte']); ?></span>
                            </td>
                            <td class="date-time">
                                <span class="date"><?php echo date('d/m/Y', strtotime($lead['created_at'])); ?></span>
                                <span class="time"><?php echo date('H:i:s', strtotime($lead['created_at'])); ?></span>
                            </td>
                            <td>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Tem certeza que deseja excluir este lead?');">
                                    <input type="hidden" name="action" value="delete_lead">
                                    <input type="hidden" name="lead_id" value="<?php echo htmlspecialchars($lead['id']); ?>">
                                    <button type="submit" class="btn btn-danger" title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Auto-hide alerts after 5 seconds
document.querySelectorAll('.alert').forEach(function(alert) {
    setTimeout(function() {
        alert.style.transition = 'opacity 0.5s';
        alert.style.opacity = '0';
        setTimeout(function() {
            alert.remove();
        }, 500);
    }, 5000);
});
</script>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>
