<?php
session_start();
date_default_timezone_set('America/Sao_Paulo');
require_once __DIR__ . '/config/database.php';

if (!isLoggedIn()) { header('Location: login.php'); exit; }
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['subscriber', 'admin'])) {
  header('Location: login.php'); exit;
}

$user_id = $_SESSION['user_id'];
$message = ''; $error = '';

// Atualização de perfil (igual ao seu original)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (isset($_POST['update_personal_info'])) {
    try {
      $name = $_POST['name'] ?? '';
      $email = $_POST['email'] ?? '';
      $native_language = $_POST['native_language'] ?? null;
      $working_languages = $_POST['working_languages'] ?? null;
      $does_version = isset($_POST['does_version']) ? 1 : 0;

      $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, native_language = ?, working_languages = ?, does_version = ? WHERE id = ?');
      $stmt->execute([$name, $email, $native_language, $working_languages, $does_version, $user_id]);
      $message = 'Informações atualizadas!';
    } catch (Exception $e) { $error = 'Erro ao atualizar as informações.'; }
  }
}

try {
  $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
  $stmt->execute([$user_id]);
  $user = $stmt->fetch();
  if (!$user) { header('Location: logout.php'); exit; }
} catch (Exception $e) { $error = 'Erro ao carregar dados do perfil.'; }

$certificates_stats = [];
try {
  $stmt = $pdo->prepare("SELECT COUNT(*) as total_certificates, SUM(duration_hours) as total_hours FROM certificates WHERE user_id = ?");
  $stmt->execute([$user_id]);
  $certificates_stats = $stmt->fetch();
} catch (Exception $e) { $certificates_stats = ['total_certificates' => 0, 'total_hours' => 0]; }

$user_watchlist = [];
try {
  $query = "SELECT w.id as watchlist_id, w.added_at, l.id as lecture_id, l.title, l.speaker, l.duration_minutes
            FROM user_watchlist w
            JOIN lectures l ON w.lecture_id = l.id
            WHERE w.user_id = ?
            ORDER BY w.added_at DESC";
  $stmt = $pdo->prepare($query);
  $stmt->execute([$user_id]);
  $user_watchlist = $stmt->fetchAll();
} catch (Exception $e) { $user_watchlist = []; }

