<?php

namespace App\Tests\Card\Image;

use App\Card\Image\ImageUrlSigner;
use PHPUnit\Framework\TestCase;

final class ImageUrlSignerTest extends TestCase
{
    private ImageUrlSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new ImageUrlSigner('secret-de-test');
    }

    public function testUrlCarriesPathAndSignature(): void
    {
        $url = $this->signer->url('tom-1-0', '/api/assets/a1/thumbnail?size=preview');

        self::assertStringStartsWith('/api/cards/tom-1-0/image?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame('/api/assets/a1/thumbnail?size=preview', $query['path']);
        self::assertTrue($this->signer->verify('tom-1-0', $query['path'], $query['sig']));
    }

    public function testTamperedPathIsRejected(): void
    {
        parse_str((string) parse_url($this->signer->url('c', '/api/assets/a1/thumbnail'), \PHP_URL_QUERY), $query);

        self::assertFalse($this->signer->verify('c', '/api/assets/a2/thumbnail', $query['sig']));
    }

    public function testSignatureIsBoundToTheCard(): void
    {
        parse_str((string) parse_url($this->signer->url('c', '/x'), \PHP_URL_QUERY), $query);

        self::assertFalse($this->signer->verify('autre', '/x', $query['sig']));
    }

    public function testDifferentSecretDifferentSignature(): void
    {
        parse_str((string) parse_url($this->signer->url('c', '/x'), \PHP_URL_QUERY), $query);

        self::assertFalse((new ImageUrlSigner('autre-secret'))->verify('c', '/x', $query['sig']));
    }
}
