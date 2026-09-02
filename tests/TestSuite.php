<?php
declare(strict_types=1);

final class TestSuite
{
    private int $passed = 0;
    private int $failed = 0;
    private int $skipped = 0;

    public function test(string $name, callable $test): void
    {
        try {
            $test();
            $this->passed++;
            fwrite(STDOUT, "PASS {$name}\n");
        } catch (SkipTest $e) {
            $this->skipped++;
            fwrite(STDOUT, "SKIP {$name}: {$e->getMessage()}\n");
        } catch (Throwable $e) {
            $this->failed++;
            fwrite(STDERR, "FAIL {$name}: " . $e::class . ': ' . $e->getMessage() . "\n");
        }
    }

    public function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    public function assertTrue(bool $actual, string $message = ''): void
    {
        $this->assertSame(true, $actual, $message);
    }

    public function assertThrows(string $class, callable $callable, ?string $errorCode = null): void
    {
        try {
            $callable();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new RuntimeException("Expected {$class}, got " . $e::class);
            }
            if ($errorCode !== null && (!property_exists($e, 'errorCode') || $e->errorCode !== $errorCode)) {
                throw new RuntimeException("Expected error code {$errorCode}.");
            }
            return;
        }
        throw new RuntimeException("Expected {$class} to be thrown.");
    }

    public function finish(): never
    {
        fwrite(STDOUT, "\n{$this->passed} passed, {$this->failed} failed, {$this->skipped} skipped\n");
        exit($this->failed === 0 ? 0 : 1);
    }
}

final class SkipTest extends RuntimeException {}
