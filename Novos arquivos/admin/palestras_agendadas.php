<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');
// Ajuste o caminho do database conforme sua estrutura
require_once __DIR__ . '/../config/database.php';

// Verificar se é admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Palestras agendadas - Admin';
$message = '';
$error = '';

// --- PROCESSAMENTO DE FORMULÁRIOS (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. ADICIONAR
    if ($action === 'add') {
        $title = $_POST['title'];
        $speaker = $_POST['speaker'];
        $date = $_POST['announcement_date'];
        $time = $_POST['lecture_time'];
        $desc = $_POST['description'];
        $embed = $_POST['video_embed'] ?? '';

        // Upload de Imagem - Formato: announcement_[uniqid]_[data].png
        $image_path = '/images/palestra-placeholder.jpg';
        
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            // Diretório correto para announcements
            $upload_dir = __DIR__ . '/../../images/announcements/';
            
            // Criar diretório se não existir
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Verificar permissões de escrita
            if (!is_writable($upload_dir)) {
                error_log("Erro: Diretório $upload_dir não tem permissão de escrita");
                $error = 'Erro: Diretório de upload não tem permissão de escrita';
            } else {
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                // Formato correto: announcement_[uniqid]_[data].extensão
                $filename = 'announcement_' . uniqid() . '_' . date('Y-m-d') . '.' . $ext;
                
                $full_path = $upload_dir . $filename;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $full_path)) {
                    $image_path = '/images/announcements/' . $filename;
                    error_log("Imagem salva: $image_path");
                } else {
                    error_log("Erro ao mover arquivo: " . $_FILES['image']['tmp_name'] . " para " . $full_path);
                    $error = 'Erro ao fazer upload da imagem. Verifique os logs.';
                }
            }
        } elseif (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            // Log de erros de upload
            $upload_errors = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo excede upload_max_filesize',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo excede MAX_FILE_SIZE do formulário',
                UPLOAD_ERR_PARTIAL => 'Upload parcial',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário ausente',
                UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever no disco',
                UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão PHP'
            ];
            $error_code = $_FILES['image']['error'];
            $error_msg = $upload_errors[$error_code] ?? 'Erro desconhecido';
            error_log("Erro no upload da imagem: $error_msg (código: $error_code)");
            $error = "Erro no upload: $error_msg";
        }

        // Só insere se não houver erro no upload
        if (empty($error)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO upcoming_announcements (title, speaker, announcement_date, lecture_time, description, video_embed, image_path, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->execute([$title, $speaker, $date, $time, $desc, $embed, $image_path]);
                $message = 'Palestra agendada!';
            } catch (PDOException $e) {
                $error = 'Erro ao agendar: ' . $e->getMessage();
                error_log("Erro no banco de dados: " . $e->getMessage());
            }
        }
    }

    // 2. EDITAR
    elseif ($action === 'edit') {
        $id = $_POST['id'];
        $title = $_POST['title'];
        $speaker = $_POST['speaker'];
        $date = $_POST['announcement_date'];
        $time = $_POST['lecture_time'];
        $desc = $_POST['description'];
        $embed = $_POST['video_embed'] ?? '';

        try {
            if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
                $upload_dir = __DIR__ . '/../../images/announcements/';
                
                // Criar diretório se não existir
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $ext = pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION);
                $filename = 'announcement_' . uniqid() . '_' . date('Y-m-d') . '.' . $ext;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dir . $filename)) {
                    $image_path = '/images/announcements/' . $filename;
                    $stmt = $pdo->prepare("UPDATE upcoming_announcements SET title=?, speaker=?, announcement_date=?, lecture_time=?, description=?, video_embed=?, image_path=? WHERE id=?");
                    $stmt->execute([$title, $speaker, $date, $time, $desc, $embed, $image_path, $id]);
                    error_log("Imagem atualizada: $image_path");
                } else {
                    error_log("Erro ao atualizar imagem para palestra ID: $id");
                }
            } else {
                $stmt = $pdo->prepare("UPDATE upcoming_announcements SET title=?, speaker=?, announcement_date=?, lecture_time=?, description=?, video_embed=? WHERE id=?");
                $stmt->execute([$title, $speaker, $date, $time, $desc, $embed, $id]);
            }
            $message = 'Palestra atualizada!';
        } catch (PDOException $e) {
            $error = 'Erro ao atualizar: ' . $e->getMessage();
            error_log("Erro ao atualizar palestra: " . $e->getMessage());
        }
    }

    // 3. EXCLUIR
    elseif ($action === 'delete') {
        $id = $_POST['id'];
        try {
            $stmt = $pdo->prepare("DELETE FROM upcoming_announcements WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Palestra removida.';
        } catch (Exception $e) {
            $error = 'Erro ao excluir.';
            error_log("Erro ao excluir palestra: " . $e->getMessage());
        }
    }

    // 4. ENVIAR PARA LIVE
    elseif ($action === 'send_to_live') {
        $id = $_POST['id'];
        try {
            // Busca dados da palestra
            $stmt = $pdo->prepare("SELECT video_embed FROM upcoming_announcements WHERE id = ?");
            $stmt->execute([$id]);
            $palestra = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($palestra && !empty($palestra['video_embed'])) {
                // Atualiza site_settings
                // Nota: Usamos parâmetros diferentes (:v1, :v2) para evitar erro de driver PDO em alguns servidores
                $sql = "INSERT INTO site_settings (setting_key, setting_value, updated_at)
                        VALUES (:key, :v1, NOW())
                        ON DUPLICATE KEY UPDATE setting_value = :v2, updated_at = NOW()";

                $stmtUpdate = $pdo->prepare($sql);

                // Define o embed
                $stmtUpdate->execute(['key' => 'live_embed_code', 'v1' => $palestra['video_embed'], 'v2' => $palestra['video_embed']]);
                // Liga a live
                $stmtUpdate->execute(['key' => 'live_status', 'v1' => '1', 'v2' => '1']);

                echo json_encode(['success' => true, 'message' => 'Palestra enviada para o ar!']);
                exit;
            } else {
                echo json_encode(['success' => false, 'message' => 'Esta palestra não tem embed configurado.']);
                exit;
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
            exit;
        }
    }
}

// --- BUSCAR DADOS ---

// 1. Buscar qual o embed está ATUALMENTE no ar (para pintar o ícone)
$current_active_embed = '';
try {
    $stmtSettings = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key = 'live_embed_code'");
    $current_active_embed = $stmtSettings->fetchColumn();
} catch (Exception $e) {}

// 2. Buscar Palestras (Ordenadas da MAIS RECENTE para antiga)
try {
    $stmt = $pdo->query("
        SELECT id, title, speaker, announcement_date, lecture_time, description, image_path, video_embed, is_active
        FROM upcoming_announcements
        ORDER BY announcement_date DESC, lecture_time DESC
    ");
    $all_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Erro ao buscar dados.';
    $all_announcements = [];
}

include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-calendar-alt"></i> Gerenciar palestras</h1>
            <p>Agende e gerencie as próximas transmissões</p>
        </div>
    </div>

    <div class="container-fluid" style="padding: 20px;">
        <?php if ($message): ?>
            <div class="alert success" id="successAlert">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <button onclick="openModal('addModal')" class="cta-btn" style="margin-bottom: 20px;">
            <i class="fas fa-plus"></i> Nova palestra
        </button>

        <div class="video-card">
            <h3 style="margin-bottom: 15px; border-bottom: 1px solid rgba(255,255,255,0.1); padding-bottom: 10px;">Lista de palestras</h3>

            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Data/hora</th>
                            <th>Palestra</th>
                            <th>Embed</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($all_announcements)): foreach ($all_announcements as $lecture):
                            // Lógica de Data Passada
                            $lectureTimestamp = strtotime($lecture['announcement_date'] . ' ' . $lecture['lecture_time']);
                            $isPast = ($lectureTimestamp < time());

                            // Lógica de "No Ar" (Compara o embed desta palestra com o que está no site_settings)
                            // Remove espaços para garantir comparação justa
                            $isOnAir = (!empty($lecture['video_embed']) && trim($lecture['video_embed']) === trim($current_active_embed));
                        ?>
                        <tr class="<?php echo $isOnAir ? 'row-active' : ''; ?>">
                            <td>
                                <div style="font-weight:bold; color:#fff; display:flex; align-items:center; gap:5px;">
                                    <?php echo date('d/m/Y', strtotime($lecture['announcement_date'])); ?>

                                    <?php if ($isPast): ?>
                                        <i class="fas fa-check-circle" style="color:#2ecc71; font-size:0.9rem;" title="Concluída"></i>
                                    <?php endif; ?>
                                </div>
                                <small style="color:#aaa;"><?php echo substr($lecture['lecture_time'], 0, 5); ?>h</small>
                            </td>
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <img src="<?php echo htmlspecialchars($lecture['image_path']); ?>" style="width:50px; height:30px; object-fit:cover; border-radius:4px;">
                                    <div>
                                        <div style="font-weight:bold; color:<?php echo $isOnAir ? '#e74c3c' : '#f39c12'; ?>;">
                                            <?php echo htmlspecialchars($lecture['title']); ?>
                                            <?php if($isOnAir): ?> <span style="background:#e74c3c; color:#fff; font-size:0.6rem; padding:1px 4px; border-radius:3px;">NO AR</span><?php endif; ?>
                                        </div>
                                        <div style="font-size:0.85rem; color:#ccc;"><?php echo htmlspecialchars($lecture['speaker']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($lecture['video_embed'])): ?>
                                    <span style="color:#2ecc71; font-size:1.2rem;" title="Configurado"><i class="fas fa-video"></i></span>
                                <?php else: ?>
                                    <span style="color:#555; font-size:1.2rem;" title="Vazio"><i class="fas fa-video-slash"></i></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($lecture)); ?>)" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </button>

                                    <button class="action-btn live <?php echo $isOnAir ? 'broadcasting' : ''; ?>"
                                            onclick="sendToLive(<?php echo $lecture['id']; ?>)"
                                            title="<?php echo $isOnAir ? 'Já está no ar' : 'Transmitir agora'; ?>">
                                        <i class="fas fa-broadcast-tower"></i>
                                    </button>

                                    <button class="action-btn delete" onclick="confirmDelete(<?php echo $lecture['id']; ?>)" title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="4" style="text-align:center; padding:20px;">Nenhuma palestra encontrada.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div id="addModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('addModal')">&times;</span>
        <h2><i class="fas fa-calendar-plus"></i> Agendar palestra</h2>
        <form method="post" enctype="multipart/form-data" id="addForm">
            <input type="hidden" name="action" value="add">
            <div class="form-row">
                <div class="form-group">
                    <label>Título</label>
                    <input type="text" name="title" required>
                </div>
                <div class="form-group">
                    <label>Palestrante</label>
                    <input type="text" name="speaker" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Data</label>
                    <input type="date" name="announcement_date" required>
                </div>
                <div class="form-group">
                    <label>Horário</label>
                    <input type="time" name="lecture_time" required>
                </div>
            </div>
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="description" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label style="color:#f39c12;"><i class="fas fa-code"></i> Embed (YouTube/Wave)</label>
                <textarea name="video_embed" rows="3" placeholder='<iframe...>' style="font-family:monospace; background:rgba(0,0,0,0.3); color:#8e44ad;"></textarea>
            </div>
            <div class="form-group">
                <label>Imagem</label>
                <input type="file" name="image" accept="image/*">
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancelar</button>
                <button type="submit" class="btn-save">Agendar</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeModal('editModal')">&times;</span>
        <h2><i class="fas fa-edit"></i> Editar palestra</h2>
        <form method="post" enctype="multipart/form-data" id="editForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="form-row">
                <div class="form-group">
                    <label>Título</label>
                    <input type="text" name="title" id="edit_title" required>
                </div>
                <div class="form-group">
                    <label>Palestrante</label>
                    <input type="text" name="speaker" id="edit_speaker" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Data</label>
                    <input type="date" name="announcement_date" id="edit_date" required>
                </div>
                <div class="form-group">
                    <label>Horário</label>
                    <input type="time" name="lecture_time" id="edit_time" required>
                </div>
            </div>
            <div class="form-group">
                <label>Descrição</label>
                <textarea name="description" id="edit_desc" rows="3"></textarea>
            </div>
            <div class="form-group">
                <label style="color:#f39c12;"><i class="fas fa-code"></i> Embed (YouTube/Wave)</label>
                <textarea name="video_embed" id="edit_embed" rows="3" style="font-family:monospace; background:rgba(0,0,0,0.3); color:#8e44ad;"></textarea>
            </div>
            <div class="form-group">
                <label>Alterar Imagem</label>
                <input type="file" name="image" accept="image/*">
                <small id="current_image_text" style="color:#888;"></small>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('editModal')">Cancelar</button>
                <button type="submit" class="btn-save">Salvar</button>
            </div>
        </form>
    </div>
