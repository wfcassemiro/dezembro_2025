<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/config/database.php';

// Funções de verificação de autenticação
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'];
}

function isSubscriber() {
    return isset($_SESSION['is_subscriber']) && $_SESSION['is_subscriber'];
}

$page_title = 'Agenda de Palestras - Translators101';
$page_description = 'Confira as próximas palestras e eventos da Translators101';

$is_logged_in = isLoggedIn();
$is_admin = isAdmin();
$is_subscriber = isSubscriber();

$success_message = '';
$error_message = '';

// Processar cadastro de lead
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cadastrar_lead') {
    // Verificar se já é assinante
    if ($is_subscriber || $is_admin) {
        $error_message = "Você já é assinante e tem acesso a todo o conteúdo!";
    } else {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        
        // Validações
        if (empty($nome) || empty($email) || empty($whatsapp)) {
            $error_message = "Por favor, preencha todos os campos.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = "Por favor, insira um e-mail válido.";
        } else {
            try {
                // Verificar se o email já existe
                $stmt = $pdo->prepare("SELECT id FROM leads WHERE email = ?");
                $stmt->execute([$email]);
                
                if ($stmt->fetch()) {
                    $error_message = "Este e-mail já está cadastrado!";
                } else {
                    // Inserir novo lead
                    $id = uniqid('lead_', true);
                    $stmt = $pdo->prepare("INSERT INTO leads (id, nome, email, whatsapp, fonte, created_at) VALUES (?, ?, ?, ?, 'agenda_palestras', NOW())");
                    $stmt->execute([$id, $nome, $email, $whatsapp]);
                    
                    $success_message = "Cadastro realizado com sucesso! Você receberá as notificações sobre as próximas palestras.";
                }
            } catch (PDOException $e) {
                error_log("Erro ao cadastrar lead: " . $e->getMessage());
                $error_message = "Ocorreu um erro ao processar seu cadastro. Tente novamente.";
            }
        }
    }
}

// Buscar palestras agendadas
$upcomingLectures = [];
try {
    $stmt = $pdo->query("
        SELECT * FROM upcoming_announcements 
        WHERE is_active = 1 
        AND CONCAT(announcement_date, ' ', lecture_time) >= NOW()
        ORDER BY announcement_date ASC, lecture_time ASC
    ");
    $upcomingLectures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar palestras: " . $e->getMessage());
}

include __DIR__ . '/vision/includes/head.php';
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<style>
.agenda-main-content {
    padding: 20px;
}

.agenda-hero {
    text-align: center;
    padding: 40px 20px;
    background: linear-gradient(135deg, rgba(142, 68, 173, 0.2), rgba(59, 130, 246, 0.1));
    border-radius: 20px;
    margin-bottom: 30px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.agenda-hero h1 {
    color: #c084fc;
    font-size: 2.2rem;
    margin-bottom: 10px;
}

.agenda-hero p {
    color: rgba(255, 255, 255, 0.7);
    font-size: 1.1rem;
}

/* Lead Section */
.lead-section {
    background: linear-gradient(135deg, rgba(236, 72, 153, 0.15), rgba(139, 92, 246, 0.1));
    border: 1px solid rgba(236, 72, 153, 0.3);
    border-radius: 20px;
    padding: 40px 30px;
    margin-bottom: 40px;
    text-align: center;
}

.lead-section h2 {
    color: #ec4899;
    margin-bottom: 15px;
    font-size: 1.8rem;
}

.lead-section p {
    color: rgba(255, 255, 255, 0.8);
    margin-bottom: 20px;
    font-size: 1.05rem;
}

.prize-text {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, rgba(245, 158, 11, 0.2), rgba(234, 179, 8, 0.1));
    border: 1px solid rgba(245, 158, 11, 0.4);
    padding: 12px 25px;
    border-radius: 30px;
    margin-bottom: 25px;
    font-weight: 600;
    color: #fbbf24;
}

.prize-text i {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.2); }
}

