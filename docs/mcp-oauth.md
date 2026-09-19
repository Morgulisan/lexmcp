# Zentrale OAuth-Anmeldung und verschlüsselte MCP-Keys

## Aufbau

`auth.mopoliti.de` läuft auf dem Account-Server. Nach Anmeldung mit dem bestehenden Benutzerkonto verwaltet jeder Benutzer seine eigenen API-Keys für WhatsApp und Obsidian. Der getrennte MCP-Server `62.83.35.183` betreibt ein Gateway vor den unveränderten Backends. Es gibt keine manuelle Benutzer-ID/API-Key-Zuordnung und keine dauerhafte API-Key-Speicherung im Gateway.

| Dienst | MCP-URL | OAuth-Issuer |
|---|---|---|
| WhatsApp | https://whtspp.mopoliti.de/mcp | https://auth.mopoliti.de/whtspp |
| Obsidian | https://obsdn.mopoliti.de/mcp | https://auth.mopoliti.de/obsdn |

Pro Dienst gibt es einen eigenen Issuer-Pfad, aber eine gemeinsame Web-Anmeldung. Clients ohne expliziten resource-Parameter lassen sich dadurch eindeutig zuordnen. Der PHP-Dienst ist ein OAuth-Authorization-Server, kein vollständiger OpenID-Connect-Provider; es werden keine ID-Tokens ausgestellt.

## Benutzeroberfläche

1. https://auth.mopoliti.de/ öffnen und mit dem bestehenden Konto anmelden.
2. Für den gewünschten Dienst den eigenen API-Key hinterlegen. Vorhandene Keys werden nur als „Key hinterlegt“ angezeigt; der Klartext ist nicht wieder abrufbar.
3. In ChatGPT oder Claude die zugehörige MCP-URL eintragen und die OAuth-Anmeldung durchführen.
4. Im Portal lassen sich Keys ersetzen oder entfernen und einzelne OAuth-Verbindungen widerrufen.

Jeder Benutzer verwaltet nur seine eigenen Einträge. Ersetzen wirkt auf folgende Anfragen; bestehende Gateway-Sessions mit dem alten Key-Fingerprint werden ungültig. Entfernen löscht den verschlüsselten Eintrag und widerruft alle OAuth-Verbindungen dieses Benutzers zum betreffenden Dienst. Der Key wird beim externen Backend dadurch nicht widerrufen. Für getrennte Daten müssen die Benutzer auch tatsächlich getrennte Backend-Keys verwenden.

## Verschlüsselung und Datenfluss

- Tabelle `mpauth_credentials`: genau ein Eintrag je Benutzer und Dienst, mit Ciphertext, zufälligem 24-Byte-Nonce, Versions-ID und Änderungszeit. Keine Klartext-Keys, keine Klartextkopie und kein Key im HTML.
- Authentifizierte Verschlüsselung: XChaCha20-Poly1305 aus libsodium. Benutzer-ID, Dienst, MCP-Ressource und Versions-ID sind als zusätzliche authentifizierte Daten gebunden. Vertauschte oder veränderte Datensätze lassen sich nicht entschlüsseln.
- Der zufällige 32-Byte-Masterkey wird beim ersten Speichern unter `/data/mcp-auth/.encryption.key` erzeugt (Verzeichnis 0700, Datei 0600). Er liegt außerhalb von Datenbank und Webroot. Alternativ `MCP_AUTH_ENCRYPTION_KEY` als Base64-Schlüssel setzen. Der Datenpfad ist mit `MCP_AUTH_DATA_PATH` konfigurierbar.
- **Datenbank und Masterkey separat sichern und den Masterkey bei Deployments erhalten.** Ohne den ursprünglichen Masterkey sind vorhandene Keys nicht wiederherstellbar. Bei vorhandenen verschlüsselten Datensätzen wird ein fehlender Masterkey nicht automatisch ersetzt. Ein manueller Wechsel der Masterkey-Variable ohne Umverschlüsselung ist keine Rotation.
- Pro MCP-Anfrage ruft das Gateway den dienstspezifischen `/oauth/credential`-Endpunkt auf. Der Auth-Server prüft das Gateway-Secret, das OAuth-Access-Token, Zielressource, Ablauf, Benutzerfreigabe und Scope. Erst dann entschlüsselt er ausschließlich den Key des Token-Inhabers und liefert ihn über HTTPS an das Gateway.
- Der Key liegt für den Backend-Aufruf kurzzeitig im Arbeitsspeicher vor. Das Gateway ersetzt den OAuth-Header durch den Backend-Key und speichert ihn nicht auf Platte oder in einem Cache. ChatGPT/Claude erhalten den Backend-Key nicht.
- Die standardmäßige `/oauth/introspect`-Antwort enthält niemals einen API-Key. Der Credential-Endpunkt erfordert unabhängig vom Benutzer-Token auch das separate Gateway-Secret. Keine Anfrage darf eine frei wählbare Benutzer-ID zur Key-Abfrage übergeben.
- Im Gateway bleiben nur Maschinensecrets für die Authentifizierung am Auth-Server und die Signatur von Session-IDs dauerhaft gespeichert. Die ursprüngliche Authentifizierung der Backends selbst bleibt bestehen.

