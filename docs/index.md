# Selfie-Upload für Photobooth

Willkommen auf der offiziellen Seite des **Selfie-Upload-Projekts**! Dieses Projekt bietet eine einfache Möglichkeit, Bilder von einem Smartphone zu einer Photobooth hochzuladen und die Galerie in Echtzeit zu erweitern.

---

## Funktionen

- **QR-Code-Integration**: Scanne den QR-Code, um die Upload-Seite direkt auf deinem Smartphone zu öffnen.
- **Webbasierter Upload**: Mache ein Selfie oder wähle ein Bild aus deiner Galerie.
- **Automatische Integration**: Lade das Bild in die Galerie der Photobooth hoch.
- **Moderne Technologie**: Unterstützt moderne Browser und ist leicht zu integrieren.

---

## Anleitung

### 1. QR-Code scannen
Scanne den bereitgestellten QR-Code mit deinem Smartphone. Dieser führt dich direkt zur Upload-Seite.

### 2. Selfie machen oder Bild hochladen
- Nutze die Kamera deines Smartphones, um ein neues Foto zu machen oder wähle ein Bild aus deiner Galerie.

### 3. Galerie aktualisieren
Nach dem Upload wird dein Bild automatisch in der Galerie der Photobooth angezeigt.

---

## Zielgruppe

Dieses Projekt eignet sich perfekt für:
- **Hochzeiten**: Gäste können Selfies direkt zur Photobooth-Galerie hinzufügen.
- **Events und Partys**: Sammle Erinnerungen von deinen Gästen an einem zentralen Ort.
- **Marketing-Kampagnen**: Nutze die Funktion, um Bilder für Social-Media-Kampagnen zu sammeln.

---

## Installation

### Voraussetzungen

Damit der Webhook korrekt funktioniert, müssen folgende Voraussetzungen erfüllt sein:

- Eine funktionierende Photobooth mit der entsprechenden Software: [Photobooth Project](https://photoboothproject.github.io).
- Eine stabile Internetverbindung auf der Photobooth.
- Der Webhook auf der Photobooth muss korrekt konfiguriert sein, um den Löschbefehl an die Website zu senden.
- Die Datei- und Ordnerberechtigungen müssen so gesetzt sein, dass der Upload und die Verarbeitung reibungslos funktionieren.
- Ein externer Webserver (z. B. Apache oder Nginx), auf dem PHP ausgeführt wird.
- Eine Netzwerkverbindung zwischen dem Webserver und der Photobooth:
  - Die Photobooth muss über eine direkte IP-Adresse oder eine Proxy-/VPN-Verbindung erreichbar sein (z. B. mittels einer festen IP oder DDNS über einen Router, der mit Wireguard verbunden ist).

### Schritt-für-Schritt-Anleitung

1. Klone das Repository:
   
   ```bash
   git clone https://github.com/flacoonb/Selfie-Upload.git
