<?php
// Lade die Konfigurationsdatei
$config = require __DIR__ . '/config/config.php';

$logFile = __DIR__ . '/selfie-upload.log';

function logLine(string $message): void {
    global $logFile;
    $line = date('c') . ' - ' . $message . "
";
    error_log($line, 3, $logFile);
}

function jsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['image']) || !is_array($_FILES['image'])) {
        jsonResponse(['status' => 'error', 'message' => 'Kein Bild gefunden'], 400);
        exit;
    }

    if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        jsonResponse(['status' => 'error', 'message' => 'Upload-Fehler'], 400);
        exit;
    }

    $maxBytes = (int)($config['max_upload_bytes'] ?? (8 * 1024 * 1024));
    $size = (int)($_FILES['image']['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        jsonResponse(['status' => 'error', 'message' => 'Datei zu groß oder ungültig'], 413);
        exit;
    }

    $imageTmpPath = (string)$_FILES['image']['tmp_name'];
    if ($imageTmpPath === '' || !is_uploaded_file($imageTmpPath)) {
        jsonResponse(['status' => 'error', 'message' => 'Ungültiger Upload'], 400);
        exit;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($imageTmpPath) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/pjpeg'], true)) {
        jsonResponse(['status' => 'error', 'message' => 'Nur JPEG wird akzeptiert'], 415);
        exit;
    }

    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            logLine('Fehler: Upload-Verzeichnis konnte nicht erstellt werden: ' . $uploadDir);
            jsonResponse(['status' => 'error', 'message' => 'Serverfehler'], 500);
            exit;
        }
    }

    try {
        $fileName = bin2hex(random_bytes(16)) . '.jpg';
    } catch (Throwable $e) {
        $fileName = uniqid('', true) . '.jpg';
    }

    $filePath = $uploadDir . $fileName;

    if (!move_uploaded_file($imageTmpPath, $filePath)) {
        jsonResponse(['status' => 'error', 'message' => 'Upload fehlgeschlagen'], 500);
        exit;
    }

    $baseUrl = (string)($config['base_url'] ?? '');
    $webhookUrl = (string)($config['photobooth_webhook_url'] ?? '');
    if ($baseUrl === '' || $webhookUrl === '') {
        logLine('Fehler: base_url oder photobooth_webhook_url fehlt in config.php');
        jsonResponse(['status' => 'error', 'message' => 'Server ist nicht konfiguriert'], 500);
        exit;
    }

    $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . $fileName;
    $webhookData = json_encode(['image_url' => $imageUrl], JSON_UNESCAPED_SLASHES);

    $headers = ['Content-Type: application/json'];
    if (!empty($config['webhook_token'])) {
        $headers[] = 'X-Webhook-Token: ' . $config['webhook_token'];
    }

    $ch = curl_init($webhookUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $webhookData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        $errorMessage = "Webhook fehlgeschlagen, HTTP-Code: $httpCode, Fehler: $curlError, Antwort: $response";
        logLine($errorMessage);
        jsonResponse(['status' => 'error', 'message' => 'Webhook fehlgeschlagen'], 502);
        exit;
    }

    jsonResponse(['status' => 'success', 'file' => $fileName]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=1, user-scalable=no">
    <title>Selfie Upload</title>
    <link rel="manifest" href="/manifest.json">
    <style>
        html, body {
            height: 100%;
            width: 100%;
            margin: 0;
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
            padding: 0;
            overflow: hidden;
        }

        body {
            font-family: 'Verdana', sans-serif;
            background-color: #ffffff;
            background-image: url('https://raw.githubusercontent.com/flacoonb/Selfie-Upload/refs/heads/main/selfieupload.webp');
            background-repeat: no-repeat;
            background-size: cover;
            background-position: center;
            color: #c42847;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100dvh;
            margin: 0;
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
        }

        h1 {
            color: #c42847;
            margin-bottom: 20px;
            background-color: rgba(255, 255, 255, 0.8);
            padding: 10px;
            border-radius: 10px;
        }

        button {
            padding: 12px 25px;
            font-size: 16px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s ease, transform 0.2s ease;
            margin-top: 10px;
            background-color: #c42847;
            color: white;
        }

        button:hover {
            background-color: #9f1f36;
            transform: scale(1.05);
        }

        #spinner {
            display: none;
            margin-top: 20px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid #3498db;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        img {
            margin-top: 20px;
            max-width: 100%;
            height: auto;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .hidden {
            display: none;
        }

        .message {
            margin-top: 10px;
            padding: 10px;
            border-radius: 5px;
            display: none;
            text-align: center;
        }

        .message.success {
            background-color: #4CAF50;
            color: white;
        }

        .message.error {
            background-color: #c42847;
            color: white;
        }

        #controls {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            background-color: rgba(255, 255, 255, 0.8);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
    </style>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js')
                .then(() => console.log('Service Worker registriert'))
                .catch((err) => console.log('Service Worker Registrierung fehlgeschlagen:', err));
        }
    </script>
