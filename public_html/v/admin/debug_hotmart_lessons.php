<?php
// Script de diagnóstico para a API de Módulos/Lições da Hotmart
set_time_limit(120);
header('Content-Type: text/plain; charset=utf-8');

// --- Configurações (as mesmas que já funcionam para autenticação) ---
define('HOTMART_BASIC_AUTH', 'Basic N2UzZDM0MmQtYWY0Zi00MTkwLTk1OWMtNmE5NzU0NmYxNDM3OjZmNjI1NzZmLTQzMzUtNDBkMC04N2FhLThhNThmMDlkZjdmZA==');
define('HOTMART_ACCESS_TOKEN_URL', 'https://api-sec-vlc.hotmart.com/security/oauth/token');
define('HOTMART_API_BASE_URL', 'https://developers.hotmart.com');
$hotmart_club_subdomain = 'assinaturapremiumplustranslato';

echo "--- INICIANDO TESTE DE BUSCA DE MÓDULOS/LIÇÕES ---\n\n";

// 1. Obter Token de Acesso
$ch_token = curl_init(HOTMART_ACCESS_TOKEN_URL);
curl_setopt($ch_token, CURLOPT_POST, 1);
curl_setopt($ch_token, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'client_credentials']));
curl_setopt($ch_token, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_token, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded', 'Authorization: ' . HOTMART_BASIC_AUTH]);
$response_token = curl_exec($ch_token);
curl_close($ch_token);
$token_data = json_decode($response_token, true);
$access_token = $token_data['access_token'] ?? null;

if (!$access_token) {
    die("FALHA AO OBTER TOKEN.\nResposta:\n" . $response_token);
}

echo "Token de Acesso obtido com sucesso.\n\n";
echo "--- BUSCANDO MÓDULOS E LIÇÕES DA API ---\n\n";

// 2. Montar URL e buscar os módulos
$headers = ['Authorization: Bearer ' . $access_token, 'Content-Type: application/json'];
$query_params = ['subdomain' => $hotmart_club_subdomain, 'max_results' => 50]; // Pegamos os primeiros 50 para teste
$modules_url = HOTMART_API_BASE_URL . '/club/api/v1/modules?' . http_build_query($query_params);

echo "URL da requisição: " . $modules_url . "\n\n";

$ch_modules = curl_init($modules_url);
curl_setopt($ch_modules, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_modules, CURLOPT_HTTPHEADER, $headers);
$response_modules = curl_exec($ch_modules);
$http_code = curl_getinfo($ch_modules, CURLINFO_HTTP_CODE);
curl_close($ch_modules);

echo "Código de Status HTTP recebido: " . $http_code . "\n\n";
echo "--- RESPOSTA BRUTA DA HOTMART --- \n";

// Imprime a resposta de forma legível
$data = json_decode($response_modules, true);
if (json_last_error() === JSON_ERROR_NONE) {
    print_r($data);
} else {
    echo $response_modules;
}

echo "\n\n--- FIM DO TESTE ---";
?>