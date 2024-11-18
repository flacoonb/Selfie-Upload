<?php
// Log-Datei für delete_image.php
$logFile = __DIR__ . '/delete_image.log';

// Log-Funktion, um alle Schritte zu protokollieren
function logMessage($message) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

logMessage("delete_image.php Webhook empfangen");

// Webhook-Daten empfangen
$data = file_get_contents("php://input");
logMessage("Empfangene Daten: " . ($data ?: 'Keine Daten empfangen'));

if ($data === false || empty($data)) {
    logMessage("Fehler: Keine Daten empfangen");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Keine Daten empfangen']);
    exit;
}

// JSON-Daten dekodieren
$dataArray = json_decode($data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage("Fehler: JSON-Daten konnten nicht dekodiert werden - " . json_last_error_msg());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Fehlerhafte JSON-Daten']);
    exit;
}

logMessage("Dekodierte JSON-Daten: " . print_r($dataArray, true));

// Überprüfen, ob der Dateipfad vorhanden ist
if (!isset($dataArray['file_path'])) {
    logMessage("Fehler: Kein Dateipfad erhalten.");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Kein Dateipfad erhalten']);
    exit;
}

$filePath = __DIR__ . '/uploads/' . basename($dataArray['file_path']);
logMessage("Pfad zur Datei, die gelöscht werden soll: " . $filePath);

// Überprüfen, ob die Datei existiert und löschen
if (file_exists($filePath)) {
    if (unlink($filePath)) {
        logMessage("Bild erfolgreich gelöscht: $filePath");
        http_response_code(200);
        echo json_encode(['status' => 'success', 'message' => 'Bild erfolgreich gelöscht']);
    } else {
        logMessage("Fehler beim Löschen des Bildes: $filePath");
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Bild konnte nicht gelöscht werden']);
    }
} else {
    logMessage("Bild nicht gefunden: $filePath");
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Bild nicht gefunden']);
}

// Logging für Abschluss
logMessage("delete_image.php Webhook beendet");
?>