</head>
<body>

    <h1>Photobooth Selfie</h1>

    <div id="controls">
        <input type="file" accept="image/*" capture="user" id="fileInput" class="hidden">
        <button id="snap">Selfie aufnehmen</button>

        <div id="instructions" style="margin-top: 10px; color: #555; font-size: 14px;">
            Klicken Sie auf den Button "Selfie aufnehmen", um ein Foto zu machen. 
            Nach dem Aufnehmen wird das Bild automatisch hochgeladen.
        </div>

        <div id="spinner"></div>

        <div id="message" class="message"></div>
    </div>

    <img id="preview" src="#" alt="Selfie Vorschau" class="hidden"/>

    <form id="uploadForm" method="post" enctype="multipart/form-data" style="display: none;">
        <input type="file" name="image" id="fileUploadInput" style="display: none;">
    </form>

    <script>
        const fileInput = document.getElementById('fileInput');
        const snapButton = document.getElementById('snap');
        const preview = document.getElementById('preview');
        const fileUploadInput = document.getElementById('fileUploadInput');
        const message = document.getElementById('message');
        const spinner = document.getElementById('spinner');

        let busy = false;
        snapButton.addEventListener('click', () => {
            if (busy) return;
            busy = true;
            snapButton.disabled = true;


            fileInput.value = '';
            fileInput.click();
        });

                fileInput.addEventListener('change', (event) => {
            const file = event.target.files && event.target.files[0];

            // Wenn User abbricht / kein File geliefert wird: UI wieder freigeben
            if (!file) {
                busy = false;
                snapButton.disabled = false;
                spinner.style.display = 'none';
                return;
            }

            // Preview (optional)
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.classList.remove('hidden');
            };
            reader.readAsDataURL(file);

            fileUploadInput.files = event.target.files;
            const formData = new FormData();
            formData.append('image', file);

            spinner.style.display = 'block';
            message.style.display = 'none';

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                spinner.style.display = 'none';

                if (data.status === 'success') {
                    message.textContent = 'Bild erfolgreich hochgeladen!';
                    message.className = 'message success';
                    message.style.display = 'block';
                    setTimeout(() => { message.style.display = 'none'; }, 2500);
                    preview.classList.add('hidden');
                } else {
                    message.textContent = 'Fehler beim Hochladen: ' + (data.message || 'Unbekannter Fehler');
                    message.className = 'message error';
                    message.style.display = 'block';
                }
            })
            .catch(error => {
                spinner.style.display = 'none';
                message.textContent = 'Upload-Fehler: ' + error;
                message.className = 'message error';
                message.style.display = 'block';
            })
            .finally(() => {
                busy = false;
                snapButton.disabled = false;
                fileInput.value = '';
            });
        });

    </script>
</body>
</html>
