---
title: Mandanten- und Account-Auswahl
description: Regeln für die sichere Auswahl eines Lexware-Accounts.
version: 1.0.0
---

# Account-Auswahl

Account-Aliasse werden in der geschützten Resource `lexware://accounts` aufgeführt. Jeder Lexware-Tool-Aufruf enthält `account` ausdrücklich.

Ist die Zuordnung nicht eindeutig, stoppe und frage den Benutzer. Ein Alias wird case-insensitiv aufgelöst, aber nie durch Ähnlichkeit oder Raten gewählt.

API-Keys sind keine Tool-Parameter. Sie werden ausschließlich über die Accountverwaltung hinterlegt und erscheinen danach nicht wieder.
