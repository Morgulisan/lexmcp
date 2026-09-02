# Lexware Office MCP

Abhängigkeitsfreier MCP-Server für die Lexware Office Public API in reinem PHP 8.4. Das Projekt verwendet weder Composer noch ein MCP-/OAuth-SDK oder Framework.

Die Implementierung basiert auf der am 28. August 2026 aktuellen [Lexware-Public-API-Dokumentation](https://developers.lexware.io/docs/) und der [MCP-Spezifikation 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28).

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

1. Öffentliche Dateien nach `/html/lxwmcp.mopoliti.de` und private Dateien nach `/includes/lxwmcp` kopieren.
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

- `/.well-known/oauth-protected-resource`
- `/.well-known/oauth-authorization-server`

Access-Tokens sind opaque, zehn Minuten gültig und nur als Bearer-Header zulässig. Refresh-Tokens gelten 30 Tage und rotieren bei jeder Verwendung.

Unterstützte MCP-Versionen werden in `Config::VERIFIED_PROTOCOL_PROFILES` einem geprüften Wire-Profil zugeordnet. Die Umgebungsvariable kann nur daraus auswählen; eine unbekannte Zukunftsversion wird nie allein wegen ihres Datums akzeptiert. Eine bestätigte wire-kompatible Nachfolgeversion benötigt lediglich einen weiteren Registry-Eintrag.

## Tools

- `lexware_search`: Kontakte, Artikel und Voucherlist; explizite Pagination.
- `lexware_get`: Details, Zahlungen, Kategorien, Referenzdaten, OCR-Status und sichere Download-ResourceLinks.
- `lexware_write`: Kontakte, Artikel, Buchhaltungsbelege sowie Rechnungs- und Gutschriftentwürfe.
- `lexware_file`: einzelner Upload als Base64 oder über einen freigegebenen HTTPS-Host.
- `lexware_finalize`: Buchung `unchecked -> open` oder finale Rechnung/Gutschrift.
- `lexware_delete`: dokumentiertes Löschen von Artikeln oder einzelnen Voucher-Dateien.

Jeder Lexware-Aufruf verlangt den Account-Alias. API-Keys sind niemals Tool-Parameter.

`lexware_finalize` und `lexware_delete` sind standardmäßig deaktiviert. Für ihre Verwendung sind gleichzeitig der jeweilige OAuth-Scope, `confirm:true` und die passende Servervariable erforderlich.

## Lokale PDF-Ordner

Der MCP-Server sieht das lokale Dateisystem des Agenten nicht. Der Agent liest einen vom Benutzer freigegebenen Ordner und sendet jede Datei einzeln mit `lexware_file`. Empfohlener Ablauf:

1. Account eindeutig festlegen.
2. PDF lesen und SHA-256 berechnen.
3. Mit stabilem Idempotenzschlüssel hochladen.
4. `file_status` oder Voucher-Detail bis zum Ende der OCR abfragen.
5. Erkannte Daten, Kontakt und Buchungskategorie mit der Benutzervorgabe prüfen.
6. Nur bei entsprechender Anweisung über `lexware_finalize` buchen.

Der passende Agenten-Skill ist als `skill://lexware/incoming-voucher-folder/SKILL.md` abrufbar.

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
- Remote-Dateien: HTTPS, Host-Allowlist, öffentliche DNS-Adressen, keine Redirects, maximal 5 MB.
- OAuth und kritische Aktionen: getrennte Scopes und technische Schalter.
- Dauerhafte Sicherheits- und Queuezustände: MySQL, nicht `/data`.
