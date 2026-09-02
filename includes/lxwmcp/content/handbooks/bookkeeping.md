---
title: Lexware Buchhaltungs-Handbook
description: Sichere Grundregeln für Buchhaltungsbelege in Lexware Office.
version: 1.0.0
---

# Lexware Buchhaltungs-Handbook

Jeder Lexware-Aufruf benötigt einen expliziten Account-Alias. Prüfe vor schreibenden Aktionen Belegtyp, Datum, Betrag, Steuerart, Kontakt und Buchungskategorie.

Eingangsrechnungen verwenden `purchaseinvoice`, Eingangsgutschriften `purchasecreditnote`. Ausgangsbelege im Buchhaltungsendpunkt verwenden `salesinvoice` oder `salescreditnote`.

Ein Beleg im Zustand `open` ist final. Ein Datei-Upload erzeugt zunächst `blank` und nach der OCR `unchecked`. Buche niemals einen `blank`-Beleg und ändere einen `unchecked`-Beleg nur durch den vorgesehenen Übergang nach `open`.

Für jedes Erstellen, Hochladen, Finalisieren und Löschen muss ein stabiler Idempotenzschlüssel verwendet werden. Einen als `uncertain` gemeldeten Vorgang niemals mit einem neuen Schlüssel wiederholen.
