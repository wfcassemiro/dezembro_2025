<?php
// budget.php - Versão adaptada para análise CSV de CAT Tools
header('Content-Type: text/html; charset=UTF-8');
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/dash_database.php';
require_once __DIR__ . '/../config/dash_functions.php';

// Verifica se o usuário está logado
if (!isLoggedIn()) {
    header('Location: /login.php');
    exit;
}

// Inicializa variáveis de sessão
if (!isset($_SESSION['analyses'])) $_SESSION['analyses'] = [];
if (!isset($_SESSION['budget_errors'])) $_SESSION['budget_errors'] = [];
if (!isset($_SESSION['budget_notices'])) $_SESSION['budget_notices'] = [];

// Configuração dos pesos para fuzzy matches - INCLUINDO Repetition e 101%
if (!isset($_SESSION['wc_weights'])) {
    $_SESSION['wc_weights'] = [
        'Repetition' => 0.0,  // Repetições - geralmente não são cobradas
        '101%' => 0.05,       // Contexto Match - cobrança mínima
        '100%' => 0.1,        // Correspondência exata
        '95-99%' => 0.2,      // Alta similaridade
        '85-94%' => 0.4,      // Similaridade moderada
        '75-84%' => 0.6,      // Similaridade baixa
        '50-74%' => 0.8,      // Similaridade muito baixa
        'No Match' => 1.0,    // Sem correspondência (custo total)
    ];
}

// Configurações iniciais do cliente
if (!isset($_SESSION['budget_client'])) {
    $_SESSION['budget_client'] = [
        'client_id' => null,
        'client_name' => '',
        'currency' => '',
        'service' => 'translation',
        'lang_from' => null,
        'lang_to' => null,
    ];
}

// Parâmetros de cálculo
if (!isset($_SESSION['budget_params'])) {
    $_SESSION['budget_params'] = [
        'markup_pct' => 30.0,
        'tax_pct' => 11.5,
    ];
}

// Custos adicionados manualmente
if (!isset($_SESSION['budget_costs']) || !isset($_SESSION['budget_costs']['items'])) {
    $_SESSION['budget_costs'] = ['items' => []];
}

// Passo atual no fluxo
if (!isset($_SESSION['budget_flow_step'])) {
    $_SESSION['budget_flow_step'] = 1;
}

// Dados para PDF
if (!isset($_SESSION['budget_pdf_data'])) {
    $_SESSION['budget_pdf_data'] = [
        'contact_name' => '',
        'delivery_date' => '',
        'validity_date' => '',
        'final_price' => 0,
        'files' => [],
    ];
}

/**
 * Função para processar CSV de análise de CAT Tool
 */
function processAnalysisCSV($csvPath, $fileName) {
    // Tenta detectar o encoding do arquivo
    $content = file_get_contents($csvPath);
    if ($content === false) {
        throw new Exception('Não foi possível ler o arquivo CSV');
    }
    
    // Remove BOM se existir
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    
    // Detecta encoding e converte para UTF-8
    $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        error_log("CSV $fileName convertido de $encoding para UTF-8");
    }
    
    // Detecta o delimitador (vírgula ou ponto-e-vírgula)
    $delimiter = ',';
    if (substr_count($content, ';') > substr_count($content, ',')) {
        $delimiter = ';';
        error_log("CSV $fileName usa ponto-e-vírgula como delimitador");
    }
    
    $analysis = [
        'fileName' => $fileName,
        'fuzzyMatches' => [],
        'totalWords' => 0,
        'totalSegments' => 0,
        'weightedWordCount' => 0,
    ];
    
    $lines = explode("\n", $content);
    $inDataSection = false;
    $headers = [];
    $dataLineCount = 0;
    
    foreach ($lines as $lineNum => $line) {
        $line = trim($line);
        
        // Pula linhas vazias
        if (empty($line)) continue;
        
        // Detecta início da seção de dados
        if (stripos($line, 'Type') !== false && stripos($line, 'Segments') !== false) {
            $headers = str_getcsv($line, $delimiter);
            $inDataSection = true;
            error_log("CSV $fileName: Cabeçalho encontrado na linha " . ($lineNum + 1));
            continue;
        }
        
        // Processa linhas de dados
        if ($inDataSection && !preg_match('/^-+/', $line)) {
            $data = str_getcsv($line, $delimiter);
            
            // Debug: log da linha sendo processada
            if ($dataLineCount < 3) {
                error_log("CSV $fileName linha " . ($lineNum + 1) . ": " . count($data) . " colunas");
            }
            
            // Verifica se tem dados suficientes (pelo menos 3 colunas)
            if (count($data) < 3) continue;
            
            $matchType = trim($data[0]);
            $segments = isset($data[1]) ? intval(str_replace([',', '.'], '', $data[1])) : 0;
            
            // Tenta encontrar a coluna de palavras (pode estar na posição 2 ou 3)
            $words = 0;
            if (isset($data[2]) && is_numeric(str_replace([',', '.'], '', $data[2]))) {
                $words = intval(str_replace([',', '.'], '', $data[2]));
            } elseif (isset($data[3]) && is_numeric(str_replace([',', '.'], '', $data[3]))) {
                $words = intval(str_replace([',', '.'], '', $data[3]));
            }
            
            // Normaliza o tipo de match
            $normalizedType = normalizeMatchType($matchType);
            
            if ($normalizedType && $words > 0) {
                $analysis['fuzzyMatches'][] = [
                    'category' => $normalizedType,
                    'segments' => $segments,
                    'words' => $words,
                ];
                
                $analysis['totalSegments'] += $segments;
                $analysis['totalWords'] += $words;
                $dataLineCount++;
            }
        }
        
        // Para ao encontrar a linha de separação final
        if (preg_match('/^-{3,}/', $line)) {
            error_log("CSV $fileName: Fim da seção de dados na linha " . ($lineNum + 1));
            break;
        }
    }
    
    error_log("CSV $fileName: Total processado - $dataLineCount linhas, {$analysis['totalWords']} palavras, {$analysis['totalSegments']} segmentos");
    
    if ($analysis['totalWords'] === 0) {
        throw new Exception("Nenhuma palavra encontrada no arquivo. Processadas $dataLineCount linhas de dados. Verifique o formato do CSV.");
    }
    
    return $analysis;
}

/**
 * Normaliza os tipos de match para corresponder aos pesos configurados
 */
function normalizeMatchType($matchType) {
    $matchType = trim($matchType);
    $matchTypeLower = strtolower($matchType);
    
    // Mapeia os tipos encontrados nos CSVs para as categorias de peso
    $typeMap = [
        // Repetition
        'repetition' => 'Repetition',
        'repetitions' => 'Repetition',
        'rep' => 'Repetition',
        'reps' => 'Repetition',
        
        // 101% / Context Match
        '101%' => '101%',
        'context match' => '101%',
        'cm' => '101%',
        'context' => '101%',
        
        // 100%
        '100%' => '100%',
        'perfect match' => '100%',
        'exact match' => '100%',
        
        // 95-99%
        '95%-99%' => '95-99%',
        '95-99%' => '95-99%',
        '95% - 99%' => '95-99%',
        '95 - 99%' => '95-99%',
        
        // 85-94%
        '85%-94%' => '85-94%',
        '85-94%' => '85-94%',
        '85% - 94%' => '85-94%',
        '85 - 94%' => '85-94%',
        
        // 75-84%
        '75%-84%' => '75-84%',
        '75-84%' => '75-84%',
        '75% - 84%' => '75-84%',
        '75 - 84%' => '75-84%',
        
        // 50-74%
        '50%-74%' => '50-74%',
        '50-74%' => '50-74%',
        '50% - 74%' => '50-74%',
        '50 - 74%' => '50-74%',
        
        // No Match
        'no match' => 'No Match',
        'no-match' => 'No Match',
        'new' => 'No Match',
        'fragments' => 'No Match',
        'fragment' => 'No Match',
    ];
    
    // Tenta com o valor original primeiro
    if (isset($typeMap[$matchType])) {
        return $typeMap[$matchType];
    }
    
    // Tenta com lowercase
    if (isset($typeMap[$matchTypeLower])) {
        return $typeMap[$matchTypeLower];
    }
    
    // Tenta detectar por padrão de porcentagem
    if (preg_match('/(\d+)%?\s*-\s*(\d+)%?/', $matchType, $matches)) {
        $start = intval($matches[1]);
        $end = intval($matches[2]);
        
        if ($start >= 95 && $end <= 99) return '95-99%';
        if ($start >= 85 && $end <= 94) return '85-94%';
        if ($start >= 75 && $end <= 84) return '75-84%';
        if ($start >= 50 && $end <= 74) return '50-74%';
    }
    
    // Tenta detectar porcentagem única
    if (preg_match('/(\d+)%/', $matchType, $matches)) {
        $percent = intval($matches[1]);
        
        if ($percent === 101) return '101%';
        if ($percent === 100) return '100%';
        if ($percent >= 95 && $percent <= 99) return '95-99%';
        if ($percent >= 85 && $percent <= 94) return '85-94%';
        if ($percent >= 75 && $percent <= 84) return '75-84%';
        if ($percent >= 50 && $percent <= 74) return '50-74%';
    }
    
    error_log("Tipo de match não reconhecido: '$matchType'");
    return null;
}

