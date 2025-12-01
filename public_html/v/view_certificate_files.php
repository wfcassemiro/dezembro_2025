<?php
session_start();
require_once 'config/database.php';

// Função auxiliar para escrever no arquivo de log customizado.
function writeToCustomLog($message) {
    $log_file = __DIR__ . '/certificate_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] [VIEW] $message\n", FILE_APPEND);
}

writeToCustomLog("DEBUG: Script view_certificate_files.php iniciado.");

// Verificar login
if (!isset($_SESSION['user_id'])) {
    writeToCustomLog("INFO: Usuário não logado. Redirecionando para login.php.");
    header('Location: login.php');
    exit;
}

$certificate_id = $_GET['id'] ?? '';
$action = $_GET['action'] ?? 'view'; // 'view' = interface, 'display' = mostrar imagem

if (empty($certificate_id)) {
    writeToCustomLog("ERRO: ID do certificado não fornecido.");
    header('Location: perfil.php?error=invalid_certificate');
    exit;
}
writeToCustomLog("DEBUG: ID do certificado recebido: " . $certificate_id);

try {
    // Buscar dados do certificado no banco de dados
    $certificate_data = null;

    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.user_id, c.user_name, c.lecture_title, c.speaker_name, c.duration_hours, c.issued_at
            FROM certificates c
            WHERE c.id = ?
        ");
        $stmt->execute([$certificate_id]);
        $certificate_data = $stmt->fetch();

        if ($certificate_data) {
            writeToCustomLog("DEBUG: Dados do certificado encontrados no banco de dados para ID: " . $certificate_id);

            // Verificar permissão (admin pode ver todos, usuário só o próprio)
            if ($certificate_data['user_id'] !== $_SESSION['user_id'] && !isset($_SESSION['is_admin'])) {
                writeToCustomLog("ALERTA: Acesso negado para o usuário " . $_SESSION['user_id'] . " ao certificado " . $certificate_id);
                header('Location: perfil.php?error=access_denied');
                exit;
            }
        } else {
            writeToCustomLog("INFO: Certificado ID " . $certificate_id . " não encontrado no banco de dados.");
            header('Location: perfil.php?error=certificate_not_found');
            exit;
        }
    } catch (Exception $e) {
        writeToCustomLog("ERRO: Erro ao buscar certificado no banco de dados: " . $e->getMessage());
        header('Location: perfil.php?error=certificate_error');
        exit;
    }

    // Buscar arquivo PNG
    $png_files = [
        __DIR__ . '/certificates/certificate_' . $certificate_id . '.png',
        __DIR__ . '/certificates/Certificate_' . $certificate_id . '.png'
    ];

    $png_path = null;
    foreach ($png_files as $test_path) {
        if (file_exists($test_path) && filesize($test_path) > 0) {
            $png_path = $test_path;
            writeToCustomLog("DEBUG: Arquivo PNG encontrado: $png_path");
            break;
        }
    }

    // Se é action=display, mostrar apenas a imagem
    if ($action === 'display' && $png_path) {
        $content_type = 'image/png';
        $filename_display = 'Certificado_' . preg_replace('/[^a-zA-Z0-9_]/', '', $certificate_data['user_name']) . '.png';
        
        writeToCustomLog("DEBUG: Mostrando imagem diretamente: $filename_display");

        // Limpar output buffer
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Headers para visualização inline da imagem
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: inline; filename="' . $filename_display . '"');
        header('Content-Length: ' . filesize($png_path));
        header('Cache-Control: private, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Enviar arquivo PNG
        readfile($png_path);
        writeToCustomLog("INFO: Imagem PNG enviada para visualização direta.");
        exit;
    }

} catch (Exception $e) {
    writeToCustomLog("ERRO FATAL: Exceção no processo de visualização: " . $e->getMessage());
    $certificate_data = null;
    $png_path = null;
}

