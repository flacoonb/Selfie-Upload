<?php
// Lade die Konfigurationsdatei
$config = require __DIR__ . '/config/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $imageTmpPath = $_FILES['image']['tmp_name'];
        $fileName = uniqid() . '.jpg';
        $uploadDir = __DIR__ . '/uploads/';
        $filePath = $uploadDir . $fileName;

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        if (move_uploaded_file($imageTmpPath, $filePath)) {
            $imageUrl = $config['base_url'] . '/uploads/' . $fileName;
            $webhookUrl = $config['photobooth_webhook_url'];
            $webhookData = json_encode(['image_url' => $imageUrl]);

            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $webhookData);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($http_code === 200) {
                echo json_encode(['status' => 'success', 'file' => $fileName]);
            } else {
                $errorMessage = "Webhook fehlgeschlagen, HTTP-Code: $http_code, Fehler: $curl_error, Antwort: $response";
                error_log($errorMessage, 3, '/path/to/logfile.log');
                echo json_encode(['status' => 'error', 'message' => $errorMessage]);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Upload fehlgeschlagen']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Kein Bild gefunden oder Upload-Fehler']);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Selfie Upload</title>
    <link rel="manifest" href="/manifest.json">
    <style>
        body {
            font-family: 'Verdana', sans-serif;
            background-color: #ffffff;
            background-image: url('https://raw.githubusercontent.com/PhotoboothProject/photobooth/dev/resources/img/logo/banner.png');
            background-repeat: no-repeat;
            background-size: cover;
            background-position: center;
            color: #c42847;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
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

        snapButton.addEventListener('click', () => {
            fileInput.click();
        });

        fileInput.addEventListener('change', (event) => {
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    preview.classList.remove('hidden');
                }
                reader.readAsDataURL(file);

                fileUploadInput.files = event.target.files;
                const formData = new FormData();
                formData.append('image', file);

                spinner.style.display = 'block';

                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    spinner.style.display = 'none';
                    if (data.status === 'success') {
                        message.textContent = 'Bild erfolgreich hochgeladen!';
                        message.className = 'message success';
                        message.style.display = 'block';
                        setTimeout(() => {
                            message.style.display = 'none';
                        }, 5000);

                        preview.classList.add('hidden');
                    } else {
                        message.textContent = 'Fehler beim Hochladen: ' + data.message;
                        message.className = 'message error';
                        message.style.display = 'block';
                    }
                })
                .catch(error => {
                    spinner.style.display = 'none';
                    message.textContent = 'Upload-Fehler: ' + error;
                    message.className = 'message error';
                    message.style.display = 'block';
                });
            }
        });
    </script>
</body>
</html>
