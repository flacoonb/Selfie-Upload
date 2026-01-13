<?php

$config = require __DIR__ . '/config/config.php';

$logFile = __DIR__ . '/delete_image.log';

function logMessage(string $message): void {
    global $logFile;
    file_put_contents($logFile, date('c') . ' - ' . $message . "\n", FILE_APPEND);
}

function jsonResponse(array $payload, int $statusCode): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
}

logMessage('delete_image.php Webhook empfangen');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
    exit;
}

$expectedToken = (string)($config['webhook_token'] ?? '');
if ($expectedToken !== '') {
    $providedToken = (string)($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        logMessage('Fehler: Ungültiger Webhook-Token');
        jsonResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
        exit;
    }
}

$data = file_get_contents('php://input');
logMessage('Empfangene Daten: ' . ($data ?: 'Keine Daten empfangen'));

if ($data === false || trim($data) === '') {
    logMessage('Fehler: Keine Daten empfangen');
    jsonResponse(['status' => 'error', 'message' => 'Keine Daten empfangen'], 400);
    exit;
}

$dataArray = json_decode($data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage('Fehler: JSON-Daten konnten nicht dekodiert werden - ' . json_last_error_msg());
    jsonResponse(['status' => 'error', 'message' => 'Fehlerhafte JSON-Daten'], 400);
    exit;
}

if (!isset($dataArray['file_path']) || !is_string($dataArray['file_path']) || $dataArray['file_path'] === '') {
    logMessage('Fehler: Kein Dateipfad erhalten');
    jsonResponse(['status' => 'error', 'message' => 'Kein Dateipfad erhalten'], 400);
    exit;
}

$pathPart = parse_url($dataArray['file_path'], PHP_URL_PATH);
$baseName = basename($pathPart ?: $dataArray['file_path']);

$filePath = __DIR__ . '/uploads/' . $baseName;
logMessage('Pfad zur Datei, die gelöscht werden soll: ' . $filePath);

if (!file_exists($filePath)) {
    logMessage('Bild nicht gefunden: ' . $filePath);
    jsonResponse(['status' => 'error', 'message' => 'Bild nicht gefunden'], 404);
    exit;
}

if (!unlink($filePath)) {
    logMessage('Fehler beim Löschen des Bildes: ' . $filePath);
    jsonResponse(['status' => 'error', 'message' => 'Bild konnte nicht gelöscht werden'], 500);
    exit;
}

logMessage('Bild erfolgreich gelöscht: ' . $filePath);
jsonResponse(['status' => 'success', 'message' => 'Bild erfolgreich gelöscht'], 200);
