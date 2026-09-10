<?php
declare(strict_types=1);

namespace LexMcp;

final class AliasResolver
{
    private array $aliases;

    public function __construct(string $file)
    {
        $json = file_get_contents($file);
        if ($json === false) {
            throw new \RuntimeException('Alias configuration cannot be read.');
        }
        $this->aliases = Util::jsonDecode($json);
    }

    public function resolve(string $namespace, string $input, array $allowed, ?string $field = null): string
    {
        $needle = Util::normalizeName($input);
        $matches = [];
        foreach ($allowed as $canonical) {
            $values = array_merge([$canonical], $this->aliases[$namespace][$canonical] ?? []);
            foreach ($values as $value) {
                if (is_string($value) && Util::normalizeName($value) === $needle) {
                    $matches[$canonical] = true;
                }
            }
        }
        $matches = array_keys($matches);
        if (count($matches) === 1) {
            return $matches[0];
        }
        if (count($matches) > 1) {
            throw new AppError('alias_ambiguous', 'The alias has more than one possible meaning.', 400, false, ['candidates' => $matches], 'Choose one canonical value.');
        }
        $field ??= match ($namespace) { 'operations' => 'operation', 'entities' => 'entity', 'parameters' => 'parameters', default => 'value' };
        throw new AppError('unknown_value', $field . ' must be one of: ' . implode(', ', $allowed) . '.', 400, false, ['field' => $field, 'allowed' => $allowed]);
    }

    public function parameterName(string $input, array $allowed): string
    {
        return $this->resolve('parameters', $input, $allowed);
    }

    public function normalizeParameters(array $parameters, array $allowed): array
    {
        $result = [];
        foreach ($parameters as $name => $value) {
            if (!is_string($name)) {
                throw new AppError('unknown_parameter', 'Unknown parameter ' . Util::jsonEncode($name) . '; parameter names must be strings.', 400, false, ['parameter' => $name, 'allowed' => $allowed]);
            }
            try {
                $canonical = $this->parameterName($name, $allowed);
            } catch (AppError $e) {
                if ($e->errorCode === 'unknown_value') {
                    throw new AppError(
                        'unknown_parameter',
                        'Unknown parameter ' . Util::jsonEncode($name) . '. Allowed parameters: ' . implode(', ', $allowed) . '.',
                        400,
                        false,
                        ['parameter' => $name, 'allowed' => $allowed],
                    );
                }
                throw $e;
            }
            if (array_key_exists($canonical, $result)) {
                throw new AppError('duplicate_parameter', 'A parameter was supplied more than once through aliases.', 400, false, ['parameter' => $canonical]);
            }
            $result[$canonical] = $value;
        }
        return $result;
    }

    public function enum(string $value, array $allowed, string $field = 'value'): string
    {
        return $this->resolve('enums', $value, $allowed, $field);
    }
}
