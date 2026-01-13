<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$logFile = __DIR__ . '/webhook_receiver.log';

function logMessage(string $message): void
{
    global $logFile;
    @file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

// Load config from a PHP file (no server config needed)
$configFile = __DIR__ . '/webhook_config.php';
$cfg = [];
if (is_file($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) {
        $cfg = $loaded;
    }
}

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

logMessage('Webhook-Empfänger gestartet');

$expectedToken = (string)($cfg['SELFIE_WEBHOOK_TOKEN'] ?? '');
if ($expectedToken !== '') {
    $providedToken = (string)($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        logMessage('Fehler: Ungültiger Webhook-Token');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
}

$raw = file_get_contents('php://input');
logMessage('Webhook-Daten empfangen: ' . ($raw ?: 'Keine Daten empfangen'));

$data = json_decode($raw ?: '', true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage('Fehler: JSON-Daten konnten nicht dekodiert werden - ' . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Fehlerhafte JSON-Daten']);
    exit;
}

$imageUrl = $data['image_url'] ?? null;
if (!is_string($imageUrl) || trim($imageUrl) === '') {
    logMessage('Fehler: Keine Bild-URL erhalten.');
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Keine Bild-URL erhalten']);
    exit;
}

$parsed = parse_url($imageUrl);
if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host']) || empty($parsed['path'])) {
    logMessage('Fehler: Ungültige image_url: ' . $imageUrl);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Ungültige Bild-URL']);
    exit;
}

$allowedHost = (string)($cfg['SELFIE_UPLOAD_HOST'] ?? '');
if ($allowedHost !== '' && strcasecmp($allowedHost, (string)$parsed['host']) !== 0) {
    logMessage('Fehler: Host nicht erlaubt: ' . $parsed['host']);
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Host nicht erlaubt']);
    exit;
}

if (strpos((string)$parsed['path'], '/uploads/') === false) {
    logMessage('Fehler: Pfad nicht erlaubt: ' . $parsed['path']);
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Pfad nicht erlaubt']);
    exit;
}

$imageFileName = basename((string)$parsed['path']);
if ($imageFileName === '' || !preg_match('/\.(jpe?g)$/i', $imageFileName)) {
    logMessage('Fehler: Dateiname/Endung nicht erlaubt: ' . $imageFileName);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Dateityp nicht erlaubt']);
    exit;
}

$imageDirectory = (string)($cfg['IMAGE_DIRECTORY'] ?? '/var/www/html/data/images/');
if ($imageDirectory === '') {
    $imageDirectory = '/var/www/html/data/images/';
}
if (substr($imageDirectory, -1) !== '/' && substr($imageDirectory, -1) !== DIRECTORY_SEPARATOR) {
    $imageDirectory .= DIRECTORY_SEPARATOR;
}

if (!is_dir($imageDirectory)) {
    @mkdir($imageDirectory, 0755, true);
}

$destination = $imageDirectory . $imageFileName;
$maxDownloadBytes = (int)($cfg['MAX_DOWNLOAD_BYTES'] ?? (8 * 1024 * 1024));
if ($maxDownloadBytes < 1) {
    $maxDownloadBytes = 8 * 1024 * 1024;
}

$connectTimeout = (int)($cfg['DOWNLOAD_CONNECT_TIMEOUT_SECONDS'] ?? 10);
if ($connectTimeout < 1) {
    $connectTimeout = 1;
}

$timeout = (int)($cfg['DOWNLOAD_TIMEOUT_SECONDS'] ?? 30);
if ($timeout < 1) {
    $timeout = 1;
}

function downloadWithLimit(string $url, string $dest, int $maxBytes, int $connectTimeout, int $timeout): array
{
    $tmp = $dest . '.part';
    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        return [false, 'Temp-Datei konnte nicht erstellt werden'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_XFERINFOFUNCTION, function ($resource, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use ($maxBytes) {
        if ($downloaded > $maxBytes) {
            return 1;
        }
        return 0;
    });

    $ok = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
        @unlink($tmp);
        return [false, $err !== '' ? $err : ('HTTP ' . $httpCode)];
    }

    $size = @filesize($tmp);
    if ($size === false || $size <= 0 || $size > $maxBytes) {
        @unlink($tmp);
        return [false, 'Downloadgröße ungültig/zu groß'];
    }

    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return [false, 'Datei konnte nicht verschoben werden'];
    }

    return [true, ''];
}

function deriveDeleteWebhookUrlFromImageUrl(string $imageUrl): string
{
    $parsed = parse_url($imageUrl);
    if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host']) || empty($parsed['path'])) {
        return '';
    }

    $path = (string)$parsed['path'];
    $pos = strpos($path, '/uploads/');
    $basePath = $pos === false ? '' : substr($path, 0, $pos);

    if ($basePath === '') {
        $deletePath = '/delete_image.php';
    } else {
        $deletePath = rtrim($basePath, '/') . '/delete_image.php';
    }

    return 'https://' . $parsed['host'] . $deletePath;
}