$user_certificates = [];
try {
  $stmt = $pdo->prepare("SELECT c.*, l.title as lecture_title, l.speaker as speaker_name, l.duration_minutes
                         FROM certificates c
                         LEFT JOIN lectures l ON c.lecture_id = l.id
                         WHERE c.user_id = ?
                         ORDER BY c.issued_at DESC");
  $stmt->execute([$user_id]);
  $user_certificates = $stmt->fetchAll();
} catch (Exception $e) {}

//
// NOVO: certificados de palestrante vinculados a este usuário (se também é assinante)
//
$user_speaker_certs = [];
try {
  $stmt = $pdo->prepare("SELECT s.*
                         FROM speaker_certificates s
                         WHERE s.speaker_user_id = ?
                         ORDER BY s.issued_at DESC");
  $stmt->execute([$user_id]);
  $user_speaker_certs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
  $user_speaker_certs = [];
}

function cleanTitle($title) {
  $parts = explode('—', $title);
  if (count($parts) > 2) {
    array_pop($parts);
    return trim(implode('—', $parts));
  }
  return trim($title);
}

$page_title = 'Meu Perfil - Translators101';
$page_description = 'Gerencie suas informações pessoais e configurações de conta';

include __DIR__ . '/vision/includes/head.php';
include __DIR__ . '/vision/includes/header.php';
include __DIR__ . '/vision/includes/sidebar.php';
?>

<div class="main-content">
  <div class="glass-hero">
    <div class="hero-content">
      <h1><i class="fas fa-user-circle"></i> Meu perfil</h1>
      <p>Gerencie suas informações pessoais e configurações de conta</p>
    </div>
  </div>

  <?php if ($message): ?><div class="alert-success"><i class="fas fa-check-circle"></i><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert-error"><i class="fas fa-exclamation-triangle"></i><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

  <div class="video-card profile-card">
    <div class="card-header"><h2><i class="fas fa-list-ul"></i> Minha lista</h2></div>
    <?php if (!empty($user_watchlist)): ?>
      <div class="list-header"><span>Palestra</span><span>Palestrante</span><span>Duração</span><span>Ações</span></div>
      <?php foreach ($user_watchlist as $item): ?>
        <div class="list-row" data-lecture-id="<?php echo htmlspecialchars($item['lecture_id']); ?>">
          <span><?php echo htmlspecialchars(cleanTitle($item['title'])); ?></span>
          <span><?php echo htmlspecialchars($item['speaker']); ?></span>
          <span><?php echo htmlspecialchars($item['duration_minutes']); ?> min</span>
          <span class="col-actions">
            <a href="/palestra.php?id=<?php echo htmlspecialchars($item['lecture_id']); ?>" class="cta-btn btn-small">Assistir</a>
            <button class="cta-btn btn-small btn-remove" onclick="removeFromWatchlist('<?php echo htmlspecialchars($item['lecture_id']); ?>', this)">Remover</button>
          </span>
        </div>
      <?php endforeach; ?>
    <?php else: ?><p class="text-light" style="padding: 15px;">Sua lista está vazia.</p><?php endif; ?>
  </div>

  <div class="video-card profile-card">
    <div class="card-header"><h2><i class="fas fa-list-alt"></i> Palestras assistidas</h2></div>
    <div class="stats-report-summary">
      <span>Certificados: <?php echo $certificates_stats['total_certificates'] ?? 0; ?></span>
      <span>Total de horas: <?php echo number_format($certificates_stats['total_hours'] ?? 0, 1); ?></span>
      <div class="button-wrapper-inline">
        <button onclick="generateReport()" class="cta-btn btn-small btn-green-report" id="btnGenerateReport">Baixar relatório</button>
      </div>
    </div>
    <?php if (!empty($user_certificates)): ?>
      <div class="list-header certificates-header"><span>Palestra</span><span>Concluída em</span><span>Duração</span><span>Ações</span></div>
      <?php foreach ($user_certificates as $cert): ?>
        <div class="list-row">
          <span><?php echo htmlspecialchars(cleanTitle($cert['lecture_title'])); ?></span>
          <span><?php echo htmlspecialchars(date('d/m/Y', strtotime($cert['issued_at']))); ?></span>
          <span><?php echo number_format($cert['duration_hours'] ?? 0, 1); ?> h</span>
          <span class="col-actions">
            <a href="view_certificate_files.php?id=<?php echo htmlspecialchars($cert['id']); ?>" target="_blank" class="cta-btn btn-small">Certificado</a>
            <a href="/palestra.php?id=<?php echo htmlspecialchars($cert['lecture_id']); ?>" class="cta-btn btn-small">Assistir</a>
          </span>
        </div>
      <?php endforeach; ?>
    <?php else: ?><p class="text-light" style="padding: 15px;">Nenhuma palestra concluída.</p><?php endif; ?>
  </div>

  <?php if (!empty($user_speaker_certs)): ?>
    <div class="video-card profile-card">
      <div class="card-header"><h2><i class="fas fa-microphone"></i> Meus certificados como palestrante</h2></div>
      <div class="list-header certificates-header"><span>Palestra</span><span>Data</span><span>CH</span><span>Ações</span></div>
      <?php foreach ($user_speaker_certs as $sc): ?>
        <div class="list-row">
          <span><?php echo htmlspecialchars(cleanTitle($sc['lecture_title'])); ?></span>
          <span><?php echo $sc['lecture_date'] ? date('d/m/Y', strtotime($sc['lecture_date'])) : '—'; ?></span>
          <span><?php echo number_format((float)$sc['duration_hours'], 1); ?> h</span>
          <span class="col-actions">
            <a href="view_speaker_certificate.php?id=<?php echo htmlspecialchars($sc['id']); ?>" target="_blank" class="cta-btn btn-small">Certificado</a>
            <a href="verificar_certificado_palestrante.php?id=<?php echo htmlspecialchars($sc['id']); ?>" target="_blank" class="cta-btn btn-small">Verificar</a>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Resto do arquivo original (relatórios, info pessoal, etc.) permanece igual -->
  <!-- Abaixo, mantenha o CSS e JS existentes do seu arquivo fornecido -->
  <!-- ... cole aqui exatamente o bloco <style> e <script> do seu perfil (4).php original ... -->
</div>
<?php include __DIR__ . '/vision/includes/footer.php'; ?>