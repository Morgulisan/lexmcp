---
title: Eingangsbelege und PDF-Ordner
description: Wiederaufnehmbarer Ablauf für lokale PDF-Ordner und Lexware OCR.
version: 1.0.0
---

# Eingangsbelege aus einem lokalen Ordner

1. Lass den Benutzer einen lokalen Ordner und genau einen Account-Alias bestimmen.
2. Liste alle unterstützten Dateien deterministisch auf und berechne pro Datei SHA-256.
3. Rufe `lexware_file` pro Datei mit `upload_voucher`, Base64-Inhalt, Dateiname, erkanntem MIME-Typ, SHA-256 und einem stabilen Idempotenzschlüssel auf.
4. Merke `fileId` und `voucherId`; eine identische Datei wird von Lexware anhand ihres Inhalts dedupliziert.
5. Frage den Datei- oder Voucher-Status mit angemessenem Abstand ab. `blank` bedeutet, dass OCR noch läuft.
6. Prüfe im Zustand `unchecked` alle erkannten Angaben und lade bei Bedarf Buchungskategorien.
7. Frage den Benutzer bei fehlenden oder mehrdeutigen Angaben. Der MCP wählt keine Kategorie selbständig.
8. Rufe nur nach ausdrücklicher Entscheidung `lexware_finalize` mit `voucher_book`, `confirm:true` und neuem Idempotenzschlüssel auf.
9. Fahre nach einem einzelnen, eindeutig isolierten Dateifehler mit den übrigen Dateien fort und liefere am Ende eine Liste aus gebuchten, hochgeladen-aber-offenen und fehlgeschlagenen Dateien. Wiederhole keine `uncertain`-Operation mit einem neuen Schlüssel.

Ein Serverpfad zum lokalen Ordner darf niemals an das MCP übergeben werden. Der Agent überträgt jede Datei einzeln.
