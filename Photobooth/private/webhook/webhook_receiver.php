<?php

/*
 * Teile dieses Codes stammen aus dem PhotoboothProject (https://github.com/PhotoboothProject/photobooth)
 * und sind lizenziert unter der MIT-Lizenz.
 * 
 * Urheberrecht © 2024 PhotoboothProject Contributors.
 * 
 * Die MIT-Lizenz gestattet die Verwendung, Änderung und Verbreitung dieses Codes unter folgenden Bedingungen:
 * - Der obige Urheberrechtsvermerk und dieser Genehmigungsvermerk müssen in allen Kopien oder wesentlichen Teilen der Software enthalten sein.
 * 
 * DIE SOFTWARE WIRD OHNE JEDE AUSDRÜCKLICHE ODER IMPLIZIERTE GARANTIE BEREITGESTELLT, EINSCHLIESSLICH DER GARANTIE DER MARKTGÄNGIGKEIT, DER EIGNUNG FÜR EINEN BESTIMMTEN ZWECK UND DER NICHTVERLETZUNG.
 */
// Log-Datei für das Webhook-Skript
$logFile = '/var/log/webhook_receiver.log';
$imageDirectory = '/var/www/html/private/images/uploads/';

// Log-Funktion, um Nachrichten in die Log-Datei zu schreiben
function logMessage($message) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

// Funktion zur Bildausrichtung basierend auf Exif-Daten
function fixImageOrientation($filename) {
    $image = @imagecreatefromjpeg($filename);
    if (!$image) {
        logMessage("Fehler beim Laden der Bilddatei für Exif-Korrektur: $filename");
        return;
    }
    $exif = @exif_read_data($filename);
    if (!empty($exif['Orientation'])) {
        switch ($exif['Orientation']) {
            case 3: $image = imagerotate($image, 180, 0); break;
            case 6: $image = imagerotate($image, -90, 0); break;
            case 8: $image = imagerotate($image, 90, 0); break;
        }
    }
    imagejpeg($image, $filename, 90);
    imagedestroy($image);
}

// Webhook-Daten empfangen und verarbeiten
logMessage("Webhook-Empfänger gestartet");

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$expectedToken = getenv('SELFIE_WEBHOOK_TOKEN') ?: '';
if ($expectedToken !== '') {
    $providedToken = (string)($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? '');
    if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
        logMessage('Fehler: Ungültiger Webhook-Token');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
}

$data = file_get_contents('php://input');
logMessage('Webhook-Daten empfangen: ' . ($data ?: 'Keine Daten empfangen'));

$dataArray = json_decode($data ?: '', true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage('Fehler: JSON-Daten konnten nicht dekodiert werden - ' . json_last_error_msg());
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Fehlerhafte JSON-Daten']);
    exit;
}

if (!isset($dataArray['image_url']) || !is_string($dataArray['image_url']) || $dataArray['image_url'] === '') {
    logMessage('Fehler: Keine Bild-URL erhalten.');
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Keine Bild-URL erhalten']);
    exit;
}

$imageUrl = $dataArray['image_url'];
$parsed = parse_url($imageUrl);

if (!is_array($parsed) || ($parsed['scheme'] ?? '') !== 'https' || empty($parsed['host']) || empty($parsed['path'])) {
    logMessage('Fehler: Ungültige image_url: ' . $imageUrl);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Ungültige Bild-URL']);
    exit;
}

$allowedHost = getenv('SELFIE_UPLOAD_HOST') ?: '';
if ($allowedHost !== '' && strcasecmp($allowedHost, $parsed['host']) !== 0) {
    logMessage('Fehler: Host nicht erlaubt: ' . $parsed['host']);
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Host nicht erlaubt']);
    exit;
}

if (strpos($parsed['path'], '/uploads/') === false) {
    logMessage('Fehler: Pfad nicht erlaubt: ' . $parsed['path']);
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Pfad nicht erlaubt']);
    exit;
}

$imageFileName = basename($parsed['path']);
if ($imageFileName === '' || !preg_match('/\.(jpe?g)$/i', $imageFileName)) {
    logMessage('Fehler: Dateiname/Endung nicht erlaubt: ' . $imageFileName);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Dateityp nicht erlaubt']);
    exit;
}

if (!is_dir($imageDirectory)) {
    @mkdir($imageDirectory, 0755, true);
}

$destination = $imageDirectory . $imageFileName;
$maxDownloadBytes = (int)(getenv('MAX_DOWNLOAD_BYTES') ?: (8 * 1024 * 1024));

