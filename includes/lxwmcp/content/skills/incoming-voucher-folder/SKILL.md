---
title: Lokalen Eingangsbeleg-Ordner verarbeiten
description: Agenten-Skill für sichere, resumierbare PDF-Uploads und benutzergeführte Buchung.
version: 1.0.0
---

# Lokalen Eingangsbeleg-Ordner verarbeiten

Lies zuerst `lexware://handbooks/incoming-vouchers`, `lexware://handbooks/accounts` und `lexware://handbooks/errors-recovery`.

Verarbeite alle unterstützten Dateien deterministisch nach normalisiertem Pfad. Sende Dateiname, MIME-Typ, Base64-Inhalt und SHA-256. Verwende als Upload-Idempotenzschlüssel eine stabile Kombination aus Account-Alias und SHA-256. Speichere `voucherId` und `fileId` im Arbeitskontext des Agenten.

OCR-Ergebnisse dürfen als Vorschlag dienen. Fehlende Pflichtwerte, mehrdeutige Kontakte oder Buchungskategorien erfordern Benutzerklärung. Finalisiere nur, wenn der Benutzer dies für den Lauf angeordnet hat und jede Datei eindeutig validiert ist. Isoliere Fehler pro Datei, setze sichere übrige Dateien fort und berichte abschließend gebuchte, offene und fehlgeschlagene Dateien getrennt.
