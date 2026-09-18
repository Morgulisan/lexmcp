# Gemeinsam – Aufgaben für Nutzer und Agenten

Separate, abhängigkeitssfreie PHP-8.4-Anwendung für `todo.mopoliti.de` mit deutscher Weboberfläche, MySQL/MariaDB, zentralem Bestandslogin und OAuth-geschütztem Remote-MCP. Die Lexware-Anwendung wird nicht verändert.

## Installation

1. `html/todo.mopoliti.de/` einschließlich `.htaccess` als DocumentRoot bereitstellen; `includes/todo/` außerhalb des Webroots bereitstellen. `tests/` niemals veröffentlichen.
2. PHP-Erweiterungen `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd`, `zip` und `xmlreader` aktivieren. HTTPS am Webserver erzwingen, `display_errors=Off`, `post_max_size=12M`, `upload_max_filesize=10M`. Ein HTTPS-Reverse-Proxy darf Request-Inhalte und Authorization-Header nicht protokollieren. Keine öffentlich erreichbare HTTP-Alternative bereitstellen.
3. Umgebungsvariablen aus `config/environment.example` im Hosting setzen. `TODO_OWNER_ID` ist die bestehende `UserAccount.ID` des einzigen V1-Nutzers. `TODO_DATABASE_INCLUDE` verweist auf die bereits vorhandene `/includes/api/sql.php`, die unverändert importiert wird und `connectToSQL(): PDO` liefert. Keine eigene Kopie und keine DB-Zugangsdaten im Projekt anlegen.
4. Einen zufälligen 32-Byte-Schlüssel base64-codiert als `TODO_ENCRYPTION_KEY` in der privaten Hosting-Konfiguration hinterlegen. Der Schlüssel muss über Deployments stabil bleiben; verschlüsselte OAuth-Daten und Idempotenzantworten sind ohne ihn nicht lesbar. Datenbank und Schlüssel getrennt sichern.
5. Migration einmal per CLI ausführen:

   ```sh
   php /includes/todo/bin/migrate.php
   ```

6. `/data/todo.mopoliti.de/` ausschließlich für den App-Benutzer zugänglich und beschreibbar anlegen. Der Standardpfad liegt relativ zur Installation unter `data/todo.mopoliti.de/`; `TODO_DATA_PATH` kann ihn überschreiben. Bei einer bestehenden Installation vorhandene Dateien aus dem alten Datenverzeichnis vor dem Umschalten übernehmen. ClamAV samt aktuellen Signaturen installieren und `TODO_MALWARE_SCANNER` auf den absoluten `clamscan`-Pfad setzen. Ohne erfolgreich abgeschlossene Prüfung werden Uploads abgewiesen. Der Scanner läuft mit Argumentliste, ohne Shell, und mit 30 Sekunden Zeitlimit.
7. Jede Minute den Worker ausführen:

   ```sh
   php /includes/todo/bin/worker.php
   ```

8. Über HTTPS anmelden, Projekt erstellen und den MCP unter `https://todo.mopoliti.de/mcp` verbinden. Vor dem Produktiveinsatz die MySQL-Tests, den tatsächlichen zentralen Login, Upload-Scanner und OAuth-Callback im Zielhosting prüfen.

Die initiale Schemaanlage ist wiederholbar und wird beim HTTP-Start geprüft. Die Laufzeit-DB-Rolle benötigt deshalb `CREATE`-Recht. Tabellen beginnen mit `todo_` und kollidieren nicht mit `lxmcp_`.

## Aufbau

- `Database`: parametrisierte SQL-Abfragen, Schema und Workspace-Transaktionen.
- `Service`, `TaskActions`, `CatalogActions`: eine gemeinsame Fachlogik für Web und MCP.
- `Policy`: einschränkende globale, Projekt- und Aufgabenregeln.
- `Auth`, `Crypto`: PKCE, Consent, rotierende Tokens, zentraler Login und verschlüsselte Auth-Daten.
- `Mcp`: fünf sichtbare Tools, versteckte Schreibweisen und Aliase.
- `Application`, `views/app.php`, öffentliche `assets/`: HTTP-Routing und responsive Oberfläche ohne externe Laufzeitabhängigkeiten.
- `FileStore`, `FileValidator`: private, größenbeschränkte, format- und malwaregeprüfte Dateien; keine Remote-Downloads.
- `TaskDeletion`: endgültige Inhaltslöschung und Bereinigung gespeicherter Idempotenzantworten.

