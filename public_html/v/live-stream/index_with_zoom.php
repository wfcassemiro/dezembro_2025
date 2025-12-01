<?php
/**
 * Página Principal com Integração Zoom
 * Versão atualizada do index.php com suporte para reuniões do Zoom
 */

session_start();
require_once __DIR__ . '/../../config/database.php';
require_once 'zoom_functions.php';

// Funções de verificação de acesso
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function hasVideotecaAccess() {
    return isLoggedIn();
}

// Verificar acesso
if (!isLoggedIn() || !hasVideotecaAccess()) {
    header('Location: planos.php');
    exit;
}

$page_title = "Transmissão Ao Vivo";
$page_description = "Assista palestras e participe de reuniões ao vivo";

// Buscar código de embed padrão (fallback)
$live_embed_code = '';
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'live_embed_code'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) {
        $live_embed_code = $row['setting_value'];
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar live_embed_code: " . $e->getMessage());
}

// Verificar se há reunião do Zoom acontecendo agora
$currentZoomMeeting = getCurrentMeeting();

// Se houver reunião do Zoom ativa, usar ela ao invés do embed padrão
if ($currentZoomMeeting) {
    $is_live_active = true;
    $meeting_type = 'zoom';
} else {
    $is_live_active = !empty(trim($live_embed_code));
    $meeting_type = 'embed';
}

$current_user_is_admin = isAdmin();

// Buscar próximas palestras/reuniões
$upcomingLectures = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM upcoming_announcements
        WHERE is_active = 1 
        AND announcement_date >= CURDATE()
        ORDER BY announcement_date ASC, display_order ASC
        LIMIT 3
    ");
    $stmt->execute();
    $upcomingLectures = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("Erro ao buscar palestras: " . $e->getMessage());
}

// Buscar próximas reuniões do Zoom
$upcomingZoomMeetings = getActiveMeetingsFromDatabase(5);

