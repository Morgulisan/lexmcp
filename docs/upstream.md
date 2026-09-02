# Upstream-Dokumentationsbasis

Stand der Implementierung: 28. August 2026.

- MCP: https://modelcontextprotocol.io/specification/2026-07-28
- Lexware API: https://developers.lexware.io/docs/
- Lexware API Basis: `https://api.lexware.io`

Vor einer Freigabe zusätzlicher Protokollversionen muss deren Wire-Kompatibilität geprüft werden. Bestätigte kompatible Versionen erhalten zuerst einen Eintrag in `Config::VERIFIED_PROTOCOL_PROFILES`; `LEXMCP_PROTOCOL_VERSIONS` kann anschließend daraus auswählen. Unbekannte Versionen werden standardkonform nicht behauptet.

Vor jeder Erweiterung von `config/endpoints.php` sind Pfad, HTTP-Methode, Parameter, Enum-Werte, Statusübergänge, Pagination und Fehlerverhalten erneut mit der offiziellen Lexware-Dokumentation abzugleichen.
