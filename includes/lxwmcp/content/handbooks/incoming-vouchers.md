---
title: Eingangsbelege und PDF-Ordner
description: Wiederaufnehmbarer Ablauf für lokale PDF-Ordner und Lexware OCR.
version: 1.1.0
---

# Eingangsbelege aus einem lokalen Ordner

1. Lass den Benutzer einen lokalen Ordner und genau einen Account-Alias bestimmen.
2. Liste alle unterstützten Dateien deterministisch auf. Komprimiere PDFs über 1.000.000 Bytes nach Möglichkeit verlustfrei, ohne Signaturen oder eingebettete Rechnungsdaten zu verändern. Original behalten; ohne sichere kleinere Fassung das Original verwenden. Dateien über 4.500.000 Bytes auslassen und melden. Berechne Größe und SHA-256 der endgültigen Datei.
3. Rufe `lexware_file` mit `prepare_upload` und `source: {filename, mime_type, size_bytes, sha256}` auf. Übertrage die lokalen Bytes per HTTP-PUT mit den zurückgegebenen Headern an die temporäre URL. Nach erfolgreichem Empfang rufe `upload_voucher` mit `source: {kind: "upload", upload_id}` und einem stabilen Idempotenzschlüssel auf. `attach_to_voucher` benötigt zusätzlich `voucher_id`.
4. Merke Upload-ID, Idempotenzschlüssel, `fileId` und `voucherId`. Verwende für Wiederholungen denselben Schlüssel. Die Übertragungsberechtigung gilt 15 Minuten, eine empfangene Datei 3.660 Sekunden. Nach erfolgreicher Weitergabe wird sie entfernt; das gespeicherte Operationsergebnis bleibt abrufbar.
5. Frage den Datei- oder Voucher-Status mit angemessenem Abstand ab. `blank` bedeutet, dass OCR noch läuft.
6. Prüfe im Zustand `unchecked` alle erkannten Angaben und lade bei Bedarf Buchungskategorien.
7. Frage den Benutzer bei fehlenden oder mehrdeutigen Angaben. Der MCP wählt keine Kategorie selbständig.
8. Rufe nur nach ausdrücklicher Entscheidung `lexware_finalize` mit `voucher_book`, `confirm:true` und neuem Idempotenzschlüssel auf.
9. Fahre nach einem einzelnen, eindeutig isolierten Dateifehler mit den übrigen Dateien fort und liefere am Ende eine Liste aus gebuchten, hochgeladen-aber-offenen und fehlgeschlagenen Dateien. Wiederhole keine `uncertain`-Operation mit einem neuen Schlüssel.

Ein lokaler Pfad wird nur an die lokale HTTP-Laufzeit übergeben, niemals an den entfernten MCP. Die Datei-Bytes umgehen den Modellkontext. `lexware_describe` liefert gültige Operationen, Enums und aktuelle Berechtigungsblocker. Finalisieren erfordert den Benutzerschalter unter `/accounts` (Standard: an), die Verbindungsberechtigung und `confirm:true`.