/* Lead Form */
.lead-form {
    max-width: 500px;
    margin: 0 auto;
    text-align: left;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    color: white;
    font-weight: 600;
    font-size: 0.95rem;
}

.form-group label i {
    margin-right: 8px;
    color: #c084fc;
}

.form-group input {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    background: rgba(0, 0, 0, 0.3);
    color: white;
    font-size: 1rem;
    transition: all 0.3s;
    box-sizing: border-box;
}

.form-group input:focus {
    outline: none;
    border-color: #c084fc;
    box-shadow: 0 0 0 3px rgba(192, 132, 252, 0.15);
}

.form-group input::placeholder {
    color: rgba(255, 255, 255, 0.4);
}

/* Phone Input Container */
.phone-input-container {
    display: flex;
    gap: 10px;
}

.country-input-wrapper {
    display: flex;
    flex-direction: column;
    gap: 5px;
    width: 160px;
    flex-shrink: 0;
}

.country-code-input {
    width: 100% !important;
    padding: 14px 10px !important;
    text-align: center;
    font-weight: 600;
    font-size: 1.1rem !important;
}

.country-select {
    width: 100%;
    padding: 8px 5px;
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    background: rgba(0, 0, 0, 0.3);
    color: white;
    font-size: 0.8rem;
    cursor: pointer;
}

.country-select:focus {
    outline: none;
    border-color: #c084fc;
}

.country-select optgroup {
    background: #1a1a1a;
    color: #c084fc;
    font-weight: 600;
}

.country-select option {
    background: #2a2a2a;
    color: white;
    padding: 5px;
}

.phone-input-container input[type="tel"] {
    flex: 1;
}

.phone-hint {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.5);
    margin-top: 6px;
    display: flex;
    align-items: center;
    gap: 5px;
}

.phone-hint i {
    color: #10b981;
}

.submit-btn {
    width: 100%;
    padding: 16px;
    background: linear-gradient(135deg, #ec4899, #8b5cf6);
    border: none;
    border-radius: 10px;
    color: white;
    font-size: 1.1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.3s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
}

.submit-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 25px rgba(236, 72, 153, 0.3);
}

/* Alerts */
.alert {
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 12px;
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

/* Palestras Grid */
.section-title {
    color: #c084fc;
    font-size: 1.6rem;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.palestras-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 25px;
}

.palestra-card.hidden {
    display: none;
}

/* Pagination */
.pagination-container {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 15px;
    margin-top: 30px;
    padding: 20px;
}

.pagination-btn {
    padding: 10px 20px;
    background: rgba(192, 132, 252, 0.2);
    border: 1px solid rgba(192, 132, 252, 0.3);
    border-radius: 8px;
    color: #c084fc;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pagination-btn:hover:not(:disabled) {
    background: rgba(192, 132, 252, 0.3);
    transform: translateY(-2px);
}

.pagination-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.pagination-info {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.95rem;
}

.pagination-info strong {
    color: #c084fc;
}

.palestra-card {
    background: rgba(30, 30, 30, 0.8);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 16px;
    overflow: hidden;
    transition: all 0.3s;
}

.palestra-card:hover {
    transform: translateY(-5px);
    border-color: rgba(192, 132, 252, 0.3);
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
}

.palestra-image {
    width: 100%;
    height: 200px;
    object-fit: cover;
    background: linear-gradient(135deg, rgba(142, 68, 173, 0.3), rgba(59, 130, 246, 0.2));
}

.palestra-content {
    padding: 20px;
}

.palestra-badges {
    display: flex;
    gap: 10px;
    margin-bottom: 15px;
}

.badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.badge-date {
    background: rgba(59, 130, 246, 0.2);
    color: #60a5fa;
}

.badge-time {
    background: rgba(245, 158, 11, 0.2);
    color: #fbbf24;
}

.palestra-title {
    color: white;
    font-size: 1.3rem;
    margin-bottom: 8px;
}

.palestra-speaker {
    color: #c084fc;
    font-size: 1rem;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.palestra-description {
    color: rgba(255, 255, 255, 0.7);
    font-size: 0.95rem;
    line-height: 1.6;
    margin-bottom: 20px;
}

.calendar-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.calendar-btn {
    flex: 1;
    min-width: 140px;
    padding: 10px 15px;
    border: none;
    border-radius: 8px;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
    text-decoration: none;
}

.btn-google {
    background: rgba(66, 133, 244, 0.2);
    color: #4285f4;
    border: 1px solid rgba(66, 133, 244, 0.3);
}

.btn-google:hover {
    background: rgba(66, 133, 244, 0.3);
}

.btn-apple {
    background: rgba(255, 255, 255, 0.1);
    color: white;
    border: 1px solid rgba(255, 255, 255, 0.2);
}

.btn-apple:hover {
    background: rgba(255, 255, 255, 0.2);
}

/* Empty State */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: rgba(30, 30, 30, 0.5);
    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, 0.1);
}

