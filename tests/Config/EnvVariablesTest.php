<?php

namespace App\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * En prod (.env.local.php), le runtime Symfony ne reprend pas de $_SERVER les
 * variables qui commencent par HTTP_ : sous PHP-FPM ce sont des en-têtes de
 * requête. Une variable ainsi nommée garde la valeur de .env, quelle que soit
 * celle donnée au container (cas de l'ancienne HTTP_CA_FILE, restée vide).
 */
final class EnvVariablesTest extends TestCase
{
    public function testNoVariableUsesTheReservedHttpPrefix(): void
    {
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(\dirname(__DIR__, 2).'/.env'), $m);

        self::assertNotEmpty($m[1]);
        self::assertSame([], array_values(array_filter($m[1], static fn (string $name): bool => str_starts_with($name, 'HTTP_'))));
    }
}
