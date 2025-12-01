<?php
session_start();

// Configurar timezone para Brasil (GMT-3)
date_default_timezone_set('America/Sao_Paulo');

require_once 'config/database.php';

// Função auxiliar para escrever no arquivo de log customizado.
function writeToCustomLog($message) {
    $log_file = __DIR__ . '/certificate_errors.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] [DOWNLOAD] $message\n", FILE_APPEND);
}

// Função para converter PNG para PDF sem sobreposição
function convertPngToPdf($pngPath, $outputPath) {
    try {
        writeToCustomLog("DEBUG: Iniciando conversão PNG para PDF: $pngPath -> $outputPath");
        
        // Verificar se o arquivo PNG existe
        if (!file_exists($pngPath)) {
            writeToCustomLog("ERRO: Arquivo PNG não encontrado: $pngPath");
            return false;
        }
        
        // Verificar extensão GD
        if (!extension_loaded('gd')) {
            writeToCustomLog("ERRO: Extensão GD não está carregada");
            return false;
        }
        
        // Verificar se FPDF está disponível
        $fpdfPath = __DIR__ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (!file_exists($fpdfPath)) {
            writeToCustomLog("AVISO: TCPDF não encontrado, tentando FPDF simples");
            
            // Usar FPDF simples se disponível
            require_once __DIR__ . '/includes/simple_fpdf.php';
            
            // Obter dimensões da imagem PNG
            $imageInfo = getimagesize($pngPath);
            if (!$imageInfo) {
                writeToCustomLog("ERRO: Não foi possível obter informações da imagem PNG");
                return false;
            }
            
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            
            // Calcular dimensões para PDF (A4 landscape se a imagem for mais larga que alta)
            $isLandscape = $width > $height;
            $pdfWidth = $isLandscape ? 297 : 210; // A4 em mm
            $pdfHeight = $isLandscape ? 210 : 297;
            
            // Criar PDF
            $pdf = new FPDF($isLandscape ? 'L' : 'P', 'mm', 'A4');
            $pdf->AddPage();
            
            // Adicionar imagem ocupando toda a página
            $pdf->Image($pngPath, 0, 0, $pdfWidth, $pdfHeight);
            
            // Salvar PDF
            $pdf->Output('F', $outputPath);
            
            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                writeToCustomLog("SUCESSO: PDF criado com FPDF simples: $outputPath");
                return true;
            }
        } else {
            // Usar TCPDF
            require_once $fpdfPath;
            
            // Obter dimensões da imagem PNG
            $imageInfo = getimagesize($pngPath);
            if (!$imageInfo) {
                writeToCustomLog("ERRO: Não foi possível obter informações da imagem PNG");
                return false;
            }
            
            $width = $imageInfo[0];
            $height = $imageInfo[1];
            
            // Determinar orientação
            $isLandscape = $width > $height;
            
            // Criar PDF com TCPDF
            $pdf = new TCPDF($isLandscape ? 'L' : 'P', PDF_UNIT, 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('Translators101');
            $pdf->SetTitle('Certificado');
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);
            
            $pdf->AddPage();
            
            // Adicionar imagem ocupando toda a página
            if ($isLandscape) {
                $pdf->Image($pngPath, 0, 0, 297, 210); // A4 landscape
            } else {
                $pdf->Image($pngPath, 0, 0, 210, 297); // A4 portrait
            }
            
            // Salvar PDF
            $pdf->Output($outputPath, 'F');
            
            if (file_exists($outputPath) && filesize($outputPath) > 0) {
                writeToCustomLog("SUCESSO: PDF criado com TCPDF: $outputPath");
                return true;
            }
        }
        
        writeToCustomLog("ERRO: Falha na criação do PDF");
        return false;
        
    } catch (Exception $e) {
        writeToCustomLog("ERRO: Exceção na conversão PNG para PDF: " . $e->getMessage());
        return false;
    }
}

writeToCustomLog("DEBUG: Script download_certificate_files.php iniciado.");

// Verificar login
if (!isset($_SESSION['user_id'])) {
    writeToCustomLog("INFO: Usuário não logado. Redirecionando para login.php.");
    header('Location: login.php');
    exit;
}

$certificate_id = $_GET['id'] ?? '';
$format = $_GET['format'] ?? 'png'; // Padrão: PNG

if (empty($certificate_id)) {
    writeToCustomLog("ERRO: ID do certificado não fornecido.");
    header('Location: videoteca.php?error=invalid_certificate');
    exit;
}