.empty-state i {
    font-size: 4rem;
    color: rgba(192, 132, 252, 0.3);
    margin-bottom: 20px;
}

.empty-state h3 {
    color: rgba(255, 255, 255, 0.7);
    margin-bottom: 10px;
}

.empty-state p {
    color: rgba(255, 255, 255, 0.5);
}

/* Subscriber Message */
.subscriber-message {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
    border: 1px solid rgba(16, 185, 129, 0.3);
    border-radius: 16px;
    padding: 25px;
    text-align: center;
    margin-bottom: 30px;
}

.subscriber-message i {
    font-size: 2.5rem;
    color: #10b981;
    margin-bottom: 15px;
}

.subscriber-message h3 {
    color: #10b981;
    margin-bottom: 10px;
}

.subscriber-message p {
    color: rgba(255, 255, 255, 0.7);
}

/* Responsive */
@media (max-width: 1200px) {
    .palestras-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .agenda-hero h1 {
        font-size: 1.8rem;
    }
    
    .lead-section {
        padding: 30px 20px;
    }
    
    .palestras-grid {
        grid-template-columns: 1fr;
    }
    
    .phone-input-container {
        flex-direction: column;
    }
    
    .country-input-wrapper {
        width: 100%;
    }
    
    .pagination-container {
        flex-wrap: wrap;
    }
}
</style>

