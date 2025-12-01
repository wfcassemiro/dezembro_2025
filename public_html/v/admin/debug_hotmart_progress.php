<?php
// Script de diagnóstico para a API de Progresso de Aluno da Hotmart
set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');

// --- !! IMPORTANTE !! ---
// --- Substitua pelo e-mail de um aluno que VOCÊ SABE que tem progresso na Hotmart ---
$student_email_to_test = 'valivonica@traducaoviaval.com.br'; 
// ----------------------------------------------------

// --- Configurações (as mesmas que já funcionam para autenticação) ---
define('HOTMART_BASIC_AUTH', 'Basic N2UzZDM0MmQtYWY0Zi00MTkwLTk1OWMtNmE5NzU0NmYxNDM3OjZmNjI1NzZmLTQzMzUtNDBkMC04N2FhLThhNThmMDlkZjdmZA==');
define('HOTMART_ACCESS_TOKEN_URL', 'https://api-sec-vlc.hotmart.com/security/oauth/token');
define('HOTMART_API_BASE_URL', 'https://developers.hotmart.com');

echo "--- INICIANDO TESTE DE BUSCA DE PROGRESSO DE ALUNO ---\n\n";

// 1. Obter Token de Acesso
$ch_token = curl_init(HOTMART_ACCESS_TOKEN_URL);
curl_setopt($ch_token, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch_token, CURLOPT_POST, 1);
curl_setopt($ch_token, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'client_credentials']));
curl_setopt($ch_token, CURLOPT_HTTPHEADER, ['Authorization: ' . HOTMART_BASIC_AUTH, 'Content-Type: application/x-www-form-urlencoded']);
$response_token = curl_exec($ch_token); curl_close($ch_token);
$token_data = json_decode($response_token, true);
$access_token = $token_data['access_token'] ?? null;

if (!$access_token) {
    die("FALHA AO OBTER TOKEN.\nResposta:\n" . $response_token);
}
echo "Token de Acesso obtido com sucesso.\n\n";
echo "--- BUSCANDO PROGRESSO PARA O E-MAIL: " . htmlspecialchars($student_email_to_test) . " ---\n\n";

// 2. Montar URL e buscar o progresso do aluno específico
$headers = ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json'];
$progress_url = HOTMART_API_BASE_URL . "/club/v1/users/" . urlencode($student_email_to_test) . "/progress";

echo "URL da requisição: " . $progress_url . "\n\n";

$ch_progress = curl_init($progress_url);
curl_setopt($ch_progress, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_progress, CURLOPT_HTTPHEADER, $headers);
$response_progress = curl_exec($ch_progress);
$http_code = curl_getinfo($ch_progress, CURLINFO_HTTP_CODE);
curl_close($ch_progress);

echo "Código de Status HTTP recebido: " . $http_code . "\n\n";
echo "--- RESPOSTA BRUTA DA HOTMART --- \n";

// Imprime a resposta de forma legível
$data = json_decode($response_progress, true);
if (json_last_error() === JSON_ERROR_NONE) {
    print_r($data);
} else {
    echo $response_progress;
}

echo "\n\n--- FIM DO TESTE ---";
?>