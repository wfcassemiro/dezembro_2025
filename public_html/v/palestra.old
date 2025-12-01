<?php
session_start();
require_once 'config/database.php';

// Verificar se o usuário tem permissão
if (!hasVideotecaAccess()) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Palestra - Translators101';
$lecture_id = $_GET['id'] ?? null;
$user_id = $_SESSION['user_id'];

// Log customizado para debug
function writeToCustomLog($message) {
    $log_file = __DIR__ . '/certificate_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] [PALESTRA] $message\n", FILE_APPEND);
}

writeToCustomLog("DEBUG: Script palestra.php iniciado.");

if (!hasVideotecaAccess()) {
    writeToCustomLog("ERRO: Usuário sem permissão.");
    header('Location: /login.php');
    exit;
}

writeToCustomLog("DEBUG: Usuário tem permissão.");

if (!$lecture_id) {
    writeToCustomLog("ERRO: ID da palestra não fornecido.");
    header('Location: /videoteca.php');
    exit;
}

writeToCustomLog("DEBUG: ID da palestra recebido: $lecture_id");

// Buscar detalhes da palestra
try {
    $stmt = $pdo->prepare("SELECT * FROM lectures WHERE id = ?");
    $stmt->execute([$lecture_id]);
    $lecture = $stmt->fetch();
    
    if (!$lecture) {
        writeToCustomLog("ERRO: Palestra não encontrada com ID: $lecture_id");
        header('Location: /videoteca.php');
        exit;
    }
    
    writeToCustomLog("DEBUG: Palestra '" . $lecture['title'] . "' encontrada. Duração em minutos: " . $lecture['duration_minutes']);
    
} catch (Exception $e) {
    writeToCustomLog("ERRO: Exceção ao buscar palestra: " . $e->getMessage());
    header('Location: /videoteca.php');
    exit;
}

// Verificar se o usuário existe na tabela users
try {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch();
    
    if (!$user_data) {
        writeToCustomLog("AVISO: User ID da sessão ('$user_id') não existe na tabela 'users'. Logs podem não funcionar corretamente.");
    } else {
        writeToCustomLog("DEBUG: User ID da sessão ('$user_id') é válido e existe na tabela 'users'.");
    }
    
} catch (Exception $e) {
    writeToCustomLog("ERRO: Exceção ao verificar usuário: " . $e->getMessage());
}

// Registrar acesso e obter progresso inicial
$user_progress_seconds = 0;
try {
    // Verificar se já existe log para esta palestra
    $stmt = $pdo->prepare("SELECT last_watched_seconds FROM access_logs WHERE user_id = ? AND resource = ? AND action = 'view_lecture'");
    $stmt->execute([$user_id, $lecture['title']]);
    $existing_log = $stmt->fetch();
    
    if ($existing_log) {
        $user_progress_seconds = $existing_log['last_watched_seconds'] ?? 0;
        writeToCustomLog("DEBUG: Log existente encontrado. Progresso: {$user_progress_seconds}s");
    } else {
        // Registrar novo acesso
        $stmt = $pdo->prepare("INSERT INTO access_logs (user_id, action, resource, ip_address, user_agent, last_watched_seconds) VALUES (?, 'view_lecture', ?, ?, ?, 0)");
        $stmt->execute([
            $user_id,
            $lecture['title'],
            $_SERVER['REMOTE_ADDR'] ?? 'N/A',
            $_SERVER['HTTP_USER_AGENT'] ?? 'N/A'
        ]);
        writeToCustomLog("INFO: Novo log de acesso registrado para palestra '" . $lecture['title'] . "' (User ID: $user_id).");
    }
    
    writeToCustomLog("DEBUG: Progresso inicial carregado do DB (user_progress_seconds): $user_progress_seconds");
    
} catch (Exception $e) {
    writeToCustomLog("ERRO: Exceção no registro/obtenção de progresso: " . $e->getMessage());
}

// Buscar palestras relacionadas
$related_lectures = [];
try {
    if (!empty($lecture['category'])) {
        $stmt = $pdo->prepare("SELECT * FROM lectures WHERE category = ? AND id != ? LIMIT 3");
        $stmt->execute([$lecture['category'], $lecture_id]);
        $related_lectures = $stmt->fetchAll();
    }
    writeToCustomLog("DEBUG: Palestras relacionadas buscadas para categoria '" . ($lecture['category'] ?? '') . "'.");
} catch (Exception $e) {
    writeToCustomLog("ERRO: Exceção ao buscar palestras relacionadas: " . $e->getMessage());
}

