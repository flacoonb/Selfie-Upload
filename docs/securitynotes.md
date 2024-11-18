---
layout: single
title: Sicherheitshinweise
---

## Sicherheitshinweis

Bitte beachte, dass der `uploads`-Ordner und die Webhook-URL Sicherheitsbedenken hervorrufen können. Es ist nicht sicher, die Photobooth über das Internet zugänglich zu machen. Achte auf folgende Punkte:

- **Upload-Beschränkungen**: Stelle sicher, dass nur authentifizierte Benutzer Zugriff auf die Upload-Funktionalität haben, um Missbrauch zu verhindern.
- **Zugriff auf Uploads einschränken**: Schütze den `uploads`-Ordner mit einer `.htaccess`-Datei, um den direkten Zugriff zu verhindern.
- **Webhook-Absicherung**: Überlege, einen Authentifizierungstoken in den Webhook-Headern zu verwenden, um unberechtigte Webhook-Aufrufe zu verhindern.
- **SSL verwenden**: Wenn möglich, stelle sicher, dass sowohl der Webserver als auch die Photobooth HTTPS verwenden, um die Verbindung zu sichern.
