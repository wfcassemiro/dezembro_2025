<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Verificar se o usuário está logado
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Download de Certificado - Translators101';
$user_id = $_SESSION['user_id'];
$certificate_id = $_GET['id'] ?? null;
$lecture_id = $_GET['lecture_id'] ?? null;

$error = '';
$certificate = null;

try {
    if ($certificate_id) {
        // Buscar certificado por ID
        $stmt = $pdo->prepare("
        SELECT c.*, 
        l.title as lecture_title, 
        l.speaker, 
        u.name as user_name,
        al.accumulated_watch_time,
        al.last_watched_seconds
        FROM certificates c
        LEFT JOIN lectures l ON c.lecture_id = l.id
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN access_logs al ON (c.user_id = al.user_id AND al.resource = l.title AND al.certificate_generated = 1)
        WHERE c.id = ? AND c.user_id = ?
        ");
        $stmt->execute([$certificate_id, $user_id]);
        $certificate = $stmt->fetch();
    } elseif ($lecture_id) {
        // Buscar certificado por lecture_id
        $stmt = $pdo->prepare("
        SELECT c.*, 
        l.title as lecture_title, 
        l.speaker, 
        u.name as user_name,
        al.accumulated_watch_time,
        al.last_watched_seconds
        FROM certificates c
        LEFT JOIN lectures l ON c.lecture_id = l.id
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN access_logs al ON (c.user_id = al.user_id AND al.resource = l.title AND al.certificate_generated = 1)
        WHERE c.lecture_id = ? AND c.user_id = ?
        ");
        $stmt->execute([$lecture_id, $user_id]);
        $certificate = $stmt->fetch();
    }

    if (!$certificate) {
        $error = 'Certificado não encontrado ou você não tem permissão para acessá-lo.';
    }

} catch (PDOException $e) {
    $error = 'Erro ao buscar certificado: ' . $e->getMessage();
}

// Se há download request, processar
if ($certificate && isset($_GET['download'])) {
    require_once __DIR__ . '/fpdf/fpdf.php'; // Ajuste o caminho conforme sua estrutura

    $filename = 'certificado_' . $certificate['id'] . '.pdf';

    // URL da imagem PNG do certificado
    $image_url = "https://v.translators101.com/download_certificate_files.php?id=" . urlencode($certificate['id']);

    // Baixa a imagem PNG
    $image_content = file_get_contents($image_url);
    if ($image_content === false) {
        die('Não foi possível baixar a imagem do certificado.');
    }

    // Salva temporariamente a imagem
    $tmp_image = tempnam(sys_get_temp_dir(), 'cert_') . '.png';
    file_put_contents($tmp_image, $image_content);

    // Cria o PDF
    $pdf = new FPDF('L', 'mm', 'A4'); // Landscape, milímetros, A4
    $pdf->AddPage();

    // Obtém dimensões da página
    $page_width = $pdf->GetPageWidth();
    $page_height = $pdf->GetPageHeight();

    // Insere a imagem para preencher a página inteira
    $pdf->Image($tmp_image, 0, 0, $page_width, $page_height);

    // Remove o arquivo temporário
    unlink($tmp_image);

    // Envia o PDF para download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    $pdf->Output('I', $filename);
    exit;
}

include __DIR__ . '/vision/includes/head.php';
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-certificate"></i> Download de Certificado</h1>
            <p>Baixe seu certificado de conclusão</p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="video-card">
            <div class="alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
            <div style="text-align: center; margin-top: 30px;">
                <a href="videoteca.php" class="cta-btn">
                    <i class="fas fa-video"></i> Ir para Videoteca
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="video-card">
            <h2><i class="fas fa-award"></i> Certificado de Conclusão</h2>
            <div class="certificate-preview">
                <div class="certificate-header">
                    <div class="certificate-logo">
                        <i class="fas fa-certificate"></i>
                    </div>
                    <h3>Certificado de Conclusão</h3>
                    <p>Translators101 - Educação Continuada para Tradutores</p>
                </div>

                <div class="certificate-body">
                    <p class="certificate-text">
                        Certificamos que <strong><?php echo htmlspecialchars($certificate['user_name']); ?></strong>
                        concluiu com êxito a palestra:
                    </p>
                    <h4 class="lecture-title"><?php echo htmlspecialchars($certificate['lecture_title']); ?></h4>
                    <p class="speaker-info">
                        Ministrada por: <strong><?php echo htmlspecialchars($certificate['speaker']); ?></strong>
                    </p>
                    <div class="certificate-details">
                        <div class="detail-item">
                            <i class="fas fa-calendar"></i>
                            <span>Data de conclusão: <?php echo date('d/m/Y', strtotime($certificate['created_at'])); ?></span>
                        </div>
                        <?php 
                        $watch_time = 0;
                        if ($certificate['accumulated_watch_time']) {
                            $watch_time = floor($certificate['accumulated_watch_time'] / 60);
                        } elseif ($certificate['last_watched_seconds']) {
                            $watch_time = floor($certificate['last_watched_seconds'] / 60);
                        }
                        if ($watch_time > 0): 
                        ?>
                        <div class="detail-item">
                            <i class="fas fa-clock"></i>
                            <span>Tempo assistido: <?php echo $watch_time; ?> minutos</span>
                        </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <i class="fas fa-shield-alt"></i>
                            <span>Código de verificação: <?php echo strtoupper(substr(md5($certificate['id'] . $certificate['created_at']), 0, 8)); ?></span>
                        </div>
                    </div>
                </div>

                <div class="certificate-footer">
                    <div class="signature-area">
                        <div class="signature">
                            <div class="signature-line"></div>
                            <p>Translators101</p>
                            <p><small>Plataforma de Educação Continuada</small></p>
                        </div>
                    </div>
                    <div class="certificate-seal">
                        <i class="fas fa-stamp"></i>
                    </div>
                </div>
            </div>

            <div class="certificate-actions">
                <a href="?id=<?php echo $certificate['id']; ?>&download=1" class="cta-btn">
                    <i class="fas fa-download"></i> Baixar PDF
                </a>
                <a href="view_certificate.php?id=<?php echo $certificate['id']; ?>" class="page-btn" target="_blank">
                    <i class="fas fa-external-link-alt"></i> Visualizar
                </a>
                <button onclick="shareCertificate()" class="page-btn">
                    <i class="fas fa-share-alt"></i> Compartilhar
                </button>
            </div>
        </div>

        <!-- Informações sobre o Certificado -->
        <div class="video-card">
            <h2><i class="fas fa-info-circle"></i> Sobre este Certificado</h2>
            <div class="dashboard-sections">
                <div>
                    <h3><i class="fas fa-check-circle"></i> <strong>Validação</strong></h3>
                    <p>Este certificado é válido e pode ser verificado através do código fornecido.</p>
                    <p>A autenticidade pode ser confirmada em nossa plataforma.</p>
                    <h3><i class="fas fa-award"></i> <strong>Reconhecimento</strong></h3>
                    <p>Certificado de participação em atividade de educação continuada.</p>
                    <p>Contribui para o desenvolvimento profissional na área de tradução.</p>
                </div>
                <div>
                    <h3><i class="fas fa-users"></i> <strong>Compartilhamento</strong></h3>
                    <p>Você pode compartilhar este certificado em redes profissionais como LinkedIn.</p>
                    <p>Use-o para demonstrar seu comprometimento com a educação continuada.</p>
                    <h3><i class="fas fa-download"></i> <strong>Download</strong></h3>
                    <p>O certificado está disponível em formato PDF de alta qualidade.</p>
                    <p>Pode ser impresso ou utilizado digitalmente.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.certificate-preview {
    background: white;
    color: #333;
    padding: 40px;
    margin: 20px 0;
    border-radius: 8px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    max-width: 800px;
    margin: 20px auto;
}

.certificate-header {
    text-align: center;
    border-bottom: 3px solid var(--brand-purple);
    padding-bottom: 20px;
    margin-bottom: 30px;
}

.certificate-logo {
    font-size: 4rem;
    color: var(--brand-purple);
    margin-bottom: 10px;
}

.certificate-header h3 {
    font-size: 2rem;
    color: var(--brand-purple);
    margin: 10px 0;
}

.certificate-body {
    text-align: center;
    margin: 30px 0;
}

.certificate-text {
    font-size: 1.1rem;
    margin-bottom: 20px;
    line-height: 1.6;
}

.lecture-title {
    font-size: 1.5rem;
    color: var(--brand-purple);
    margin: 20px 0;
    font-weight: bold;
}

.speaker-info {
    font-size: 1.1rem;
    margin-bottom: 30px;
}

.certificate-details {
    display: flex;
    justify-content: center;
    flex-wrap: wrap;
    gap: 20px;
    margin: 30px 0;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f8f9fa;
    padding: 10px 15px;
    border-radius: 6px;
    font-size: 0.9rem;
}

.certificate-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 40px;
    padding-top: 20px;
    border-top: 1px solid #ddd;
}

.signature-area {
    text-align: center;
}

.signature-line {
    width: 200px;
    height: 1px;
    background: #333;
    margin-bottom: 10px;
}

.certificate-seal {
    font-size: 3rem;
    color: var(--brand-purple);
}

.certificate-actions {
    text-align: center;
    margin: 30px 0;
    display: flex;
    justify-content: center;
    gap: 15px;
    flex-wrap: wrap;
}

@media (max-width: 768px) {
    .certificate-preview {
        padding: 20px;
        margin: 10px;
    }
    
    .certificate-details {
        flex-direction: column;
        align-items: center;
    }
    
    .certificate-footer {
        flex-direction: column;
        gap: 20px;
    }
}
</style>

<script>
function shareCertificate() {
    if (navigator.share) {
        navigator.share({
            title: 'Meu Certificado - Translators101',
            text: 'Acabei de concluir uma palestra na plataforma Translators101!',
            url: window.location.href
        });
    } else {
        const url = window.location.href;
        navigator.clipboard.writeText(url).then(() => {
            alert('Link copiado para a área de transferência!');
        });
    }
}
</script>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>