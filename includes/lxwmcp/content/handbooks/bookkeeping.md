---
title: Lexware Buchhaltungs-Handbook
description: Sichere Grundregeln für Buchhaltungsbelege in Lexware Office.
version: 1.1.0
---

# Lexware Buchhaltungs-Handbook

Jeder Lexware-Aufruf benötigt einen expliziten Account-Alias. Prüfe vor schreibenden Aktionen Belegtyp, Datum, Betrag, Steuerart, Kontakt und Buchungskategorie.

Eingangsrechnungen verwenden `purchaseinvoice`, Eingangsgutschriften `purchasecreditnote`. Ausgangsbelege im Buchhaltungsendpunkt verwenden `salesinvoice` oder `salescreditnote`.

Ein Beleg im Zustand `open` ist final. Ein Datei-Upload erzeugt zunächst `blank` und nach der OCR `unchecked`. Buche niemals einen `blank`-Beleg. Die Felder eines `unchecked`-Belegs dürfen nur durch den vorgesehenen Übergang nach `open` geändert werden; das Anhängen eines Dokuments verwendet den separaten Datei-Endpunkt.

Wenn ein PDF vorliegt, zuerst `lexware_file` mit `prepare_upload`, direktem PUT und `upload_voucher` verwenden. Mit der zurückgegebenen `voucherId` fortfahren; nicht zusätzlich `voucher_create` aufrufen.

Fehlt einem bereits vorhandenen Beleg die Datei, dessen UUID über `lexware_search`/`lexware_get` eindeutig ermitteln und den vorhandenen Zustand prüfen. Danach die Datei vorbereiten und mit `lexware_file`, `operation: "attach_to_voucher"`, `voucher_id` und `source: {kind: "upload", upload_id}` anhängen. Anschließend den Beleg erneut lesen und die Dateizuordnung prüfen. Keine zweite Belegerstellung und kein Finalisieren nur zum Umgehen einer Bearbeitungssperre. Ein Fehler von `lexware_write` beweist nicht, dass der separate Datei-Endpunkt ebenfalls gesperrt ist.

Ist die Belegverarbeitung bereits beauftragt und sind Datei und Zielbeleg eindeutig, führe das Anhängen ohne weitere Benutzerbestätigung aus. Nur unklare Zuordnungen oder fehlende erforderliche Angaben benötigen Rückfragen.

Für jedes Erstellen, Hochladen, Finalisieren und Löschen muss ein stabiler Idempotenzschlüssel verwendet werden. Einen als `uncertain` gemeldeten Vorgang niemals mit einem neuen Schlüssel wiederholen.
