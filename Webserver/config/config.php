<?php

return [
    // Basis-URL der Website, z.B. "https://example.com/selfie-upload"
    'base_url' => getenv('SELFIE_BASE_URL') ?: 'https://XXXX.com/selfie-upload',

    // URL der Photobooth (Webhook-Empfänger), z.B. "https://photobooth.example.com/private/webhook/webhook_receiver.php"
    'photobooth_webhook_url' => getenv('PHOTOBOOTH_WEBHOOK_URL') ?: 'https://XXXX.com/private/webhook/webhook_receiver.php',

    // ffentliche URL dieses Servers für den Lösch-Webhook (wird von der Photobooth aufgerufen)
    'delete_image_webhook_url' => getenv('DELETE_IMAGE_WEBHOOK_URL') ?: 'https://XXXX.com/selfie-upload/delete_image.php',

    // Shared Secret für Webhooks (Header: X-Webhook-Token). Setze als ENV: SELFIE_WEBHOOK_TOKEN
    'webhook_token' => getenv('SELFIE_WEBHOOK_TOKEN') ?: '',

    // Upload-Schutz
    'max_upload_bytes' => (int)(getenv('MAX_UPLOAD_BYTES') ?: 8 * 1024 * 1024),
];
