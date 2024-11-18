<?php
// Beispielsweise die Authentifizierung prüfen
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("HTTP/1.1 403 Forbidden");
    exit;
}

// Bild abrufen
$filename = basename($_GET['file']);
$filepath = __DIR__ . '/uploads/' . $filename;

if (!file_exists($filepath)) {
    header("HTTP/1.1 404 Not Found");
    exit;
}

// Bildinhalt anzeigen
header('Content-Type: ' . mime_content_type($filepath));
readfile($filepath);
?>
