<?php

declare(strict_types=1);

namespace Dynoxide\Tests\Support;

/**
 * An environment for proc_open() as "name=value" strings. Given an array
 * keyed by name, proc_open() passes a variable whose name is a number (PHP
 * makes it an integer key) without its name, which on Windows makes
 * CreateProcess fail. ParaTest and Pest workers there sometimes have one.
 */
final class ChildEnv
{
    /**
     * @param array<array-key, string> $env
     * @return list<string>
     */
    public static function pairs(array $env): array
    {
        $pairs = [];
        foreach ($env as $name => $value) {
            if ($value !== '') {
                $pairs[] = $name . '=' . $value;
            }
        }

        return $pairs;
    }
}