V1 serialisiert Fachänderungen mit einer InnoDB-Zeilensperre pro Workspace. Die Sperre wird vor dem Lesen veränderlicher Datensätze genommen; Mutation, Versionsprüfung, Audit und Idempotenzantwort committen zusammen. Verschiedene Workspaces blockieren einander nicht. Das ist bewusst einfacher als mehrere voneinander abhängige Locks. Suchergebnisse werden in V1 im PHP-Prozess aus den Workspace-Aufgaben gefiltert; für sehr große Bestände sind indizierte Suchspalten ein späterer Optimierungsschritt.

Aufgaben besitzen eigene Zeilen mit `memory_md`, `memory_version` und `version`. Projekte, Skills, Kommentare, Pläne, Sessions und kleinere Katalogobjekte liegen als typisierte, versionierte Datensätze in `todo_entities`. Workspaces und Projektzuordnungen sind bereits vorhanden; Teamrollen sind nicht Teil von V1.

## Bedienung

„Meine Aufmerksamkeit“ bündelt offene Rückfragen/Pläne, Reviews, überfällige Aufgaben und abgelaufene Agentenläufe. Aufgaben sind als Liste, Board und Monatskalender verfügbar. Filter können als Sichten gespeichert werden. Unteraufgaben sind eigene Aufgaben. Eine harte Abhängigkeit erzeugt zusätzlich eine verknüpfte Unteraufgabe; Zyklen einschließlich widersprüchlicher Elternbeziehungen werden abgewiesen.

Aufgabenbeschreibungen unterstützen eine sichere Markdown-Teilmenge (Überschriften, Fettdruck, Code, Aufzählungen); HTML wird immer escaped. Dateien werden nur als Downloads mit Attachment-Disposition ausgeliefert.

Die Memory wird erst beim Aufklappen geladen. Nutzer können aktuelle und frühere Versionen ansehen, bearbeiten oder leeren. Sie erscheint weder in Suchtexten noch in normalen Aufgabendetails oder Audit-Payloads. Jeder Inhalt gilt als nicht vertrauenswürdiger Kontext; die Oberfläche warnt vor Zugangsdaten, und bekannte Secret-Muster werden abgewiesen. Diese Mustererkennung kann beliebig verschleierte Geheimnisse nicht zuverlässig erkennen; Agenten müssen das Speicherverbot zusätzlich befolgen.

Archivieren behält Aufgaben, Dateien und Memory ohne automatische Löschfrist; „Aus Archiv holen“ macht sie wieder aktiv. „Endgültig löschen“ entfernt die Aufgabe samt Unteraufgaben, Kommentaren, Plänen, Reviews, Memory-Versionen und ihren Audit-Inhalten aus der Anwendungsdatenbank. Betroffene Idempotenzantworten werden durch inhaltsfreie Löschmarker ersetzt. Minimale Lösch-, Idempotenz- und Wiederholungsmarker verhindern eine unbeabsichtigte Neuerzeugung durch verspätete Requests oder Worker-Läufe. Es gibt keinen Papierkorb und keine Wiederherstellung gelöschter Aufgaben in der App. Externe Backups werden dadurch nicht nachträglich verändert.

Dateien werden direkt nach erfolgreichem Datenbank-Commit noch im selben Löschrequest entfernt. Bei einem Dateisystemfehler meldet die API `file_delete_pending` (503), statt vollständigen Erfolg vorzutäuschen; der Operationsstatus lautet `cleanup_pending`. Ein Retry mit demselben Idempotenzschlüssel oder der Worker wiederholt nur die ausstehende Bereinigung. Der Löschauftrag bleibt bis zum Erfolg erhalten. Ein zurückgerollter Datenbankvorgang löscht keine Dateien.

Uploads erlauben ausschließlich JPG/JPEG, PNG, GIF, WebP, BMP, PDF, DOCX, XLSX und CSV bis 10 MiB. Alte DOC-/XLS-Binärformate, Makroformate, SVG und andere Endungen werden abgewiesen. Serverprüfungen vergleichen Endung, erkannten Inhalt und Formatstruktur: Bilder werden unter Pixelgrenzen dekodiert; Office-Pakete auf ZIP-Integrität, Entpackgrenzen, XML, Pakettyp und Hauptbestandteile geprüft. PDF-Prüfung umfasst Header, Endmarker und Querverweisposition, ist aber kein vollständiger PDF-Parser. CSV muss mit Komma, Semikolon oder Tab konsistente Zeilen mit mindestens zwei Spalten enthalten; UTF-8, Windows-1252 und UTF-16 mit BOM werden unterstützt. Formatprüfung ersetzt die zusätzliche Malwareprüfung nicht.

