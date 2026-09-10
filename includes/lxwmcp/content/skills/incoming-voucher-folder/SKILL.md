---
name: incoming-voucher-folder
title: Lokalen Eingangsbeleg-Ordner verarbeiten
description: Agenten-Skill für sichere, resumierbare PDF-Uploads und benutzergeführte Buchung.
version: 1.1.0
---

# Lokalen Eingangsbeleg-Ordner verarbeiten

Lies zuerst `lexware://handbooks/incoming-vouchers`, `lexware://handbooks/accounts` und `lexware://handbooks/errors-recovery`.

Verarbeite alle unterstützten Dateien deterministisch nach normalisiertem Pfad. Komprimiere PDFs über 1.000.000 Bytes nach Möglichkeit lokal und verlustfrei. Behalte das Original und verwende die kleinere Fassung nur, wenn Lesbarkeit, Vollständigkeit, digitale Signaturen und eingebettete Rechnungsdaten erhalten bleiben. Signierte PDFs nicht neu schreiben, wenn dadurch ihre Signatur ungültig würde. Ohne sichere Komprimierung verwende das Original; Dateien über 4.500.000 Bytes auslassen und mit Begründung melden.

Berechne Größe und SHA-256 erst für die endgültige Upload-Datei. Rufe `lexware_file` mit `operation: "prepare_upload"`, Account und `source: {filename, mime_type, size_bytes, sha256}` auf. Übertrage die Datei aus der lokalen Laufzeit per HTTP-PUT an die zurückgegebene URL mit den angegebenen Headern. Lokale Pfade bleiben in der lokalen Laufzeit; Datei-Bytes niemals über den Modellkontext kopieren. Die Upload-Berechtigung gilt 15 Minuten, die empfangene Datei 3.660 Sekunden.

Nach bestätigtem Empfang verwende `upload_voucher` mit `source: {kind: "upload", upload_id}` und einem stabilen Idempotenzschlüssel aus Account-Alias und SHA-256. Zum Anhängen verwende `attach_to_voucher` mit zusätzlicher `voucher_id`. Wiederhole eine Übertragung nur mit denselben Bytes; wiederhole eine Belegoperation nur mit demselben Schlüssel. Bei unklarem Ergebnis zuerst `lexware_get` mit `entity: "operation_status"` verwenden. Speichere `upload_id`, Idempotenzschlüssel, `voucherId` und `fileId` zur Wiederaufnahme.

Nutze `lexware_describe`, um Operationen, Enums oder Berechtigungsblocker zu klären. Fehlt dem Client eine lokale HTTP-Upload-Möglichkeit, melde diese Einschränkung; lasse große Dateien nicht ersatzweise als Base64 durch den Modellkontext laufen.

OCR-Ergebnisse dürfen als Vorschlag dienen. Fehlende Pflichtwerte, mehrdeutige Kontakte oder Buchungskategorien erfordern Benutzerklärung. Finalisiere nur, wenn der Benutzer dies für den Lauf angeordnet hat und jede Datei eindeutig validiert ist. Isoliere Fehler pro Datei, setze sichere übrige Dateien fort und berichte abschließend gebuchte, offene und fehlgeschlagene Dateien getrennt.
