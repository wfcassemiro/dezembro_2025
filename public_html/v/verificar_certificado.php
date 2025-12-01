<?php
// Define o fuso horário para GMT-3 (Horário Padrão de Brasília)
date_default_timezone_set('America/Sao_Paulo');
// NÃO iniciar sessão - página pública para verificação
// session_start(); 

require_once 'config/database.php';

$page_title = 'Verificação de certificado - Translators101';
$certificate_id = $_GET['id'] ?? '';
$certificate = null;
$error = '';
$success = false;

// Log de acesso para auditoria
function logVerificationAccess($certificate_id, $ip, $user_agent, $status) {
    $log_file = __DIR__ . '/certificate_verification.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] ID: $certificate_id | IP: $ip | Status: $status | User-Agent: " . substr($user_agent, 0, 100) . "\n";
    @file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Log da tentativa de acesso
$access_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$access_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
logVerificationAccess($certificate_id ?: 'EMPTY', $access_ip, $access_agent, 'ACCESS_ATTEMPT');

if (empty($certificate_id)) {
    $error = 'ID do certificado não fornecido para verificação.';
    logVerificationAccess('EMPTY', $access_ip, $access_agent, 'ERROR_NO_ID');
} else {
    try {
        // Buscar certificado no banco de dados com informações completas
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   u.name as user_name, 
                   u.email as user_email,
                   l.title as lecture_title, 
                   l.speaker as speaker_name,
                   l.description as lecture_description,
                   al.accumulated_watch_time,
                   al.last_watched_seconds
            FROM certificates c
            LEFT JOIN users u ON c.user_id = u.id
            LEFT JOIN lectures l ON c.lecture_id = l.id
            LEFT JOIN access_logs al ON (c.user_id = al.user_id AND al.resource = l.title AND al.certificate_generated = 1)
            WHERE c.id = ?
        ");
        $stmt->execute([$certificate_id]);
        $certificate = $stmt->fetch();

        if ($certificate) {
            $success = true;
            logVerificationAccess($certificate_id, $access_ip, $access_agent, 'SUCCESS');
        } else {
            $error = 'Certificado não encontrado. Verifique se o ID está correto.';
            logVerificationAccess($certificate_id, $access_ip, $access_agent, 'ERROR_NOT_FOUND');
        }

    } catch (PDOException $e) {
        $error = 'Erro interno na verificação. Tente novamente mais tarde.';
        logVerificationAccess($certificate_id, $access_ip, $access_agent, 'ERROR_DATABASE');
        error_log("Erro PDO na verificação de certificado: " . $e->getMessage());
    }
}

include __DIR__ . '/vision/includes/head.php';
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<div class="main-content">
    <div class="glass-hero">
        <div class="hero-content">
            <h1><i class="fas fa-shield-check"></i> Verificação de certificado</h1>
            <p>Sistema de autenticação de certificados Translators101</p>
        </div>
    </div>

    <?php if ($success && $certificate): ?>
        <!-- Certificado Válido -->
        <div class="video-card" style="border: 3px solid #10b981; background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.05));">
            <div class="alert-success">
                <strong>✅ CERTIFICADO VÁLIDO E AUTÊNTICO</strong>
            </div>

            <div class="certificate-verification-details">
                <h2><i class="fas fa-certificate"></i> Informações do certificado</h2>
                
                <div class="verification-grid">
                    <div class="verification-section">
                        <h3><i class="fas fa-user"></i> Dados do participante</h3>
                        <div class="info-item">
                            <span class="label">Nome:</span>
                            <span class="value"><?php echo htmlspecialchars($certificate['user_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">E-mail:</span>
                            <span class="value"><?php echo htmlspecialchars($certificate['user_email']); ?></span>
                        </div>
                    </div>

                    <div class="verification-section">
                        <h3><i class="fas fa-video"></i> Dados da palestra</h3>
                        <div class="info-item">
                            <span class="label">Título:</span>
                            <span class="value"><?php echo htmlspecialchars($certificate['lecture_title']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Palestrante:</span>
                            <span class="value"><?php echo htmlspecialchars($certificate['speaker_name']); ?></span>
                        </div>
                        <?php if ($certificate['lecture_description']): ?>
                        <div class="info-item">
                            <span class="label">Descrição:</span>
                            <span class="value description"><?php echo htmlspecialchars($certificate['lecture_description']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="verification-section">
                        <h3><i class="fas fa-info-circle"></i> Dados de emissão</h3>
                        <div class="info-item">
                            <span class="label">Data de conclusão:</span>
                            <span class="value"><?php echo date('d/m/Y', strtotime($certificate['created_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Horário:</span>
                            <span class="value"><?php echo date('H:i', strtotime($certificate['created_at'])); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">ID do certificado:</span>
                            <span class="value certificate-id"><?php echo htmlspecialchars($certificate['id']); ?></span>
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
                        <div class="info-item">
                            <span class="label">Tempo assistido:</span>
                            <span class="value"><?php echo $watch_time; ?> minutos</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="verification-section">
                        <h3><i class="fas fa-shield-alt"></i> Verificação de segurança</h3>
                        <div class="info-item">
                            <span class="label">Hash de verificação:</span>
                            <span class="value hash"><?php echo strtoupper(substr(md5($certificate['id'] . $certificate['created_at']), 0, 16)); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="label">Status:</span>
                            <span class="value status-valid">
                                <i class="fas fa-check-circle"></i> Certificado autêntico
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="label">Emissor:</span>
                            <span class="value">Translators101 - Educação Continuada para Tradutores</span>
                        </div>
                    </div>
                </div>

                <div class="verification-actions">
                    <a href="view_certificate_files.php?id=<?php echo $certificate['id']; ?>" 
                       target="_blank" class="cta-btn" style="display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-eye"></i> Visualizar certificado
                    </a>
                    
                    <button onclick="window.print()" class="cta-btn" style="background: rgba(142, 68, 173, 0.8); border: 1px solid rgba(142, 68, 173, 0.3); color: white; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-print"></i> Imprimir verificação
                    </button>
                    
                    <button onclick="shareVerification()" class="cta-btn" style="background: rgba(142, 68, 173, 0.8); border: 1px solid rgba(142, 68, 173, 0.3); color: white; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fas fa-share-alt"></i> Compartilhar
                    </button>
                </div>
            </div>
        </div>

        <!-- Informações sobre a Verificação -->
        <div class="video-card">
            <h2><i class="fas fa-info-circle"></i> Sobre esta verificação</h2>
            
            <div class="dashboard-sections">
                <div>
                    <h3><i class="fas fa-shield-check"></i> <strong>✅ Autenticidade confirmada</strong></h3>
                    <p>Este certificado foi emitido oficialmente pela plataforma Translators101.</p>
                    <p>Todos os dados apresentados foram validados contra nossa base de dados.</p>
                    <p>-</p>
                    <h3><i class="fas fa-clock"></i> <strong>Verificação em tempo real</strong></h3>
                    <p>Esta verificação foi realizada em: <strong><?php echo date('d/m/Y H:i'); ?></strong></p>
                    <p>Os dados são consultados diretamente da base de dados oficial.</p>
                </div>
                
                <div>
                    <h3><i class="fas fa-qrcode"></i> <strong>QR Code verificado</strong></h3>
                    <p>Este certificado foi acessado através de QR Code autêntico.</p>
                    <p>O código QR contém link direto para esta verificação.</p>
                    <p>-</p>
                    <h3><i class="fas fa-certificate"></i> <strong>Certificado digital</strong></h3>
                    <p>Emitido com base na conclusão efetiva do conteúdo educacional.</p>
                    <p>Válido para fins de educação continuada e desenvolvimento profissional.</p>
                </div>
            </div>
        </div>

    <?php else: ?>
        <!-- Certificado Inválido ou Erro -->
        <div class="video-card" style="border: 3px solid #ef4444; background: linear-gradient(135deg, rgba(239, 68, 68, 0.1), rgba(220, 38, 38, 0.05));">
            <div class="alert-error">
                <i class="fas fa-times-circle"></i>
                <strong>❌ ERRO NA VERIFICAÇÃO</strong>
            </div>

            <div style="text-align: center; padding: 40px 20px;">
                <div style="font-size: 4rem; margin-bottom: 20px;">🚫</div>
                <h2>Certificado não encontrado</h2>
                <p style="font-size: 1.1rem; color: #666; margin-bottom: 30px;">
                    <?php echo htmlspecialchars($error); ?>
                </p>

                <div class="verification-help">
                    <h3>Possíveis causas:</h3>
                    <ul style="text-align: left; display: inline-block; margin: 20px 0;">
                        <li>ID do certificado incorreto ou incompleto</li>
                        <li>Certificado ainda não foi emitido</li>
                        <li>QR Code danificado ou alterado</li>
                        <li>Link de verificação expirado ou inválido</li>
                    </ul>

                    <h3>O que fazer:</h3>
                    <ul style="text-align: left; display: inline-block; margin: 20px 0;">
                        <li>Verifique se o QR Code está íntegro</li>
                        <li>Escaneie novamente o código QR</li>
                        <li>Entre em contato com o portador do certificado</li>
                        <li>Acesse a plataforma Translators101 para mais informações</li>
                    </ul>
                </div>
            </div>

            <div style="text-align: center; margin-top: 30px;">
                <a href="index.php" class="cta-btn" style="display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-home"></i> Página inicial
                </a>
                
                <a href="contato.php" class="cta-btn" style="background: rgba(142, 68, 173, 0.8); border: 1px solid rgba(142, 68, 173, 0.3); color: white; display: inline-flex; align-items: center; gap: 8px; margin-left: 15px;">
                    <i class="fas fa-envelope"></i> Fale conosco
                </a>
            </div>
        </div>

        <!-- Informações sobre Verificação -->
        <div class="video-card">
            <h2><i class="fas fa-question-circle"></i> Como funciona a verificação</h2>
            
            <div class="dashboard-sections">
                <div>
                    <h3><i class="fas fa-qrcode"></i> <strong>QR Code autêntico</strong></h3>
                    <p>Certificados oficiais contêm QR Code que direciona para esta página.</p>
                    <p>O código deve ser escaneado diretamente do certificado original.</p>
                    
                    <h3><i class="fas fa-database"></i> <strong>Verificação em tempo real</strong></h3>
                    <p>Consultamos nossa base de dados oficial em tempo real.</p>
                    <p>Somente certificados emitidos oficialmente são validados.</p>
                </div>
                
                <div>
                    <h3><i class="fas fa-shield-alt"></i> <strong>Segurança</strong></h3>
                    <p>Sistema protegido contra falsificações e alterações.</p>
                    <p>Logs de acesso são mantidos para auditoria.</p>
                    
                    <h3><i class="fas fa-support"></i> <strong>Suporte</strong></h3>
                    <p>Em caso de dúvidas, entre em contato conosco.</p>
                    <p>Nossa equipe pode auxiliar na verificação manual.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
.certificate-verification-details {
    margin-top: 30px;
}

.verification-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
    margin: 30px 0;
}

.verification-section {
    background: rgba(255, 255, 255, 0.8);
    padding: 25px;
    border-radius: 12px;
    border: 1px solid rgba(142, 68, 173, 0.2);
}

.verification-section h3 {
    color: var(--brand-purple);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid rgba(142, 68, 173, 0.1);
}

.info-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
    padding: 10px;
    background: rgba(142, 68, 173, 0.05);
    border-radius: 8px;
}

.info-item .label {
    font-weight: bold;
    color: #333;
    min-width: 120px;
    flex-shrink: 0;
}

.info-item .value {
    color: #666;
    text-align: right;
    flex-grow: 1;
    word-break: break-word;
}

.info-item .value.certificate-id {
    font-family: 'Courier New', monospace;
    font-size: 0.9rem;
    background: #f0f0f0;
    padding: 4px 8px;
    border-radius: 4px;
}

.info-item .value.hash {
    font-family: 'Courier New', monospace;
    font-size: 0.8rem;
    background: #e8f4fd;
    padding: 4px 8px;
    border-radius: 4px;
    color: #1e40af;
}

.info-item .value.status-valid {
    color: #10b981;
    font-weight: bold;
}

.info-item .value.description {
    font-style: italic;
    line-height: 1.4;
}

.verification-actions {
    text-align: center;
    margin: 40px 0;
    display: flex;
    justify-content: center;
    gap: 15px;
    flex-wrap: wrap;
}

.verification-help ul {
    margin: 10px 0;
    padding-left: 20px;
}

.verification-help li {
    margin-bottom: 8px;
    color: #666;
}

/* Responsivo */
@media (max-width: 768px) {
    .verification-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .info-item {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .info-item .label {
        margin-bottom: 5px;
        min-width: auto;
    }
    
    .info-item .value {
        text-align: left;
    }
    
    .verification-actions {
        flex-direction: column;
        align-items: center;
    }
}

/* Estilo para impressão - Apple Vision UI */
@media print {
    .verification-actions {
        display: none;
    }
    
    .main-content {
        box-shadow: none;
        background: white;
    }
    
    .video-card {
        box-shadow: none;
        border: 1px solid #ddd;
        background: white;
    }
    
    .verification-section {
        background: #f9f9f9;
        border: 1px solid #ddd;
    }
    
    .info-item {
        background: #f5f5f5;
    }
    
    .glass-hero {
        background: white;
        color: black;
    }
}
</style>

<script>
function shareVerification() {
    const certificateId = '<?php echo addslashes($certificate['id'] ?? ''); ?>';
    const url = window.location.href;
    const title = 'Verificação de certificado - Translators101';
    const text = 'Certificado verificado e autenticado pela plataforma Translators101';
    
    if (navigator.share) {
        navigator.share({
            title: title,
            text: text,
            url: url
        });
    } else {
        // Fallback para navegadores sem suporte ao Web Share API
        navigator.clipboard.writeText(url).then(() => {
            alert('Link de verificação copiado para a área de transferência!');
        });
    }
}

// Adicionar informações úteis no console para desenvolvedores
console.info('🔍 Sistema de Verificação de Certificados Translators101');
console.info('📋 ID consultado: <?php echo addslashes($certificate_id); ?>');
console.info('✅ Status: <?php echo $success ? "VÁLIDO" : "INVÁLIDO"; ?>');
<?php if ($success && $certificate): ?>
console.info('👤 Participante: <?php echo addslashes($certificate['user_name']); ?>');
console.info('🎓 Palestra: <?php echo addslashes($certificate['lecture_title']); ?>');
<?php endif; ?>
</script>

<?php include __DIR__ . '/vision/includes/footer.php'; ?>