Wiederholungen entstehen für fällige tägliche, wöchentliche oder monatliche Intervalle. Monatsenden werden auf den letzten gültigen Monatstag begrenzt; lokale Uhrzeit und Zeitzone bleiben erhalten. Ein dauerhafter Intervallmarker verhindert Duplikate auch nach späterem Löschen einer erzeugten Aufgabe. Der Worker holt pro Vorlage maximal 3660 Intervalle nach; abgebrochene Vorlagen erzeugen nichts mehr. Eine erzeugte Instanz erhält keine fremde Memory und keinen alten Claim.

## MCP-Vertrag

OAuth Authorization Code mit PKCE-S256, exakter Redirect-Prüfung (native Loopback-Ports ausgenommen), Resource-Bindung, 10-Minuten-Access-Tokens und rotierenden 30-Tage-Refresh-Tokens. Wiederverwendung eines Grants widerruft die Verbindung einschließlich Sessions und Claims. Tokens werden nie im Klartext gespeichert. Discovery liegt unter den OAuth-Well-Known-Pfaden; dynamische Registrierung ist verfügbar. Client-ID-Metadatendokumente werden derzeit nicht angeboten.

Projektzugriff und Scopes werden beim Consent ausgewählt. Scopes: `todo:read`, `todo:comment`, `todo:write`. Widerruf wirkt auf jeden weiteren MCP-Request. IP-, Nutzer-, Client- und Agentenlimits schützen die Schnittstellen. Der Server vertraut nicht automatisch `X-Forwarded-For`.

| Tool | Zweck |
|---|---|
| `todo_query` | Aufgaben suchen/lesen, Projekte, Capabilities, Skills, explizite Memory, Operationsstatus |
| `todo_task` | Erstellen, Claim/Verlängerung/Freigabe, Arbeitsfelder, Memory, Abhängigkeiten, Artefaktlinks |
| `todo_collaborate` | Typisierte Kommentare, Rückfragen, Übergaben, versionierte Pläne, Reviews, Ergebnisse |
| `todo_catalog` | Neue Skill-Entwürfe und unveränderliche Folgeversionen |
| `todo_session` | Lauf-ID und aktuell vorhandene Capabilities registrieren |

1. `todo_session` mit `action=session_begin`, `run_id`, Katalog-Capability-IDs und `idempotency_key` aufrufen. Rückgabe-`id` als `session_id` weitergeben.
2. Mit `todo_query`, `action=tasks`, `compatible=true`, `status=ready` suchen. Vollständige Aufgabe und bei Bedarf Memory separat lesen.
3. `todo_task`, `action=claim` mit `task_id`, `expected_version` und neuem `idempotency_key`. Die Antwort enthält ein nur für diesen Agenten gültiges `claim_token`.
4. Arbeitsänderungen benötigen zusätzlich dieses Token. Die Lease gilt standardmäßig 30 Minuten, maximal 120 Minuten ab Erstellung/Verlängerung. Verlängerung nach Ablauf ist verboten. Jede Mutation liefert die neue Aufgaben-Version.
5. Memory benötigt außerdem `expected_memory_version`, `content` und optional `mode=append`; maximal 2048 Unicode-Zeichen nach Anhängen.
6. Bei unklarem Request-Ausgang `todo_query`, `action=operation` mit demselben `idempotency_key` prüfen. Replays identischer Requests liefern dieselbe verschlüsselt gespeicherte Antwort; anderer Inhalt unter demselben Schlüssel wird abgewiesen. Beim Retry auch die ursprüngliche erwartete Version beibehalten.

Versteckte Aliase umfassen `read_task_memory`, `update_task_memory`, `search_tasks`, `get_task`, `claim_task` sowie unterschiedliche Groß-/Kleinschreibung und Trennzeichen der fünf Toolnamen. Sichtbare Parameter bleiben kanonisch und werden validiert. Nutzeraktionen wie Planfreigabe, Reviewentscheidung, Löschen, Abbrechen und Claim-Übernahme sind nicht als MCP-Tools verfügbar.

