<?php
// gerenciar_cursos.php
session_start();
require_once __DIR__ . '/../config/database.php';

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $id = $_POST['id'] ?? 0;
        
        switch ($_POST['action']) {
            case 'toggle_enrollment':
                $stmt = $pdo->prepare("UPDATE courses SET enrollment_open = NOT enrollment_open, manual_close = 1 WHERE id = ?");
                $stmt->execute([$id]);
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
                $stmt->execute([$id]);
                break;
                
            case 'add':
                $stmt = $pdo->prepare("INSERT INTO courses (title, start_date, end_date, enrollment_open) VALUES (?, ?, ?, 1)");
                $stmt->execute([$_POST['title'], $_POST['start_date'], $_POST['end_date']]);
                break;
                
            case 'edit':
                $stmt = $pdo->prepare("UPDATE courses SET title = ?, start_date = ?, end_date = ? WHERE id = ?");
                $stmt->execute([$_POST['title'], $_POST['start_date'], $_POST['end_date'], $id]);
                break;
        }
        header('Location: gerenciar_cursos.php');
        exit;
    }
}

// Buscar cursos
$stmt = $pdo->query("SELECT * FROM courses ORDER BY start_date DESC");
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Gerenciar Cursos - Admin';
include __DIR__ . '/../vision/includes/head.php';
?>

<style>
/* Estilos específicos da página de gerenciamento */
.page-header {
    margin-bottom: 40px;
}

.page-header h1 {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 0.5rem;
    display: flex;
    align-items: center;
    gap: 15px;
}

.page-header p {
    color: var(--text-muted);
    font-size: 1.1rem;
}

.actions-bar {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 24px;
    background: var(--glass-bg);
    backdrop-filter: blur(10px);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    color: var(--text-primary);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.95rem;
    cursor: pointer;
    transition: all 0.3s ease;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(168, 85, 247, 0.3);
    border-color: var(--brand-purple);
}