// Se chegou aqui, mostrar interface completa
include __DIR__ . '/vision/includes/head.php';
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<div class="main-content">
    <!-- Hero Section -->
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-certificate"></i> Visualizar Certificado</h1>
            <p>Certificado de participação na palestra</p>
        </div>
    </div>

    <?php if ($certificate_data && $png_path): ?>
    <!-- Interface de Visualização do Certificado -->
    <div class="certificate-viewer">
        <!-- Informações do Certificado -->
        <div class="certificate-info">
            <div class="info-grid">
                <div class="info-item">
                    <i class="fas fa-user"></i>
                    <div>
                        <strong>Participante</strong>
                        <span><?php echo htmlspecialchars($certificate_data['user_name']); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <i class="fas fa-video"></i>
                    <div>
                        <strong>Palestra</strong>
                        <span><?php echo htmlspecialchars($certificate_data['lecture_title']); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <i class="fas fa-microphone"></i>
                    <div>
                        <strong>Palestrante</strong>
                        <span><?php echo htmlspecialchars($certificate_data['speaker_name']); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <i class="fas fa-clock"></i>
                    <div>
                        <strong>Duração</strong>
                        <span><?php echo $certificate_data['duration_hours']; ?> horas</span>
                    </div>
                </div>
                
                <div class="info-item">
                    <i class="fas fa-calendar"></i>
                    <div>
                        <strong>Emitido em</strong>
                        <span><?php echo date('d/m/Y H:i', strtotime($certificate_data['issued_at'])); ?></span>
                    </div>
                </div>
                
                <div class="info-item">
                    <i class="fas fa-shield-check"></i>
                    <div>
                        <strong>ID do Certificado</strong>
                        <span><?php echo htmlspecialchars($certificate_id); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Prévia do Certificado -->
        <div class="certificate-preview">
            <div class="preview-container">
                <img src="view_certificate_files.php?id=<?php echo urlencode($certificate_id); ?>&action=display" 
                     alt="Certificado de <?php echo htmlspecialchars($certificate_data['user_name']); ?>"
                     class="certificate-image"
                     onclick="openCertificateModal()">
                <div class="preview-overlay">
                    <div class="zoom-icon">
                        <i class="fas fa-search-plus"></i>
                        <span>Clique para ampliar</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Ações do Certificado -->
        <div class="certificate-actions">
            <div class="actions-grid">
                <a href="download_certificate_files.php?id=<?php echo urlencode($certificate_id); ?>&format=png" 
                   class="action-btn download-btn">
                    <i class="fas fa-download"></i>
                    <div>
                        <strong>Baixar PNG</strong>
                        <span>Imagem de alta qualidade</span>
                    </div>
                </a>
                
                <a href="download_certificate_files.php?id=<?php echo urlencode($certificate_id); ?>&format=pdf" 
                   class="action-btn pdf-btn">
                    <i class="fas fa-file-pdf"></i>
                    <div>
                        <strong>Baixar PDF</strong>
                        <span>Documento portátil</span>
                    </div>
                </a>
                
                <button onclick="shareCertificate()" class="action-btn share-btn">
                    <i class="fas fa-share-alt"></i>
                    <div>
                        <strong>Compartilhar</strong>
                        <span>Redes sociais</span>
                    </div>
                </button>
                
                <button onclick="printCertificate()" class="action-btn print-btn">
                    <i class="fas fa-print"></i>
                    <div>
                        <strong>Imprimir</strong>
                        <span>Versão física</span>
                    </div>
                </button>
            </div>
        </div>

        <!-- Navegação -->
        <div class="certificate-navigation">
            <a href="perfil.php" class="nav-btn primary">
                <i class="fas fa-user"></i> Voltar ao Perfil
            </a>
            
            <a href="videoteca.php" class="nav-btn secondary">
                <i class="fas fa-video"></i> Ver mais palestras
            </a>
            
        </div>
    </div>

    <?php else: ?>
    <!-- Estado de Erro -->
    <div class="error-state">
        <div class="error-content">
            <div class="error-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h2>Certificado Não Encontrado</h2>
            <?php if ($certificate_data && !$png_path): ?>
                <p><strong>Os dados do certificado são válidos, mas o arquivo não foi encontrado.</strong></p>
                <p>Participante: <strong><?php echo htmlspecialchars($certificate_data['user_name']); ?></strong></p>
                <p>Palestra: <strong><?php echo htmlspecialchars($certificate_data['lecture_title']); ?></strong></p>
                <br>
                <p style="color: #e74c3c;">⚠️ O arquivo físico precisa ser regenerado.</p>
                
                <?php if (isset($_SESSION['is_admin'])): ?>
                <div class="error-actions">
                    <a href="generate_simple_certificate.php?regenerate=1&certificate_id=<?php echo urlencode($certificate_id); ?>" 
                       class="cta-btn" style="background: #e74c3c;">
                        <i class="fas fa-redo"></i> Regenerar Certificado
                    </a>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <p>O certificado solicitado não foi encontrado ou você não tem permissão para visualizá-lo.</p>
            <?php endif; ?>
            
            <div class="error-actions">
                <a href="perfil.php" class="cta-btn">
                    <i class="fas fa-user"></i> Voltar ao Perfil
                </a>
                <a href="videoteca.php" class="page-btn">
                    <i class="fas fa-video"></i> Ver Palestras
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Modal para Visualização Ampliada -->
<div id="certificateModal" class="certificate-modal" onclick="closeCertificateModal()">
    <div class="modal-content">
        <span class="modal-close" onclick="closeCertificateModal()">&times;</span>
        <img id="modalImage" src="" alt="Certificado Ampliado">
    </div>
