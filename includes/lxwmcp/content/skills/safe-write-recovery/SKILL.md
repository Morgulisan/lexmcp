---
title: Sichere Lexware-Schreiboperationen wiederherstellen
description: Agenten-Skill gegen doppelte Belege nach Timeouts oder 5xx-Fehlern.
version: 1.0.0
---

# Sichere Write-Recovery

Behalte den ursprünglichen Idempotenzschlüssel. Lies zuerst den gespeicherten Operationsstatus. Ist der Ausgang `uncertain`, suche anhand dokumentierter, stabiler Merkmale nach dem möglicherweise erstellten Objekt.

Ein neuer Schreibversuch ist erst zulässig, wenn eindeutig feststeht, dass der frühere Aufruf nicht ausgeführt wurde. Bei finalen Rechnungen und Gutschriften niemals aufgrund eines Timeouts automatisch neu senden.
