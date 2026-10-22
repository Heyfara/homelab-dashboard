<?php

namespace App\Tests\Source;

use App\Source\Credentials;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;

final class CredentialsTest extends TestCase
{
    private function credentials(): Credentials
    {
        return new Credentials(['GW2_API_KEY' => 'cle-tom', 'GW2_API_KEY_ZOE' => 'cle-zoe', 'GW2_API_KEY_VIDE' => '', 'APP_SECRET' => 'secret']);
    }

    public function testDefaultSecret(): void
    {
        self::assertSame('cle-tom', $this->credentials()->get('GW2_API_KEY'));
        self::assertSame('cle-tom', $this->credentials()->get('GW2_API_KEY', null));
    }

    public function testVariantIsTheBaseNameSuffixedInUpperCase(): void
    {
        self::assertSame('cle-zoe', $this->credentials()->get('GW2_API_KEY', 'zoe'));
    }

    public function testMissingVariantNamesTheVariable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('GW2_API_KEY_LEA absente ou vide');

        $this->credentials()->get('GW2_API_KEY', 'lea');
    }

    public function testEmptyIsMissing(): void
    {
        $this->expectExceptionMessage('GW2_API_KEY_VIDE absente ou vide');

        $this->credentials()->get('GW2_API_KEY', 'vide');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidVariants(): iterable
    {
        yield 'tiret' => ['jean-paul'];
        yield 'remontée vers une autre variable' => ['../APP_SECRET'];
        yield 'vide' => [''];
        yield 'espace' => ['zoe '];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVariants')]
    public function testOnlyLettersDigitsAndUnderscore(string $variant): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->credentials()->get('GW2_API_KEY', $variant);
    }

    public function testValidVariantOption(): void
    {
        self::assertTrue(Credentials::isValidVariant('zoe_2'));
        self::assertFalse(Credentials::isValidVariant('jean-paul'));
    }
}