## Projekt und Deployment

| Inhalt | Projektpfad | Ziel |
|---|---|---|
| Öffentliche PHP-Dateien | `html/auth.mopoliti.de/` | `/html/auth.mopoliti.de/` |
| Privater OAuth-Code | `includes/mcp-auth/` | `/includes/mcp-auth/` |
| Masterkey und optionale private Konfiguration | nicht versioniert | `/data/mcp-auth/` |
| Gateway, Compose und Nginx-Vorlage | `deploy/mcp-gateway/` | `/opt/mcp-gateway/` auf dem MCP-Server |
| Tests | `tests/mcp-auth/` | isolierte Testcontainer |

Der Account-Server wird durch Commit und Push deployed. PHP 8.4 benötigt `pdo_mysql`, `curl`, `mbstring` und `sodium`. Apache muss `.htaccess`/mod_rewrite zulassen; `display_errors=Off` verwenden. Die vorhandene `/includes/api/sql.php` liefert `connectToSQL(): PDO` (überschreibbar mit `MCP_AUTH_DATABASE_INCLUDE`).

Die versionierte `config/services.php` enthält öffentliche Dienstadressen und SHA-256-Verifikatoren der zufälligen Gateway-Secrets, keine Klartextsecrets. Optional überschreibt eine private `/data/mcp-auth/config.php` die Konfiguration (`MCP_AUTH_CONFIG`). Ohne `allowed_user_ids` ist Selbstverwaltung für alle bestehenden, angemeldeten Benutzer möglich; eine gesetzte Liste schränkt einen Dienst ein, `[]` sperrt ihn für alle.

Die idempotenten Migrationen laufen beim ersten HTTP-Zugriff automatisch. Die Datenbankrolle braucht deshalb bei neuen Migrationen CREATE-Rechte; optional vorher `php /includes/mcp-auth/bin/migrate.php` ausführen. Vorhandene Todo-/Lexware-Tabellen bleiben unverändert. Alle neuen Tabellen beginnen mit `mpauth_`.

Stündlich `php /includes/mcp-auth/bin/cleanup.php` ausführen oder in bestehende Wartung aufnehmen. Der Befehl entfernt abgelaufene Grants, Sessions und Rate-Limit-Daten; er löscht keine Benutzer-Keys.

## Gateway auf dem MCP-Server

WAHA läuft auf `127.0.0.1:3000/mcp`, Obsidian auf `127.0.0.1:8000/mcp`. Beim vorhandenen WAHA-Zugang wird `X-Api-Key` verwendet, bei Obsidian `Authorization: Bearer`.

1. `deploy/mcp-gateway/` übertragen und `python3 prepare-server.py` ausführen. Das Skript legt `/opt/mcp-gateway` an, erhält bestehende Maschinensecrets und entfernt die beiden bekannten redundanten Key-Dateien des ursprünglichen ungenutzten Setups. Es liest oder importiert keine Benutzer-Keys.
2. Die SHA-256-Verifikatoren in der erzeugten `/opt/mcp-gateway/auth-config.php` müssen zu `includes/mcp-auth/config/services.php` passen. Beim aktuellen Server sind sie bereits abgestimmt. Klartext-Gateway-Secrets nicht committen.
3. In `/opt/mcp-gateway` `docker compose up -d` ausführen; nach Code-/Secret-Änderungen `docker compose restart gateway`. Der Container läuft als UID/GID 65534, mit schreibgeschütztem Dateisystem und einem read-only eingebundenen Secret-Verzeichnis. Er lauscht nur auf `127.0.0.1:8787`.
4. Vor Nginx-Änderungen die aktiven Dateien sichern. Die Locations aus `nginx-locations.conf` jeweils in den HTTPS-Serverblock für whtspp/obsdn übernehmen. `nginx -t` prüfen, dann `systemctl reload nginx`. Andere Dashboard-/API-Routen bleiben unverändert.
5. Die öffentlichen Discovery-Antworten und die 401-Challenge ohne Token prüfen. Mit einem eigenen Benutzerkonto einen Key speichern und die Verbindung in ChatGPT und Claude herstellen.

Die Nginx-Dateien heißen auf diesem Host `/etc/nginx/sites-enabled/waha` und `/etc/nginx/sites-enabled/obsdn.mopoliti.de`. Zur Rücknahme die zuvor gesicherten Dateien wiederherstellen, `nginx -t` und Reload ausführen. Die OAuth-Tabellen und verschlüsselten Benutzer-Keys nicht löschen.

