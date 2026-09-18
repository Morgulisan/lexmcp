# OAuth-Einrichtung – Stand 18.09.2026

- Separaten OAuth-Dienst und Login-Portal für eigene MCP-Keys implementiert.
- Keys werden mit XChaCha20-Poly1305 verschlüsselt in mpauth_credentials gespeichert; Masterkey liegt privat außerhalb von Datenbank und Webroot.
- Gateway auf dem MCP-Server vorbereitet; zentrale Credential-Abfrage ersetzt die ursprünglichen lokalen Key-Dateien und manuelle Benutzerzuordnung.
- Automatische Migrationen und öffentliche Dienstkonfiguration für Deployment durch Commit/Push ergänzt.
- PHP-Syntax, 55 OAuth-/Key-Prüfungen und Gateway-Test erfolgreich in isolierten Containern.
- Anleitung zu Betrieb, Backup, Erweiterung um Dienste und Benutzer sowie Rücknahme: docs/mcp-oauth.md.

Deployment und öffentliche Aktivierung werden nach dem Push geprüft. Die abschließende Anmeldung mit dem persönlichen Konto und Eingabe der eigenen Backend-Keys erfolgt durch den Benutzer; keine produktiven Benutzer-Keys wurden automatisch zugeordnet.