// Incluir cabeçalho e navegação
include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<style>
    :root {
        --brand-purple: #667eea;
        --brand-purple-dark: #5a67d8;
        --glass-bg: rgba(255, 255, 255, 0.1);
        --glass-border: rgba(255, 255, 255, 0.2);
    }
    
    .glass-hero {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.9) 0%, rgba(118, 75, 162, 0.9) 100%);
        padding: 60px 40px;
        text-align: center;
        color: white;
        margin-bottom: 40px;
        border-radius: 20px;
        backdrop-filter: blur(10px);
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
    }
    
    .hero-content h1 {
        font-size: 48px;
        font-weight: 700;
        margin-bottom: 15px;
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
    }
    
    .hero-content p {
        font-size: 20px;
        opacity: 0.95;
    }
    
    .live-status {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: rgba(255, 255, 255, 0.2);
        padding: 12px 24px;
        border-radius: 30px;
        font-weight: 600;
        margin-top: 20px;
        backdrop-filter: blur(10px);
    }
    
    .live-status.active::before {
        content: '';
        width: 12px;
        height: 12px;
        background: #ff4444;
        border-radius: 50%;
        animation: pulse 1.5s infinite;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.5; transform: scale(1.2); }
    }
    
    .live-container {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
        margin-bottom: 40px;
    }
    
    .player-card, .chat-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 30px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
    }
    
    .live-player {
        position: relative;
        width: 100%;
        padding-bottom: 56.25%;
        background: #000;
        border-radius: 15px;
        overflow: hidden;
    }
    
    .live-player iframe {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        border: none;
    }
    
    .zoom-embed-container {
        width: 100%;
        height: 600px;
        border-radius: 15px;
        overflow: hidden;
        background: #000;
    }
    
    .meeting-info-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px;
        border-radius: 15px;
        margin-bottom: 20px;
    }
    
    .meeting-info-card h3 {
        font-size: 24px;
        margin-bottom: 10px;
    }
    
    .meeting-info-item {
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 8px 0;
        font-size: 14px;
    }
    
    .join-button {
        display: inline-block;
        background: white;
        color: #667eea;
        padding: 15px 30px;
        border-radius: 10px;
        text-decoration: none;
        font-weight: 600;
        margin-top: 15px;
        transition: all 0.3s ease;
    }
    
    .join-button:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }
    
    .player-controls {
        display: flex;
        gap: 10px;
        margin-top: 15px;
    }
    
    .control-btn {
        background: var(--brand-purple);
        color: white;
        border: none;
        padding: 10px 20px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    
    .control-btn:hover {
        background: var(--brand-purple-dark);
        transform: translateY(-2px);
    }
    
    .offline-player {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 400px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border-radius: 15px;
        color: white;
        text-align: center;
    }
    
    .offline-content h3 {
        font-size: 28px;
        margin-bottom: 15px;
    }
    
    .chat-messages {
        height: 400px;
        overflow-y: auto;
        padding: 15px;
        background: #f7fafc;
        border-radius: 10px;
        margin-bottom: 20px;
    }
    
    .chat-message {
        background: white;
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 10px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
    }
    
    .chat-input-container {
        display: flex;
        gap: 10px;
    }
    
    .chat-input-container input {
        flex: 1;
        padding: 12px;
        border: 2px solid #e2e8f0;
        border-radius: 8px;
        font-size: 14px;
    }
    
    .chat-input-container button {
        background: var(--brand-purple);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-weight: 600;
    }
    
    .schedule-card {
        background: rgba(255, 255, 255, 0.95);
        border-radius: 20px;
        padding: 40px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
        margin-top: 40px;
    }
    
    .schedule-card h2 {
        font-size: 32px;
        color: #2d3748;
        margin-bottom: 30px;
        text-align: center;
    }
    
    .zoom-meetings-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        margin-top: 30px;
    }
    
    .zoom-meeting-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 25px;
        border-radius: 15px;
        transition: all 0.3s ease;
    }
    
    .zoom-meeting-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
    }
    
    .zoom-meeting-card h4 {
        font-size: 20px;
        margin-bottom: 15px;
    }
    
    .zoom-meeting-info {
        font-size: 14px;
        margin: 8px 0;
        opacity: 0.9;
    }
    
    @media (max-width: 1024px) {
        .live-container {
            grid-template-columns: 1fr;
        }
    }
    
    @media (max-width: 768px) {
        .hero-content h1 {
            font-size: 32px;
        }
        
        .zoom-meetings-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<section class="glass-hero">
    <div class="hero-content">
        <h1><?php echo htmlspecialchars($page_title); ?></h1>
        <p><?php echo htmlspecialchars($page_description); ?></p>
        
        <?php if ($is_live_active): ?>
            <div class="live-status active">
                <span>AO VIVO</span>
            </div>
        <?php else: ?>
            <div class="live-status">
                <span>OFFLINE</span>
            </div>
        <?php endif; ?>
    </div>
</section>

<div class="live-container">
    <div class="player-section">
        <div class="player-card">
            <?php if ($is_live_active): ?>
                <?php if ($meeting_type === 'zoom' && $currentZoomMeeting): ?>
                    <!-- Informações da Reunião Zoom -->
                    <div class="meeting-info-card">
                        <h3><?php echo htmlspecialchars($currentZoomMeeting['topic']); ?></h3>
                        
                        <div class="meeting-info-item">
                            <span>🕐</span>
                            <span><?php echo date('d/m/Y \à\s H:i', strtotime($currentZoomMeeting['start_time'])); ?></span>
                        </div>
                        
                        <div class="meeting-info-item">
                            <span>⏱️</span>
                            <span><?php echo $currentZoomMeeting['duration']; ?> minutos</span>
                        </div>
                        
                        <?php if (!empty($currentZoomMeeting['agenda'])): ?>
                            <div class="meeting-info-item">
                                <span>📋</span>
                                <span><?php echo htmlspecialchars($currentZoomMeeting['agenda']); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <a href="<?php echo htmlspecialchars($currentZoomMeeting['join_url']); ?>" 
                           target="_blank" 
                           class="join-button">
                            🚀 Entrar na Reunião
                        </a>
                    </div>
                    
                    <!-- Zoom Web SDK Embed -->
                    <div class="zoom-embed-container">
                        <iframe 
                            src="<?php echo htmlspecialchars($currentZoomMeeting['join_url']); ?>" 
                            allow="microphone; camera; fullscreen"
                            style="width: 100%; height: 100%; border: none;">
                        </iframe>
                    </div>
                    
                <?php else: ?>
                    <!-- Embed Code Padrão -->
                    <div class="live-player">
                        <?php echo $live_embed_code; ?>
                    </div>
                <?php endif; ?>
                
                <div class="player-controls">
                    <button class="control-btn" onclick="toggleFullscreen()">
                        🖥️ Tela Cheia
                    </button>
                </div>
                
            <?php else: ?>
                <!-- Player Offline -->
                <div class="offline-player">
                    <div class="offline-content">
                        <div style="font-size: 64px; margin-bottom: 20px;">📡</div>
                        <h3>Transmissão Offline</h3>
                        <p>Não há transmissão ao vivo no momento</p>
                        <p style="margin-top: 15px; opacity: 0.9;">
                            Confira as próximas reuniões agendadas abaixo
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="chat-section">
        <div class="chat-card">
            <h3 style="margin-bottom: 20px; color: #2d3748;">💬 Chat ao Vivo</h3>
            
            <div class="chat-messages" id="chatMessagesContainer">
                <div class="chat-message">
                    <strong>Sistema</strong>
                    <p>Bem-vindo ao chat! Seja respeitoso com outros participantes.</p>
                </div>
            </div>
            
            <?php if ($is_live_active): ?>
                <div class="chat-input-container">
                    <input type="text" 
                           id="chatInput" 
                           placeholder="Digite sua mensagem..." 
                           maxlength="500">
                    <button onclick="sendMessage()">Enviar</button>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #a0aec0;">
                    Chat disponível apenas durante transmissões ao vivo
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Próximas Reuniões do Zoom -->
<?php if (!empty($upcomingZoomMeetings)): ?>
<div class="schedule-card">
    <h2>📅 Próximas Reuniões Zoom</h2>
    
    <div class="zoom-meetings-grid">
        <?php foreach ($upcomingZoomMeetings as $meeting): ?>
            <div class="zoom-meeting-card">
                <h4><?php echo htmlspecialchars($meeting['topic']); ?></h4>
                
                <div class="zoom-meeting-info">
                    <strong>📅 Data:</strong> 
                    <?php echo date('d/m/Y', strtotime($meeting['start_time'])); ?>
                </div>
                
                <div class="zoom-meeting-info">
                    <strong>🕐 Horário:</strong> 
                    <?php echo date('H:i', strtotime($meeting['start_time'])); ?>
                </div>
                
                <div class="zoom-meeting-info">
                    <strong>⏱️ Duração:</strong> 
                    <?php echo $meeting['duration']; ?> minutos
                </div>
                
                <?php if (!empty($meeting['agenda'])): ?>
                    <div class="zoom-meeting-info" style="margin-top: 10px;">
                        <?php echo htmlspecialchars($meeting['agenda']); ?>
                    </div>
                <?php endif; ?>
                
                <a href="<?php echo htmlspecialchars($meeting['join_url']); ?>" 
                   target="_blank" 
                   class="join-button" 
                   style="margin-top: 15px; display: inline-block;">
                    Acessar Reunião
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<script>
function sendMessage() {
    const input = document.getElementById('chatInput');
    const message = input.value.trim();
    
    if (!message) return;
    
    // Adicionar mensagem ao chat
    const container = document.getElementById('chatMessagesContainer');
    const messageDiv = document.createElement('div');
    messageDiv.className = 'chat-message';
    messageDiv.innerHTML = `
        <strong>Você</strong>
        <p>${escapeHtml(message)}</p>
        <small style="color: #a0aec0;">${new Date().toLocaleTimeString('pt-BR')}</small>
    `;
    
    container.appendChild(messageDiv);
    container.scrollTop = container.scrollHeight;
    
    input.value = '';
    
    // Aqui você pode adicionar lógica para salvar no banco de dados
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function toggleFullscreen() {
    const player = document.querySelector('.live-player, .zoom-embed-container');
    
    if (player.requestFullscreen) {
        player.requestFullscreen();
    } else if (player.webkitRequestFullscreen) {
        player.webkitRequestFullscreen();
    } else if (player.msRequestFullscreen) {
        player.msRequestFullscreen();
    }
}

// Permitir envio com Enter
document.getElementById('chatInput')?.addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        sendMessage();
    }
});
</script>

<?php include __DIR__ . '/../vision/includes/footer.php'; ?>