.btn-primary {
    background: linear-gradient(135deg, var(--brand-purple), #6a1b9a);
    border-color: var(--brand-purple);
}

.btn-primary:hover {
    box-shadow: 0 8px 20px rgba(168, 85, 247, 0.5);
}

.btn-success {
    background: linear-gradient(135deg, var(--accent-green), #1e8449);
    border-color: var(--accent-green);
}

.btn-success:hover {
    box-shadow: 0 8px 20px rgba(39, 174, 96, 0.5);
}

.btn-warning {
    background: linear-gradient(135deg, var(--accent-gold), #e67e22);
    border-color: var(--accent-gold);
}

.btn-warning:hover {
    box-shadow: 0 8px 20px rgba(243, 156, 18, 0.5);
}

.btn-danger {
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    border-color: #e74c3c;
}

.btn-danger:hover {
    box-shadow: 0 8px 20px rgba(231, 76, 60, 0.5);
}

.btn-small {
    padding: 8px 16px;
    font-size: 0.85rem;
}

.glass-card {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 20px;
    padding: 30px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th, td {
    padding: 16px;
    text-align: left;
    border-bottom: 1px solid var(--glass-border);
}

th {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.9rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

td {
    color: var(--text-muted);
}

tr:hover {
    background: rgba(255, 255, 255, 0.02);
}

.course-title {
    color: var(--text-primary);
    font-weight: 600;
    font-size: 1.05rem;
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.status-open {
    background: rgba(39, 174, 96, 0.2);
    color: var(--accent-green);
    border: 1px solid var(--accent-green);
}

.status-closed {
    background: rgba(231, 76, 60, 0.2);
    color: #e74c3c;
    border: 1px solid #e74c3c;
}

.actions-cell {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.actions-cell form {
    display: inline;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.85);
    backdrop-filter: blur(10px);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: linear-gradient(135deg, rgba(26, 26, 46, 0.95) 0%, rgba(22, 33, 62, 0.95) 100%);
    backdrop-filter: blur(20px);
    max-width: 600px;
    width: 90%;
    padding: 40px;
    border-radius: 24px;
    border: 1px solid var(--glass-border);
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
    position: relative;
    animation: modalSlideIn 0.3s ease;
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.modal-header h2 {
    font-size: 1.8rem;
    font-weight: 700;
}

.close {
    font-size: 28px;
    cursor: pointer;
    color: var(--text-muted);
    transition: color 0.3s ease;
    background: none;
    border: none;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.close:hover {
    color: var(--text-primary);
}

.form-group {
    margin-bottom: 24px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.95rem;
}

.form-group input[type="text"],
.form-group input[type="date"] {
    width: 100%;
    padding: 12px 16px;
    border-radius: 12px;
    border: 1px solid var(--glass-border);
    background: var(--glass-bg);
    color: var(--text-primary);
    font-size: 1rem;
    transition: all 0.3s ease;
}

.form-group input:focus {
    outline: none;
    border-color: var(--brand-purple);
    box-shadow: 0 0 0 3px rgba(168, 85, 247, 0.2);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.3;
}

.empty-state h3 {
    font-size: 1.5rem;
    margin-bottom: 10px;
    color: var(--text-primary);
}

@media (max-width: 768px) {
    .page-header h1 {
        font-size: 2rem;
    }
    
    .actions-cell {
        flex-direction: column;
    }
    
    .modal-content {
        padding: 30px 20px;
    }
}
</style>

<?php
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="page-header">
        <h1><i class="fas fa-graduation-cap"></i> Gerenciar cursos</h1>
        <p>Adicione, edite e controle as inscrições dos cursos</p>
    </div>
    
    <div class="actions-bar">
        <button onclick="openAddModal()" class="btn btn-success">
            <i class="fas fa-plus"></i> Adicionar curso
        </button>
        <a href="/cursos/index.php" class="btn">
            <i class="fas fa-arrow-left"></i> Voltar aos cursos
        </a>
    </div>

    <div class="glass-card">
        <?php if (empty($courses)): ?>
            <div class="empty-state">
                <i class="fas fa-graduation-cap"></i>
                <h3>Nenhum curso cadastrado</h3>
                <p>Clique em "Adicionar curso" para começar</p>
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Curso</th>
                            <th>Data de início</th>
                            <th>Data de término</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($courses as $course): ?>
                        <tr>
                            <td class="course-title"><?= htmlspecialchars($course['title']) ?></td>
                            <td><?= date('d/m/Y', strtotime($course['start_date'])) ?></td>
                            <td><?= $course['end_date'] ? date('d/m/Y', strtotime($course['end_date'])) : '-' ?></td>
                            <td>
                                <span class="status-badge <?= $course['enrollment_open'] ? 'status-open' : 'status-closed' ?>">
                                    <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                    <?= $course['enrollment_open'] ? 'Inscrições abertas' : 'Inscrições encerradas' ?>
                                </span>
                            </td>
                            <td>
                                <div class="actions-cell">
                                    <form method="POST">
                                        <input type="hidden" name="id" value="<?= $course['id'] ?>">
                                        <input type="hidden" name="action" value="toggle_enrollment">
                                        <button type="submit" class="btn btn-warning btn-small">
                                            <i class="fas fa-toggle-<?= $course['enrollment_open'] ? 'on' : 'off' ?>"></i>
                                            <?= $course['enrollment_open'] ? 'Encerrar' : 'Abrir' ?>
                                        </button>
                                    </form>
                                    
                                    <button onclick='openEditModal(<?= json_encode($course) ?>)' class="btn btn-primary btn-small">
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                    
                                    <form method="POST" onsubmit="return confirm('Tem certeza que deseja excluir este curso?')">
                                        <input type="hidden" name="id" value="<?= $course['id'] ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-danger btn-small">
                                            <i class="fas fa-trash"></i> Excluir
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
</div>

<!-- Modal Adicionar -->
<div id="addModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Adicionar curso</h2>
            <button class="close" onclick="closeModal('addModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <div class="form-group">
                <label>Nome do curso</label>
                <input type="text" name="title" required placeholder="Ex: Curso de Tradução Literária">
            </div>
            <div class="form-group">
                <label>Data de início</label>
                <input type="date" name="start_date" required>
            </div>
            <div class="form-group">
                <label>Data de término (opcional)</label>
                <input type="date" name="end_date">
            </div>
            <button type="submit" class="btn btn-success" style="width: 100%;">
                <i class="fas fa-check"></i> Adicionar curso
            </button>
        </form>
    </div>
</div>

<!-- Modal Editar -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Editar curso</h2>
            <button class="close" onclick="closeModal('editModal')">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-group">
                <label>Nome do curso</label>
                <input type="text" name="title" id="edit_title" required>
            </div>
            <div class="form-group">
                <label>Data de início</label>
                <input type="date" name="start_date" id="edit_start_date" required>
            </div>
            <div class="form-group">
                <label>Data de término (opcional)</label>
                <input type="date" name="end_date" id="edit_end_date">
            </div>
            <button type="submit" class="btn btn-success" style="width: 100%;">
                <i class="fas fa-save"></i> Salvar alterações
            </button>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').classList.add('active');
}

function openEditModal(course) {
    document.getElementById('edit_id').value = course.id;
    document.getElementById('edit_title').value = course.title;
    document.getElementById('edit_start_date').value = course.start_date;
    document.getElementById('edit_end_date').value = course.end_date || '';
    document.getElementById('editModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Fechar modal ao clicar fora
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('active');
    }
}

// Fechar modal com ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.remove('active');
        });
    }
});
</script>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>