<div class="main-content">
    <div class="agenda-main-content">
        <!-- Hero -->
        <div class="agenda-hero">
            <h1><i class="fas fa-calendar-alt"></i> Agenda de Palestras</h1>
            <p>Confira as próximas palestras e não perca nenhum evento!</p>
        </div>

        <!-- Mensagens -->
        <?php if ($success_message): ?>
        <div class="alert alert-success" id="successAlert">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
        <div class="alert alert-error" id="errorAlert">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <!-- Seção para assinantes -->
        <?php if ($is_subscriber || $is_admin): ?>
        <div class="subscriber-message">
            <i class="fas fa-crown"></i>
            <h3>Você é assinante!</h3>
            <p>Você tem acesso a todo o conteúdo da plataforma. Aproveite as palestras ao vivo e a videoteca completa!</p>
        </div>
        <?php endif; ?>

        <!-- Seção de captura de leads (apenas para não-assinantes) -->
        <?php if (!$is_subscriber && !$is_admin): ?>
        <div class="lead-section" id="cadastro">
            <h2><i class="fas fa-bell"></i> Não perca nenhuma palestra!</h2>
            <p>Cadastre-se para receber notificações sobre as próximas palestras gratuitas.</p>
            
            <div class="prize-text">
                <i class="fas fa-trophy"></i>
                Concorra a 7 dias de acesso grátis no sorteio mensal!
            </div>

            <form method="POST" action="#cadastro" class="lead-form">
                <input type="hidden" name="action" value="cadastrar_lead">
                
                <div class="form-group">
                    <label for="nome"><i class="fas fa-user"></i> Seu nome</label>
                    <input type="text" id="nome" name="nome" placeholder="Digite seu nome completo" required>
                </div>

                <div class="form-group">
                    <label for="email"><i class="fas fa-envelope"></i> Seu e-mail</label>
                    <input type="email" id="email" name="email" placeholder="seuemail@exemplo.com" required>
                </div>

                <div class="form-group">
                    <label for="whatsapp"><i class="fab fa-whatsapp"></i> Seu WhatsApp (com código do país)</label>
                    <div class="phone-input-container">
                        <div class="country-input-wrapper">
                            <input type="text" id="country_code" class="country-code-input" value="+55" maxlength="5" placeholder="+55">
                            <select id="country_select" class="country-select" onchange="selectCountry(this)">
                                <option value="">Selecione...</option>
                                <optgroup label="América do Sul">
                                    <option value="+55" data-flag="🇧🇷">🇧🇷 Brasil (+55)</option>
                                    <option value="+54" data-flag="🇦🇷">🇦🇷 Argentina (+54)</option>
                                    <option value="+591" data-flag="🇧🇴">🇧🇴 Bolívia (+591)</option>
                                    <option value="+56" data-flag="🇨🇱">🇨🇱 Chile (+56)</option>
                                    <option value="+57" data-flag="🇨🇴">🇨🇴 Colômbia (+57)</option>
                                    <option value="+593" data-flag="🇪🇨">🇪🇨 Equador (+593)</option>
                                    <option value="+595" data-flag="🇵🇾">🇵🇾 Paraguai (+595)</option>
                                    <option value="+51" data-flag="🇵🇪">🇵🇪 Peru (+51)</option>
                                    <option value="+598" data-flag="🇺🇾">🇺🇾 Uruguai (+598)</option>
                                    <option value="+58" data-flag="🇻🇪">🇻🇪 Venezuela (+58)</option>
                                    <option value="+592" data-flag="🇬🇾">🇬🇾 Guiana (+592)</option>
                                    <option value="+597" data-flag="🇸🇷">🇸🇷 Suriname (+597)</option>
                                </optgroup>
                                <optgroup label="América do Norte e Central">
                                    <option value="+1" data-flag="🇺🇸">🇺🇸 EUA (+1)</option>
                                    <option value="+1" data-flag="🇨🇦">🇨🇦 Canadá (+1)</option>
                                    <option value="+52" data-flag="🇲🇽">🇲🇽 México (+52)</option>
                                    <option value="+502" data-flag="🇬🇹">🇬🇹 Guatemala (+502)</option>
                                    <option value="+503" data-flag="🇸🇻">🇸🇻 El Salvador (+503)</option>
                                    <option value="+504" data-flag="🇭🇳">🇭🇳 Honduras (+504)</option>
                                    <option value="+505" data-flag="🇳🇮">🇳🇮 Nicarágua (+505)</option>
                                    <option value="+506" data-flag="🇨🇷">🇨🇷 Costa Rica (+506)</option>
                                    <option value="+507" data-flag="🇵🇦">🇵🇦 Panamá (+507)</option>
                                    <option value="+509" data-flag="🇭🇹">🇭🇹 Haiti (+509)</option>
                                    <option value="+53" data-flag="🇨🇺">🇨🇺 Cuba (+53)</option>
                                    <option value="+1809" data-flag="🇩🇴">🇩🇴 Rep. Dominicana (+1809)</option>
                                    <option value="+1787" data-flag="🇵🇷">🇵🇷 Porto Rico (+1787)</option>
                                </optgroup>
                                <optgroup label="Europa">
                                    <option value="+351" data-flag="🇵🇹">🇵🇹 Portugal (+351)</option>
                                    <option value="+34" data-flag="🇪🇸">🇪🇸 Espanha (+34)</option>
                                    <option value="+33" data-flag="🇫🇷">🇫🇷 França (+33)</option>
                                    <option value="+49" data-flag="🇩🇪">🇩🇪 Alemanha (+49)</option>
                                    <option value="+44" data-flag="🇬🇧">🇬🇧 Reino Unido (+44)</option>
                                    <option value="+39" data-flag="🇮🇹">🇮🇹 Itália (+39)</option>
                                    <option value="+41" data-flag="🇨🇭">🇨🇭 Suíça (+41)</option>
                                    <option value="+31" data-flag="🇳🇱">🇳🇱 Holanda (+31)</option>
                                    <option value="+32" data-flag="🇧🇪">🇧🇪 Bélgica (+32)</option>
                                    <option value="+43" data-flag="🇦🇹">🇦🇹 Áustria (+43)</option>
                                    <option value="+48" data-flag="🇵🇱">🇵🇱 Polônia (+48)</option>
                                    <option value="+46" data-flag="🇸🇪">🇸🇪 Suécia (+46)</option>
                                    <option value="+47" data-flag="🇳🇴">🇳🇴 Noruega (+47)</option>
                                    <option value="+45" data-flag="🇩🇰">🇩🇰 Dinamarca (+45)</option>
                                    <option value="+358" data-flag="🇫🇮">🇫🇮 Finlândia (+358)</option>
                                    <option value="+353" data-flag="🇮🇪">🇮🇪 Irlanda (+353)</option>
                                    <option value="+30" data-flag="🇬🇷">🇬🇷 Grécia (+30)</option>
                                    <option value="+420" data-flag="🇨🇿">🇨🇿 Rep. Tcheca (+420)</option>
                                    <option value="+36" data-flag="🇭🇺">🇭🇺 Hungria (+36)</option>
                                    <option value="+40" data-flag="🇷🇴">🇷🇴 Romênia (+40)</option>
                                    <option value="+380" data-flag="🇺🇦">🇺🇦 Ucrânia (+380)</option>
                                    <option value="+7" data-flag="🇷🇺">🇷🇺 Rússia (+7)</option>
                                </optgroup>
                                <optgroup label="Ásia">
                                    <option value="+81" data-flag="🇯🇵">🇯🇵 Japão (+81)</option>
                                    <option value="+86" data-flag="🇨🇳">🇨🇳 China (+86)</option>
                                    <option value="+82" data-flag="🇰🇷">🇰🇷 Coreia do Sul (+82)</option>
                                    <option value="+91" data-flag="🇮🇳">🇮🇳 Índia (+91)</option>
                                    <option value="+62" data-flag="🇮🇩">🇮🇩 Indonésia (+62)</option>
                                    <option value="+66" data-flag="🇹🇭">🇹🇭 Tailândia (+66)</option>
                                    <option value="+84" data-flag="🇻🇳">🇻🇳 Vietnã (+84)</option>
                                    <option value="+60" data-flag="🇲🇾">🇲🇾 Malásia (+60)</option>
                                    <option value="+65" data-flag="🇸🇬">🇸🇬 Singapura (+65)</option>
                                    <option value="+63" data-flag="🇵🇭">🇵🇭 Filipinas (+63)</option>
                                    <option value="+852" data-flag="🇭🇰">🇭🇰 Hong Kong (+852)</option>
                                    <option value="+886" data-flag="🇹🇼">🇹🇼 Taiwan (+886)</option>
                                    <option value="+90" data-flag="🇹🇷">🇹🇷 Turquia (+90)</option>
                                    <option value="+972" data-flag="🇮🇱">🇮🇱 Israel (+972)</option>
                                    <option value="+971" data-flag="🇦🇪">🇦🇪 Emirados Árabes (+971)</option>
                                    <option value="+966" data-flag="🇸🇦">🇸🇦 Arábia Saudita (+966)</option>
                                    <option value="+92" data-flag="🇵🇰">🇵🇰 Paquistão (+92)</option>
                                    <option value="+880" data-flag="🇧🇩">🇧🇩 Bangladesh (+880)</option>
                                </optgroup>
                                <optgroup label="África">
                                    <option value="+27" data-flag="🇿🇦">🇿🇦 África do Sul (+27)</option>
                                    <option value="+20" data-flag="🇪🇬">🇪🇬 Egito (+20)</option>
                                    <option value="+234" data-flag="🇳🇬">🇳🇬 Nigéria (+234)</option>
                                    <option value="+254" data-flag="🇰🇪">🇰🇪 Quênia (+254)</option>
                                    <option value="+212" data-flag="🇲🇦">🇲🇦 Marrocos (+212)</option>
                                    <option value="+213" data-flag="🇩🇿">🇩🇿 Argélia (+213)</option>
                                    <option value="+233" data-flag="🇬🇭">🇬🇭 Gana (+233)</option>
                                    <option value="+244" data-flag="🇦🇴">🇦🇴 Angola (+244)</option>
                                    <option value="+258" data-flag="🇲🇿">🇲🇿 Moçambique (+258)</option>
                                    <option value="+238" data-flag="🇨🇻">🇨🇻 Cabo Verde (+238)</option>
                                </optgroup>
                                <optgroup label="Oceania">
                                    <option value="+61" data-flag="🇦🇺">🇦🇺 Austrália (+61)</option>
                                    <option value="+64" data-flag="🇳🇿">🇳🇿 Nova Zelândia (+64)</option>
                                </optgroup>
                                <option value="outro">✏️ Outro (digitar código)</option>
                            </select>
                        </div>
                        <input type="tel" id="whatsapp_number" placeholder="99999-9999" required>
                    </div>
                    <input type="hidden" id="whatsapp" name="whatsapp">
                    <p class="phone-hint">
                        <i class="fas fa-info-circle"></i>
                        Selecione seu país ou digite o código manualmente. Ex: +55 para Brasil
                    </p>
                </div>

                <button type="submit" class="submit-btn">
                    <i class="fas fa-paper-plane"></i> Quero participar!
                </button>
            </form>
        </div>
        <?php endif; ?>

        <!-- Palestras Agendadas -->
        <h2 class="section-title"><i class="fas fa-video"></i> Próximas palestras</h2>

        <?php if (empty($upcomingLectures)): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>Nenhuma palestra agendada no momento</h3>
            <p>Fique atento! Em breve teremos novas palestras.</p>
        </div>
        <?php else: ?>
        <div class="palestras-grid" id="palestrasGrid">
            <?php foreach ($upcomingLectures as $index => $lecture): ?>
            <div class="palestra-card <?php echo $index >= 3 ? 'hidden' : ''; ?>" data-index="<?php echo $index; ?>">
                <?php if (!empty($lecture['image_path'])): ?>
                <img src="<?php echo htmlspecialchars($lecture['image_path']); ?>" alt="<?php echo htmlspecialchars($lecture['title']); ?>" class="palestra-image">
                <?php else: ?>
                <div class="palestra-image"></div>
                <?php endif; ?>
                
                <div class="palestra-content">
                    <div class="palestra-badges">
                        <span class="badge badge-date">
                            <i class="fas fa-calendar"></i>
                            <?php echo date('d/m/Y', strtotime($lecture['announcement_date'])); ?>
                        </span>
                        <span class="badge badge-time">
                            <i class="fas fa-clock"></i>
                            <?php echo date('H:i', strtotime($lecture['lecture_time'])); ?>h
                        </span>
                    </div>
                    
                    <h3 class="palestra-title"><?php echo htmlspecialchars($lecture['title']); ?></h3>
                    
                    <p class="palestra-speaker">
                        <i class="fas fa-user"></i>
                        <?php echo htmlspecialchars($lecture['speaker']); ?>
                    </p>
                    
                    <?php if (!empty($lecture['description'])): ?>
                    <p class="palestra-description"><?php echo htmlspecialchars($lecture['description']); ?></p>
                    <?php endif; ?>
                    
                    <div class="calendar-buttons">
                        <button class="calendar-btn btn-google" 
                                onclick="addToGoogleCalendar('<?php echo htmlspecialchars($lecture['title'], ENT_QUOTES); ?>', '<?php echo $lecture['announcement_date']; ?>', '<?php echo $lecture['lecture_time']; ?>', '<?php echo htmlspecialchars($lecture['description'] ?? '', ENT_QUOTES); ?>')">
                            <i class="fab fa-google"></i> Google Calendar
                        </button>
                        <button class="calendar-btn btn-apple"
                                onclick="downloadICS('<?php echo htmlspecialchars($lecture['title'], ENT_QUOTES); ?>', '<?php echo $lecture['announcement_date']; ?>', '<?php echo $lecture['lecture_time']; ?>', '<?php echo htmlspecialchars($lecture['description'] ?? '', ENT_QUOTES); ?>')">
                            <i class="fab fa-apple"></i> Apple/Outlook
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (count($upcomingLectures) > 3): ?>
        <!-- Paginação -->
        <div class="pagination-container">
            <button class="pagination-btn" id="prevBtn" onclick="changePage(-1)" disabled>
                <i class="fas fa-chevron-left"></i> Anterior
            </button>
            <span class="pagination-info">
                Página <strong id="currentPage">1</strong> de <strong id="totalPages"><?php echo ceil(count($upcomingLectures) / 3); ?></strong>
            </span>
            <button class="pagination-btn" id="nextBtn" onclick="changePage(1)">
                Próxima <i class="fas fa-chevron-right"></i>
            </button>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Combinar código do país com número antes do envio