Fremde MCP-Aktionen werden nicht ausgeführt oder technisch vermittelt. Die App kann nur ihren eigenen Workflow und die dokumentierten Freigaben erzwingen; ein externer Agent muss diese Freigaben bei tatsächlichen externen Aktionen selbst beachten.

## Policies

Regeln sind JSON-Listen mit `action`, `effect` (`allow`, `deny`, `approval`) und optional `agent`, `project`, `risk_min`, `priority_max`, `capability`. Erlaubte Aktionen stehen in `Policy::ACTIONS`. Ein Verbot auf irgendeiner Ebene gewinnt; niedrigere Allow-Regeln entfernen keine Freigabeanforderung.

```json
[{"action":"complete","effect":"approval"}]
```

Damit verlangt jeder Agentenabschluss einen genehmigten aktuellen Plan mit der Aktion `complete`. Alternativ kann der Agent `review_request` nutzen und den Nutzer das Ergebnis über die Weboberfläche annehmen lassen. Neue Planversionen entwerten alte Freigaben automatisch. Änderungen an Priorität, Risiko oder Denkaufwand durch Agenten verlangen eine Begründung als Entscheidungskommentar.

## Tests und Abnahme

```sh
php tests/todo/run.php
php tests/todo/mysql.php
php tests/run.php
```

Die neue Fachtestsuite verwendet dieselbe Service- und SQL-Schicht gegen isoliertes SQLite einschließlich zweier echter konkurrierender PHP-Prozesse. `mysql.php` prüft die tatsächliche InnoDB-Sperrung, Schemaanlage, UTF-8-Memory und Idempotenz; dafür ausschließlich eine separate Testdatenbank mit `TODO_TEST_DSN`, `TODO_TEST_DB_USER`, `TODO_TEST_DB_PASSWORD` konfigurieren. Ohne DSN meldet sie ausdrücklich SKIP.

Eine lokale UI-Fixture ohne Produktivzugang startet mit `TODO_TEST_PREVIEW=1` und `php -S 127.0.0.1:18181 tests/todo/preview.php`. Sie akzeptiert nur Loopback-Requests und legt eine separate SQLite-Datei im System-Temp an. Der produktive Bootstrap importiert diese Fixture nie.

Mit derselben Preview-Umgebungsvariable erzeugt `php tests/todo/seed_preview.php` lokale Beispieldaten. Anschließend prüft `php tests/todo/http.php` zehn HTTP-Sicherheitsfälle, einschließlich unzulässiger Dateiendungen, gefälschter Bildinhalte und geschlossenem Upload-Verhalten ohne Scanner. Die Fachtestsuite enthält zusätzlich Format-, Archiv- und endgültige Löschtests samt simuliertem Dateisystemfehler. Für die Formatprüfungen müssen auch im Testprozess `fileinfo`, `gd` und `zip` aktiv sein; sonst werden diese Tests ausdrücklich übersprungen. MySQL wurde in der Entwicklungsumgebung mangels Test-DSN ausdrücklich übersprungen.

Browserprüfung: Projekt und Aufgabe anlegen, bereitstellen, Kommentar speichern und Memory-Version anlegen; mobile Planfreigabe, Rückfrageantwort und Reviewabschluss einschließlich Statuswechsel verifiziert. Layout bei Desktop, 820 × 1180 und 390 × 844 geprüft. Im Browser wurden keine JavaScript-Fehler gemeldet. Der echte zentrale Login, TLS/Apache, MySQL und der Malware-Scanner müssen zusätzlich in der Zielumgebung geprüft werden.

Die bestehende Lexware-Testsuite wird separat betrachtet. Vorhandene lokale Änderungen in `tests/run.php` und anderen Bestandsdateien wurden durch die Todo-Implementierung nicht verändert.

Protokollgrundlage: [MCP Authorization 2025-11-25](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization), [OAuth Security BCP / RFC 9700](https://www.rfc-editor.org/rfc/rfc9700.html). Der MCP bietet die klassischen Streamable-HTTP-JSON-Profile 2025-03-26, 2025-06-18 und 2025-11-25 an; keine vorgetäuschte Unterstützung späterer Wire-Profile.
