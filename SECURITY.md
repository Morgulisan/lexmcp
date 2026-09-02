# Security Policy

Sicherheitsprobleme dürfen keine API-Keys, Tokens, Belege oder personenbezogenen Daten in öffentlichen Tickets enthalten.

## Secret-Regeln

- Keine Secrets im Repository, Webroot, in URLs oder Logs.
- Produktionsschlüssel werden ausschließlich als Server-Umgebungsvariablen bereitgestellt.
- Bei vermutetem Verlust: Lexware-Key im Lexware-Account ersetzen, OAuth-Tokenfamilien widerrufen und beide MCP-Masterkeys kontrolliert rotieren.

## Kritische Operationen

Finalisieren und Löschen bleiben ohne explizite Serverfreigabe deaktiviert. Eine Freigabe ersetzt weder OAuth-Scope noch `confirm:true`.

## Unterstützte Meldungen

Bitte mit ungefährer Zeit, Trace-ID, betroffener Route und reproduzierbaren Schritten melden. Niemals Request-Header oder vollständige Payloads mitsenden.