</div>

<style>
/* Visualizador de Certificados */
.certificate-viewer {
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 20px;
}

.certificate-info {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.info-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px;
    background: rgba(255, 255, 255, 0.05);
    border-radius: 12px;
    border: 1px solid var(--glass-border);
}

.info-item i {
    font-size: 1.5rem;
    color: var(--brand-purple);
    min-width: 30px;
}

.info-item div {
    flex: 1;
}

.info-item strong {
    display: block;
    color: #fff;
    font-size: 0.9rem;
    margin-bottom: 4px;
}

.info-item span {
    color: #ccc;
    font-size: 0.95rem;
    line-height: 1.4;
}

/* Prévia do Certificado */
.certificate-preview {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
    text-align: center;
}

.preview-container {
    position: relative;
    display: inline-block;
    max-width: 100%;
}

.certificate-image {
    max-width: 100%;
    height: auto;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
    cursor: pointer;
    transition: transform 0.3s ease;
}

.certificate-image:hover {
    transform: scale(1.02);
}

.preview-overlay {
    position: absolute;
    inset: 0;
    background: rgba(0, 0, 0, 0.7);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
    border-radius: 12px;
}

.preview-container:hover .preview-overlay {
    opacity: 1;
}

.zoom-icon {
    color: #fff;
    text-align: center;
}

.zoom-icon i {
    font-size: 2rem;
    margin-bottom: 8px;
    display: block;
}

.zoom-icon span {
    font-size: 0.9rem;
}

/* Ações do Certificado */
.certificate-actions {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 30px;
}


.action-btn {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 20px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid var(--glass-border);
    border-radius: 12px;
    color: #fff;
    text-decoration: none;
    transition: all 0.3s ease;
    cursor: pointer;
}

.action-btn:hover {
    background: rgba(255, 255, 255, 0.1);
    transform: translateY(-2px);
    box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
}

.action-btn i {
    font-size: 1.5rem;
    min-width: 30px;
}

