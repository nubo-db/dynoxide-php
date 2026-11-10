<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

/**
 * A stand-in for the dynoxide binary: a PHP script, run through the PHP that
 * runs the tests, so the same fake works on Windows.
 */
final class FakeBinary
{
    /** @param list<string> $command */
    private function __construct(public readonly string $path, public readonly array $command)
    {
    }

    public static function printing(string $dir, string $output, string $name = 'dynoxide'): self
    {
        return self::script($dir, 'echo ' . var_export($output, true) . ';', $name);
    }

    /** A fake whose body is $php, in its own directory under $dir. */
    public static function script(string $dir, string $php, string $name = 'dynoxide'): self
    {
        $home = $dir . DIRECTORY_SEPARATOR . 'fake-' . bin2hex(random_bytes(4));
        mkdir($home, 0700);
        $path = $home . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, "<?php\n{$php}\n");
        chmod($path, 0755);
        $path = realpath($path) ?: $path;

        return new self($path, self::command($path));
    }

    /** @return list<string> */
    public static function command(string $path): array
    {
        return [PHP_BINARY, $path];
    }
}
