---
title: Auswahl des richtigen Lexware-Tools
description: Kompakte Zuordnung von Aufgaben zu den MCP-Tools.
version: 1.0.0
---

# Tool-Auswahl

- `lexware_search`: Listen, Filter und Pagination.
- `lexware_describe`: Operationen, Pflichtfelder, Enums, Dateigrenzen und Berechtigungsblocker; optional nach `tool` und `operation` filtern.
- `lexware_get`: Einzelobjekte, Zahlungsstatus, Kategorien, Referenzdaten und OCR-Status.
- `lexware_write`: reversible oder noch nicht finale Erstellungen und Änderungen.
- `lexware_file`: lokale Datei über `prepare_upload`, direkten HTTP-PUT und Upload-ID übertragen; bestehende Base64- und HTTPS-Quellen bleiben unterstützt.
- `lexware_finalize`: ausdrücklich bestätigte Buchung oder finale Sales-Belegerstellung.
- `lexware_delete`: ausdrücklich bestätigte, dokumentierte Löschung.

Lies `lexware://accounts`, wenn der Account-Alias nicht bekannt ist. Verwende niemals einen vermuteten Mandanten.