// Verificar se já existe certificado
$existing_certificate_id = null;
try {
    $stmt = $pdo->prepare("SELECT id FROM certificates WHERE user_id = ? AND lecture_id = ?");
    $stmt->execute([$user_id, $lecture_id]);
    $existing_certificate = $stmt->fetch();
    if ($existing_certificate) {
        $existing_certificate_id = $existing_certificate['id'];
    }
    writeToCustomLog("DEBUG: Verificação de certificado existente para exibição inicial. ID existente: " . ($existing_certificate_id ?? 'Nenhum'));
} catch (Exception $e) {
    writeToCustomLog("ERRO: Exceção ao verificar certificado existente: " . $e->getMessage());
}

// Extrair ID do player do embed_code
$panda_player_id = null;
if (!empty($lecture['embed_code'])) {
    if (preg_match('/id=["\']([^"\']+)["\']/', $lecture['embed_code'], $matches)) {
        $panda_player_id = $matches[1];
    }
    writeToCustomLog("DEBUG: Panda Player Div ID extraído do embed: '$panda_player_id'");
}

include __DIR__ . '/vision/includes/head.php';
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<main class="main-content">
    <!-- Detalhes da Palestra -->
    <div class="video-card">
        <h2><i class="fas fa-play-circle"></i> Detalhes da Palestra</h2>
        
        <div class="lecture-details">
            <div class="detail-item">
                <i class="fas fa-user"></i>
                <span><strong>Palestrante:</strong></span>
                <span><?php echo htmlspecialchars($lecture['speaker']); ?></span>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-clock"></i>
                <span><strong>Duração:</strong></span>
                <span><?php echo $lecture['duration_minutes']; ?> min</span>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-tag"></i>
                <span><strong>Categoria:</strong></span>
                <span><?php echo htmlspecialchars($lecture['category'] ?? 'Geral'); ?></span>
            </div>
            
            <div class="detail-item">
                <i class="fas fa-calendar"></i>
                <span><strong>Publicado:</strong></span>
                <span><?php echo date('d/m/Y', strtotime($lecture['created_at'])); ?></span>
            </div>
        </div>
    </div>

    <!-- Player da Palestra -->
    <div class="video-card">
        <h1 style="margin-bottom: 20px;"><?php echo htmlspecialchars($lecture['title']); ?></h1>
        
        <div class="video-container">
            <?php echo $lecture['embed_code']; ?>
        </div>
        
        <?php if (!empty($lecture['description'])): ?>
        <div class="lecture-description">
            <h3><i class="fas fa-info-circle"></i> Descrição</h3>
            <p><?php echo nl2br(htmlspecialchars($lecture['description'])); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- Certificado de Conclusão -->
    <div class="video-card">
        <h2><i class="fas fa-certificate"></i> Certificado de Conclusão</h2>
        
        <div id="certificateStatus" class="certificate-status">
            <?php if ($existing_certificate_id): ?>
                <div class="alert-success">
                    <i class="fas fa-check-circle"></i> Você já possui certificado para esta palestra!
                    <div style="margin-top: 15px;">
                        <a href="/view_certificate_files.php?id=<?php echo $existing_certificate_id; ?>" class="cta-btn">
                            <i class="fas fa-eye"></i> Ver Certificado
                        </a>
                        <a href="/download_certificate_files.php?id=<?php echo $existing_certificate_id; ?>" class="cta-btn" style="margin-left: 10px;">
                            <i class="fas fa-download"></i> Baixar PDF
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div id="certificateMessage" class="alert-warning">
                    <i class="fas fa-hourglass-half"></i> Assista à palestra para habilitar a geração do certificado.
                </div>

                <button id="generateCertificateBtn" class="cta-btn" disabled style="opacity: 0.5; margin-top: 15px; cursor: not-allowed; background: #6b7280;">
                    <i class="fas fa-certificate"></i> Gerar Certificado
                </button>
                
                <p class="certificate-requirement">
                    <i class="fas fa-info-circle"></i> Requer 85% de visualização sequencial (anti-fraude ativo)
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Progresso Seguro de Visualização -->
    <div class="video-card">
        <h2><i class="fas fa-chart-line"></i> Progresso Seguro de Visualização</h2>
        
        <div id="progressText" class="progress-text">
            Carregando progresso...
        </div>
        
        <div class="progress-container">
            <div id="progressBar" class="progress-bar"></div>
        </div>
        
        <div class="anti-fraud-notice">
            <i class="fas fa-shield-alt"></i> Sistema anti-fraude ativo 
            <span id="autoStatus">🔄 Tentando auto-start...</span>
        </div>

        <!-- Controles Simples com Estilo Vision UI -->
        <div class="manual-controls">
            <h3><i class="fas fa-bolt"></i> Controles (Auto-start em andamento):</h3>
            
            <div class="controls-row">
                <button id="startSimpleTimer" class="vision-btn vision-btn-primary">
                    <i class="fas fa-play"></i> Iniciar Manualmente
                </button>
                
                <button id="resetSimpleTimer" class="vision-btn vision-btn-secondary">
                    <i class="fas fa-undo"></i> Reset
                </button>
                
                <div class="time-display">
                    <i class="fas fa-stopwatch"></i> Total: <span id="simpleTimerDisplay">0:00</span>
                </div>
            </div>
            
            <p class="controls-description">
                Sistema tentará iniciar automaticamente. Use controles se falhar.
            </p>
        </div>

        <!-- Status de Progresso -->
        <div class="progress-status">
            <div class="status-item">
                <span>Segmentos assistidos:</span>
                <span id="segmentsWatched">0/0</span>
            </div>
            <div class="status-item">
                <span>Progressão natural:</span>
                <span id="naturalProgress" class="status-ok"><i class="fas fa-check"></i> OK</span>
            </div>
        </div>
    </div>

    <!-- Palestras Relacionadas -->
    <?php if (!empty($related_lectures)): ?>
    <div class="video-card">
        <h2><i class="fas fa-list"></i> Palestras Relacionadas</h2>
        <div class="related-lectures">
            <?php foreach ($related_lectures as $related): ?>
            <div class="related-lecture">
                <h4><a href="/palestra.php?id=<?php echo $related['id']; ?>"><?php echo htmlspecialchars($related['title']); ?></a></h4>
                <p><i class="fas fa-user"></i> <?php echo htmlspecialchars($related['speaker']); ?></p>
                <p><i class="fas fa-clock"></i> <?php echo $related['duration_minutes']; ?> min</p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</main>