</div>

<form id="deleteForm" method="post" style="display:none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_id">
</form>

<style>
    /* Estilos de Alerta */
    .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; transition: opacity 0.5s ease; }
    .alert.success { background: rgba(46, 204, 113, 0.2); border: 1px solid #2ecc71; color: #2ecc71; }
    .alert.error { background: rgba(231, 76, 60, 0.2); border: 1px solid #e74c3c; color: #e74c3c; }

    /* Tabela */
    .data-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
    .data-table th, .data-table td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .data-table th { background: rgba(142, 68, 173, 0.2); color: #fff; font-weight: 600; }
    .data-table tr:hover { background: rgba(255,255,255,0.05); }

    /* Destaque para linha no ar */
    .row-active { background: rgba(231, 76, 60, 0.1) !important; border-left: 3px solid #e74c3c; }

    /* Botões */
    .action-buttons { display: flex; gap: 10px; }
    .action-btn { background: transparent; border: none; cursor: pointer; font-size: 1.1rem; color: #ccc; transition: 0.2s; }
    .action-btn:hover { transform: scale(1.1); }
    .action-btn.edit:hover { color: #f39c12; }
    .action-btn.delete:hover { color: #e74c3c; }

    /* Botão Transmitir */
    .action-btn.live { color: #fff; }
    .action-btn.live:hover { color: #e74c3c; }

    /* Estado Ativo do Ícone */
    .action-btn.live.broadcasting {
        color: #e74c3c;
        animation: pulse-red 2s infinite;
        cursor: default; /* Indica que já está ativo */
    }

    @keyframes pulse-red {
        0% { transform: scale(1); text-shadow: 0 0 0 rgba(231, 76, 60, 0.7); }
        50% { transform: scale(1.1); text-shadow: 0 0 10px rgba(231, 76, 60, 1); }
        100% { transform: scale(1); text-shadow: 0 0 0 rgba(231, 76, 60, 0.7); }
    }

    .cta-btn { background: #8e44ad; color: #fff; border: none; padding: 10px 20px; border-radius: 30px; cursor: pointer; font-weight: bold; }
    .cta-btn:hover { background: #9b59b6; }

    /* Modal - Alinhamento */
    .modal {
        display: none; position: fixed; z-index: 1000; left: 0; top: 0;
        width: 100%; height: 100%; overflow: auto;
        background-color: rgba(0,0,0,0.8); backdrop-filter: blur(5px);
        padding-left: 260px; /* Compensa sidebar */
        box-sizing: border-box;
    }
    @media (max-width: 768px) { .modal { padding-left: 0; } }

    .modal-content {
        background: #1e1e1e; margin: 5% auto; padding: 30px;
        border: 1px solid #444; border-radius: 15px;
        width: 90%; max-width: 700px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }
    .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }

    .form-group { margin-bottom: 15px; }
    .form-group label { display: block; margin-bottom: 5px; color: #ccc; }
    .form-group input, .form-group textarea { width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #444; background: #2c2c2e; color: #fff; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
    .form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    .btn-save { background: #2ecc71; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; }
    .btn-cancel { background: #555; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; }
</style>

<script>
// --- AUTO-HIDE ALERTS (5 Segundos) ---
document.addEventListener('DOMContentLoaded', function() {
    const alert = document.getElementById('successAlert');
    if (alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() { alert.style.display = 'none'; }, 500); // Aguarda fade out
        }, 5000); // 5000ms = 5 segundos
    }
});

function openModal(id) { document.getElementById(id).style.display = 'block'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_title').value = data.title;
    document.getElementById('edit_speaker').value = data.speaker;
    document.getElementById('edit_date').value = data.announcement_date;
    document.getElementById('edit_time').value = data.lecture_time;
    document.getElementById('edit_desc').value = data.description;
    document.getElementById('edit_embed').value = data.video_embed || '';
    document.getElementById('current_image_text').innerText = 'Imagem atual: ' + data.image_path.split('/').pop();
    openModal('editModal');
}

function confirmDelete(id) {
    if(confirm('Excluir esta palestra?')) {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}

function sendToLive(id) {
    if (!confirm('ATENÇÃO: Substituir a transmissão atual por esta palestra?')) return;

    const formData = new FormData();
    formData.append('action', 'send_to_live');
    formData.append('id', id);

    fetch('palestras_agendadas.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ Palestra está no ar!');
            window.location.reload(); // Recarrega para ver o ícone vermelho
        } else {
            alert('❌ Erro: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro de conexão.');
    });
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) event.target.style.display = 'none';
}
</script>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>
