---
title: Typische Lexware-Workflows
description: Kurze sichere Abläufe für häufige Agentenaufgaben.
version: 1.0.0
---

# Typische Workflows

## Kontakt finden oder anlegen

Erst `lexware_search` verwenden. Nur wenn kein eindeutiger Treffer existiert und der Benutzer das Anlegen wünscht, `lexware_write` mit `contact_create` ausführen.

## Rechnung erstellen

Kontakt und optionale Artikel lesen, Beträge und Steuerbedingungen prüfen und anschließend entweder einen Entwurf über `lexware_write` oder eine ausdrücklich finale Rechnung über `lexware_finalize` erstellen. Ein Entwurf kann über die API nicht nachträglich finalisiert werden.

## Zahlung prüfen

Voucher-ID ermitteln und `lexware_get` mit `payment` aufrufen. Die Public API bietet hier nur Lesezugriff.
