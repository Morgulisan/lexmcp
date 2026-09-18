# Security Policy

Sicherheitsprobleme dürfen keine API-Keys, Tokens, Belege oder personenbezogenen Daten in öffentlichen Tickets enthalten.

## Secret-Regeln

- Keine Secrets im Repository, Webroot, in URLs oder Logs.
- Masterkeys werden als Server-Umgebungsvariablen oder in geschützten privaten Dateien außerhalb des Webroots bereitgestellt. Sie dürfen nicht im Repository oder in der Datenbank liegen. MCP-Benutzerkeys werden ausschließlich authentifiziert verschlüsselt in der Datenbank gespeichert; siehe docs/mcp-oauth.md.
- Bei vermutetem Verlust: Lexware-Key im Lexware-Account ersetzen, OAuth-Tokenfamilien widerrufen und beide MCP-Masterkeys kontrolliert rotieren.

## Kritische Operationen

Finalisieren wird pro Benutzer unter `/accounts` gesteuert (Standard: an). Die Einstellung ersetzt weder die Verbindungsberechtigung `lexware:finalize` noch `confirm:true`. Löschen bleibt ohne explizite Serverfreigabe deaktiviert.

Datei-Uploads verwenden kurzlebige, an Benutzer, Verbindung und Account gebundene Tokens im Header. Nur Token-Hashes werden gespeichert. Die gemeinsame MST-Komponente speichert Dateien außerhalb des Webroots; Lexware und MST verwenden getrennte Verzeichnisse. Upload-Header und Abrufpasswörter niemals protokollieren.

## Unterstützte Meldungen

Bitte mit ungefährer Zeit, Trace-ID, betroffener Route und reproduzierbaren Schritten melden. Niemals Request-Header oder vollständige Payloads mitsenden.