<style>
/* Estilos específicos da página de palestra */
.lecture-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 15px;
    margin: 20px 0;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    border: 1px solid rgba(142, 68, 173, 0.3);
}

.detail-item i {
    color: var(--brand-purple);
    width: 20px;
}

.video-container {
    position: relative;
    width: 100%;
    background: rgba(0, 0, 0, 0.5);
    border-radius: 15px;
    overflow: hidden;
    margin: 20px 0;
}

.lecture-description {
    margin-top: 20px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    border-left: 4px solid var(--brand-purple);
}

.certificate-status {
    padding: 20px;
    border-radius: 10px;
    margin: 20px 0;
}

.certificate-requirement {
    margin-top: 15px;
    font-size: 0.9em;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 8px;
}

.progress-text {
    font-size: 1.1em;
    color: var(--text-light);
    margin: 15px 0;
    font-weight: 500;
}

.progress-container {
    width: 100%;
    height: 12px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 6px;
    overflow: hidden;
    margin: 20px 0;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--brand-purple), #bd72e8);
    width: 0%;
    transition: width 0.5s ease;
    border-radius: 6px;
}

.anti-fraud-notice {
    background: rgba(241, 196, 15, 0.1);
    color: #f1c40f;
    padding: 12px;
    border-radius: 8px;
    margin: 15px 0;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid rgba(241, 196, 15, 0.3);
}

.manual-controls {
    margin: 25px 0;
    padding: 20px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    border: 1px solid rgba(142, 68, 173, 0.3);
}

.controls-row {
    display: flex;
    align-items: center;
    gap: 15px;
    margin: 15px 0;
    flex-wrap: wrap;
}