document.querySelector('.lead-form')?.addEventListener('submit', function(e) {
    const countryCode = document.getElementById('country_code').value;
    const phoneNumber = document.getElementById('whatsapp_number').value.replace(/\D/g, '');
    const fullNumber = countryCode + phoneNumber;
    document.getElementById('whatsapp').value = fullNumber;
    
    // Validação mínima
    if (phoneNumber.length < 7) {
        e.preventDefault();
        alert('Por favor, insira um número de telefone válido.');
        return false;
    }
});

// Máscara flexível para número de telefone (aceita formatos internacionais)
document.getElementById('whatsapp_number')?.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    const countryCode = document.getElementById('country_code').value;
    
    // Limitar o tamanho baseado no país
    let maxLength = 15; // Padrão internacional
    
    if (countryCode === '+55') {
        // Brasil: máximo 11 dígitos (DDD + 9 dígitos)
        maxLength = 11;
        if (value.length > maxLength) {
            value = value.substring(0, maxLength);
        }
        
        // Aplicar máscara brasileira
        if (value.length > 6) {
            if (value.length === 11) {
                value = '(' + value.substring(0, 2) + ') ' + value.substring(2, 7) + '-' + value.substring(7);
            } else if (value.length === 10) {
                value = '(' + value.substring(0, 2) + ') ' + value.substring(2, 6) + '-' + value.substring(6);
            } else {
                value = '(' + value.substring(0, 2) + ') ' + value.substring(2);
            }
        } else if (value.length > 2) {
            value = '(' + value.substring(0, 2) + ') ' + value.substring(2);
        } else if (value.length > 0) {
            value = '(' + value;
        }
    } else if (countryCode === '+1') {
        // EUA/Canadá: formato (XXX) XXX-XXXX
        maxLength = 10;
        if (value.length > maxLength) {
            value = value.substring(0, maxLength);
        }
        
        if (value.length > 6) {
            value = '(' + value.substring(0, 3) + ') ' + value.substring(3, 6) + '-' + value.substring(6);
        } else if (value.length > 3) {
            value = '(' + value.substring(0, 3) + ') ' + value.substring(3);
        } else if (value.length > 0) {
            value = '(' + value;
        }
    } else if (countryCode === '+351') {
        // Portugal: 9 dígitos
        maxLength = 9;
        if (value.length > maxLength) {
            value = value.substring(0, maxLength);
        }
        
        if (value.length > 6) {
            value = value.substring(0, 3) + ' ' + value.substring(3, 6) + ' ' + value.substring(6);
        } else if (value.length > 3) {
            value = value.substring(0, 3) + ' ' + value.substring(3);
        }
    } else {
        // Outros países: formato genérico com espaços a cada 3-4 dígitos
        if (value.length > maxLength) {
            value = value.substring(0, maxLength);
        }
        
        // Formato simples com espaços
        if (value.length > 8) {
            value = value.substring(0, 4) + ' ' + value.substring(4, 8) + ' ' + value.substring(8);
        } else if (value.length > 4) {
            value = value.substring(0, 4) + ' ' + value.substring(4);
        }
    }
    
    e.target.value = value;
});

