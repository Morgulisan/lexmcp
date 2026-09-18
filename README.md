# Lexware Office MCP

Abhängigkeitsfreier MCP-Server für die Lexware Office Public API in reinem PHP 8.4. Das Projekt verwendet weder Composer noch ein MCP-/OAuth-SDK oder Framework.

Die Implementierung basiert auf der am 28. August 2026 aktuellen [Lexware-Public-API-Dokumentation](https://developers.lexware.io/docs/) und unterstützt sowohl die MCP-Spezifikation 2026-07-28 als auch klassische Streamable-HTTP-Clients.

## Verzeichniszuordnung

Auf dem Server werden die Projektverzeichnisse wie folgt bereitgestellt:

```text
/html/lxwmcp.mopoliti.de/   -> html/lxwmcp.mopoliti.de/
/includes/lxwmcp/           -> includes/lxwmcp/
/data/lxwmcp/               -> data/lxwmcp/
```

Die bereits vorhandene `/includes/api/sql.php` wird im Bootstrap direkt eingebunden; anschließend wird unmittelbar `connectToSQL(): PDO` aufgerufen. Diese Datei wird nicht kopiert oder verändert. Benutzername und Passwort werden gegen das bestehende Benutzerkonto in derselben Datenbank geprüft.

## Anforderungen

- PHP 8.4
- Erweiterungen `pdo_mysql`, `curl`, `json`, `sodium`, `mbstring`
- MySQL/MariaDB mit InnoDB und Mikrosekunden-Zeitstempeln
- Apache mit `mod_rewrite`
- HTTPS

## Installation

1. Öffentliche Dateien einschließlich der versteckten `.htaccess` nach `/html/lxwmcp.mopoliti.de` und private Dateien nach `/includes/lxwmcp` kopieren.
2. `/data/lxwmcp` für den Webserver beschreibbar und dauerhaft anlegen. Wenn keine Schlüssel als Umgebungsvariablen gesetzt sind, werden sie dort einmalig erzeugt; ein Verlust dieses Verzeichnisses macht gespeicherte API-Keys unlesbar.
3. Die Werte aus `includes/lxwmcp/config/environment.example` als echte Server-Umgebungsvariablen setzen. Secrets niemals in den Webroot oder ins Repository schreiben.
4. Optional zwei unabhängige Schlüssel als Umgebungsvariablen setzen. Ohne diese Variablen erzeugt der Server sie automatisch im privaten Datenverzeichnis:

   ```php
   <?php echo base64_encode(random_bytes(32)), PHP_EOL;
   ```

5. Neue Tabellen werden beim ersten Request automatisch angelegt. Optional kann die Migration vorab manuell ausgeführt werden:

   ```bash
   php /includes/lxwmcp/bin/migrate.php
   ```

6. PHP so konfigurieren, dass JSON-Requests bis 10 MiB akzeptiert werden, beispielsweise `post_max_size=11M`.
7. `https://lxwmcp.mopoliti.de/accounts` öffnen, einmalig mit dem bestehenden Benutzerkonto oder einem gültigen `MST:`-Token anmelden und die Lexware-API-Keys unter eindeutigen Account-Aliassen speichern.

## Authentifizierung

Der vorhandene Login ist nur die Onboarding-Brücke. Das Passwort wird serverseitig mit dem bestehenden Legacy-Verfahren geprüft und danach aus dem Speicher entfernt. Ein alternativ eingegebener `MST:`-Token wird gegen die bestehende `access_tokens`-Tabelle geprüft und nicht als MCP-Token akzeptiert.

MCP verwendet anschließend OAuth Authorization Code mit PKCE-S256. Metadaten stehen unter:

- `/.well-known/oauth-protected-resource` und `/.well-known/oauth-protected-resource/mcp`
- `/.well-known/oauth-authorization-server` und `/.well-known/oauth-authorization-server/mcp`
- `/.well-known/openid-configuration` (identisch zu den Authorization-Server-Metadaten)

Diese Dokumente müssen öffentlich erreichbar sein. Viele Hosting-Konfigurationen
sperren pauschal jeden Pfad, der mit einem Punkt beginnt; ein MCP-Client bricht
dann bereits bei der Suche nach dem Authorization Server mit HTTP 403 ab. Auf
diesem Host geschieht die Sperre in einem Abschnitt, den weder ein Rewrite noch
ein `<Files>`-Grant der `.htaccess` erreicht, deshalb sind drei Ebenen nötig:

1. `<If>` in der `.htaccess` gibt `/.well-known/` wieder frei; dieser Abschnitt
   wird als letzter gemergt und benötigt `AllowOverride All`.
2. `ErrorDocument 403` verweist auf eine absolute URL. Apache beantwortet das
   mit einer Weiterleitung statt mit dem ursprünglichen Status, sodass ein
   gesperrter Discovery-Request auf `/oauth/discovery` landet. Dieses Dokument
   enthält die Felder beider Metadatenformate; jedes Format ignoriert die ihm
   unbekannten Member.
3. Punktfreie Spiegel: `/oauth/protected-resource`, `/oauth/authorization-server`
   sowie die vom MCP-Standard vorgesehenen Ersatzendpunkte `/authorize`,
   `/token`, `/register` und `/revoke` auf Root-Ebene; sie verhalten sich
   identisch zu den Pfaden unter `/oauth/`.

Sauberer als alle drei ist eine Freigabe in der vHost-Konfiguration:

```apache
<LocationMatch "^/\.well-known/">
    Require all granted
</LocationMatch>
```

Optionale Parameter werden standardkonform behandelt: unbekannte Parameter am
Authorization-Endpunkt werden ignoriert, ein fehlender `resource`-Parameter fällt
auf `https://lxwmcp.mopoliti.de/mcp` zurück, ein fremder wird weiterhin abgelehnt.

Der gesamte MCP-Endpunkt ist geschützt. Auch `server/discover`, `initialize` und
MCP-Benachrichtigungen erfordern ein gültiges Bearer-Token; ein Request ohne Token
antwortet mit HTTP 401 und einer `WWW-Authenticate`-Challenge zur OAuth-Erkennung.

Die im MCP-Client einzutragende Serveradresse ist `https://lxwmcp.mopoliti.de/mcp`.

Access-Tokens sind opaque, zehn Minuten gültig und nur als Bearer-Header zulässig. Refresh-Tokens gelten 30 Tage und rotieren bei jeder Verwendung.

Unterstützte MCP-Versionen werden in `Config::VERIFIED_PROTOCOL_PROFILES` einem Wire-Profil zugeordnet. Der Server beherrscht die neue `server/discover`-Initialisierung und die klassische `initialize`-Initialisierung parallel. Fehlende, unbekannte oder voneinander abweichende Versionsangaben führen nicht allein zu einem Abbruch; der Server wählt anhand der Nachrichtenform das kompatibelste Profil. Widersprüchliche `Mcp-Method`- oder `Mcp-Name`-Header werden aus Sicherheitsgründen weiterhin abgelehnt.

## Tools

- `lexware_search`: Kontakte, Artikel und Voucherlist; explizite Pagination.
- `lexware_describe`: Operationen, Pflichtfelder, Enums und aktuelle Berechtigungsblocker; optional `tool` und `operation` filtern, kein Account erforderlich.
- `lexware_get`: Details, Zahlungen, Kategorien, Referenzdaten, OCR-Status und sichere Download-ResourceLinks.
- `lexware_write`: Kontakte, Artikel, Buchhaltungsbelege sowie Rechnungs- und Gutschriftentwürfe.
- `lexware_file`: Upload vorbereiten, Datei direkt per HTTP-PUT übertragen und Upload-ID verwenden; Base64 und freigegebene HTTPS-Hosts bleiben unterstützt.
- `lexware_finalize`: Buchung `unchecked -> open` oder finale Rechnung/Gutschrift.
- `lexware_delete`: dokumentiertes Löschen von Artikeln oder einzelnen Voucher-Dateien.

Jeder Lexware-Aufruf verlangt den Account-Alias. API-Keys sind niemals Tool-Parameter.

### Verbindungen und Berechtigungen

Ein MCP-Harness legt beim OAuth-Verbindungsaufbau mit dem Parameter `scope` fest, welche Berechtigungen es anfragt. Nach der Freigabe wird jede Tokenfamilie als eigene, benennbare Verbindung geführt. Unter `/accounts` kann der angemeldete Benutzer den Anzeigenamen und die tatsächlich erlaubten Scopes per Checkbox ändern. Die aktuelle Freigabe wird bei jedem Request aus der Datenbank gelesen und gilt daher sofort auch für bereits ausgestellte Access-Tokens.

`tools/list` enthält nur Tools, deren Scope für diese Verbindung aktuell freigegeben ist. `lexware_search` und `lexware_get` benötigen `lexware:read`, `lexware_write` und `lexware_file` benötigen `lexware:write`; Finalisieren und Löschen verwenden ihre jeweils eigenen Scopes. Ein ausgeblendetes Tool wird auch bei einem direkten `tools/call` abgewiesen. Tool-Listen werden mit `ttlMs: 0` ausgeliefert, damit Hosts Änderungen nicht weiterverwenden.

`lexware_finalize` wird durch „Belege finalisieren und verbuchen erlauben“ unter `/accounts` pro Benutzer gesteuert. Standard für neue und bestehende Benutzer ist **an**; `LEXMCP_ENABLE_FINALIZE` wird nicht mehr ausgewertet. Ausschalten sperrt neue Aufrufe sofort, laufende Buchungen werden nicht rückgängig gemacht. Verbindungsrecht `lexware:finalize` und `confirm:true` bleiben erforderlich; bestehende Verbindungsrechte werden nicht erweitert. `lexware_delete` bleibt zusätzlich durch `LEXMCP_ENABLE_DELETE` geschützt. `lexware_describe` benötigt `lexware:read` und erklärt auch ausgeblendete Tools.

## Lokale PDF-Ordner

Der MCP-Server sieht das lokale Dateisystem des Agenten nicht. Der Agent liest einen vom Benutzer freigegebenen Ordner und sendet jede Datei einzeln mit `lexware_file`. Empfohlener Ablauf:

1. Account eindeutig festlegen.
2. PDFs über 1.000.000 Bytes möglichst lokal verlustfrei komprimieren. Original, Signaturen und eingebettete Rechnungsdaten erhalten. Größe und SHA-256 der endgültigen Datei berechnen; maximal 4.500.000 Bytes.
3. `lexware_file` mit `prepare_upload` und `source: {filename, mime_type, size_bytes, sha256}` aufrufen. Datei aus der lokalen Laufzeit per PUT mit den zurückgegebenen Headern an `url` übertragen. Danach `upload_voucher` mit `source: {kind: "upload", upload_id}` und stabilem Idempotenzschlüssel ausführen. Für `attach_to_voucher` zusätzlich `voucher_id` angeben.
4. `file_status` oder Voucher-Detail bis zum Ende der OCR abfragen.
5. Erkannte Daten, Kontakt und Buchungskategorie mit der Benutzervorgabe prüfen.
6. Nur bei entsprechender Anweisung über `lexware_finalize` buchen.

Der passende Agenten-Skill ist als `skill://lexware/incoming-voucher-folder/SKILL.md` abrufbar.

Bei vorhandenem PDF zuerst `upload_voucher` nutzen und mit dessen `voucherId` weiterarbeiten. Bereits vorhandenen Belegen lässt sich über `attach_to_voucher` eine Datei zuordnen; dies verwendet einen anderen Endpunkt als die gesperrte Änderung von `unchecked`-Belegfeldern. Im beauftragten Ablauf bei eindeutiger Zuordnung ohne zusätzliche Benutzerbestätigung fortfahren. Nach dem Anhängen die Dateizuordnung erneut lesen und prüfen. Fehlermeldung, `lexware_describe` und Skill weisen den Agenten auf diesen Reparaturweg hin.

### Gemeinsame MST-Speicherung und Deployment

Die Speicherimplementierung liegt ausschließlich im Schwesterprojekt unter `MST/includes/libs/FileTransfer/Storage.php`. Zuerst diese Komponente und den darauf umgestellten MST-PdfTransfer-Endpunkt bereitstellen, anschließend LexMCP einschließlich Migration `004_uploads_user_settings.sql`. Der bestehende MST-Endpunkt und alte Abrufpasswörter bleiben kompatibel; das MST-Limit steigt von 2,5 MiB auf 4.500.000 Bytes.

`LEXMCP_FILE_TRANSFER_INCLUDE` zeigt auf die gemeinsame Datei, im Serverlayout `/includes/libs/FileTransfer/Storage.php`. Es wird keine Kopie in LexMCP benötigt. Lexware speichert privat unter `LEXMCP_DATA_PATH/uploads`, MST weiterhin unter `/data/pdf_transfer`. Beide Verzeichnisse müssen für PHP schreibbar sein. PHP, Apache und vorgeschaltete Proxies müssen PUT sowie 4.500.000 Bytes Binärdaten zulassen; `post_max_size=11M` und `upload_max_filesize=5M` decken auch MST-Multipart-Uploads ab.

Upload-URLs gehören zur Lexware-Domain; der geheime Token wird über `X-Upload-Token` gesendet und nur gehasht gespeichert. Keine Upload-Header protokollieren. Berechtigung: 900 Sekunden, an Benutzer, Verbindung, Account und Dateimetadaten gebunden. Die Dateigültigkeit beginnt beim Empfang und beträgt 3.660 Sekunden; identische Übertragungswiederholungen verlängern sie nicht. Nach erfolgreicher Lexware-Weitergabe wird die Datei gelöscht. Operations- und Upload-Zuordnungen bleiben für sichere Wiederholungen erhalten.

Zusätzlich zur Bereinigung bei Zugriffen den gemeinsamen Aufruf regelmäßig, beispielsweise alle fünf Minuten, serverseitig einrichten:

```sh
php /includes/libs/FileTransfer/cleanup.php /data/pdf_transfer /data/lxwmcp/uploads
```

Beide Verzeichnisse vorher mit privaten Zugriffsrechten anlegen. Abgelaufene Dateien sind sofort nicht mehr abrufbar; die physische Entfernung erfolgt beim nächsten Bereinigungslauf. `prepare_upload` benötigt keinen Idempotenzschlüssel und reserviert noch keinen Lexware-Beleg. Eine erneut vorbereitete URL ersetzt keinen unklaren Belegvorgang: dafür stets zuerst `operation_status` mit dem ursprünglichen Schlüssel abfragen.

## Aliase und Validierung

`includes/lxwmcp/config/aliases.json` enthält kontextabhängige Aliase für Entitäten, Operationen, Parameter und Enums. Matching ist case-insensitiv und normalisiert Trenner. Canonical Schreibweisen funktionieren stets automatisch.

Ein Alias mit genau einem Ziel wird aufgelöst. Mehrere Ziele erzeugen `alias_ambiguous`; unbekannte Parameter erzeugen `unknown_parameter`. Eingaben werden niemals stillschweigend entfernt. Aliaslisten werden nicht in den MCP-Tooldefinitionen veröffentlicht.

## Globales Rate-Limit

Der Limiter reserviert per `SELECT ... FOR UPDATE` einen MySQL-Slot je HMAC-Fingerprint des API-Keys. Ausgehende Requests haben mindestens 550 ms Abstand. Dadurch teilen auch parallele PHP-Prozesse, Tools und Account-Aliasse mit demselben Key eine Queue.

`Retry-After` aktualisiert `blocked_until`. Sichere GETs werden begrenzt wiederholt. Schreiboperationen werden nach Netzwerkfehlern, 5xx oder 504 nicht blind wiederholt. Eine explizite 429-Antwort darf wiederholt werden, weil Lexware den Aufruf nicht ausgeführt hat.

## Idempotenz und Recovery

Create-, Upload-, Finalize- und Delete-Vorgänge benötigen `idempotency_key`. Derselbe Schlüssel mit identischer normalisierter Nutzlast liefert das gespeicherte Ergebnis. Eine andere Nutzlast erzeugt einen Konflikt.

Ein unklarer Schreibausgang wird `uncertain`. Danach muss der Agent `lexware_get` mit `entity=operation_status`, `operation` und dem ursprünglichen Schlüssel verwenden. Ein neuer Schlüssel darf nicht zur Umgehung eingesetzt werden.

## Resources und Prompts

Markdown-Dateien unter `includes/lxwmcp/content/handbooks` und `content/skills` werden beim Lesen automatisch entdeckt. Neue Inhalte benötigen keine Codeänderung. `resources/list` enthält nur Metadaten; der eigentliche Inhalt wird erst durch `resources/read` geladen.

Prompts:

- `lexware_workflow`
- `lexware_recovery`

## Tests

Der Testläufer hat keine Drittanbieter-Abhängigkeiten:

```bash
php tests/run.php
```

Reine Unit-Tests laufen sofort. MySQL- und Paralleltests werden aktiviert mit:

```bash
LEXMCP_TEST_DSN='mysql:host=127.0.0.1;dbname=lexmcp_test;charset=utf8mb4' \
LEXMCP_TEST_DB_USER='lexmcp_test' \
LEXMCP_TEST_DB_PASSWORD='...' \
php tests/run.php
```

Die Integrationstests verwenden ausschließlich eine separate Testdatenbank. Der Fake-Lexware-Router kann über `php -S 127.0.0.1:18080 tests/fake_lexware/router.php` gestartet werden.

## Neue Lexware-Endpunkte

1. Den Endpunkt zuerst gegen die aktuelle offizielle Dokumentation prüfen.
2. Methode, Pfad, erlaubte Parameter und Sicherheitsklasse in `config/endpoints.php` ergänzen.
3. Canonical Parameter in die Validator-Liste aufnehmen; optionale Synonyme nur in `aliases.json` ergänzen.
4. Read, Write, Finalize oder Delete eindeutig zuordnen.
5. Fake-API- und Regressionstest ergänzen.
6. Keine veralteten oder nur vermuteten Endpunkte freischalten.

## Sicherheitsmodell

- Lexware-Keys: XChaCha20-Poly1305 mit zugehöriger Account-ID.
- Rate-Key: separater HMAC, keine Key-Ableitung aus dem Ciphertext.
- Logs: feste Allowlist; keine Header, Bodies, Dateien, URLs mit Query, PII oder Secrets.
- Remote-Dateien: HTTPS, Host-Allowlist, öffentliche DNS-Adressen, keine Redirects, maximal 4.500.000 Bytes (4,5 MB). Ohne `LEXMCP_REMOTE_FILE_HOSTS` sind `drive.google.com`, `*.mopoliti.de`, `*.sldo.de`, `*.tecis.de` und `*crm.vertrieb-plattform.de` erlaubt. `*.domain` umfasst beliebig tiefe Subdomains, nicht die Hauptdomain; `*crm.vertrieb-plattform.de` umfasst auch `crm.vertrieb-plattform.de` und Hostnamen mit Präfix vor `crm`. Wildcards sind ausschließlich am Anfang zulässig; eine gesetzte Liste ersetzt diesen Standard, ein explizit leerer Wert deaktiviert URL-Uploads. Bei bestehender Konfiguration die gewünschten Standardfreigaben zur kommagetrennten Liste hinzufügen. Die URL muss ohne Anmeldung direkt Dateibytes liefern; Vorschau-, Anmelde- und Weiterleitungsseiten lassen sich damit nicht importieren. In diesem Fall die Datei lokal herunterladen und `prepare_upload` verwenden.
- OAuth und kritische Aktionen: getrennte Scopes und technische Schalter.
- Dauerhafte Sicherheits- und Queuezustände: MySQL, nicht `/data`.

## Zentrale OAuth-Anmeldung für weitere MCP-Dienste

Der separate Dienst auf auth.mopoliti.de und das Docker-Gateway für WhatsApp/Obsidian sind in [docs/mcp-oauth.md](docs/mcp-oauth.md) dokumentiert. Den tatsächlich ausgeführten Stand und noch offene Aktivierungsschritte enthält [docs/mcp-oauth-status.md](docs/mcp-oauth-status.md).