writeToCustomLog("DEBUG: ID do certificado recebido: $certificate_id, formato solicitado: $format");

try {
    // Buscar dados do certificado no banco
    $user_name = 'Usuario';
    $user_id_from_db = null;

    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.user_id, c.user_name, c.lecture_title, c.speaker_name, c.duration_hours, c.issued_at
            FROM certificates c
            WHERE c.id = ?
        ");
        $stmt->execute([$certificate_id]);
        $certificate_data = $stmt->fetch(); 

        if ($certificate_data) {
            $user_name = $certificate_data['user_name'] ?: 'Usuario';
            $user_id_from_db = $certificate_data['user_id'];
            writeToCustomLog("DEBUG: Dados do certificado encontrados no banco de dados.");

            // Verificar permissão (admin pode baixar todos, usuário só o próprio)
            if ($user_id_from_db !== $_SESSION['user_id'] && !isset($_SESSION['is_admin'])) {
                writeToCustomLog("ALERTA: Acesso negado para o usuário " . $_SESSION['user_id'] . " ao certificado " . $certificate_id);
                header('Location: videoteca.php?error=access_denied');
                exit;
            }
        } else {
            writeToCustomLog("INFO: Certificado não encontrado no banco de dados.");
            header('Location: videoteca.php?error=certificate_not_found');
            exit;
        }
    } catch (Exception $e) {
        writeToCustomLog("ERRO: Erro ao buscar certificado no banco de dados: " . $e->getMessage());
        header('Location: videoteca.php?error=certificate_error');
        exit;
    }

    // Buscar arquivo PNG primeiro (prioritário)
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

    if (!$png_path) {
        writeToCustomLog("ERRO: Arquivo PNG do certificado não encontrado.");
        header('Location: videoteca.php?error=certificate_file_missing');
        exit;
    }

    // Determinar o arquivo final baseado no formato solicitado
    $final_file_path = null;
    $content_type = '';
    $extension = '';

    if ($format === 'pdf') {
        // Usuário quer PDF - converter PNG para PDF
        $pdf_path = __DIR__ . '/certificates/certificate_' . $certificate_id . '_converted.pdf';
        
        // Verificar se já existe PDF convertido e se é mais recente que o PNG
        if (file_exists($pdf_path) && filemtime($pdf_path) >= filemtime($png_path)) {
            writeToCustomLog("DEBUG: PDF convertido já existe e está atualizado: $pdf_path");
            $final_file_path = $pdf_path;
        } else {
            // Converter PNG para PDF
            if (convertPngToPdf($png_path, $pdf_path)) {
                $final_file_path = $pdf_path;
                writeToCustomLog("DEBUG: PNG convertido para PDF com sucesso: $pdf_path");
            } else {
                writeToCustomLog("AVISO: Falha na conversão, usando PNG original");
                $final_file_path = $png_path;
                $format = 'png'; // Fallback para PNG
            }
        }
        
        if ($format === 'pdf') {
            $content_type = 'application/pdf';
            $extension = 'pdf';
        }
    }
    
    // Se não é PDF ou falhou a conversão, usar PNG
    if (!$final_file_path || $format === 'png') {
        $final_file_path = $png_path;
        $content_type = 'image/png';
        $extension = 'png';
    }

    // Preparar nome do arquivo
    $safe_user_name = preg_replace('/[^a-zA-Z0-9]/', '_', $user_name);
    $filename = 'Certificado_' . $safe_user_name . '_' . date('Y-m-d') . '.' . $extension;
    
    writeToCustomLog("DEBUG: Preparando download final: $filename (Tipo: $content_type) - Arquivo: $final_file_path");

    // Verificar se arquivo final existe e tem conteúdo
    if (!file_exists($final_file_path) || filesize($final_file_path) == 0) {
        writeToCustomLog("ERRO: Arquivo final está vazio ou não existe: $final_file_path");
        header('Location: videoteca.php?error=certificate_error');
        exit;
    }

    // Limpar output buffer
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Headers para download
    header('Content-Type: ' . $content_type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($final_file_path));
    header('Cache-Control: private, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Enviar arquivo
    readfile($final_file_path);
    writeToCustomLog("INFO: Arquivo $filename enviado para download com sucesso.");
    exit;

} catch (Exception $e) {
    writeToCustomLog("ERRO FATAL: Exceção no download do certificado: " . $e->getMessage());
    header('Location: videoteca.php?error=certificate_error');
    exit;
}
?>