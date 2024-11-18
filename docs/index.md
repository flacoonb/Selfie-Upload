# Selfie-Upload für Photobooth

Willkommen auf der offiziellen Seite des **Selfie-Upload-Projekts**! Dieses Projekt ermöglicht es, Bilder direkt von einem Smartphone zu einer Photobooth hochzuladen und die Galerie automatisch zu erweitern. 

---

## Übersicht

- [Funktionen](#funktionen)
- [Anleitung](#anleitung)
- [Installation](#installation)
- [Technische Details](#technische-details)
- [Sicherheitshinweise](#sicherheitshinweise)

---

## Funktionen

- **QR-Code-Integration**: Scanne den QR-Code, um die Upload-Seite direkt auf deinem Smartphone zu öffnen.
- **Webbasierter Upload**: Mache ein Selfie oder wähle ein Bild aus deiner Galerie.
- **Automatische Integration**: Lade das Bild in die Galerie der Photobooth hoch.
- **Webhook-Unterstützung**: Synchronisiert Bilder zwischen Webserver und Photobooth in Echtzeit.
- **Moderne Technologie**: Unterstützt aktuelle Browser und ist leicht zu konfigurieren.

---

## Anleitung

### 1. QR-Code scannen
Scanne den bereitgestellten QR-Code mit deinem Smartphone, um die Upload-Seite zu öffnen.

### 2. Selfie machen oder Bild hochladen
- Nutze die Kamera deines Smartphones, um ein neues Foto zu machen.
- Alternativ kannst du ein Bild aus deiner Galerie hochladen.

### 3. Galerie aktualisieren
Nach dem Upload wird das Bild automatisch von der Photobooth verarbeitet und in die Galerie integriert.

---

## Installation

### Voraussetzungen

- **Photobooth-Software**: [Photobooth Project](https://photoboothproject.github.io).
- **Externer Webserver** (z. B. Apache oder Nginx) mit PHP-Unterstützung.
- **Netzwerkverbindung**: Zwischen Webserver und Photobooth, z. B. über fixe IP oder VPN.

### Schritte zur Installation

1. **Repository klonen**:
   ```bash
   git clone https://github.com/flacoonb/Selfie-Upload.git