function updatePhotoboothDb(string $dbFile, string $imageFileName): array
{
    if ($imageFileName === '') {
        return [false, 'empty filename'];
    }

    $dir = dirname($dbFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $curr = [];
    if (is_file($dbFile)) {
        $raw = @file_get_contents($dbFile);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $curr = $decoded;
            }
        }
    }

    if (!in_array($imageFileName, $curr, true)) {
        $curr[] = $imageFileName;
        $encoded = json_encode(array_values($curr));
        if (!is_string($encoded) || $encoded == '') {
            return [false, 'json encode failed'];
        }
        if (@file_put_contents($dbFile, $encoded) === false) {
            return [false, 'write failed'];
        }
    }

    return [true, ''];
}

function callDeleteWebhook(string $deleteUrl, string $token, string $filePath, int $connectTimeout, int $timeout): array
{
    $payload = json_encode(['file_path' => $filePath], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        return [false, 'JSON encode failed'];
    }

    $headers = ['Content-Type: application/json'];
    if ($token !== '') {
        $headers[] = 'X-Webhook-Token: ' . $token;
    }

    $ch = curl_init($deleteUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $httpCode < 200 || $httpCode >= 300) {
        $msg = $err !== '' ? $err : ('HTTP ' . $httpCode);
        return [false, $msg];
    }

    return [true, ''];
}

$maxRetries = (int)($cfg['MAX_RETRIES'] ?? 2);
if ($maxRetries < 1) {
    $maxRetries = 1;
}

$retryDelay = (int)($cfg['RETRY_DELAY_SECONDS'] ?? 1);
if ($retryDelay < 0) {
    $retryDelay = 0;
}

$attempt = 0;
$lastError = '';

while ($attempt < $maxRetries) {
    [$ok, $err] = downloadWithLimit($imageUrl, $destination, $maxDownloadBytes, $connectTimeout, $timeout);
    if ($ok) {
        $lastError = '';
        break;
    }

    $lastError = (string)$err;
    logMessage('Download fehlgeschlagen, erneuter Versuch in ' . $retryDelay . ' Sekunden... (Versuch: ' . ($attempt + 1) . '), Fehler: ' . $lastError);
    if ($retryDelay > 0) {
        sleep($retryDelay);
    }
    $attempt++;
}

if ($lastError !== '') {
    logMessage('Fehler: Bild konnte nicht geladen werden: ' . $imageUrl . ' (' . $lastError . ')');
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Bild konnte nicht geladen werden']);
    exit;
}

logMessage('Bild erfolgreich heruntergeladen und gespeichert: ' . $destination);

// Ensure the image shows up in Photobooth gallery: store in data/images and update data/db.txt
$dbFile = (string)($cfg['PHOTOBOOTH_DB_FILE'] ?? '/var/www/html/data/db.txt');
[$dbOk, $dbErr] = updatePhotoboothDb($dbFile, $imageFileName);
if ($dbOk) {
    logMessage('Photobooth-DB aktualisiert: ' . $dbFile . ' +' . $imageFileName);
} else {
    logMessage('Warnung: Photobooth-DB konnte nicht aktualisiert werden: ' . $dbFile . ' (' . (string)$dbErr . ')');
}


// Optional: tell Selfie-Upload to delete the original file after we successfully pulled it.
$deleteWebhookUrl = (string)($cfg['DELETE_IMAGE_WEBHOOK_URL'] ?? ($cfg['SELFIE_DELETE_WEBHOOK_URL'] ?? ''));
if ($deleteWebhookUrl === '') {
    $deleteWebhookUrl = deriveDeleteWebhookUrlFromImageUrl($imageUrl);
}

if ($deleteWebhookUrl !== '') {
    $deleteConnectTimeout = (int)($cfg['DELETE_CONNECT_TIMEOUT_SECONDS'] ?? 5);
    if ($deleteConnectTimeout < 1) {
        $deleteConnectTimeout = 1;
    }

    $deleteTimeout = (int)($cfg['DELETE_TIMEOUT_SECONDS'] ?? 10);
    if ($deleteTimeout < 1) {
        $deleteTimeout = 1;
    }

    [$deleteOk, $deleteErr] = callDeleteWebhook(
        $deleteWebhookUrl,
        (string)($cfg['SELFIE_WEBHOOK_TOKEN'] ?? ''),
        $imageUrl,
        $deleteConnectTimeout,
        $deleteTimeout
    );

    if ($deleteOk) {
        logMessage('Delete-Webhook erfolgreich aufgerufen: ' . $deleteWebhookUrl);
    } else {
        logMessage('Warnung: Delete-Webhook fehlgeschlagen: ' . $deleteWebhookUrl . ' (' . (string)$deleteErr . ')');
    }
} else {
    logMessage('Hinweis: Kein Delete-Webhook konfiguriert/ableitbar; Original bleibt auf dem Selfie-Server.');
}


http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Bild erfolgreich empfangen']);
