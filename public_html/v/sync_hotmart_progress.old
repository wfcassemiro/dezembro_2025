<?php
// Configurações para log
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/hotmart_api_debug.log');

error_log("Script iniciado - sync_hotmart_progress.php");

require_once 'hotmart.php';

class HotmartSync {
    private $hotmartApi;
    private $subdomain = 't101';

    public function __construct() {
        $this->hotmartApi = new HotmartAPI();
    }

    public function syncProgress() {
        error_log("[SYNC_PROGRESS] Iniciando sincronização de progresso de aulas.");

        // Tentar obter usuários do Club
        $clubUsersResult = $this->hotmartApi->getClubUsers($this->subdomain);

        $users = [];
        if ($clubUsersResult['success']) {
            if (isset($clubUsersResult['data']['items'])) {
                $users = $clubUsersResult['data']['items'];
            } elseif (isset($clubUsersResult['data'])) {
                $users = $clubUsersResult['data'];
            }
            error_log("[SYNC_PROGRESS] Usuários obtidos do Club: " . count($users));
        } else {
            error_log("[SYNC_PROGRESS_WARN] Falha ao obter usuários do Club: " . json_encode($clubUsersResult));
            // Fallback para assinaturas
            $subscriptionsResult = $this->hotmartApi->getSubscriptions();
            if ($subscriptionsResult['success']) {
                $users = $subscriptionsResult['data']['items'] ?? $subscriptionsResult['data'] ?? [];
                error_log("[SYNC_PROGRESS] Usuários obtidos do fallback de assinaturas: " . count($users));
            } else {
                error_log("[SYNC_PROGRESS_ERROR] Falha ao obter assinaturas: " . json_encode($subscriptionsResult));
            }
        }

        $totalUsers = count($users);
        error_log("[SYNC_PROGRESS] Total de usuários/assinaturas encontrados: {$totalUsers}");

        foreach ($users as $hotmartUser) {
            $userEmail = $hotmartUser['email'] ?? '';
            // Priorizar ucode (UUID) quando disponível, senão subscriber_code, senão subscription_id
            $hotmartUserId = $hotmartUser['ucode']
                            ?? $hotmartUser['subscriber']['ucode'] ?? null
                            ?? $hotmartUser['subscriber']['subscriber_code'] ?? null
                            ?? $hotmartUser['subscription_id'] ?? null
                            ?? null;

            if (!$hotmartUserId) {
                error_log("[SYNC_PROGRESS_WARN] Usuário sem ID válido encontrado, pulando.");
                continue;
            }

            $progressResult = $this->hotmartApi->getUserProgress($hotmartUserId);

            if ($progressResult['success']) {
                $progressData = $progressResult['data'];
                // Processar progresso aqui, salvar no banco, etc.
                error_log("[SYNC_PROGRESS] Progresso sincronizado para usuário {$userEmail} (ID: {$hotmartUserId})");
            } else {
                error_log("[SYNC_PROGRESS_WARN] Nenhum progresso encontrado para usuário {$userEmail} (ID: {$hotmartUserId})");
            }
        }

        error_log("[SYNC_PROGRESS_SUCCESS] Sincronização de progresso de aulas concluída.");
    }
}

// Exemplo de uso
$sync = new HotmartSync();
$sync->syncProgress();

?>