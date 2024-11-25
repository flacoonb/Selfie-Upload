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

<?php
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

$data = file_get_contents("php://input");
logMessage("Webhook-Daten empfangen: " . ($data ?: 'Keine Daten empfangen'));

$dataArray = json_decode($data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    logMessage("Fehler: JSON-Daten konnten nicht dekodiert werden - " . json_last_error_msg());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Fehlerhafte JSON-Daten']);
    exit;
}

if (!isset($dataArray['image_url'])) {
    logMessage("Fehler: Keine Bild-URL erhalten.");
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Keine Bild-URL erhalten']);
    exit;
}

$imageUrl = $dataArray['image_url'];
$imageFileName = basename($imageUrl);
$destination = $imageDirectory . $imageFileName;

// Wiederholungsversuche für das Herunterladen des Bildes
$maxRetries = 10;
$retryDelay = 3;
$attempt = 0;
$imageData = false;

while ($attempt < $maxRetries) {
    $imageData = @file_get_contents($imageUrl);
    if ($imageData !== false) {
        break;
    }
    logMessage("Bild nicht gefunden, erneuter Versuch in {$retryDelay} Sekunden... (Versuch: " . ($attempt + 1) . ")");
    sleep($retryDelay);
    $attempt++;
}

if ($imageData === false) {
    logMessage("Fehler: Bild konnte nach $maxRetries Versuchen nicht von URL geladen werden: $imageUrl");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Bild konnte nicht geladen werden']);
    exit;
}

// Speichere das Bild im Zielverzeichnis
if (file_put_contents($destination, $imageData) === false) {
    logMessage("Fehler: Bild konnte nicht gespeichert werden: $destination");
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Bild konnte nicht gespeichert werden']);
    exit;
}

logMessage("Bild erfolgreich heruntergeladen und gespeichert: $destination");

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
$deleteImageUrl = 'https://example.com/delete_image.php';
$deleteData = json_encode(['file_path' => $imageUrl]);

$contextOptions = [
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $deleteData,
        'timeout' => 120,
    ]
];
$context = stream_context_create($contextOptions);
$response = @file_get_contents($deleteImageUrl, false, $context);

if ($response !== false) {
    logMessage("Lösch-Webhook erfolgreich gesendet, Antwort: " . $response);
} else {
    $error = error_get_last();
    logMessage("Fehler beim Senden des Lösch-Webhooks: " . ($error['message'] ?? 'Unbekannter Fehler'));
}

http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Bild erfolgreich empfangen und verarbeitet']);
