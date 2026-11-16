<?php

namespace App\Tests\Source\Paperless;

use App\Source\Paperless\PaperlessMonetary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaperlessMonetaryTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?string, ?PaperlessMonetary}>
     */
    public static function values(): iterable
    {
        yield 'devise en préfixe' => ['EUR246.50', null, new PaperlessMonetary(24650, 'EUR')];
        yield 'sans devise : celle du champ' => ['39.99', 'USD', new PaperlessMonetary(3999, 'USD')];
        yield 'sans devise ni défaut : euros' => ['39.99', null, new PaperlessMonetary(3999, 'EUR')];
        yield 'entier' => ['EUR12', null, new PaperlessMonetary(1200, 'EUR')];
        yield 'un seul décimal' => ['GBP7.5', null, new PaperlessMonetary(750, 'GBP')];
        yield 'nombre JSON (anciennes versions)' => [12.3, null, new PaperlessMonetary(1230, 'EUR')];
        yield 'négatif (avoir)' => ['EUR-15.00', null, new PaperlessMonetary(-1500, 'EUR')];
        yield 'vide' => ['', null, null];
        yield 'null' => [null, null, null];
        yield 'illisible' => ['beaucoup', null, null];
    }

    #[DataProvider('values')]
    public function testParse(mixed $value, ?string $defaultCurrency, ?PaperlessMonetary $expected): void
    {
        self::assertEquals($expected, PaperlessMonetary::parse($value, $defaultCurrency));
    }
}
