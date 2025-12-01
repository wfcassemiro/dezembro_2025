<?php
// v/dash-t101/budget-test.php
// TESTE ISOLADO DE UPLOAD (SEM INCLUDES, SEM SESSÃO, SEM NADA)

if (isset($_GET['debug_files'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DEBUG _FILES:\n\n";
    var_dump($_FILES);
    exit;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Teste Upload - T101</title>
</head>
<body>
<h1>Teste de upload simples</h1>

<form method="POST" enctype="multipart/form-data" action="?debug_files=1">
    <p>
        <input type="file" name="files[]" multiple>
    </p>
    <p>
        <button type="submit">Enviar</button>
    </p>
</form>

</body>
</html>