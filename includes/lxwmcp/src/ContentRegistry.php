<?php
declare(strict_types=1);

namespace LexMcp;

final class ContentRegistry
{
    public function __construct(private readonly AccountStore $accounts) {}

    public function list(int $userId): array
    {
        $resources = [[
            'uri' => 'lexware://accounts', 'name' => 'Lexware accounts',
            'description' => 'Account aliases available to the authenticated user.', 'mimeType' => 'application/json',
        ]];
        foreach ($this->files() as $item) {
            $resources[] = ['uri' => $item['uri'], 'name' => $item['title'], 'description' => $item['description'], 'mimeType' => 'text/markdown'];
        }
        return $resources;
    }

    public function read(int $userId, string $uri): array
    {
        if ($uri === 'lexware://accounts') {
            return [['uri' => $uri, 'mimeType' => 'application/json', 'text' => Util::jsonEncode(['accounts' => $this->accounts->listForUser($userId)])]];
        }
        foreach ($this->files() as $item) {
            if (hash_equals($item['uri'], $uri)) {
                $text = file_get_contents($item['path']);
                if ($text === false) {
                    break;
                }
                return [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $text]];
            }
        }
        throw new AppError('resource_not_found', 'Resource does not exist.', 404, false, ['uri' => $uri]);
    }

    public function templates(): array
    {
        return [[
            'uriTemplate' => 'skill://lexware/{name}/SKILL.md',
            'name' => 'Lexware workflow skill',
            'description' => 'A modular workflow skill stored on the MCP server.',
            'mimeType' => 'text/markdown',
        ]];
    }

    public function prompts(): array
    {
        return [
            ['name' => 'lexware_workflow', 'description' => 'Select the concise handbook for a Lexware workflow.', 'arguments' => [['name' => 'topic', 'description' => 'Workflow topic such as incoming-vouchers or tool-selection.', 'required' => true], ['name' => 'account', 'description' => 'Optional Lexware account alias.', 'required' => false]]],
            ['name' => 'lexware_recovery', 'description' => 'Guide recovery after a failed or uncertain Lexware operation.', 'arguments' => [['name' => 'error_code', 'description' => 'Structured MCP error code.', 'required' => true], ['name' => 'operation', 'description' => 'Operation name or idempotency reference.', 'required' => false]]],
        ];
    }

    public function prompt(string $name, array $arguments, int $userId): array
    {
        if ($name === 'lexware_workflow') {
            Util::assertKeys($arguments, ['topic', 'account']);
            $topic = Util::requireString($arguments, 'topic', 96);
            $uri = 'lexware://handbooks/' . Util::normalizeName($topic);
            $known = array_column($this->files(), 'uri');
            if (!in_array($uri, $known, true)) {
                throw new AppError('resource_not_found', 'Requested workflow handbook does not exist.', 404);
            }
            $account = null;
            if (isset($arguments['account'])) {
                $requestedAccount = Util::requireString($arguments, 'account', 96);
                $account = (string) $this->accounts->getForUser($userId, $requestedAccount)['alias'];
            }
            return ['description' => 'Use the selected workflow handbook on demand.', 'messages' => [[
                'role' => 'user', 'content' => ['type' => 'text', 'text' => 'Read ' . $uri . ' before acting.' . ($account === null ? '' : ' Use account alias ' . $account . '.')],
            ]]];
        }
        if ($name === 'lexware_recovery') {
            Util::assertKeys($arguments, ['error_code', 'operation']);
            Util::requireString($arguments, 'error_code', 96);
            return ['description' => 'Recover safely without repeating uncertain writes.', 'messages' => [[
                'role' => 'user', 'content' => ['type' => 'text', 'text' => 'Read lexware://handbooks/errors-recovery and recover the reported error without blindly repeating a write.'],
            ]]];
        }
        throw new AppError('prompt_not_found', 'Prompt does not exist.', 404);
    }

    private function files(): array
    {
        $root = realpath(Config::contentPath());
        if ($root === false) {
            return [];
        }
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'md') {
                continue;
            }
            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            if (str_starts_with($relative, 'handbooks/')) {
                $name = basename($relative, '.md');
                $uri = 'lexware://handbooks/' . $name;
            } elseif (preg_match('#^skills/([^/]+)/SKILL\.md$#', $relative, $match) === 1) {
                $name = $match[1];
                $uri = "skill://lexware/{$name}/SKILL.md";
            } else {
                continue;
            }
            $meta = $this->metadata($path, $name);
            $result[] = ['uri' => $uri, 'path' => $path, 'title' => $meta['title'], 'description' => $meta['description']];
        }
        usort($result, static fn(array $a, array $b): int => strcmp($a['uri'], $b['uri']));
        return $result;
    }

    private function metadata(string $path, string $fallback): array
    {
        $handle = fopen($path, 'rb');
        $title = ucwords(str_replace('-', ' ', $fallback));
        $description = $title;
        if ($handle !== false) {
            for ($i = 0; $i < 20 && ($line = fgets($handle)) !== false; $i++) {
                if (preg_match('/^title:\s*(.+)$/i', trim($line), $m) === 1) {
                    $title = trim($m[1], " \t\n\r\0\x0B\"'");
                } elseif (preg_match('/^description:\s*(.+)$/i', trim($line), $m) === 1) {
                    $description = trim($m[1], " \t\n\r\0\x0B\"'");
                }
            }
            fclose($handle);
        }
        return ['title' => $title, 'description' => $description];
    }
}
