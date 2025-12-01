<?php
session_start();

// Configurações de tempo e fuso
date_default_timezone_set('America/Sao_Paulo');
set_time_limit(300); // Aumenta limite de tempo do PHP

// Adicionar as classes do PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP; 

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email_config.php';
require_once __DIR__ . '/../vendor/autoload.php';

// Verificar se é admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Sistema de E-mails - Admin';

// =================================================================================
// 1. PROCESSAMENTO AJAX (NOVA LÓGICA DE LOTES)
// =================================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    // --- FASE 1: PREPARAR A LISTA (CONSULTA O BANCO E SALVA NA SESSÃO) ---
    // Esta lógica foi extraída do seu arquivo original (emails.old)
    if ($_POST['ajax_action'] === 'prepare_list') {
        try {
            $recipient_type = $_POST['recipient_type'];
            $recipients = [];

            // 1. Todos os Assinantes (Query original mantida)
            if ($recipient_type === 'all_subscribers') {
                $stmt = $pdo->query("SELECT name, email FROM users WHERE subscription_status = 'active' AND is_active = 1");
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } 
            // 2. Espectadores de Palestra (Query original mantida)
            elseif ($recipient_type === 'lecture_viewers') {
                $lecture_id = $_POST['lecture_id'];
                if (!$lecture_id) throw new Exception("Selecione uma palestra.");
                
                $stmt = $pdo->prepare("
                    SELECT DISTINCT u.name, u.email 
                    FROM users u 
                    JOIN user_lecture_views v ON u.id = v.user_id 
                    WHERE v.lecture_id = ? AND u.is_active = 1
                ");
                $stmt->execute([$lecture_id]);
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } 
            // 3. Usuários Específicos (Query original mantida)
            elseif ($recipient_type === 'specific_users') {
                $selected_ids = $_POST['selected_users'] ?? [];
                if (empty($selected_ids)) throw new Exception("Nenhum usuário selecionado.");
                
                // Monta query segura com IN (?)
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } 
            // 4. Lista Externa (Lógica original mantida)
            elseif ($recipient_type === 'external_list') {
                $raw_emails = explode(',', $_POST['external_emails']);
                foreach ($raw_emails as $email) {
                    $email = trim($email);
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $recipients[] = ['email' => $email, 'name' => 'Usuário'];
                    }
                }
            }

            if (empty($recipients)) {
                throw new Exception("Nenhum destinatário encontrado para o filtro selecionado.");
            }

            // Salva na sessão para o processamento em etapas
            $_SESSION['mail_queue'] = $recipients;
            $_SESSION['mail_config'] = [
                'subject' => trim($_POST['subject']),
                'message' => trim($_POST['message']),
                'access_link' => trim($_POST['access_link'] ?? '')
            ];

            echo json_encode([
                'status' => 'success', 
                'total' => count($recipients), 
                'message' => count($recipients) . ' destinatários encontrados. Iniciando...'
            ]);
            exit;

        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // --- FASE 2: PROCESSAR LOTE (ENVIA 5 DE CADA VEZ) ---
    if ($_POST['ajax_action'] === 'process_batch') {
        $batch_size = 5; // Tamanho do lote (seguro para não travar)
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        
        $queue = $_SESSION['mail_queue'] ?? [];
        $config = $_SESSION['mail_config'] ?? [];
        $total = count($queue);

        // Verifica se terminou
        if (empty($queue) || $offset >= $total) {
            echo json_encode(['status' => 'done']);
            exit;
        }

        // Pega a fatia atual
        $batch = array_slice($queue, $offset, $batch_size);
        $sent_count = 0;

        // Instancia o PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = SMTP_PORT;
            $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Erro SMTP: ' . $e->getMessage()]);
            exit;
        }

        // Loop de envio do lote
        foreach ($batch as $recipient) {
            try {
                $mail->clearAddresses();
                $mail->addAddress($recipient['email'], $recipient['name']);
                
                $mail->Subject = $config['subject'];
                
                // Substituição de variáveis {nome} (Lógica original)
                $body = str_replace('{nome}', $recipient['name'], $config['message']);
                
                if (!empty($config['access_link'])) {
                    $body .= "<br><br><a href='" . $config['access_link'] . "' style='padding:10px 20px; background:#8e44ad; color:#fff; text-decoration:none; border-radius:5px;'>Acessar Agora</a>";
                }
                
                $mail->Body = nl2br($body);
                $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], "\n", $body));

                $mail->send();
                $sent_count++;
                
                // Pausa técnica (0.1s) para evitar bloqueio do servidor de email
                usleep(100000); 

            } catch (Exception $e) {
                // Continua o loop mesmo se um falhar
            }
        }

        $new_offset = $offset + count($batch);
        $percent = ($total > 0) ? round(($new_offset / $total) * 100) : 100;

        echo json_encode([
            'status' => 'progress',
            'offset' => $new_offset,
            'percent' => $percent,
            'processed' => $new_offset,
            'total' => $total
        ]);
        exit;
    }
}