.download-btn i { color: #2ecc71; }
.pdf-btn i { color: #e74c3c; }
.share-btn i { color: #3498db; }
.print-btn i { color: #f39c12; }

.action-btn div {
    flex: 1;
}

.action-btn strong {
    display: block;
    margin-bottom: 4px;
    font-size: 1rem;
}

.action-btn span {
    color: #ccc;
    font-size: 0.85rem;
}

/* Navegação */
.certificate-navigation {
    display: flex;
    gap: 20px;
    justify-content: center;
    flex-wrap: wrap;
    margin-bottom: 40px;
}

.nav-btn {
    padding: 12px 24px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.nav-btn.primary {
    background: var(--brand-purple);
    color: #fff;
}

.nav-btn.primary:hover {
    background: var(--brand-purple-dark);
    transform: translateY(-2px);
}

.nav-btn.secondary {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
    border: 1px solid var(--glass-border);
}

.nav-btn.secondary:hover {
    background: rgba(255, 255, 255, 0.2);
}

.nav-btn.admin {
    background: #e74c3c;
    color: #fff;
}

.nav-btn.admin:hover {
    background: #c0392b;
}

/* Estado de Erro */
.error-state {
    text-align: center;
    padding: 60px 20px;
}

.error-content {
    background: var(--glass-bg);
    backdrop-filter: blur(20px);
    border: 1px solid var(--glass-border);
    border-radius: 16px;
    padding: 60px 40px;
    max-width: 600px;
    margin: 0 auto;
}

.error-icon {
    font-size: 4rem;
    color: #e74c3c;
    margin-bottom: 20px;
}

.error-content h2 {
    color: #fff;
    margin-bottom: 20px;
    font-size: 1.5rem;
}

.error-content p {
    color: #ccc;
    margin-bottom: 12px;
    line-height: 1.5;
}

.error-actions {
    margin-top: 30px;
    display: flex;
    gap: 15px;
    justify-content: center;
    flex-wrap: wrap;
}

/* Modal */
.certificate-modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0, 0, 0, 0.9);
    backdrop-filter: blur(5px);
}

.modal-content {
    position: relative;
    margin: auto;
    padding: 20px;
    width: 90%;
    max-width: 1200px;
    top: 50%;
    transform: translateY(-50%);
    text-align: center;
}

.modal-close {
    position: absolute;
    top: 10px;
    right: 25px;
    color: #fff;
    font-size: 35px;
    font-weight: bold;
    cursor: pointer;
    z-index: 1001;
}

.modal-close:hover {
    color: #ccc;
}

#modalImage {
    max-width: 100%;
    max-height: 80vh;
    border-radius: 8px;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
}

/* Responsividade */
@media (max-width: 768px) {
    .certificate-viewer {
        padding: 0 15px;
    }
    
    .info-grid,
    .actions-grid {
        grid-template-columns: 1fr;
        gap: 15px;
    }
    
    .certificate-navigation {
        flex-direction: column;
        align-items: center;
    }
    
    .nav-btn {
        width: 100%;
        max-width: 300px;
        justify-content: center;
    }
    
    .certificate-info,
    .certificate-preview,
    .certificate-actions {
        padding: 20px;
    }
}
.actions-grid {
  display: flex;
  flex-wrap: wrap;    
  gap: 16px;
  justify-content: center; /* <<< Centraliza os cards */
}

.actions-grid .action-btn {
  flex: 1 1 200px;   /* largura base */
  min-width: 200px;  /* não deixa diminuir demais */
  max-width: 250px;  /* opcional: evita que fiquem enormes */
  text-align: center;
}
</style>

<script>
function openCertificateModal() {
    const modal = document.getElementById('certificateModal');
    const modalImg = document.getElementById('modalImage');
    const certificateImg = document.querySelector('.certificate-image');
    
    modal.style.display = 'block';
    modalImg.src = certificateImg.src;
    
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
}

function closeCertificateModal() {
    const modal = document.getElementById('certificateModal');
    modal.style.display = 'none';
    
    // Restore body scroll
    document.body.style.overflow = 'auto';
}

function shareCertificate() {
    const certificateUrl = window.location.href;
    const text = 'Confira meu certificado de participação da palestra!';
    
    if (navigator.share) {
        navigator.share({
            title: 'Certificado Translators101',
            text: text,
            url: certificateUrl
        });
    } else {
        // Fallback - copiar URL
        navigator.clipboard.writeText(certificateUrl).then(() => {
            alert('Link do certificado copiado para a área de transferência!');
        });
    }
}

function printCertificate() {
    const printWindow = window.open('view_certificate_files.php?id=<?php echo urlencode($certificate_id); ?>&action=display', '_blank');
    printWindow.onload = function() {
        printWindow.print();
    };
}

// Fechar modal com ESC
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeCertificateModal();
    }
});
</script>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>