.vision-btn {
    padding: 12px 20px;
    border: none;
    border-radius: 8px;
    font-family: 'SF Pro Display', -apple-system, BlinkMacSystemFont, sans-serif;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.vision-btn-primary {
    background: linear-gradient(135deg, var(--brand-purple), #bd72e8);
    color: white;
}

.vision-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(142, 68, 173, 0.4);
}

.vision-btn-secondary {
    background: rgba(108, 117, 125, 0.8);
    color: white;
}

.vision-btn-secondary:hover {
    background: rgba(108, 117, 125, 1);
    transform: translateY(-2px);
}

.time-display {
    background: rgba(0, 0, 0, 0.3);
    padding: 10px 15px;
    border-radius: 8px;
    color: var(--text-light);
    font-family: 'SF Mono', Monaco, monospace;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.controls-description {
    color: var(--text-muted);
    font-size: 0.9em;
    margin-top: 10px;
    font-style: italic;
}

.progress-status {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
    margin: 20px 0;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 8px;
}

.status-ok {
    color: #2ecc71;
    font-weight: 500;
}

.related-lectures {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.related-lecture {
    padding: 20px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 10px;
    border: 1px solid rgba(142, 68, 173, 0.3);
    transition: transform 0.3s ease;
}

.related-lecture:hover {
    transform: translateY(-5px);
    border-color: var(--brand-purple);
}

.related-lecture h4 a {
    color: var(--text-light);
    text-decoration: none;
}

.related-lecture h4 a:hover {
    color: var(--brand-purple);
}

.related-lecture p {
    margin: 8px 0;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 8px;
}

@media (max-width: 768px) {
    .lecture-details {
        grid-template-columns: 1fr;
    }
    
    .controls-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .vision-btn {
        justify-content: center;
    }
    
    .progress-status {
        grid-template-columns: 1fr;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Constantes do sistema
    const LECTURE_ID = <?php echo json_encode($lecture_id); ?>;
    const USER_ID = <?php echo json_encode($user_id); ?>;
    const LECTURE_DURATION_SECONDS = <?php echo ($lecture['duration_minutes'] ?? 0) * 60; ?>;
    const REQUIRED_WATCH_SECONDS = Math.floor(LECTURE_DURATION_SECONDS * 0.85);
    const USER_INITIAL_PROGRESS = <?php echo $user_progress_seconds; ?>;
    const EXISTING_CERTIFICATE_ID = <?php echo json_encode($existing_certificate_id); ?>;
    const PANDA_PLAYER_ID = <?php echo json_encode($panda_player_id); ?>;

    console.log('Sistema iniciado:', {
        LECTURE_ID,
        USER_ID,
        LECTURE_DURATION_SECONDS,
        REQUIRED_WATCH_SECONDS,
        USER_INITIAL_PROGRESS,
        EXISTING_CERTIFICATE_ID,
        PANDA_PLAYER_ID
    });

    // Sistema de tracking
    const sessionTracker = {
        totalWatchedSeconds: USER_INITIAL_PROGRESS,
        lastSaveTime: Date.now(),
        isTracking: false,
        simpleTimerStart: null,
        simpleTimerSeconds: 0,
        autoStartAttempted: false
    };

    // Elementos DOM
    const progressText = document.getElementById('progressText');
    const progressBar = document.getElementById('progressBar');
    const certificateMessage = document.getElementById('certificateMessage');
    const generateBtn = document.getElementById('generateCertificateBtn');
    const startSimpleBtn = document.getElementById('startSimpleTimer');
    const resetSimpleBtn = document.getElementById('resetSimpleTimer');
    const simpleTimerDisplay = document.getElementById('simpleTimerDisplay');
    const segmentsWatched = document.getElementById('segmentsWatched');
    const naturalProgress = document.getElementById('naturalProgress');
    const autoStatus = document.getElementById('autoStatus');

    // Inicializar interface
    updateProgressDisplay();
    
    // Tentar auto-start após 3 segundos
    setTimeout(function() {
        attemptAutoStart();
    }, 3000);
    
    function attemptAutoStart() {
        if (sessionTracker.autoStartAttempted || EXISTING_CERTIFICATE_ID) return;
        
        sessionTracker.autoStartAttempted = true;
        
        // Tentar detectar Panda Player ou qualquer vídeo
        const playerIds = [PANDA_PLAYER_ID, 'panda-player', 'video-player', 'player'];
        let playerFound = false;
        
        for (const id of playerIds) {
            if (id && document.getElementById(id)) {
                console.log('Player encontrado:', id);
                playerFound = true;
                break;
            }
        }
        
        if (playerFound || document.querySelector('video, iframe[src*="player"], [id*="player"], [class*="player"]')) {
            // Auto-start o tracking
            sessionTracker.isTracking = true;
            sessionTracker.simpleTimerStart = Date.now();
            
            if (autoStatus) {
                autoStatus.innerHTML = '✅ Auto-start ativo';
                autoStatus.style.color = '#2ecc71';
            }
            
            if (startSimpleBtn) {
                startSimpleBtn.innerHTML = '<i class="fas fa-pause"></i> Pausar (Auto)';
            }
            
            console.log('🎬 AUTO-START ATIVADO');
        } else {
            if (autoStatus) {
                autoStatus.innerHTML = '⚠️ Auto-start falhou - Use controles manuais';
                autoStatus.style.color = '#f39c12';
            }
            console.log('❌ Auto-start falhou - player não detectado');
        }
    }
    
    // Controles simples
    if (startSimpleBtn) {
        startSimpleBtn.addEventListener('click', function() {
            if (!sessionTracker.isTracking) {
                sessionTracker.isTracking = true;
                sessionTracker.simpleTimerStart = Date.now();
                startSimpleBtn.innerHTML = '<i class="fas fa-pause"></i> Pausar Cronômetro';
                
                if (autoStatus) {
                    autoStatus.innerHTML = '✅ Manual ativo';
                    autoStatus.style.color = '#2ecc71';
                }
                console.log('Cronômetro manual iniciado');
            } else {
                sessionTracker.isTracking = false;
                startSimpleBtn.innerHTML = '<i class="fas fa-play"></i> Continuar Cronômetro';
                
                if (autoStatus) {
                    autoStatus.innerHTML = '⏸️ Pausado';
                    autoStatus.style.color = '#f39c12';
                }
                console.log('Cronômetro pausado');
            }
        });
    }

    if (resetSimpleBtn) {
        resetSimpleBtn.addEventListener('click', function() {
            sessionTracker.isTracking = false;
            sessionTracker.simpleTimerStart = null;
            sessionTracker.simpleTimerSeconds = 0;
            sessionTracker.totalWatchedSeconds = USER_INITIAL_PROGRESS;
            
            if (startSimpleBtn) {
                startSimpleBtn.innerHTML = '<i class="fas fa-play"></i> Iniciar Cronômetro';
            }
            
            if (autoStatus) {
                autoStatus.innerHTML = '🔄 Reset - Pronto para iniciar';
                autoStatus.style.color = '#3498db';
            }
            
            updateProgressDisplay();
            console.log('Cronômetro resetado');
        });
    }

    // Botão gerar certificado
    if (generateBtn && !EXISTING_CERTIFICATE_ID) {
        generateBtn.addEventListener('click', function() {
            if (sessionTracker.totalWatchedSeconds >= REQUIRED_WATCH_SECONDS) {
                generateCertificate();
            } else {
                alert('Tempo insuficiente assistido. Continue assistindo à palestra.');
            }
        });
    }

    // Loop principal
    setInterval(function() {
        // Cronômetro simples
        if (sessionTracker.isTracking && sessionTracker.simpleTimerStart) {
            const elapsed = Math.floor((Date.now() - sessionTracker.simpleTimerStart) / 1000);
            sessionTracker.simpleTimerSeconds = elapsed;
            sessionTracker.totalWatchedSeconds = USER_INITIAL_PROGRESS + elapsed;
            
            if (simpleTimerDisplay) {
                simpleTimerDisplay.textContent = formatTime(sessionTracker.simpleTimerSeconds);
            }
            
            updateProgressDisplay();
            
            // Salvar progresso a cada 10 segundos
            if (Date.now() - sessionTracker.lastSaveTime > 10000) {
                saveProgress();
                sessionTracker.lastSaveTime = Date.now();
            }
        }
        
        // Limitar o progresso ao máximo da duração do vídeo
        if (sessionTracker.totalWatchedSeconds > LECTURE_DURATION_SECONDS) {
            sessionTracker.totalWatchedSeconds = LECTURE_DURATION_SECONDS;
        }
        
    }, 1000);

    function updateProgressDisplay() {
        if (!progressText || !progressBar) return;

        const totalPercentageOfVideo = Math.min((sessionTracker.totalWatchedSeconds / LECTURE_DURATION_SECONDS) * 100, 100);
        const certificatePercentage = (sessionTracker.totalWatchedSeconds / REQUIRED_WATCH_SECONDS) * 100;
        
        const minutes = Math.floor(sessionTracker.totalWatchedSeconds / 60);
        const seconds = sessionTracker.totalWatchedSeconds % 60;
        const totalMinutes = Math.floor(LECTURE_DURATION_SECONDS / 60);
        const totalSecondsCalc = LECTURE_DURATION_SECONDS % 60;
        
        progressText.textContent = `Tempo Sequencial ${totalPercentageOfVideo.toFixed(1)}% assistido (${minutes}:${seconds.toString().padStart(2, '0')} / ${totalMinutes}:${totalSecondsCalc.toString().padStart(2, '0')}) - Certificado aos 85%`;
        progressBar.style.width = `${Math.min(certificatePercentage, 100)}%`;
        
        // Cores da barra
        if (certificatePercentage >= 100) {
            progressBar.className = "progress-bar";
            progressBar.style.background = "linear-gradient(90deg, #2ecc71, #27ae60)";
        } else {
            progressBar.className = "progress-bar";
            progressBar.style.background = "linear-gradient(90deg, var(--brand-purple), #bd72e8)";
        }

        // Atualizar botão de certificado
        if (certificateMessage && generateBtn) {
            if (sessionTracker.totalWatchedSeconds >= REQUIRED_WATCH_SECONDS) {
                generateBtn.disabled = false;
                generateBtn.style.opacity = "1";
                generateBtn.style.cursor = "pointer";
                generateBtn.style.background = "linear-gradient(135deg, var(--brand-purple), #bd72e8)";
                certificateMessage.innerHTML = '<i class="fas fa-check-circle"></i> 🎉 Certificado disponível! (85% completados)';
                certificateMessage.className = "alert-success";
            } else {
                const remaining = REQUIRED_WATCH_SECONDS - sessionTracker.totalWatchedSeconds;
                const remainingMinutes = Math.ceil(remaining / 60);
                generateBtn.disabled = true;
                generateBtn.style.opacity = "0.5";
                generateBtn.style.cursor = "not-allowed";
                generateBtn.style.background = "#6b7280";
                certificateMessage.innerHTML = `<i class="fas fa-hourglass-half"></i> Assista mais ${remainingMinutes} min para habilitar certificado (${remaining}s restantes)`;
                certificateMessage.className = "alert-warning";
            }
        }

        // Atualizar status
        if (segmentsWatched) {
            const watchedSegments = Math.floor(totalPercentageOfVideo / 10);
            const totalSegments = 10;
            segmentsWatched.textContent = `${watchedSegments}/${totalSegments}`;
        }
    }

    function saveProgress() {
        fetch('/config/api/save_simple_progress.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lecture_id: LECTURE_ID,
                user_id: USER_ID,
                watched_seconds: sessionTracker.totalWatchedSeconds
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Progresso salvo:', sessionTracker.totalWatchedSeconds + 's');
            } else {
                console.error('Erro ao salvar progresso:', data.message);
            }
        })
        .catch(error => {
            console.error('Erro na requisição de salvamento:', error);
        });
    }

    function generateCertificate() {
        generateBtn.disabled = true;
        generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Gerando T101...';
        
        fetch('/generate_simple_certificate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                lecture_id: LECTURE_ID,
                user_id: USER_ID,
                watched_seconds: sessionTracker.totalWatchedSeconds
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Atualizar a interface com botões de acesso
                certificateMessage.innerHTML = `
                    <div class="alert-success">
                        <i class="fas fa-check-circle"></i> ${data.message}
                        <div style="margin-top: 15px;">
                            <a href="${data.certificate_url || '/view_certificate_files.php?id=' + data.certificate_id}" class="cta-btn">
                                <i class="fas fa-eye"></i> Ver Certificado PNG
                            </a>
                            <a href="${data.download_url || '/download_certificate_files.php?id=' + data.certificate_id}" class="cta-btn" style="margin-left: 10px;">
                                <i class="fas fa-download"></i> Baixar PDF
                            </a>
                            <a href="/perfil.php" class="cta-btn" style="margin-left: 10px; background: linear-gradient(135deg, #2ecc71, #27ae60);">
                                <i class="fas fa-user"></i> Ver no Perfil
                            </a>
                        </div>
                        <div style="margin-top: 10px; color: var(--text-muted); font-size: 0.9em;">
                            <i class="fas fa-info-circle"></i> Certificado salvo automaticamente no seu perfil
                        </div>
                    </div>
                `;
                generateBtn.style.display = 'none';
                
                console.log('🎉 Certificado gerado com sucesso!', data);
            } else {
                alert('❌ Erro: ' + data.message);
                generateBtn.disabled = false;
                generateBtn.innerHTML = '<i class="fas fa-certificate"></i> Gerar Certificado';
            }
        })
        .catch(error => {
            alert('❌ Erro inesperado. Tente novamente.');
            generateBtn.disabled = false;
            generateBtn.innerHTML = '<i class="fas fa-certificate"></i> Gerar Certificado';
            console.error('Erro:', error);
        });
    }

    function formatTime(seconds) {
        const mins = Math.floor(seconds / 60);
        const secs = seconds % 60;
        return `${mins}:${secs.toString().padStart(2, '0')}`;
    }
});
</script>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>