// =================================================================================
// 2. CARREGAR DADOS PARA A VIEW (Queries originais)
// =================================================================================

// Buscar palestras
$lectures = [];
try {
    $stmt = $pdo->query("SELECT id, title FROM lectures ORDER BY created_at DESC");
    $lectures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// Buscar usuários
$users = [];
try {
    $stmt = $pdo->query("SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name ASC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-paper-plane"></i> Sistema de E-mails</h1>
            <p>Envie comunicados em massa com segurança e acompanhamento.</p>
        </div>
    </div>

    <div class="container-fluid" style="padding: 20px;">
        
        <div class="video-card">
            <h2><i class="fas fa-envelope-open-text"></i> Novo Disparo</h2>
            
            <form id="emailForm">
                
                <div class="form-grid">
                    <div style="flex: 1; min-width: 300px;">
                        <div class="form-group">
                            <label>Destinatários</label>
                            <select name="recipient_type" id="recipient_type" required onchange="toggleRecipientFields()">
                                <option value="all_subscribers">Todos os Assinantes Ativos</option>
                                <option value="lecture_viewers">Espectadores de uma Palestra</option>
                                <option value="specific_users">Selecionar Usuários Manualmente</option>
                                <option value="external_list">Lista de E-mails (Externo)</option>
                            </select>
                        </div>

                        <div id="field_lecture" class="form-group conditional-field" style="display:none;">
                            <label>Escolha a Palestra</label>
                            <select name="lecture_id">
                                <option value="">Selecione...</option>
                                <?php foreach ($lectures as $l): ?>
                                    <option value="<?php echo $l['id']; ?>"><?php echo htmlspecialchars($l['title']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="field_users" class="form-group conditional-field" style="display:none;">
                            <label>Selecione os Usuários (Segure Ctrl)</label>
                            <select name="selected_users[]" multiple style="height: 150px;">
                                <?php foreach ($users as $u): ?>
                                    <option value="<?php echo $u['id']; ?>">
                                        <?php echo htmlspecialchars($u['name'] . ' (' . $u['email'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div id="field_external" class="form-group conditional-field" style="display:none;">
                            <label>E-mails (separados por vírgula)</label>
                            <textarea name="external_emails" placeholder="email1@teste.com, email2@teste.com"></textarea>
                        </div>

                        <div class="form-group">
                            <label>Link de Ação (Opcional)</label>
                            <input type="url" name="access_link" placeholder="https://...">
                        </div>
                    </div>

                    <div style="flex: 2; min-width: 300px;">
                        <div class="form-group">
                            <label>Assunto</label>
                            <input type="text" name="subject" required placeholder="Assunto do e-mail">
                        </div>

                        <div class="form-group">
                            <label>Mensagem</label>
                            <textarea name="message" rows="10" required placeholder="Olá {nome}, ..."></textarea>
                            <small style="color: #aaa;">Variável disponível: <strong>{nome}</strong></small>
                        </div>
                    </div>
                </div>

                <div id="progressArea" style="display: none; margin-top: 30px; background: rgba(0,0,0,0.3); padding: 20px; border-radius: 10px; border: 1px solid #444;">
                    <h4 style="color: #fff; margin-bottom: 10px;">Enviando E-mails...</h4>
                    
                    <div style="width: 100%; background: #333; height: 25px; border-radius: 15px; overflow: hidden; margin-bottom: 10px;">
                        <div id="progressBar" style="width: 0%; height: 100%; background: linear-gradient(90deg, #8e44ad, #9b59b6); transition: width 0.3s ease; text-align: center; color: white; line-height: 25px; font-weight: bold; font-size: 0.8rem;">0%</div>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; color: #ccc; font-size: 0.9rem;">
                        <span id="progressText">Preparando lista...</span>
                        <span id="progressCount">0/0</span>
                    </div>
                </div>

                <div class="form-actions" style="margin-top: 20px; text-align: right;">
                    <button type="submit" class="cta-btn" id="btnSend">
                        <i class="fas fa-paper-plane"></i> Iniciar Envio
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>

<style>
/* Estilos do Formulário */
.form-grid { display: flex; gap: 30px; flex-wrap: wrap; }
.form-group { margin-bottom: 20px; }
.form-group label { display: block; margin-bottom: 8px; color: #fff; font-weight: 500; }
.form-group input, .form-group select, .form-group textarea {
    width: 100%; padding: 12px; background: #222; border: 1px solid #444; border-radius: 8px; color: #fff; font-size: 1rem;
}
.form-group input:focus, .form-group textarea:focus { border-color: #8e44ad; outline: none; }

.cta-btn {
    background: #8e44ad; color: #fff; border: none; padding: 12px 30px; border-radius: 30px; font-size: 1rem; font-weight: bold; cursor: pointer; transition: 0.3s;
    display: inline-flex; align-items: center; gap: 10px;
}
.cta-btn:hover { background: #9b59b6; transform: translateY(-2px); box-shadow: 0 5px 15px rgba(142, 68, 173, 0.4); }
.cta-btn:disabled { background: #555; cursor: not-allowed; transform: none; box-shadow: none; }

.status-complete { color: #2ecc71; font-weight: bold; }
</style>

<script>
// Toggle de Campos (Original)
function toggleRecipientFields() {
    const type = document.getElementById('recipient_type').value;
    document.querySelectorAll('.conditional-field').forEach(el => el.style.display = 'none');
    
    if (type === 'lecture_viewers') document.getElementById('field_lecture').style.display = 'block';
    if (type === 'specific_users') document.getElementById('field_users').style.display = 'block';
    if (type === 'external_list') document.getElementById('field_external').style.display = 'block';
}

// LÓGICA DE ENVIO EM LOTES (NOVA)
document.getElementById('emailForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const btn = document.getElementById('btnSend');
    const progressArea = document.getElementById('progressArea');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const progressCount = document.getElementById('progressCount');
    
    // UI: Bloquear botão e mostrar barra
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processando...';
    progressArea.style.display = 'block';
    progressBar.style.width = '0%';
    progressBar.innerText = '0%';
    
    // BACKEND: Preparar Lista
    const formData = new FormData(this);
    formData.append('ajax_action', 'prepare_list');

    fetch('', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            const total = data.total;
            progressText.innerText = data.message;
            progressCount.innerText = `0 / ${total}`;
            
            // Iniciar loop
            processBatch(0, total);
        } else {
            throw new Error(data.message);
        }
    })
    .catch(error => {
        alert('Erro ao iniciar: ' + error.message);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane"></i> Tentar Novamente';
        progressArea.style.display = 'none';
    });

    // Função recursiva para lotes
    function processBatch(offset, total) {
        const batchData = new FormData();
        batchData.append('ajax_action', 'process_batch');
        batchData.append('offset', offset);

        fetch('', { method: 'POST', body: batchData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'progress') {
                // Atualiza Barra
                progressBar.style.width = data.percent + '%';
                progressBar.innerText = data.percent + '%';
                progressText.innerText = 'Enviando pacote de e-mails...';
                progressCount.innerText = `${data.processed} / ${total}`;
                
                // Próximo lote
                processBatch(data.offset, total);
                
            } else if (data.status === 'done') {
                // Fim
                progressBar.style.width = '100%';
                progressBar.innerText = '100%';
                progressBar.style.background = '#2ecc71';
                progressText.innerHTML = '<span class="status-complete"><i class="fas fa-check-circle"></i> Envio Concluído com Sucesso!</span>';
                progressCount.innerText = `${total} / ${total}`;
                
                btn.innerHTML = '<i class="fas fa-check"></i> Enviado';
                alert('Processo finalizado com sucesso!');
                
                setTimeout(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Iniciar Novo Envio';
                }, 3000);
            }
        })
        .catch(error => {
            console.error(error);
            progressText.innerText = 'Erro de conexão. Tentando novamente em 5s...';
            setTimeout(() => processBatch(offset, total), 5000);
        });
    }
});
</script>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>