function downloadWithLimit(string $url, string $dest, int $maxBytes): array {
    $tmp = $dest . '.part';
    $fp = @fopen($tmp, 'wb');
    if ($fp === false) {
        return [false, 'Temp-Datei konnte nicht erstellt werden'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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

    if (filesize($tmp) === false || filesize($tmp) <= 0 || filesize($tmp) > $maxBytes) {
        @unlink($tmp);
        return [false, 'Downloadgre ungültig/zu groß'];
    }

    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return [false, 'Datei konnte nicht verschoben werden'];
    }

    return [true, ''];
}

$maxRetries = 10;
$retryDelay = 3;
$attempt = 0;
$lastError = '';

while ($attempt < $maxRetries) {
    [$ok, $err] = downloadWithLimit($imageUrl, $destination, $maxDownloadBytes);
    if ($ok) {
        $lastError = '';
        break;
    }

    $lastError = (string)$err;
    logMessage('Download fehlgeschlagen, erneuter Versuch in ' . $retryDelay . ' Sekunden... (Versuch: ' . ($attempt + 1) . '), Fehler: ' . $lastError);
    sleep($retryDelay);
    $attempt++;
}

if ($lastError !== '') {
    logMessage('Fehler: Bild konnte nicht geladen werden: ' . $imageUrl . ' (' . $lastError . ')');
    http_response_code(502);
    echo json_encode(['status' => 'error', 'message' => 'Bild konnte nicht geladen werden']);
    exit;
}

logMessage('Bild erfolgreich heruntergeladen und gespeichert: ' . $destination);

// Beginne die Weiterverarbeitung
require_once '/var/www/html/lib/boot.php';

use Photobooth\Image;
use Photobooth\Enum\FolderEnum;
use Photobooth\Service\DatabaseManagerService;
use Photobooth\Service\LoggerService;

$logger = LoggerService::getInstance()->getLogger('main');
$logger->info("Verarbeite neues Bild: $destination");

$imageHandler = new Image();
$database = DatabaseManagerService::getInstance();

try {
    $imageNewName = Image::createNewFilename($config['picture']['naming']);
    $filename_photo = FolderEnum::IMAGES->absolute() . DIRECTORY_SEPARATOR . $imageNewName;
    $filename_tmp = FolderEnum::TEMP->absolute() . DIRECTORY_SEPARATOR . $imageNewName;
    $filename_thumb = FolderEnum::THUMBS->absolute() . DIRECTORY_SEPARATOR . $imageNewName;

    if (!copy($destination, $filename_tmp)) {
        throw new \Exception("Fehler: Foto konnte nicht kopiert werden: $destination");
    }

    // Bildausrichtung basierend auf Exif-Daten korrigieren
    fixImageOrientation($filename_tmp);

    $imageResource = $imageHandler->createFromImage($filename_tmp);
    if (!$imageResource instanceof \GdImage) {
        throw new \Exception('Fehler beim Erstellen der Bildressource.');
    }

    $thumb_size = intval(substr($config['picture']['thumb_size'], 0, -2));
    $thumbResource = $imageHandler->resizeImage($imageResource, $thumb_size);
    if (!$thumbResource instanceof \GdImage) {
        throw new \Exception('Fehler beim Erstellen der Thumbnail-Ressource.');
    }

    $imageHandler->jpegQuality = $config['jpeg_quality']['thumb'];
    if (!$imageHandler->saveJpeg($thumbResource, $filename_thumb)) {
        throw new \Exception("Fehler beim Speichern des Thumbnails: $filename_thumb.");
    }

    $imageHandler->jpegQuality = $config['jpeg_quality']['image'];
    if (!$imageHandler->saveJpeg($imageResource, $filename_photo)) {
        throw new \Exception("Fehler beim Speichern des Bildes: $filename_photo.");
    }

    // Berechtigungen setzen
    $picture_permissions = $config['picture']['permissions'];
    if (!chmod($filename_photo, (int)octdec($picture_permissions))) {
        logMessage("Warnung: Berechtigungen für Bild konnten nicht geändert werden.");
    }

    // Temporäre Datei löschen
    if (!unlink($filename_tmp)) {
        logMessage("Warnung: Temporäre Datei konnte nicht gelöscht werden: $filename_tmp.");
    }

    // Datenbank aktualisieren, falls aktiviert
    if ($config['database']['enabled']) {
        $database->appendContentToDB($imageNewName);
    }

    $logger->info("Bild $destination erfolgreich verarbeitet.");

} catch (\Exception $e) {
    $logger->error('Fehler bei der Bildverarbeitung: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Bildverarbeitung fehlgeschlagen']);
    exit;
}

// Lösch-Webhook an die Website senden
$deleteImageUrl = getenv('DELETE_IMAGE_WEBHOOK_URL') ?: (getenv('SELFIE_DELETE_WEBHOOK_URL') ?: '');
$deleteData = json_encode(['file_path' => $imageUrl], JSON_UNESCAPED_SLASHES);

if ($deleteImageUrl === '') {
    logMessage('Warnung: DELETE_IMAGE_WEBHOOK_URL ist nicht gesetzt – Bild bleibt ggf. auf dem Webserver liegen.');
} else {
    $headers = ['Content-Type: application/json'];
    $token = getenv('SELFIE_WEBHOOK_TOKEN') ?: '';
    if ($token !== '') {
        $headers[] = 'X-Webhook-Token: ' . $token;
    }

    $ch = curl_init($deleteImageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $deleteData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
        logMessage('Lösch-Webhook erfolgreich gesendet, Antwort: ' . $response);
    } else {
        logMessage('Fehler beim Senden des Lösch-Webhooks: HTTP ' . $httpCode . ' - ' . ($curlError ?: (string)$response));
    }
}

http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Bild erfolgreich empfangen und verarbeitet']);