/**
 * Converte string BRL para float
 */
function parseBRLFloat($value) {
    if (empty($value)) return 0.0;
    $value = trim($value);
    $value = str_replace(',', '.', $value);
    
    $parts = explode('.', $value);
    if (count($parts) > 2) {
        $value = implode('', array_slice($parts, 0, -1)) . '.' . end($parts);
    }
    return (float)$value;
}

// ==================== HANDLER: Adicionar Cliente ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_client') {
    $company_name = trim($_POST['company_name'] ?? '');
    $default_currency = trim($_POST['default_currency'] ?? 'BRL');
    
    if (!empty($company_name)) {
        try {
            global $pdo;
            $currentUserId = $_SESSION['user_id'] ?? null;
            
            $stmt = $pdo->prepare("INSERT INTO dash_clients (user_id, name, default_currency, created_at) VALUES (:uid, :name, :currency, NOW())");
            $stmt->execute([
                ':uid' => $currentUserId,
                ':name' => $company_name,
                ':currency' => $default_currency
            ]);
            
            $newClientId = $pdo->lastInsertId();
            
            $_SESSION['budget_notices'][] = 'Cliente adicionado!';
            $_SESSION['budget_client']['client_id'] = $newClientId;
            $_SESSION['budget_client']['client_name'] = $company_name;
            $_SESSION['budget_client']['currency'] = $default_currency;
            
        } catch (Throwable $e) {
            $_SESSION['budget_errors'][] = 'Erro ao adicionar cliente: ' . $e->getMessage();
        }
    }
    
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ==================== HANDLER: Gerar PDF ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_pdf') {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $contactName = trim($_POST['contact_name'] ?? '');
    $companyName = trim($_POST['company_name'] ?? '');
    $deliveryDate = trim($_POST['delivery_date'] ?? '');
    $validityDate = trim($_POST['validity_date'] ?? '');
    $paymentMethods = $_POST['payment_methods'] ?? [];
    $paymentDate = trim($_POST['payment_date'] ?? '');
    $finalPrice = parseBRLFloat($_POST['final_price'] ?? '0');
    $selectedFiles = $_POST['selected_files'] ?? [];
    
    $clientName = $_SESSION['budget_client']['client_name'] ?? 'Cliente';
    $currency = $_SESSION['budget_client']['currency'] ?? 'BRL';
    $langFrom = $_SESSION['budget_client']['lang_from'] ?? '';
    $langTo = $_SESSION['budget_client']['lang_to'] ?? '';
    
    class MYPDF extends TCPDF {
        public function Footer() {
            $this->SetY(-15);
            $this->SetFont('dejavusans', '', 8);
            $this->SetTextColor(150, 150, 150);
            $this->Cell(0, 10, 'Orçamento gerado pelo Dash-T101, da Translators101', 0, 0, 'C');
        }
    }
    
    $pdf = new MYPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    $pdf->SetCreator('Dash-T101');
    $pdf->SetAuthor('Dash-T101');
    $pdf->SetTitle('Orçamento - ' . $clientName);
    $pdf->SetSubject('Orçamento de Tradução');
    
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(true);
    
    $pdf->SetMargins(20, 20, 20);
    $pdf->SetAutoPageBreak(true, 20);
    
    $pdf->AddFont('dejavusans', '', 'dejavusans.php');
    $pdf->AddFont('dejavusans', 'B', 'dejavusansb.php');
    $pdf->AddFont('dejavusans', 'I', 'dejavusansi.php');
    
    $pdf->AddPage();
    
    // Título
    $pdf->SetFont('dejavusans', 'B', 24);
    $pdf->SetTextColor(74, 20, 140);
    $pdf->Cell(0, 15, 'Orçamento', 0, 1, 'C');
    
    $pdf->Ln(5);
    
    // Informações da Empresa
    if (!empty($companyName)) {
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 8, 'Empresa:', 0, 1);
        
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(0, 6, $companyName, 0, 1);
        
        $pdf->Ln(5);
    }
    
    // Informações do Contato
    if (!empty($contactName)) {
        $pdf->SetFont('dejavusans', 'B', 14);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 8, 'Contato:', 0, 1);
        
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(0, 6, $contactName, 0, 1);
        
        $pdf->Ln(5);
    }
    
    // Datas
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(90, 6, 'Prazo de entrega:', 0, 0);
    $pdf->SetFont('dejavusans', '', 12);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 6, $deliveryDate, 0, 1);
    
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(90, 6, 'Validade do orçamento:', 0, 0);
    $pdf->SetFont('dejavusans', '', 12);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 6, $validityDate, 0, 1);
    
    $pdf->SetFont('dejavusans', 'B', 12);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(90, 6, 'Orçamento gerado em:', 0, 0);
    $pdf->SetFont('dejavusans', '', 12);
    $pdf->SetTextColor(60, 60, 60);
    $pdf->Cell(0, 6, date('d-m-Y'), 0, 1);
    
    $pdf->Ln(5);
    
    // Formas de pagamento
    if (!empty($paymentMethods)) {
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 6, 'Formas de pagamento:', 0, 1);
        
        $pdf->SetFont('dejavusans', '', 12);
        $pdf->SetTextColor(60, 60, 60);
        foreach ($paymentMethods as $method) {
            $pdf->Cell(10, 6, '•', 0, 0);
            $pdf->Cell(0, 6, $method, 0, 1);
        }
    }
    
    $pdf->Ln(10);
    
    // Arquivos
    $pdf->SetFont('dejavusans', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell(0, 8, 'Arquivos para tradução:', 0, 1);
    
    if (!empty($langFrom) && !empty($langTo)) {
        $pdf->SetFont('dejavusans', 'I', 11);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(0, 6, 'Idioma de origem: ' . strtoupper($langFrom) . ' -> Idioma de chegada: ' . strtoupper($langTo), 0, 1);
        $pdf->Ln(3);
    }
    
    $pdf->SetFont('dejavusans', '', 11);
    $pdf->SetTextColor(60, 60, 60);
    
    foreach ($selectedFiles as $file) {
        $pdf->Cell(10, 6, '•', 0, 0);
        $pdf->Cell(0, 6, $file, 0, 1);
    }
    
    $pdf->Ln(10);
    
    // Valor Total
    $pdf->SetFillColor(244, 244, 244);
    $pdf->SetFont('dejavusans', 'B', 16);
    $pdf->SetTextColor(34, 197, 94);
    $pdf->Cell(0, 12, 'Valor total: ' . $currency . ' ' . number_format($finalPrice, 2, ',', '.'), 0, 1, 'C', true);
    
    if (!empty($paymentDate)) {
        $pdf->Ln(5);
        $pdf->SetFont('dejavusans', 'B', 12);
        $pdf->SetTextColor(80, 80, 80);
        $pdf->Cell(0, 6, 'Data de pagamento: ' . $paymentDate, 0, 1, 'C');
    }
    
    $pdf->Ln(15);
    
    $pdf->SetFont('dejavusans', 'I', 9);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->MultiCell(0, 5, 'Este orçamento é válido até a data especificada acima. Após a aprovação, iniciaremos o trabalho imediatamente.', 0, 'L');
    
    $filename = 'Orcamento - ' . $contactName . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// ==================== HANDLERS AJAX ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    
    try {
        switch ($_POST['ajax_action']) {
            case 'update_client':
                $clientState = $_SESSION['budget_client'];
                $clientState['client_id'] = !empty($_POST['client_id']) ? (int)$_POST['client_id'] : null;
                $clientState['service'] = !empty($_POST['service']) ? trim($_POST['service']) : 'translation';
                $clientState['lang_from'] = $_POST['lang_from'] !== '' ? $_POST['lang_from'] : null;
                $clientState['lang_to'] = $_POST['lang_to'] !== '' ? $_POST['lang_to'] : null;
                $clientState['currency'] = isset($_POST['currency']) ? trim($_POST['currency']) : '';

                global $pdo;
                if ($clientState['client_id']) {
                    $stmt = $pdo->prepare("SELECT name, default_currency FROM dash_clients WHERE id = :id");
                    $stmt->execute([':id' => $clientState['client_id']]);
                    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $clientState['client_name'] = $row['name'];
                        if ($clientState['currency'] === '' && !empty($row['default_currency'])) {
                            $clientState['currency'] = $row['default_currency'];
                        }
                    }
                }

                if ($clientState['currency'] === '') {
                    $clientState['currency'] = 'BRL';
                }

                $_SESSION['budget_client'] = $clientState;
                $_SESSION['budget_flow_step'] = max($_SESSION['budget_flow_step'], 2);
                
                $response['success'] = true;
                $response['message'] = 'Cliente configurado';
                $response['next_step'] = 2;
                break;

            case 'update_weights':
                $keys = ['Repetition', '101%', '100%', '95-99%', '85-94%', '75-84%', '50-74%', 'No Match'];
                $newWeights = [];
                foreach ($keys as $k) {
                    $field = 'w_' . preg_replace('/[^0-9A-Za-z]/', '_', $k);
                    $val = isset($_POST[$field]) ? (float)str_replace(',', '.', $_POST[$field]) : 0.0;
                    if ($val < 0) $val = 0.0;
                    $newWeights[$k] = $val;
                }
                $_SESSION['wc_weights'] = $newWeights;
                $_SESSION['budget_flow_step'] = max($_SESSION['budget_flow_step'], 3);
                
                $response['success'] = true;
                $response['message'] = 'Pesos atualizados';
                $response['next_step'] = 3;
                break;

            case 'remove_analysis':
                $index = (int)($_POST['analysis_index'] ?? -1);
                if (isset($_SESSION['analyses'][$index])) {
                    array_splice($_SESSION['analyses'], $index, 1);
                    $response['success'] = true;
                    $response['message'] = 'Arquivo removido do orçamento';
                } else {
                    throw new Exception('Arquivo não encontrado');
                }
                break;

            case 'update_params':
                $_SESSION['budget_params']['markup_pct'] = parseBRLFloat($_POST['markup_pct'] ?? '30');
                $_SESSION['budget_params']['tax_pct'] = parseBRLFloat($_POST['tax_pct'] ?? '11.5');
                
                $response['success'] = true;
                $response['message'] = 'Parâmetros atualizados';
                break;

            case 'add_cost':
                $provider_id = $_POST['provider_id'] ?? 'outro';
                $service = $_POST['cost_service'] ?? 'Tradução';
                $cost_value = parseBRLFloat($_POST['cost_value'] ?? '0');
                
                $provider_name = 'Custo Diverso';
                if ($provider_id === 'interno') {
                    $provider_name = 'Interno';
                } elseif ($provider_id !== 'outro') {
                    global $pdo;
                    $stmt = $pdo->prepare("SELECT name FROM dash_freelancers WHERE id = :id");
                    $stmt->execute([':id' => $provider_id]);
                    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $provider_name = $row['name'];
                    }
                }

                if ($cost_value > 0) {
                    $_SESSION['budget_costs']['items'][] = [
                        'provider_id' => $provider_id,
                        'provider_name' => $provider_name,
                        'service' => $service,
                        'unit_cost' => $cost_value
                    ];
                    
                    $response['success'] = true;
                    $response['message'] = 'Custo adicionado';
                    $response['cost_item'] = end($_SESSION['budget_costs']['items']);
                    $response['cost_index'] = count($_SESSION['budget_costs']['items']) - 1;
                } else {
                    throw new Exception('O valor do custo deve ser maior que zero.');
                }
                break;

            case 'remove_cost':
                $index = (int)($_POST['cost_index'] ?? -1);
                if (isset($_SESSION['budget_costs']['items'][$index])) {
                    array_splice($_SESSION['budget_costs']['items'], $index, 1);
                    $response['success'] = true;
                    $response['message'] = 'Custo removido';
                } else {
                    throw new Exception('Custo não encontrado');
                }
                break;

            case 'calculate_budget':
                if (empty($_SESSION['budget_costs']['items'])) {
                    throw new Exception('Adicione pelo menos um custo antes de calcular');
                }
                
                $_SESSION['budget_flow_step'] = 5;
                
                $totalWords = 0;
                $totalSegments = 0;
                $weightedSum = 0;
                $totalPages = 0;

                if (!empty($_SESSION['analyses'])) {
                    foreach ($_SESSION['analyses'] as $analysis) {
                        $totalWords += $analysis['totalWords'];
                        $totalSegments += $analysis['totalSegments'];
                        $weightedSum += $analysis['weightedWordCount'] ?? 0;
                        $totalPages += $analysis['estimatedPages'] ?? 0;
                    }
                }

                $custoTotal = 0.0;

                foreach ($_SESSION['budget_costs']['items'] as $item) {
                    $service = $item['service'];
                    $unitCost = $item['unit_cost'];
                    $totalCost = 0.0;

                    switch ($service) {
                        case 'Tradução':
                            $totalCost = $unitCost * $weightedSum;
                            break;
                        case 'Pós-edição':
                        case 'Revisão':
                            $totalCost = $unitCost * $totalWords;
                            break;
                        case 'Diagramação':
                            $totalCost = $unitCost * $totalPages;
                            break;
                        default:
                            $totalCost = $unitCost;
                            break;
                    }

                    $custoTotal += $totalCost;
                }

                $markupPct = $_SESSION['budget_params']['markup_pct'] ?? 30.0;
                $taxPct = $_SESSION['budget_params']['tax_pct'] ?? 11.5;

                $subtotalSemImposto = $custoTotal * (1 + ($markupPct / 100));
                $valorImposto = $subtotalSemImposto * ($taxPct / 100);
                $sugestaoCliente = $subtotalSemImposto + $valorImposto;

                $currencyLabel = $_SESSION['budget_client']['currency'] !== '' ? $_SESSION['budget_client']['currency'] : 'BRL';

                $fileNames = [];
                foreach ($_SESSION['analyses'] as $analysis) {
                    $fileNames[] = $analysis['fileName'];
                }
                
                $_SESSION['budget_pdf_data'] = [
                    'contact_name' => '',
                    'delivery_date' => '',
                    'validity_date' => '',
                    'final_price' => $sugestaoCliente,
                    'files' => $fileNames,
                ];

                $response['success'] = true;
                $response['message'] = 'Orçamento calculado';
                $response['results'] = [
                    'totalWords' => $totalWords,
                    'totalSegments' => $totalSegments,
                    'weightedSum' => $weightedSum,
                    'totalPages' => $totalPages,
                    'custoTotal' => $custoTotal,
                    'subtotal' => $subtotalSemImposto,
                    'impostos' => $valorImposto,
                    'precoFinal' => $sugestaoCliente,
                    'currency' => $currencyLabel,
                    'markupPct' => $markupPct,
                    'taxPct' => $taxPct,
                ];
                break;

            default:
                throw new Exception('Ação inválida');
        }
    } catch (Throwable $e) {
        $response['success'] = false;
        $response['message'] = $e->getMessage();
    }
    
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== UPLOAD DE ARQUIVOS CSV ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_files']) && !isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => '', 'analyses' => []];
    
    try {
        $files = $_FILES['csv_files'];
        
        if (!isset($files['name']) || !is_array($files['name'])) {
            throw new Exception('Nenhum arquivo foi selecionado.');
        }
        
        $processed_any = false;
        $fileCount = count($files['name']);
        
        for ($i = 0; $i < $fileCount; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            
            $tmpName = $files['tmp_name'][$i];
            $fileName = $files['name'][$i];
            
            // Verifica se é CSV
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if ($ext !== 'csv') {
                continue;
            }
            
            try {
                $result = processAnalysisCSV($tmpName, $fileName);
                
                if ($result['totalWords'] > 0) {
                    // Calcula palavras ponderadas
                    $weights = $_SESSION['wc_weights'];
                    $weighted = 0;
                    
                    foreach ($result['fuzzyMatches'] as $match) {
                        $w = $weights[$match['category']] ?? 1.0;
                        $weighted += $match['words'] * $w;
                    }
                    
                    $result['weightedWordCount'] = (int)round($weighted);
                    $result['estimatedPages'] = max(1, (int)round($result['totalWords'] / 250));
                    
                    $_SESSION['analyses'][] = $result;
                    $response['analyses'][] = $result;
                    $processed_any = true;
                }
            } catch (Throwable $e) {
                error_log("Erro ao processar CSV {$fileName}: " . $e->getMessage());
            } finally {
                // Descarta o arquivo CSV após processamento
                if (file_exists($tmpName)) {
                    @unlink($tmpName);
                }
            }
        }

        if ($processed_any) {
            $_SESSION['budget_flow_step'] = max($_SESSION['budget_flow_step'], 4);
            $response['success'] = true;
            $response['message'] = 'Arquivos CSV processados';
            $response['next_step'] = 4;
        } else {
            throw new Exception('Nenhum arquivo CSV válido foi processado.');
        }
    } catch (Throwable $e) {
        $response['message'] = $e->getMessage();
    }
    
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== LIMPAR SESSÃO ====================
if (isset($_GET['clear'])) {
    unset($_SESSION['analyses']);
    unset($_SESSION['budget_costs']);
    unset($_SESSION['budget_pdf_data']);
    $_SESSION['budget_flow_step'] = 1;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ==================== DADOS PARA RENDERIZAÇÃO ====================
$budgetClient = $_SESSION['budget_client'];
$budgetCosts = $_SESSION['budget_costs'];
$budgetParams = $_SESSION['budget_params'];
$budgetPdfData = $_SESSION['budget_pdf_data'];
$currentStep = $_SESSION['budget_flow_step'] ?? 1;

$clientsList = [];
$providersList = [];
$currenciesList = [];

try {
    global $pdo;
    $currentUserId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

    if ($currentUserId) {
        $stmt = $pdo->prepare("SELECT id, name, default_currency FROM dash_clients WHERE user_id = :uid ORDER BY name ASC");
        $stmt->execute([':uid' => $currentUserId]);
        $clientsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtProviders = $pdo->prepare("SELECT id, name FROM dash_freelancers WHERE user_id = :uid ORDER BY name ASC");
        $stmtProviders->execute([':uid' => $currentUserId]);
        $providersList = $stmtProviders->fetchAll(PDO::FETCH_ASSOC);
        
        $stmtCurrency = $pdo->prepare("SELECT setting_key, setting_value FROM dash_settings WHERE user_id = :uid AND setting_key LIKE 'rate_%'");
        $stmtCurrency->execute([':uid' => $currentUserId]);
        $currencySettings = $stmtCurrency->fetchAll(PDO::FETCH_ASSOC);
        
        $currenciesList = ['BRL'];
        foreach ($currencySettings as $cs) {
            $currency = strtoupper(str_replace('rate_', '', $cs['setting_key']));
            if (!in_array($currency, $currenciesList)) {
                $currenciesList[] = $currency;
            }
        }
    }
} catch (Throwable $e) {
    // Em caso de erro, as listas permanecem vazias
}

$page_title = 'Orçamentos - Dash-T101';
$page_description = "Gere orçamentos com análises CSV de CAT Tools";

include __DIR__ . '/../vision/includes/head.php';
include __DIR__ . '/../vision/includes/header.php';
include __DIR__ . '/../vision/includes/sidebar.php';
?>

<!-- CSS continua igual... -->
<style>
.main-content { 
    padding-bottom: 100px; 
    padding-top: 10px;
    transition: margin-left 0.3s ease;
}

.profile-header-card {
    background: linear-gradient(135deg, #9D4EDD, #5A189A) !important;
    border: none;
    margin-bottom: 30px;
    margin-top: 10px;
    display: flex !important;
    flex-direction: row !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 20px 30px;
    border-radius: 16px;
}

.profile-header-card .header-icon-container {
    background: rgba(255, 255, 255, 0.15);
    border-radius: 50%;
    width: 65px;
    height: 65px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    align-self: center;
}

.profile-header-card .header-icon-container i {
    font-size: 2rem;
    color: #fff;
}

.profile-header-card .header-text-container {
    margin-left: 25px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.profile-header-card .header-text-container h2 {
    margin: 0 0 6px 0;
    padding: 0;
    font-size: 1.6rem;
    color: #fff;
    font-weight: 700;
    border: none;
    line-height: 1.3;
}

.profile-header-card .header-text-container p {
    margin: 0;
    color: rgba(255, 255, 255, 0.85);
    font-size: 1rem;
    font-weight: 400;
    line-height: 1.4;
}

.report-nav-buttons { display: flex; gap: 15px; margin-bottom: 20px; }

.cards-grid-2col {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

.video-card {
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.02), rgba(255, 255, 255, 0.05));
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 20px;
    display: flex;
    flex-direction: column;
    transition: all 0.3s ease;
}

.video-card > h2 {
    margin: 0;
    padding: 25px 30px 20px;
    font-size: 1.3rem;
    font-weight: 600;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.video-card:hover {
    background-color: rgba(74, 20, 140, 0.15);
    border-color: rgba(170, 100, 255, 0.25);
}

.video-card.disabled {
    opacity: 0.4;
    pointer-events: none;
    filter: grayscale(0.5);
}

.video-card.completed > h2 {
    background: linear-gradient(90deg, rgba(46, 204, 113, 0.08), rgba(39, 174, 96, 0.05));
    border-bottom-color: rgba(46, 204, 113, 0.2);
}

.video-card.completed > h2::after {
    content: "✓";
    background: #2ecc71;
    color: white;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    font-weight: bold;
    margin-left: auto;
}

.vision-form-refined {
    padding: 25px 30px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: rgba(255, 255, 255, 0.9);
}

.vision-input, .vision-select {
    width: 100%;
    padding: 12px 16px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    color: #fff;
    font-size: 1rem;
}

.vision-input:focus, .vision-select:focus {
    outline: none;
    border-color: #9D4EDD;
    background: rgba(157, 78, 221, 0.1);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-actions {
    display: flex;
    justify-content: flex-end;
    padding: 20px 30px;
    gap: 15px;
}

.vision-btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.vision-btn-primary {
    background: linear-gradient(135deg, #9D4EDD, #7B2CBF);
    color: white;
}

.vision-btn-primary:hover {
    background: linear-gradient(135deg, #7B2CBF, #5A189A);
    transform: translateY(-2px);
}

.vision-btn-secondary {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
}

.vision-btn-secondary:hover {
    background: rgba(255, 255, 255, 0.2);
}

.vision-btn-success {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
}

.vision-btn-success:hover {
    background: linear-gradient(135deg, #059669, #047857);
}

.analysis-list-item {
    padding: 15px 20px;
    background: rgba(0, 0, 0, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 10px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.analysis-list-item .file-name {
    font-weight: 600;
    color: #c084fc;
}

.analysis-list-item .file-stats {
    font-size: 0.9rem;
    color: rgba(255, 255, 255, 0.7);
}

.fuzzy-breakdown {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 10px;
    margin-top: 10px;
}

.fuzzy-item {
    background: rgba(157, 78, 221, 0.1);
    padding: 8px;
    border-radius: 6px;
    text-align: center;
}

.fuzzy-item .category {
    font-size: 0.8rem;
    color: rgba(255, 255, 255, 0.6);
}

.fuzzy-item .words {
    font-size: 1.1rem;
    font-weight: 600;
    color: #c084fc;
}

.vision-table-container {
    overflow-x: auto;
    margin: 20px 0;
}

.vision-table {
    width: 100%;
    border-collapse: collapse;
}

.vision-table thead th {
    background: rgba(157, 78, 221, 0.2);
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid rgba(157, 78, 221, 0.3);
}

.vision-table tbody td {
    padding: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
}

.vision-table tbody tr:hover {
    background: rgba(255, 255, 255, 0.02);
}

.form-row-cost {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr auto;
    gap: 15px;
    align-items: end;
}

.btn-add-cost {
    background: linear-gradient(135deg, #10b981, #059669);
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 8px;
    cursor: pointer;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
}

.btn-add-cost:hover {
    background: linear-gradient(135deg, #059669, #047857);
}

.final-cost-breakdown {
    padding: 30px;
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.03), rgba(255, 255, 255, 0.01));
    border-radius: 15px;
}

.final-cost-breakdown .total {
    font-size: 2rem;
    font-weight: 700;
    color: #10b981;
    text-align: center;
    margin-bottom: 20px;
}

.final-cost-breakdown .sub-line {
    padding: 10px 0;
    border-top: 1px solid rgba(255, 255, 255, 0.1);
    display: flex;
    justify-content: space-between;
}

.vision-modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    backdrop-filter: blur(5px);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}

.vision-modal.active {
    display: flex;
}

.vision-modal-content {
    background: #1e1e2e;
    border-radius: 20px;
    padding: 30px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.vision-modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    padding-bottom: 15px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

.vision-modal-header h3 {
    margin: 0;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 10px;
}

.vision-modal-close {
    background: none;
    border: none;
    color: rgba(255, 255, 255, 0.6);
    font-size: 1.5rem;
    cursor: pointer;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: all 0.2s;
}

.vision-modal-close:hover {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
}

.vision-modal-form .form-group {
    margin-bottom: 20px;
}

.vision-modal-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 25px;
}

.payment-methods-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.payment-method-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.payment-method-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

.file-checkbox-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.file-checkbox-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px;
    background: rgba(255, 255, 255, 0.03);
    border-radius: 6px;
}

.file-checkbox-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
}

#progressContainer {
    display: none;
    margin: 20px 30px;
    background: rgba(0, 0, 0, 0.3);
    border-radius: 10px;
    padding: 15px;
}

.progress-bar {
    height: 30px;
    background: rgba(255, 255, 255, 0.1);
    border-radius: 15px;
    overflow: hidden;
    margin-bottom: 10px;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #9D4EDD, #7B2CBF);
    width: 0%;
    transition: width 0.3s ease;
}

.progress-text {
    text-align: center;
    color: rgba(255, 255, 255, 0.9);
}

.csv-upload-hint {
    background: rgba(59, 130, 246, 0.1);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 10px;
    padding: 15px 20px;
    margin: 15px 30px;
    color: rgba(255, 255, 255, 0.9);
}

.csv-upload-hint i {
    color: #3b82f6;
    margin-right: 8px;
}
</style>

<div class="main-content">
    <div class="profile-header-card">
        <div class="header-icon-container">
            <i class="fas fa-file-invoice-dollar"></i>
        </div>
        <div class="header-text-container">
            <h2>Orçamentos com Análise CAT Tool</h2>
            <p>Importe arquivos CSV de análise de fuzzy match para gerar orçamentos precisos</p>
        </div>
    </div>

    <div class="report-nav-buttons">
        <a href="?clear" class="vision-btn vision-btn-secondary">
            <i class="fas fa-sync-alt"></i> Novo Orçamento
        </a>
    </div>

    <!-- PASSO 1: CLIENTE -->
    <div class="cards-grid-2col">
        <div class="video-card <?= $currentStep >= 2 ? 'completed' : '' ?>" id="cardCliente">
            <h2><i class="fas fa-user"></i> 1. Cliente e Projeto</h2>
            <form id="formCliente" class="vision-form-refined">
                <div class="form-group">
                    <label for="client_id">Cliente</label>
                    <select name="client_id" id="client_id" class="vision-select">
                        <option value="">Selecione um cliente</option>
                        <?php foreach ($clientsList as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($budgetClient['client_id'] == $c['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="showAddClientModal()" style="margin-top: 10px;" class="vision-btn vision-btn-secondary">
                        <i class="fas fa-plus"></i> Novo Cliente
                    </button>
                </div>

                <div class="form-group">
                    <label for="service">Serviço</label>
                    <select name="service" id="service" class="vision-select">
                        <option value="translation" <?= $budgetClient['service'] === 'translation' ? 'selected' : '' ?>>Tradução</option>
                        <option value="revision" <?= $budgetClient['service'] === 'revision' ? 'selected' : '' ?>>Revisão</option>
                        <option value="proofreading" <?= $budgetClient['service'] === 'proofreading' ? 'selected' : '' ?>>Proofreading</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="lang_from">Idioma de origem</label>
                        <input type="text" name="lang_from" id="lang_from" class="vision-input" 
                            value="<?= htmlspecialchars($budgetClient['lang_from'] ?? '') ?>" placeholder="Ex: pt-BR">
                    </div>
                    <div class="form-group">
                        <label for="lang_to">Idioma de destino</label>
                        <input type="text" name="lang_to" id="lang_to" class="vision-input" 
                            value="<?= htmlspecialchars($budgetClient['lang_to'] ?? '') ?>" placeholder="Ex: en-US">
                    </div>
                </div>

                <div class="form-group">
                    <label for="currency">Moeda</label>
                    <select name="currency" id="currency" class="vision-select">
                        <?php foreach ($currenciesList as $curr): ?>
                        <option value="<?= $curr ?>" <?= ($budgetClient['currency'] === $curr) ? 'selected' : '' ?>>
                            <?= $curr ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="submit" class="vision-btn vision-btn-primary">
                        <i class="fas fa-arrow-right"></i> Próximo
                    </button>
                </div>
            </form>
        </div>

        <!-- PASSO 2: PESOS -->
        <div class="video-card <?= $currentStep >= 3 ? 'completed' : ($currentStep < 2 ? 'disabled' : '') ?>" id="cardPesos">
            <h2><i class="fas fa-sliders-h"></i> 2. Configurar Pesos</h2>
            <form id="formPesos" class="vision-form-refined">
                <p style="color: rgba(255, 255, 255, 0.7); margin-bottom: 20px;">
                    Defina quanto será cobrado para cada faixa de fuzzy match (0 = grátis, 1 = preço cheio)
                </p>

                <?php
                $weightKeys = ['Repetition', '101%', '100%', '95-99%', '85-94%', '75-84%', '50-74%', 'No Match'];
                foreach ($weightKeys as $key):
                    $fieldName = 'w_' . preg_replace('/[^0-9A-Za-z]/', '_', $key);
                    $value = $_SESSION['wc_weights'][$key] ?? 1.0;
                ?>
                <div class="form-group">
                    <label for="<?= $fieldName ?>"><?= $key ?></label>
                    <input type="text" name="<?= $fieldName ?>" id="<?= $fieldName ?>" class="vision-input" 
                        value="<?= number_format($value, 2, ',', '.') ?>" placeholder="0,00">
                </div>
                <?php endforeach; ?>

                <div class="form-actions">
                    <button type="submit" class="vision-btn vision-btn-primary">
                        <i class="fas fa-save"></i> Salvar Pesos
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- PASSO 3: ARQUIVOS CSV -->
    <div class="video-card <?= $currentStep >= 4 ? 'completed' : ($currentStep < 3 ? 'disabled' : '') ?>" id="cardArquivos">
        <h2><i class="fas fa-file-csv"></i> 3. Importar Análises CSV</h2>
        
        <div class="csv-upload-hint">
            <i class="fas fa-info-circle"></i>
            <strong>Importante:</strong> Faça upload dos arquivos CSV gerados pela análise de fuzzy match da sua CAT Tool 
            (ex: Trados, memoQ, etc). Os arquivos serão descartados após o processamento.
        </div>

        <form id="formArquivos" class="vision-form-refined">
            <div class="form-group">
                <label for="csv_files">Selecionar arquivos CSV</label>
                <input type="file" name="csv_files" id="csv_files" class="vision-input" 
                    accept=".csv" multiple style="padding: 10px;">
            </div>

            <div id="progressContainer">
                <div class="progress-bar">
                    <div class="progress-fill" id="progressFill"></div>
                </div>
                <div class="progress-text" id="progressText">Processando...</div>
            </div>

            <div class="form-actions">
                <button type="submit" class="vision-btn vision-btn-primary" id="btnProcessCsv">
                    <i class="fas fa-cogs"></i> Processar CSVs
                </button>
            </div>
        </form>

        <?php if (!empty($_SESSION['analyses'])): ?>
        <div style="padding: 20px 30px;">
            <h3 style="margin-bottom: 15px; color: #c084fc;">Arquivos Processados:</h3>
            <?php foreach ($_SESSION['analyses'] as $index => $analysis): ?>
            <div class="analysis-list-item">
                <div>
                    <div class="file-name"><?= htmlspecialchars($analysis['fileName']) ?></div>
                    <div class="file-stats">
                        <?= number_format($analysis['totalWords']) ?> palavras | 
                        <?= number_format($analysis['totalSegments']) ?> segmentos | 
                        <?= number_format($analysis['weightedWordCount']) ?> ponderadas
                    </div>
                    <div class="fuzzy-breakdown">
                        <?php foreach ($analysis['fuzzyMatches'] as $match): ?>
                        <div class="fuzzy-item">
                            <div class="category"><?= htmlspecialchars($match['category']) ?></div>
                            <div class="words"><?= number_format($match['words']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <button class="vision-btn vision-btn-secondary btn-remove-analysis" data-index="<?= $index ?>" style="padding: 8px 12px;">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- PASSO 4: CUSTOS -->
    <div class="video-card <?= $currentStep >= 5 ? 'completed' : ($currentStep < 4 ? 'disabled' : '') ?>" id="cardCustos">
        <h2><i class="fas fa-dollar-sign"></i> 4. Adicionar Custos</h2>
        
        <div id="costsTableContainer" style="padding: 0 30px;">
            <?php if (!empty($budgetCosts['items'])): ?>
            <div class="vision-table-container">
                <table class="vision-table">
                    <thead>
                        <tr>
                            <th>Fornecedor</th>
                            <th>Serviço</th>
                            <th>Valor Unitário</th>
                            <th>Ação</th>
                        </tr>
                    </thead>
                    <tbody id="costsTableBody">
                        <?php foreach ($budgetCosts['items'] as $index => $item): ?>
                        <tr data-cost-index="<?= $index ?>">
                            <td><?= htmlspecialchars($item['provider_name']) ?></td>
                            <td><?= htmlspecialchars($item['service']) ?></td>
                            <td><?= number_format($item['unit_cost'], 4, ',', '.') ?></td>
                            <td>
                                <button class="vision-btn vision-btn-secondary btn-remove-cost" data-index="<?= $index ?>" style="padding: 6px 10px; font-size: 0.8rem;">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div style="padding: 20px 0; text-align: center; color: rgba(255, 255, 255, 0.6);">
                Nenhum custo adicionado.
            </div>
            <?php endif; ?>
        </div>

        <form id="formCusto" class="vision-form-refined" style="padding-top: 15px; border-top: 1px solid rgba(255, 255, 255, 0.06);">
            <div class="form-row-cost">
                <div class="form-group">
                    <label for="provider_id">Fornecedor</label>
                    <select id="provider_id" name="provider_id" class="vision-input">
                        <option value="interno">Interno</option>
                        <option value="outro">Outro Custo Diverso</option>
                        <optgroup label="Fornecedores">
                            <?php foreach ($providersList as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="form-group">
                    <label for="cost_service">Serviço</label>
                    <select id="cost_service" name="cost_service" class="vision-input">
                        <option value="Tradução">Tradução</option>
                        <option value="Pós-edição">Pós-edição</option>
                        <option value="Revisão">Revisão</option>
                        <option value="Diagramação">Diagramação</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="cost_value">Valor Unitário</label>
                    <input type="text" name="cost_value" id="cost_value" class="vision-input" placeholder="0,00">
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn-add-cost">
                        <i class="fas fa-plus"></i> Adicionar
                    </button>
                </div>
            </div>

            <div class="form-row" style="margin-top: 20px;">
                <div class="form-group">
                    <label for="markup_pct">Markup (%)</label>
                    <input type="text" name="markup_pct" id="markup_pct" class="vision-input" 
                        value="<?= number_format($budgetParams['markup_pct'], 1, ',', '.') ?>" placeholder="30,0">
                </div>
                <div class="form-group">
                    <label for="tax_pct">Impostos (%)</label>
                    <input type="text" name="tax_pct" id="tax_pct" class="vision-input" 
                        value="<?= number_format($budgetParams['tax_pct'], 1, ',', '.') ?>" placeholder="11,5">
                </div>
            </div>
        </form>

        <div class="form-actions" id="costsBtnContainer" style="<?= empty($budgetCosts['items']) ? 'display:none;' : '' ?> justify-content: center; padding-bottom: 20px;">
            <button id="btnCalculateBudget" class="vision-btn vision-btn-primary">
                <i class="fas fa-calculator"></i>
                <span>Calcular orçamento</span>
            </button>
        </div>
    </div>

    <!-- SEÇÃO DE RESULTADOS -->
    <div id="resultsSection" style="<?= $currentStep >= 5 ? 'display:block;' : 'display:none;' ?>">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 20px;">
            
            <div class="video-card">
                <h2><i class="fas fa-chart-pie"></i> Resumo</h2>
                <div class="vision-form-refined">
                    <div class="form-row" style="grid-template-columns: repeat(2, 1fr);">
                        <div class="form-group">
                            <label>Total de palavras</label>
                            <div class="vision-input" style="background:transparent;border:none;">
                                <strong id="resultTotalWords">0</strong>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Total de segmentos</label>
                            <div class="vision-input" style="background:transparent;border:none;">
                                <strong id="resultTotalSegments">0</strong>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Total ponderado</label>
                            <div class="vision-input" style="background:transparent;border:none;">
                                <strong id="resultWeightedSum">0</strong>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Total de páginas</label>
                            <div class="vision-input" style="background:transparent;border:none;">
                                <strong id="resultTotalPages">0</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="video-card">
                <h2><i class="fas fa-coins"></i> Custo Total</h2>
                <div class="vision-form-refined" style="justify-content: center; padding-top: 20px;">
                    <div class="form-group">
                        <div class="vision-input" style="background:transparent; border:none; text-align:center; padding: 20px 0;">
                            <strong style="color: #FFB74D; font-size: 1.8rem;" id="resultCustoTotal">R$ 0,00</strong>
                        </div>
                    </div>
                </div>
            </div>

            <div class="video-card">
                <h2><i class="fas fa-calculator"></i> Preço sugerido</h2>
                <div class="vision-form-refined final-cost-breakdown">
                    <div class="total" id="resultPrecoFinal">R$ 0,00</div>
                    <div class="sub-line">
                        Subtotal (Custo + Markup <span id="resultMarkupPct">30,0</span>%): 
                        <strong id="resultSubtotal">R$ 0,00</strong>
                    </div>
                    <div class="sub-line">
                        Impostos (<span id="resultTaxPct">11,5</span>%): 
                        <strong id="resultImpostos">R$ 0,00</strong>
                    </div>
                    
                    <div style="margin-top: 20px;">
                        <button class="vision-btn vision-btn-success" onclick="showPdfModal()">
                            <i class="fas fa-file-pdf"></i>
                            <span>Preparar para enviar</span>
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

</div>

<!-- MODAL ADICIONAR CLIENTE -->
<div id="addClientModal" class="vision-modal">
    <div class="vision-modal-content">
        <div class="vision-modal-header">
            <h3><i class="fas fa-user-plus"></i> Adicionar Novo Cliente</h3>
            <button type="button" class="vision-modal-close" onclick="hideAddClientModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" class="vision-modal-form">
            <input type="hidden" name="action" value="add_client">
            <div class="form-group">
                <label for="company_name">Nome do cliente/empresa:</label>
                <input type="text" id="company_name" name="company_name" required class="vision-input" placeholder="Ex: Acme Inc.">
            </div>
            <div class="form-group">
                <label for="default_currency">Moeda padrão:</label>
                <select id="default_currency" name="default_currency" class="vision-select">
                    <?php foreach ($currenciesList as $curr): ?>
                    <option value="<?= htmlspecialchars($curr) ?>"><?= htmlspecialchars($curr) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="vision-modal-actions">
                <button type="submit" class="vision-btn vision-btn-primary">
                    <i class="fas fa-plus"></i> Adicionar
                </button>
                <button type="button" class="vision-btn vision-btn-secondary" onclick="hideAddClientModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL PREPARAR PDF -->
<div id="pdfModal" class="vision-modal">
    <div class="vision-modal-content">
        <div class="vision-modal-header">
            <h3><i class="fas fa-file-pdf"></i> Preparar Orçamento PDF</h3>
            <button type="button" class="vision-modal-close" onclick="hidePdfModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form method="POST" class="vision-modal-form" id="formPdf">
            <input type="hidden" name="action" value="generate_pdf">
            
            <div class="form-group">
                <label for="company_name">Nome da empresa geradora:</label>
                <input type="text" id="company_name" name="company_name" required class="vision-input" placeholder="Ex: Translators101">
            </div>
            
            <div class="form-group">
                <label for="contact_name">Nome do contato:</label>
                <input type="text" id="contact_name" name="contact_name" required class="vision-input" placeholder="Ex: João Silva">
            </div>
            
            <div class="form-group">
                <label for="delivery_date">Prazo de entrega (DD-MM-AAAA):</label>
                <input type="text" id="delivery_date" name="delivery_date" required class="vision-input" placeholder="DD-MM-AAAA" pattern="\d{2}-\d{2}-\d{4}">
            </div>
            
            <div class="form-group">
                <label for="validity_date">Validade do orçamento (DD-MM-AAAA):</label>
                <input type="text" id="validity_date" name="validity_date" required class="vision-input" placeholder="DD-MM-AAAA" pattern="\d{2}-\d{2}-\d{4}">
            </div>
            
            <div class="form-group">
                <label>Forma de pagamento (selecione uma ou mais):</label>
                <div class="payment-methods-list">
                    <div class="payment-method-item">
                        <input type="checkbox" name="payment_methods[]" value="Transferência bancária" id="pm_transferencia">
                        <label for="pm_transferencia">Transferência bancária</label>
                    </div>
                    <div class="payment-method-item">
                        <input type="checkbox" name="payment_methods[]" value="PIX" id="pm_pix">
                        <label for="pm_pix">PIX</label>
                    </div>
                    <div class="payment-method-item">
                        <input type="checkbox" name="payment_methods[]" value="Boleto bancário" id="pm_boleto">
                        <label for="pm_boleto">Boleto bancário</label>
                    </div>
                    <div class="payment-method-item">
                        <input type="checkbox" name="payment_methods[]" value="Cartão de crédito" id="pm_credito">
                        <label for="pm_credito">Cartão de crédito</label>
                    </div>
                    <div class="payment-method-item">
                        <input type="checkbox" name="payment_methods[]" value="Cartão de débito" id="pm_debito">
                        <label for="pm_debito">Cartão de débito</label>
                    </div>
                    <div class="payment-method-item">
                        <input type="checkbox" name="payment_methods[]" value="Dinheiro" id="pm_dinheiro">
                        <label for="pm_dinheiro">Dinheiro</label>
                    </div>
                    <div class="payment-method-item">
                        <input type="checkbox" name="payment_methods[]" value="Cheque" id="pm_cheque">
                        <label for="pm_cheque">Cheque</label>
                    </div>
                    <div class="payment-method-item">
                        <input type="checkbox" name="payment_methods[]" value="Outro" id="pm_outro">
                        <label for="pm_outro">Outro</label>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="payment_date">Data de pagamento (DD-MM-AAAA):</label>
                <input type="text" id="payment_date" name="payment_date" required class="vision-input" placeholder="DD-MM-AAAA" pattern="\d{2}-\d{2}-\d{4}">
            </div>
            
            <div class="form-group">
                <label for="final_price">Preço final:</label>
                <input type="text" id="final_price" name="final_price" required class="vision-input" placeholder="0,00">
            </div>
            
            <div class="form-group">
                <label>Arquivos do orçamento:</label>
                <div class="file-checkbox-list" id="pdfFilesList">
                    <!-- Preenchido via JavaScript -->
                </div>
            </div>
            
            <div class="vision-modal-actions">
                <button type="submit" class="vision-btn vision-btn-success">
                    <i class="fas fa-download"></i> Gerar PDF
                </button>
                <button type="button" class="vision-btn vision-btn-secondary" onclick="hidePdfModal()">
                    <i class="fas fa-times"></i> Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const formCliente = document.getElementById('formCliente');
    const formPesos = document.getElementById('formPesos');
    const formArquivos = document.getElementById('formArquivos');
    const formCusto = document.getElementById('formCusto');
    const btnProcessCsv = document.getElementById('btnProcessCsv');
    const btnCalculateBudget = document.getElementById('btnCalculateBudget');
    const progressContainer = document.getElementById('progressContainer');
    
    const cardCliente = document.getElementById('cardCliente');
    const cardPesos = document.getElementById('cardPesos');
    const cardArquivos = document.getElementById('cardArquivos');
    const cardCustos = document.getElementById('cardCustos');
    
    function enableCard(cardElement) {
        cardElement.classList.remove('disabled');
    }
    
    function markCardCompleted(cardElement) {
        cardElement.classList.add('completed');
    }
    
    function formatBRL(value, decimals = 2) {
        return parseFloat(value).toLocaleString('pt-BR', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        });
    }
    
    // Form Cliente
    if (formCliente) {
        formCliente.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(formCliente);
            formData.append('ajax_action', 'update_client');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    markCardCompleted(cardCliente);
                    enableCard(cardPesos);
                }
            });
        });
    }
    
    // Form Pesos
    if (formPesos) {
        formPesos.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(formPesos);
            formData.append('ajax_action', 'update_weights');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    markCardCompleted(cardPesos);
                    enableCard(cardArquivos);
                }
            });
        });
    }
    
    // Botões remover análises
    document.querySelectorAll('.btn-remove-analysis').forEach(btn => {
        btn.addEventListener('click', function() {
            const index = parseInt(this.dataset.index);
            
            if (!confirm('Remover este arquivo do orçamento?')) return;
            
            const formData = new FormData();
            formData.append('ajax_action', 'remove_analysis');
            formData.append('analysis_index', index);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                }
            });
        });
    });
    
    // Form Upload CSV
    if (formArquivos) {
        formArquivos.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const csvFiles = document.getElementById('csv_files').files;
            
            if (csvFiles.length === 0) {
                alert('Selecione pelo menos um arquivo CSV');
                return;
            }
            
            const formData = new FormData();
            for (let i = 0; i < csvFiles.length; i++) {
                formData.append('csv_files[]', csvFiles[i]);
            }
            
            progressContainer.style.display = 'block';
            btnProcessCsv.disabled = true;
            
            let progress = 0;
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');
            
            const interval = setInterval(() => {
                progress += 10;
                progressFill.style.width = progress + '%';
                progressText.textContent = `Processando... ${progress}%`;
                
                if (progress >= 90) {
                    clearInterval(interval);
                    progressText.textContent = 'Finalizando...';
                }
            }, 200);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                clearInterval(interval);
                progressFill.style.width = '100%';
                progressText.textContent = 'Concluído!';
                
                setTimeout(() => {
                    progressContainer.style.display = 'none';
                    progressFill.style.width = '0%';
                    btnProcessCsv.disabled = false;
                }, 1000);
                
                if (data.success) {
                    markCardCompleted(cardArquivos);
                    enableCard(cardCustos);
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert('Erro: ' + data.message);
                }
            });
        });
    }
    
    // Form Custo
    if (formCusto) {
        formCusto.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(formCusto);
            formData.append('ajax_action', 'add_cost');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('cost_value').value = '';
                    addCostToTable(data.cost_item, data.cost_index);
                    document.getElementById('costsBtnContainer').style.display = 'flex';
                } else {
                    alert('Erro: ' + data.message);
                }
            });
        });
    }
    
    function addCostToTable(item, index) {
        let tbody = document.getElementById('costsTableBody');
        if (!tbody) {
            const container = document.getElementById('costsTableContainer');
            container.innerHTML = `
                <div class="vision-table-container">
                    <table class="vision-table">
                        <thead>
                            <tr>
                                <th>Fornecedor</th>
                                <th>Serviço</th>
                                <th>Valor Unitário</th>
                                <th>Ação</th>
                            </tr>
                        </thead>
                        <tbody id="costsTableBody"></tbody>
                    </table>
                </div>
            `;
            tbody = document.getElementById('costsTableBody');
        }
        
        const row = document.createElement('tr');
        row.dataset.costIndex = index;
        row.innerHTML = `
            <td>${item.provider_name}</td>
            <td>${item.service}</td>
            <td>${formatBRL(item.unit_cost, 4)}</td>
            <td>
                <button class="vision-btn vision-btn-secondary btn-remove-cost" data-index="${index}" style="padding: 6px 10px; font-size: 0.8rem;">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
        `;
        tbody.appendChild(row);
        
        row.querySelector('.btn-remove-cost').addEventListener('click', function() {
            removeCost(index, row);
        });
    }
    
    function removeCost(index, rowElement) {
        if (!confirm('Remover este custo?')) return;
        
        const formData = new FormData();
        formData.append('ajax_action', 'remove_cost');
        formData.append('cost_index', index);
        
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                rowElement.remove();
                
                const tbody = document.getElementById('costsTableBody');
                if (tbody && tbody.children.length === 0) {
                    document.getElementById('costsBtnContainer').style.display = 'none';
                }
            }
        });
    }
    
    document.querySelectorAll('.btn-remove-cost').forEach(btn => {
        btn.addEventListener('click', function() {
            const index = parseInt(this.dataset.index);
            const row = this.closest('tr');
            removeCost(index, row);
        });
    });
    
    // Calcular Orçamento
    if (btnCalculateBudget) {
        btnCalculateBudget.addEventListener('click', function() {
            const formData = new FormData();
            formData.append('ajax_action', 'update_params');
            formData.append('markup_pct', document.getElementById('markup_pct').value);
            formData.append('tax_pct', document.getElementById('tax_pct').value);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(() => {
                const formData2 = new FormData();
                formData2.append('ajax_action', 'calculate_budget');
                
                return fetch(window.location.href, {
                    method: 'POST',
                    body: formData2
                });
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    markCardCompleted(cardCustos);
                    displayResults(data.results);
                } else {
                    alert('Erro: ' + data.message);
                }
            });
        });
    }
    
    function displayResults(results) {
        document.getElementById('resultTotalWords').textContent = results.totalWords.toLocaleString('pt-BR');
        document.getElementById('resultTotalSegments').textContent = results.totalSegments.toLocaleString('pt-BR');
        document.getElementById('resultWeightedSum').textContent = results.weightedSum.toLocaleString('pt-BR');
        document.getElementById('resultTotalPages').textContent = results.totalPages.toLocaleString('pt-BR');
        
        document.getElementById('resultCustoTotal').textContent = results.currency + ' ' + formatBRL(results.custoTotal, 2);
        document.getElementById('resultSubtotal').textContent = results.currency + ' ' + formatBRL(results.subtotal, 2);
        document.getElementById('resultImpostos').textContent = results.currency + ' ' + formatBRL(results.impostos, 2);
        document.getElementById('resultPrecoFinal').textContent = results.currency + ' ' + formatBRL(results.precoFinal, 2);
        
        document.getElementById('resultMarkupPct').textContent = results.markupPct.toFixed(1).replace('.', ',');
        document.getElementById('resultTaxPct').textContent = results.taxPct.toFixed(1).replace('.', ',');
        
        document.getElementById('resultsSection').style.display = 'block';
        document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });
    }
});