## OAuth-Endpunkte und Rechte

Beispiel WhatsApp:

- Protected Resource Metadata: `https://whtspp.mopoliti.de/.well-known/oauth-protected-resource/mcp`, zusätzlich ohne `/mcp`.
- Authorization Server Metadata: `https://auth.mopoliti.de/.well-known/oauth-authorization-server/whtspp`.
- Authorization, Token, Registrierung, Widerruf: `/whtspp/oauth/{authorize,token,register,revoke}` auf auth.mopoliti.de.
- Maschinenzugriff: `/whtspp/oauth/introspect` bzw. `/whtspp/oauth/credential`.
- Alle Keys: `/accounts`; dienstspezifische Ansicht: `/whtspp/accounts`.

Authorization Code mit PKCE-S256, Dynamic Client Registration, öffentliche Clients mit `token_endpoint_auth_method=none` und Client-ID-Metadatendokumente werden unterstützt. CIMD-Downloads pinnen öffentliche DNS-Adressen und begrenzen Größe und Laufzeit; Redirects werden nicht verfolgt. Access-Tokens gelten eine Stunde. Refresh-Tokens rotieren und gelten jeweils 30 Tage. Ein 60-sekündiges Retryfenster verhindert, dass parallele oder nach einem verlorenen Response wiederholte Refresh-Anfragen die gesamte Verbindung widerrufen; späterer Replay widerruft weiterhin die vollständige Token-Familie. Tokens liegen nur gehasht in der Datenbank. CSRF-Schutz, hostgebundene sichere Cookies und Rate-Limits schützen die Anmeldung und Webänderungen.

`mcp:access` erlaubt den gesamten Zugriff, den der hinterlegte Backend-Key besitzt. Feinere Tool-Rechte muss das Backend selbst oder eine spätere Erweiterung durchsetzen. Kein positiver Token-/Key-Cache: Widerrufe und Key-Änderungen gelten bei der nächsten Anfrage. Bereits laufende Streams werden nicht rückwirkend abgebrochen. Gateway-Sessions sind an Benutzer, OAuth-Verbindung, Ressource und Key-Fingerprint gebunden und höchstens 24 Stunden gültig.

## Weitere Dienste hinzufügen

1. Einen neuen `services`-Eintrag in `includes/mcp-auth/config/services.php` ergänzen: eindeutige ID, Name, feste HTTPS-MCP-Ressource, Gateway-Client-ID und SHA-256 eines neuen zufälligen Gateway-Secrets. Der Portal-Eintrag entsteht automatisch.
2. In `/opt/mcp-gateway/secrets/gateway.json` die feste interne Backend-URL, MCP-Ressource, Issuer `https://auth.mopoliti.de/DIENST`, Gateway-Secret-Datei und `auth_header` ergänzen. Unterstützt sind `authorization` und `x-api-key`.
3. Domain und TLS sowie Nginx-MCP-/Discovery-Routen einrichten. Gateway neu starten und die zentrale Konfiguration deployen.
4. Benutzer melden sich unter auth.mopoliti.de an und hinterlegen selbst ihren Key. Keine SQL-Änderungen und keine manuelle Benutzerzuordnung nötig.
5. Registrierung, OAuth-Freigabe, Aufruf, Refresh, Key-Wechsel und Widerruf mit mindestens zwei Testkonten prüfen.

Benutzerdefinierte Backend-URLs sind bewusst nicht Teil der Key-Verwaltung: Die Zieladressen werden nur durch die vertrauenswürdige Dienstkonfiguration festgelegt.

## Tests und Betrieb

`node --test tests/mcp-auth/gateway.test.mjs` prüft Discovery, Header-Ersetzung, Benutzer-/Ressourcentrennung, SSE, Session-Bindung, Key-Wechsel und Fehlerfälle. `tests/mcp-auth/run-remote.sh` prüft PHP-Syntax und OAuth-/Key-Integration in einer temporären MariaDB ohne veröffentlichte Ports. Der Runner erwartet die Testkopie unter `/opt/mcp-oauth-work/stage` und entfernt den Testcontainer danach.

Die 55 PHP-Prüfungen decken unter anderem PKCE, Token-Rotation, Replay-Widerruf, Legacy-Login, CGI-Authentifizierung, Ciphertext-Speicherung, Manipulationsschutz, fehlende Masterkeys, CSRF, fremde Benutzer-IDs und Widerruf beim Löschen ab. Produktionskonten und echte Benutzer-Keys werden dabei nicht verwendet.

Keine API-Keys, Authorization-/Cookie-Header oder Request-Bodies in Logs aufnehmen. Auth-Server-Ausfälle führen zu HTTP 503 statt zu einem Rückfall auf lokale Keys. Verbindungsübernahme auf weitere Geräte hängt vom jeweiligen MCP-Client ab.