// Atualizar placeholder quando mudar o país
document.getElementById('country_code')?.addEventListener('change', function() {
    const input = document.getElementById('whatsapp_number');
    const code = this.value;
    
    // Limpar o campo quando mudar de país
    input.value = '';
    
    // Atualizar placeholder baseado no país
    const placeholders = {
        '+55': '(11) 99999-9999',
        '+1': '(555) 123-4567',
        '+351': '912 345 678',
        '+34': '612 345 678',
        '+33': '6 12 34 56 78',
        '+49': '151 1234 5678',
        '+44': '7911 123456',
        '+39': '333 123 4567'
    };
    
    input.placeholder = placeholders[code] || '1234 5678 9012';
});

// Função para adicionar ao Google Calendar
function addToGoogleCalendar(title, date, time, description) {
    const startDate = new Date(date + 'T' + time);
    const endDate = new Date(startDate.getTime() + 2 * 60 * 60 * 1000); // +2 horas
    
    const formatDate = (d) => {
        return d.toISOString().replace(/-|:|\.\d{3}/g, '');
    };
    
    const url = `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${encodeURIComponent(title)}&dates=${formatDate(startDate)}/${formatDate(endDate)}&details=${encodeURIComponent(description)}&location=translators101.com`;
    
    window.open(url, '_blank');
}

