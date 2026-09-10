---
title: Fehlerbehandlung und Recovery
description: Vorgehen bei Rate Limits, Konflikten und unklarem Ausgang.
version: 1.1.0
---

# Fehler und Recovery

- `alias_ambiguous`: Einen der genannten canonical Werte auswählen.
- `unknown_parameter`: Schreibweise und zulässige Felder prüfen; nichts entfernen, ohne die Bedeutung zu klären.
- `rate_queue_timeout` oder HTTP 429: Den angegebenen Backoff respektieren.
- HTTP 409: Objekt neu lesen, Version und Änderungen vergleichen, danach bewusst erneut aufrufen.
- `operation_uncertain`: `lexware_get` mit `operation_status`, Operation und ursprünglichem Idempotenzschlüssel verwenden. Niemals einen neuen Schlüssel erzeugen, um die Sperre zu umgehen.
- `voucher_not_ready`: OCR-Status später erneut lesen.
- Fehlendes Belegbild bei vorhandenem Beleg: `lexware_file` mit `attach_to_voucher` und bestehender Beleg-UUID verwenden, danach Dateizuordnung prüfen. Dafür weder `voucher_update` noch eine zusätzliche Belegerstellung verwenden. Im bereits beauftragten Ablauf bei eindeutiger Zuordnung selbständig fortfahren; keine zusätzliche Benutzerbestätigung anfordern.
- `insufficient_scope`: Eine neue OAuth-Zustimmung für den benötigten Scope einholen.

Ein Timeout oder HTTP 504 beweist bei Schreiboperationen nicht, dass Lexware nichts verarbeitet hat.