// Modals
const addClientModal = document.getElementById("addClientModal");
function showAddClientModal() { addClientModal.classList.add("active"); }
function hideAddClientModal() { addClientModal.classList.remove("active"); }

const pdfModal = document.getElementById("pdfModal");

function showPdfModal() {
    const finalPrice = document.getElementById('resultPrecoFinal').textContent;
    document.getElementById('final_price').value = finalPrice.replace(/[^\d,]/g, '');
    
    const filesList = document.getElementById('pdfFilesList');
    filesList.innerHTML = '';
    
    <?php if (!empty($_SESSION['analyses'])): ?>
    const files = <?= json_encode(array_map(function($a) { return $a['fileName']; }, $_SESSION['analyses'])) ?>;
    files.forEach((file, index) => {
        const div = document.createElement('div');
        div.className = 'file-checkbox-item';
        div.innerHTML = `
            <input type="checkbox" name="selected_files[]" value="${file}" checked id="file_${index}">
            <label for="file_${index}" style="margin: 0; cursor: pointer; flex-grow: 1;">${file}</label>
        `;
        filesList.appendChild(div);
    });
    <?php endif; ?>
    
    pdfModal.classList.add("active");
}

function hidePdfModal() { pdfModal.classList.remove("active"); }

document.addEventListener("click", function(e) {
    if (e.target.matches("#addClientModal")) hideAddClientModal();
    if (e.target.matches("#pdfModal")) hidePdfModal();
});

document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") {
        hideAddClientModal();
        hidePdfModal();
    }
});

window.showAddClientModal = showAddClientModal;
window.hideAddClientModal = hideAddClientModal;
window.showPdfModal = showPdfModal;
window.hidePdfModal = hidePdfModal;
</script>

<?php
include __DIR__ . '/../vision/includes/footer.php';
?>