// Função para baixar arquivo ICS
function downloadICS(title, date, time, description) {
    const startDate = new Date(date + 'T' + time);
    const endDate = new Date(startDate.getTime() + 2 * 60 * 60 * 1000);
    
    const formatICSDate = (d) => {
        return d.toISOString().replace(/-|:|\.\d{3}/g, '').slice(0, -1);
    };
    
    const escapeICS = (str) => {
        return str.replace(/[\\;,\n]/g, (match) => {
            if (match === '\n') return '\\n';
            return '\\' + match;
        });
    };
    
    const icsContent = `BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Translators101//PT
BEGIN:VEVENT
UID:${Date.now()}@translators101.com
DTSTAMP:${formatICSDate(new Date())}Z
DTSTART:${formatICSDate(startDate)}
DTEND:${formatICSDate(endDate)}
SUMMARY:${escapeICS(title)}
DESCRIPTION:${escapeICS(description)}
LOCATION:translators101.com
END:VEVENT
END:VCALENDAR`;
    
    const blob = new Blob([icsContent], { type: 'text/calendar;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = title.replace(/[^a-z0-9]/gi, '_') + '.ics';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Fade out das mensagens
setTimeout(function() {
    document.querySelectorAll('.alert').forEach(function(alert) {
        alert.style.transition = 'opacity 1s';
        alert.style.opacity = '0';
        setTimeout(function() {
            alert.remove();
        }, 1000);
    });
}, 10000);
</script>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>
