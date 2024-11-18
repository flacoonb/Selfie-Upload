<?php
// Log-Datei fÃ¼r das Webhook-Skript
$logFile = '/var/log/webhook_receiver.log';
$imageDirectory = '/var/www/html/private/images/uploads/';

// Log-Funktion, um Nachrichten in die Log-Datei zu schreiben
function logMessage($message) {
    global $logFile;
    file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

// Funktion zur Bildausrichtung basierend auf Exif-Daten
function fixImageOrientation($filename) {
    $image = imagecreatefromjpeg($filename);
    $exif = exif_read_data($filename);
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
logMessage("Webhook-EmpfÃ¤nger gestartet");

$data = file_get_contents("php://input");
logMessage("Webhook-Daten empfangen: " . $data);

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

// Wiederholungsversuche fÃ¼r das Herunterladen des Bildes
$maxRetries = 10;
$retryDelay = 3;
$attempt = 0;
$imageData = false;

while ($attempt < $maxRetries) {
    $imageData = file_get_contents($imageUrl);
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
    $imageHandler->resizeMaxWidth = $thumb_size;
    $imageHandler->resizeMaxHeight = $thumb_size;
    $thumbResource = $imageHandler->resizeImage($imageResource);
    if (!$thumbResource instanceof \GdImage) {
        throw new \Exception('Fehler beim Erstellen der Thumbnail-Ressource.');
    }

    $imageHandler->jpegQuality = $config['jpeg_quality']['thumb'];
    if (!$imageHandler->saveJpeg($thumbResource, $filename_thumb)) {
        $imageHandler->addErrorData('Warnung: Thumbnail konnte nicht erstellt werden.');
    }

    if ($imageHandler->imageModified || ($config['jpeg_quality']['image'] >= 0 && $config['jpeg_quality']['image'] < 100)) {
        $imageHandler->jpegQuality = $config['jpeg_quality']['image'];
        if (!$imageHandler->saveJpeg($imageResource, $filename_photo)) {
            throw new \Exception('Fehler beim Erstellen des Bildes.');
        }
    } else {
        if (!copy($filename_tmp, $filename_photo)) {
            throw new \Exception('Fehler beim Kopieren des Bildes.');
        }
    }

    $picture_permissions = $config['picture']['permissions'];
    if (!chmod($filename_photo, (int)octdec($picture_permissions))) {
        $imageHandler->addErrorData('Warnung: Berechtigungen fÃ¼r Bild konnten nicht geÃ¤ndert werden.');
    }

    if (!unlink($filename_tmp)) {
        $imageHandler->addErrorData('Warnung: TemporÃ¤re Datei konnte nicht gelÃ¶scht werden.');
    }

    if ($config['database']['enabled']) {
        $database->appendContentToDB($imageNewName);
    }

    $logger->info("Bild $destination erfolgreich verarbeitet.");

} catch (\Exception $e) {
    $logger->error('Fehler bei der Bildverarbeitung: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Bildverarbeitung fehlgeschlagen']);
    exit;
}

// VerzÃ¶gerung vor dem Senden des LÃ¶sch-Webhook
sleep(2);

// LÃ¶sch-Webhook an die Website senden
$deleteImageUrl = 'https://fotomat-sg.ch/test/delete_image.php';
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
$response = file_get_contents($deleteImageUrl, false, $context);

if ($response) {
    logMessage("LÃ¶sch-Webhook erfolgreich gesendet, Antwort: " . $response);
} else {
    $error = error_get_last();
    logMessage("Fehler beim Senden des LÃ¶sch-Webhooks: " . $error['message']);
}

http_response_code(200);
echo json_encode(['status' => 'success', 'message' => 'Bild erfolgreich empfangen und verarbeitet']);
?>
