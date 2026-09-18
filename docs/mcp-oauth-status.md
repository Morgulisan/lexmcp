# OAuth-Einrichtung – Stand 18.09.2026

- Separaten OAuth-Dienst und Login-Portal für eigene MCP-Keys implementiert.
- Keys werden mit XChaCha20-Poly1305 verschlüsselt in mpauth_credentials gespeichert; Masterkey liegt privat außerhalb von Datenbank und Webroot.
- Gateway auf dem MCP-Server vorbereitet; zentrale Credential-Abfrage ersetzt die ursprünglichen lokalen Key-Dateien und manuelle Benutzerzuordnung.
- Automatische Migrationen und öffentliche Dienstkonfiguration für Deployment durch Commit/Push ergänzt.
- PHP-Syntax, 55 OAuth-/Key-Prüfungen und Gateway-Test erfolgreich in isolierten Containern.
- Anleitung zu Betrieb, Backup, Erweiterung um Dienste und Benutzer sowie Rücknahme: docs/mcp-oauth.md.

## Live-Deployment

- Implementierung in Commit `e7718ff` auf `main` gepusht. Die beiden zuvor vorhandenen Todo-Commits wurden nach ausdrücklicher Freigabe ebenfalls veröffentlicht.
- Account-Server automatisch deployed: Login unter `https://auth.mopoliti.de/` und OAuth-Metadaten liefern HTTP 200. Die automatischen Datenbankmigrationen laufen erfolgreich.
- Gateway aktualisiert und gestartet. Die zwei redundanten lokalen Benutzer-Key-Dateien sind entfernt; keine statische Benutzer-Key-Zuordnung verbleibt im Gateway.
- Die beiden Maschinensecrets wurden gegen die realen `/oauth/credential`-Endpunkte geprüft: authentifizierte Anfragen mit ungültigem Benutzer-Token liefern erwartungsgemäß `active: false`.
- Nginx aktiviert: `whtspp.mopoliti.de/mcp` und `obsdn.mopoliti.de/mcp` laufen jetzt über das Gateway. Beide öffentlichen Discovery-Endpunkte liefern HTTP 200; beide MCP-Endpunkte liefern ohne Token HTTP 401 mit passendem `resource_metadata`-Verweis.
- Vorherige Nginx-Konfigurationen: `/opt/mcp-gateway/backups/20260918T205358Z/waha` und `/opt/mcp-gateway/backups/20260918T205358Z/obsdn.mopoliti.de`. Für eine Rücknahme diese Inhalte in die jeweiligen aktiven Dateien zurückkopieren, `nginx -t` und `systemctl reload nginx` ausführen.
- Der erste Probeaufruf unmittelbar nach dem Nginx-Reload traf noch einen alten Worker; die anschließenden öffentlichen Prüfungen waren erfolgreich.

Die abschließende Anmeldung mit dem persönlichen Konto, Eingabe der eigenen Backend-Keys und Abnahme in ChatGPT/Claude erfolgt durch den Benutzer. Es wurden keine produktiven Benutzer-Keys automatisch zugeordnet. Verschlüsselung und Benutzertrennung wurden mit isolierten Testkonten geprüft; keine persönlichen Zugangsdaten wurden für einen Live-Login verwendet.
