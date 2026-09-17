<?php
declare(strict_types=1);

namespace Todo;

/** Format validation is separate from malware scanning; neither replaces the other. */
final class FileValidator
{
    public const EXTENSIONS = ['jpg','jpeg','png','gif','webp','bmp','pdf','docx','xlsx','csv'];
    private const IMAGES = ['jpg'=>IMAGETYPE_JPEG,'jpeg'=>IMAGETYPE_JPEG,'png'=>IMAGETYPE_PNG,'gif'=>IMAGETYPE_GIF,'webp'=>IMAGETYPE_WEBP,'bmp'=>IMAGETYPE_BMP];

    public static function validate(string $path, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($extension, self::EXTENSIONS, true)) throw new Failure('file_type', 'Erlaubt sind JPG, PNG, GIF, WebP, BMP, PDF, DOCX, XLSX und CSV.', 415);
        $size = filesize($path);
        if (!$size || $size > 10 * 1024 * 1024) throw new Failure('file_size', 'Dateien dürfen maximal 10 MiB groß sein.', 413);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (isset(self::IMAGES[$extension])) {
            if (!function_exists('imagecreatefromstring')) throw new Failure('validator_unavailable', 'Die Bildprüfung benötigt PHP GD.', 503);
            set_error_handler(static fn(): bool => true);
            try {
                $info = getimagesize($path);
                if (!$info || $info[2] !== self::IMAGES[$extension] || $mime !== image_type_to_mime_type($info[2]) && !($extension === 'bmp' && $mime === 'image/x-ms-bmp')) self::invalid();
                if ($info[0] > 12000 || $info[1] > 12000 || $info[0] * $info[1] > 20000000) throw new Failure('image_size', 'Das Bild darf maximal 20 Megapixel und 12000 Pixel pro Kante haben.', 413);
                $image = imagecreatefromstring(file_get_contents($path));
                if ($image === false) self::invalid();
                unset($image);
                $mime = image_type_to_mime_type($info[2]);
            } finally { restore_error_handler(); }
        } elseif ($extension === 'pdf') {
            $bytes = file_get_contents($path);
            if ($mime !== 'application/pdf' || !preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)(?:\r|\n)/', $bytes) || !preg_match('/startxref\s+(\d+)\s+%%EOF\s*\z/s', $bytes, $match)) self::invalid();
            $offset = (int)$match[1];
            if ($offset < 9 || $offset >= strlen($bytes)) self::invalid();
            // Accept traditional xref tables and PDF 1.5+ cross-reference streams.
            $xref = substr($bytes, $offset);
            if (!str_starts_with($xref, 'xref') && !preg_match('/\A\d+\s+\d+\s+obj\s*<<(?:(?!>>).)*\/Type\s*\/XRef\b/s', substr($xref, 0, 65536))) self::invalid();
        } elseif (in_array($extension, ['docx','xlsx'], true)) {
            if (!in_array($mime, ['application/zip','application/x-zip-compressed','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'], true)) self::invalid();
            self::office($path, $extension);
            $mime = 'application/vnd.openxmlformats-officedocument.' . ($extension === 'docx' ? 'wordprocessingml.document' : 'spreadsheetml.sheet');
        } else {
            self::csv(file_get_contents($path));
            $mime = 'text/csv';
        }
        return ['extension' => $extension, 'mime' => $mime];
    }
    private static function invalid(): never { throw new Failure('invalid_file_format', 'Dateiendung und Inhalt passen nicht zusammen oder die Datei ist beschädigt.', 415); }

    private static function xml(string $bytes): array
    {
        if (stripos($bytes, '<!DOCTYPE') !== false || stripos($bytes, '<!ENTITY') !== false || str_contains($bytes, "\0")) self::invalid();
        $previous = libxml_use_internal_errors(true);
        try {
            $reader = new \XMLReader();
            if (!$reader->XML($bytes, null, LIBXML_NONET)) self::invalid();
            $result = ['name'=>null, 'namespace'=>null, 'body'=>0, 'sheets'=>0, 'items'=>[]];
            try {
                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::DOC_TYPE || $reader->nodeType === \XMLReader::ENTITY_REF) self::invalid();
                    if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
                    if ($reader->depth === 0) { $result['name'] = $reader->localName; $result['namespace'] = $reader->namespaceURI; }
                    if (in_array($reader->localName, ['body','sheets'], true) && $reader->namespaceURI === $result['namespace']) $result[$reader->localName]++;
                    if (!in_array($reader->localName, ['Override','Default','Relationship','sheet'], true)) continue;
                    if (count($result['items']) >= 10000) self::invalid();
                    $item = ['element'=>$reader->localName];
                    while ($reader->moveToNextAttribute()) $item[$reader->localName] = $reader->value;
                    $reader->moveToElement(); $result['items'][] = $item;
                }
                if (libxml_get_errors() || $result['name'] === null) self::invalid();
                return $result;
            } finally { $reader->close(); }
        } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
    }
    private static function office(string $path, string $extension): void
    {
        if (!class_exists(\ZipArchive::class)) throw new Failure('validator_unavailable', 'Die Office-Prüfung benötigt PHP ZIP.', 503);
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) self::invalid();
        try {
            if ($zip->numFiles > 2000) self::invalid();
            $total = 0; $names = []; $xml = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i); $name = $entry['name'];
                if (isset($names[$name]) || preg_match('~(?:^/|\\\\|(?:^|/)\.\.(?:/|$)|\x00)~', $name) || ($entry['encryption_method'] ?? 0) !== 0) self::invalid();
                $names[$name] = true; $total += $entry['size'];
                if ($entry['size'] > 20 * 1024 * 1024 || $total > 50 * 1024 * 1024 || ($entry['size'] > 1048576 && $entry['size'] > max(1, $entry['comp_size']) * 200)) self::invalid();
                if (preg_match('~(?:vbaProject|activeX|macrosheets)(?:[./]|$)~i', $name)) self::invalid();
                if (str_ends_with($name, '/')) continue;
                // Read each entry: CRC failure and truncated archive members are rejected.
                $bytes = $zip->getFromIndex($i);
                if ($bytes === false || strlen($bytes) !== $entry['size'] || sprintf('%u', crc32($bytes)) !== sprintf('%u', $entry['crc'])) self::invalid();
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) $xml[$name] = self::xml($bytes);
            }
            $types = $xml['[Content_Types].xml'] ?? null; $relations = $xml['_rels/.rels'] ?? null;
            if (!$types || !$relations) self::invalid();
            if ($types['name'] !== 'Types' || $types['namespace'] !== 'http://schemas.openxmlformats.org/package/2006/content-types' || $relations['name'] !== 'Relationships' || $relations['namespace'] !== 'http://schemas.openxmlformats.org/package/2006/relationships') self::invalid();
            $main = null;
            foreach ($relations['items'] as $relation) {
                if (!in_array($relation['Type'] ?? '', ['http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument','http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument'], true)) continue;
                if ($main !== null || ($relation['TargetMode'] ?? '') === 'External') self::invalid();
                $main = ltrim($relation['Target'] ?? '', '/');
            }
            if ($main === null || !isset($xml[$main])) self::invalid();
            $expected = 'application/vnd.openxmlformats-officedocument.' . ($extension === 'docx' ? 'wordprocessingml.document.main+xml' : 'spreadsheetml.sheet.main+xml');
            $matched = false;
            foreach ($types['items'] as $type) {
                $contentType = $type['ContentType'] ?? '';
                if (preg_match('/macroEnabled|vbaProject|macrosheet/i', $contentType)) self::invalid();
                if (ltrim($type['PartName'] ?? '', '/') === $main && $contentType === $expected) $matched = true;
            }
            $root = $xml[$main];
            $namespace = $extension === 'docx' ? 'wordprocessingml' : 'spreadsheetml';
            if (!$matched || $root['name'] !== ($extension === 'docx' ? 'document' : 'workbook') || !in_array($root['namespace'], ['http://schemas.openxmlformats.org/' . $namespace . '/2006/main','http://purl.oclc.org/ooxml/' . $namespace . '/main'], true)) self::invalid();
            if ($extension === 'docx' && $root['body'] !== 1) self::invalid();
            if ($extension === 'xlsx') {
                if ($root['sheets'] !== 1 || !$root['items']) self::invalid();
                $mainRelations = $xml[dirname($main) . '/_rels/' . basename($main) . '.rels'] ?? null;
                if (!$mainRelations) self::invalid();
                foreach ($root['items'] as $sheet) {
                    if ($sheet['element'] !== 'sheet') continue;
                    $found = false;
                    foreach ($mainRelations['items'] as $relation) {
                        if (($relation['Id'] ?? '') !== ($sheet['id'] ?? '') || !str_ends_with($relation['Type'] ?? '', '/worksheet') || ($relation['TargetMode'] ?? '') === 'External') continue;
                        $target = $relation['Target'] ?? '';
                        $target = str_starts_with($target, '/') ? ltrim($target, '/') : dirname($main) . '/' . $target;
                        if (($xml[$target]['name'] ?? '') === 'worksheet' && ($xml[$target]['namespace'] ?? '') === $root['namespace']) $found = true;
                    }
                    if (!$found) self::invalid();
                }
            }
        } finally { $zip->close(); }
    }
    private static function csv(string $bytes): void
    {
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) $bytes = mb_convert_encoding(substr($bytes, 2), 'UTF-8', str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        elseif (!mb_check_encoding($bytes, 'UTF-8')) $bytes = mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
        $bytes = preg_replace('/\A\xEF\xBB\xBF/', '', $bytes);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $bytes) || preg_match('/\A\s*(?:<\?|<!doctype|<html|<script|%PDF-|PK\x03\x04)/i', $bytes)) self::invalid();
        foreach ([',',';',"\t"] as $delimiter) {
            if (!self::csvQuotes($bytes, $delimiter)) continue;
            $stream = fopen('php://temp', 'w+'); fwrite($stream, $bytes); rewind($stream);
            $columns = null; $rows = 0; $valid = true;
            try {
                while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                    if ($row === [null]) continue;
                    $columns ??= count($row);
                    if ($columns < 2 || $columns > 1024 || count($row) !== $columns || ++$rows > 100000) { $valid = false; break; }
                }
            } finally { fclose($stream); }
            if ($valid && $rows > 0) return;
        }
        self::invalid();
    }
    private static function csvQuotes(string $bytes, string $delimiter): bool
    {
        $state = 'start';
        for ($i = 0, $length = strlen($bytes); $i < $length; $i++) {
            $char = $bytes[$i];
            if ($state === 'quoted') {
                if ($char !== '"') continue;
                if (($bytes[$i + 1] ?? '') === '"') { $i++; continue; }
                $state = 'closed'; continue;
            }
            if ($char === $delimiter || $char === "\n" || $char === "\r") { $state = 'start'; continue; }
            if ($state === 'closed') return false;
            if ($char === '"') { if ($state !== 'start') return false; $state = 'quoted'; }
            else $state = 'plain';
        }
        return $state !== 'quoted';
    }
}
