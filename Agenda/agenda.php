<?php
/**
 * Página Pública - Agenda de Palestras
 * Translators101 - Topo de Funil
 * 
 * Esta página exibe todas as palestras agendadas e permite
 * captura de leads através de formulário.
 */

session_start();
date_default_timezone_set('America/Sao_Paulo');

// Inclui o arquivo de configuração do banco de dados
require_once __DIR__ . '/../config/database.php';

// Configurações da página
$page_title = 'Agenda de Palestras - Translators101';
$page_description = 'Confira todas as próximas palestras gratuitas sobre tradução e interpretação. Cadastre-se para receber notificações e participar do sorteio mensal!';

// Variáveis de mensagem
$success_message = '';
$error_message = '';

// Processar formulário de lead
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cadastrar_lead') {
    $nome = trim($_POST['nome'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $whatsapp = trim($_POST['whatsapp'] ?? '');
    
    // Validação básica
    if (empty($nome) || empty($email) || empty($whatsapp)) {
        $error_message = 'Por favor, preencha todos os campos.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Por favor, insira um email válido.';
    } else {
        try {
            // Verificar se o email já está cadastrado
            $stmt_check = $pdo->prepare("SELECT id FROM leads WHERE email = ?");
            $stmt_check->execute([$email]);
            
            if ($stmt_check->fetch()) {
                $error_message = 'Este email já está cadastrado. Você já está participando!';
            } else {
                // Inserir novo lead
                $lead_id = uniqid('lead_', true);
                $stmt = $pdo->prepare("
                    INSERT INTO leads (id, nome, email, whatsapp, fonte, created_at) 
                    VALUES (?, ?, ?, ?, 'agenda_palestras', NOW())
                ");
                $stmt->execute([$lead_id, $nome, $email, $whatsapp]);
                
                $success_message = 'Cadastro realizado com sucesso! Você receberá notificações pelo WhatsApp caso seja o sorteado do mês. Boa sorte!';
            }
        } catch (PDOException $e) {
            error_log("Erro ao cadastrar lead: " . $e->getMessage());
            $error_message = 'Ocorreu um erro ao processar seu cadastro. Por favor, tente novamente.';
        }
    }
}

// Buscar todas as palestras agendadas futuras
$upcomingLectures = [];
try {
    $stmt = $pdo->query("
        SELECT id, title, speaker, description, image_path, announcement_date, lecture_time
        FROM upcoming_announcements
        WHERE is_active = 1
        AND CONCAT(announcement_date, ' ', lecture_time) >= NOW()
        ORDER BY announcement_date ASC, lecture_time ASC
    ");
    $upcomingLectures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Erro ao buscar palestras: " . $e->getMessage());
    $upcomingLectures = [];
}

// Incluir o cabeçalho
include __DIR__ . '/../vision/includes/head.php';
?>

<style>
/* === ESTILOS DA PÁGINA AGENDA === */

/* Hero Section */
.agenda-hero {
    background: linear-gradient(135deg, rgba(142, 68, 173, 0.9), rgba(44, 62, 80, 0.95));
    padding: 60px 20px;
    text-align: center;
    margin-bottom: 40px;
    border-radius: 16px;
}

.agenda-hero h1 {
    font-size: 2.5rem;
    color: #fff;
    margin-bottom: 15px;
    text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
}

.agenda-hero p {
    font-size: 1.2rem;
    color: rgba(255,255,255,0.9);
    max-width: 700px;
    margin: 0 auto;
}

/* Grid de Palestras */
.palestras-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 30px;
    margin-bottom: 60px;
}

@media (max-width: 768px) {
    .palestras-grid {
        grid-template-columns: 1fr;
    }
}

/* Card de Palestra */
.palestra-card {
    background: var(--glass-bg, rgba(255, 255, 255, 0.1));
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border, rgba(255, 255, 255, 0.2));
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
}

.palestra-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
    border-color: var(--brand-purple, #8e44ad);
}

.palestra-image {
    width: 100%;
    height: 200px;
    object-fit: cover;
    background: linear-gradient(135deg, var(--brand-purple, #8e44ad), #5e3370);
}

.palestra-placeholder {
    width: 100%;
    height: 200px;
    background: linear-gradient(135deg, var(--brand-purple, #8e44ad), #5e3370);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: rgba(255,255,255,0.8);
}

.palestra-placeholder i {
    font-size: 3rem;
    margin-bottom: 10px;
}

.palestra-content {
    padding: 25px;
}

.palestra-date {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: linear-gradient(135deg, #e74c3c, #c0392b);
    color: #fff;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 0.9rem;
    font-weight: 600;
    margin-bottom: 15px;
}

.palestra-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #fff;
    margin-bottom: 10px;
    line-height: 1.3;
}

.palestra-speaker {
    color: var(--brand-purple, #8e44ad);
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.palestra-description {
    color: rgba(255,255,255,0.8);
    font-size: 0.95rem;
    line-height: 1.6;
    margin-bottom: 20px;
}

/* Botões de Calendário */
.calendar-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

.calendar-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border-radius: 30px;
    font-size: 0.9rem;
    font-weight: 600;
    text-decoration: none;
    cursor: pointer;
    transition: all 0.3s ease;
    border: none;
}

.calendar-btn.google {
    background: linear-gradient(135deg, #4285f4, #34a853);
    color: #fff;
}

.calendar-btn.google:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(66, 133, 244, 0.4);
}

.calendar-btn.apple {
    background: linear-gradient(135deg, #333, #555);
    color: #fff;
}

.calendar-btn.apple:hover {
    transform: scale(1.05);
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
}

.calendar-btn.clicked {
    background: linear-gradient(135deg, #27ae60, #2ecc71) !important;
}

/* Seção CTA Lead */
.lead-section {
    background: linear-gradient(135deg, rgba(231, 76, 60, 0.15), rgba(142, 68, 173, 0.15));
    border: 2px solid rgba(231, 76, 60, 0.3);
    border-radius: 20px;
    padding: 50px 30px;
    text-align: center;
    margin: 60px 0;
}

.lead-section h2 {
    font-size: 2rem;
    color: #fff;
    margin-bottom: 15px;
}

.lead-section .subtitle {
    font-size: 1.1rem;
    color: rgba(255,255,255,0.85);
    margin-bottom: 10px;
}

.lead-section .prize-text {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #f39c12, #e74c3c);
    color: #fff;
    padding: 12px 25px;
    border-radius: 30px;
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 30px;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}

/* Formulário de Lead */
.lead-form {
    max-width: 500px;
    margin: 0 auto;
}

.form-group {
    margin-bottom: 20px;
    text-align: left;
}

.form-group label {
    display: block;
    color: rgba(255,255,255,0.9);
    font-weight: 600;
    margin-bottom: 8px;
}

.form-group input {
    width: 100%;
    padding: 15px 20px;
    border-radius: 12px;
    border: 2px solid rgba(255,255,255,0.2);
    background: rgba(255,255,255,0.1);
    color: #fff;
    font-size: 1rem;
    transition: all 0.3s ease;
}

.form-group input:focus {
    outline: none;
    border-color: var(--brand-purple, #8e44ad);
    background: rgba(255,255,255,0.15);
}

.form-group input::placeholder {
    color: rgba(255,255,255,0.5);
}

.submit-btn {
    width: 100%;
    padding: 18px;
    background: linear-gradient(135deg, var(--brand-purple, #8e44ad), #9b59b6);
    color: #fff;
    border: none;
    border-radius: 12px;
    font-size: 1.1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.submit-btn:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(142, 68, 173, 0.4);
}

/* Mensagens de Alerta */
.alert {
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 15px;
    animation: slideIn 0.5s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.alert.success {
    background: rgba(39, 174, 96, 0.2);
    border: 2px solid #27ae60;
    color: #2ecc71;
}

.alert.error {
    background: rgba(231, 76, 60, 0.2);
    border: 2px solid #e74c3c;
    color: #e74c3c;
}

.alert i {
    font-size: 1.5rem;
}

.alert-content {
    flex: 1;
}

/* Estado vazio */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: var(--glass-bg, rgba(255, 255, 255, 0.1));
    border-radius: 16px;
    margin-bottom: 40px;
}

.empty-state i {
    font-size: 4rem;
    color: rgba(255,255,255,0.3);
    margin-bottom: 20px;
}

.empty-state h3 {
    color: #fff;
    font-size: 1.5rem;
    margin-bottom: 10px;
}

.empty-state p {
    color: rgba(255,255,255,0.7);
}

/* Container principal */
.agenda-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

/* Section Title */
.section-title {
    text-align: center;
    font-size: 2rem;
    color: #fff;
    margin-bottom: 40px;
    position: relative;
}

.section-title::after {
    content: '';
    display: block;
    width: 80px;
    height: 4px;
    background: linear-gradient(90deg, var(--brand-purple, #8e44ad), #e74c3c);
    margin: 15px auto 0;
    border-radius: 2px;
}
</style>

<div class="agenda-container">
    <!-- Hero Section -->
    <div class="agenda-hero">
        <h1><i class="fas fa-calendar-alt"></i> Agenda de Palestras</h1>
        <p>Confira todas as próximas palestras gratuitas da Translators101. Adicione ao seu calendário e não perca nenhum evento!</p>
    </div>

    <!-- Mensagens de Alerta -->
    <?php if (!empty($success_message)): ?>
        <div class="alert success" id="successAlert">
            <i class="fas fa-check-circle"></i>
            <div class="alert-content"><?php echo htmlspecialchars($success_message); ?></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($error_message)): ?>
        <div class="alert error" id="errorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <div class="alert-content"><?php echo htmlspecialchars($error_message); ?></div>
        </div>
    <?php endif; ?>

    <!-- Seção de Palestras Agendadas -->
    <h2 class="section-title">Próximas Palestras</h2>

    <?php if (!empty($upcomingLectures)): ?>
        <div class="palestras-grid">
            <?php foreach ($upcomingLectures as $lecture): ?>
                <?php
                // Preparar dados para os botões de calendário
                $dateObj = new DateTime($lecture['announcement_date'] . ' ' . $lecture['lecture_time']);
                $endDateObj = clone $dateObj;
                $endDateObj->modify('+1 hour'); // Duração padrão de 1 hora
                
                // Formato para ICS (local)
                $icsStart = $dateObj->format('Ymd\THis');
                $icsEnd = $endDateObj->format('Ymd\THis');
                
                // Formato para Google Calendar (UTC)
                $dateObjUTC = clone $dateObj;
                $dateObjUTC->setTimezone(new DateTimeZone('UTC'));
                $endDateObjUTC = clone $endDateObj;
                $endDateObjUTC->setTimezone(new DateTimeZone('UTC'));
                
                $googleStart = $dateObjUTC->format('Ymd\THis\Z');
                $googleEnd = $endDateObjUTC->format('Ymd\THis\Z');
                
                // Dados do evento em JSON para JavaScript
                $eventData = json_encode([
                    'title' => $lecture['title'],
                    'speaker' => $lecture['speaker'],
                    'description' => $lecture['description'],
                    'start' => $icsStart,
                    'end' => $icsEnd,
                    'start_utc' => $googleStart,
                    'end_utc' => $googleEnd,
                    'reminder' => 30 // 30 minutos antes
                ]);
                
                // Formatar data para exibição
                $displayDate = $dateObj->format('d/m/Y');
                $displayTime = $dateObj->format('H:i');
                $dayOfWeek = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'][$dateObj->format('w')];
                ?>
                <div class="palestra-card">
                    <!-- Imagem -->
                    <?php if (!empty($lecture['image_path']) && file_exists(__DIR__ . '/..' . $lecture['image_path'])): ?>
                        <img src="<?php echo htmlspecialchars($lecture['image_path']); ?>" alt="<?php echo htmlspecialchars($lecture['title']); ?>" class="palestra-image">
                    <?php else: ?>
                        <div class="palestra-placeholder">
                            <i class="fas fa-microphone-alt"></i>
                            <span>Palestra</span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Conteúdo -->
                    <div class="palestra-content">
                        <div class="palestra-date">
                            <i class="fas fa-calendar"></i>
                            <?php echo $dayOfWeek . ', ' . $displayDate . ' às ' . $displayTime; ?>
                        </div>
                        
                        <h3 class="palestra-title"><?php echo htmlspecialchars($lecture['title']); ?></h3>
                        
                        <div class="palestra-speaker">
                            <i class="fas fa-user"></i>
                            <?php echo htmlspecialchars($lecture['speaker']); ?>
                        </div>
                        
                        <?php if (!empty($lecture['description'])): ?>
                            <p class="palestra-description">
                                <?php echo htmlspecialchars(substr($lecture['description'], 0, 200)); ?><?php echo strlen($lecture['description']) > 200 ? '...' : ''; ?>
                            </p>
                        <?php endif; ?>
                        
                        <!-- Botões de Calendário -->
                        <div class="calendar-buttons">
                            <button class="calendar-btn google" data-event='<?php echo htmlspecialchars($eventData); ?>' onclick="generateGoogleCalendarLink(event)">
                                <i class="fab fa-google"></i> Google Calendar
                            </button>
                            <button class="calendar-btn apple" data-event='<?php echo htmlspecialchars($eventData); ?>' onclick="generateIcs(event)">
                                <i class="fab fa-apple"></i> Apple Calendar
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>Nenhuma palestra agendada no momento</h3>
            <p>Cadastre-se abaixo para ser notificado quando novas palestras forem agendadas!</p>
        </div>
    <?php endif; ?>

    <!-- Seção CTA de Captura de Lead -->
    <div class="lead-section" id="cadastro">
        <h2><i class="fas fa-gift"></i> Não Perca Nenhuma Palestra!</h2>
        <p class="subtitle">Cadastre-se para receber notificações sobre as próximas palestras</p>
        <div class="prize-text">
            <i class="fas fa-trophy"></i>
            Concorra a 15 dias de acesso grátis no sorteio mensal!
        </div>
        
        <form class="lead-form" method="POST" action="#cadastro">
            <input type="hidden" name="action" value="cadastrar_lead">
            
            <div class="form-group">
                <label for="nome"><i class="fas fa-user"></i> Seu Nome</label>
                <input type="text" id="nome" name="nome" placeholder="Digite seu nome completo" required>
            </div>
            
            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Seu Email</label>
                <input type="email" id="email" name="email" placeholder="seuemail@exemplo.com" required>
            </div>
            
            <div class="form-group">
                <label for="whatsapp"><i class="fab fa-whatsapp"></i> Seu WhatsApp</label>
                <input type="tel" id="whatsapp" name="whatsapp" placeholder="(11) 99999-9999" required>
            </div>
            
            <button type="submit" class="submit-btn">
                <i class="fas fa-paper-plane"></i> Quero Participar!
            </button>
        </form>
    </div>
</div>

<script>
// Função auxiliar para escapar caracteres especiais para o formato ICS
function escapeIcs(value) {
    if (!value) return '';
    return value
        .replace(/\\/g, '\\\\')
        .replace(/,/g, '\\,')
        .replace(/;/g, '\\;')
        .replace(/\n/g, '\\n')
        .replace(/\r/g, '');
}

// Função para exibir feedback visual no botão após clicado
function showFeedback(btn, isGoogle) {
    btn.innerHTML = `<i class="fas fa-check"></i> ${isGoogle ? 'Aberto' : 'Baixado'}`;
    btn.classList.add('clicked');
    btn.onclick = function(event) { event.preventDefault(); };
    btn.style.cursor = 'default';
}

// Função para gerar link para o Google Calendar
function generateGoogleCalendarLink(e) {
    e.preventDefault();
    const btn = e.currentTarget;
    const eventData = JSON.parse(btn.getAttribute('data-event'));

    const baseUrl = 'https://www.google.com/calendar/render?action=TEMPLATE';
    const params = new URLSearchParams({
        'text': `${eventData.title} com ${eventData.speaker}`,
        'dates': `${eventData.start_utc.replace(/\.000Z$/, 'Z')}/${eventData.end_utc.replace(/\.000Z$/, 'Z')}`,
        'details': `${eventData.description}\n\nPalestrante: ${eventData.speaker}\n\nAssista em: https://translators101.com/v/live-stream`,
        'location': 'Translators101 - Online'
    });
    
    window.open(baseUrl + '&' + params.toString(), '_blank');
    showFeedback(btn, true);
}

// Função para gerar arquivo ICS (para outros calendários como Apple Calendar, Outlook)
function generateIcs(e) {
    e.preventDefault();
    const btn = e.currentTarget;
    const eventData = JSON.parse(btn.getAttribute('data-event'));
    
    const title = eventData.title || "Evento Translators101";
    const description = eventData.description || "Palestra Exclusiva da Translators101";
    const speaker = eventData.speaker || "Palestrante";
    const start = eventData.start;
    const end = eventData.end;
    const reminder = eventData.reminder;
    
    const escapedTitle = escapeIcs(title);
    const escapedDescription = escapeIcs(description);
    const escapedSpeaker = escapeIcs(speaker);

    const icsContent = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Translators101//Live Reminder//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        `DTSTART;TZID=America/Sao_Paulo:${start}`,
        `DTEND;TZID=America/Sao_Paulo:${end}`,
        `SUMMARY;CHARSET=UTF-8:${escapedTitle} com ${escapedSpeaker}`,
        `DESCRIPTION;CHARSET=UTF-8:${escapedDescription}\\n\\nPalestrante: ${escapedSpeaker}\\n\\nAssista em: https://translators101.com/v/live-stream`,
        `LOCATION;CHARSET=UTF-8:Translators101 - Online`,
        `UID:${Date.now()}-${Math.random().toString(36).substring(2, 9)}@translators101.com.br`,
        `DTSTAMP:${new Date().toISOString().replace(/[-:]|\.\d{3}/g, '')}Z`,
        'BEGIN:VALARM',
        'ACTION:DISPLAY',
        `DESCRIPTION;CHARSET=UTF-8:Lembrete: ${escapedTitle}`,
        `TRIGGER:-PT${reminder}M`,
        'END:VALARM',
        'END:VEVENT',
        'END:VCALENDAR'
    ].join('\r\n');
    
    const safeTitle = title.replace(/[\\/:\*?"<>|]/g, '_').substring(0, 40).trim();
    const filename = `Lembrete_${safeTitle}.ics`;
    
    const blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });

    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    showFeedback(btn, false);
}

// Fade out automático para mensagens de sucesso (10 segundos)
const successAlert = document.getElementById('successAlert');
if (successAlert) {
    setTimeout(function() {
        successAlert.style.transition = 'opacity 1s ease-out';
        successAlert.style.opacity = '0';
        setTimeout(function() {
            successAlert.style.display = 'none';
        }, 1000);
    }, 10000); // 10 segundos
}

// Fade out para mensagens de erro (10 segundos)
const errorAlert = document.getElementById('errorAlert');
if (errorAlert) {
    setTimeout(function() {
        errorAlert.style.transition = 'opacity 1s ease-out';
        errorAlert.style.opacity = '0';
        setTimeout(function() {
            errorAlert.style.display = 'none';
        }, 1000);
    }, 10000); // 10 segundos
}

// Máscara simples para WhatsApp
document.getElementById('whatsapp').addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    if (value.length > 11) value = value.substring(0, 11);
    
    if (value.length > 6) {
        value = '(' + value.substring(0, 2) + ') ' + value.substring(2, 7) + '-' + value.substring(7);
    } else if (value.length > 2) {
        value = '(' + value.substring(0, 2) + ') ' + value.substring(2);
    } else if (value.length > 0) {
        value = '(' + value;
    }
    
    e.target.value = value;
});
</script>

<?php
// Inclui o rodapé do site
include __DIR__ . '/../vision/includes/footer.php';
?>