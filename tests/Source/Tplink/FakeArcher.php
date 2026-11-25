<?php

namespace App\Tests\Source\Tplink;

use PHPUnit\Framework\Assert;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Faux Archer C6 : il a sa propre clé RSA, déchiffre la clé AES que le client
 * lui confie, vérifie la signature de chaque requête et chiffre ses réponses,
 * comme le vrai (protocole repris de tplinkrouterc6u, classe TplinkC80Router).
 * Mot de passe attendu : « motdepasse ».
 */
final class FakeArcher
{
    private const SEQ = 412345;
    // Jeton attendu pour le mot de passe « motdepasse » et les deux valeurs ci-dessous,
    // calculé avec la bibliothèque Python d'origine.
    private const INFO1 = 'AbcDEF123xyz';
    private const INFO2 = 'QwErTy987654321zZ';
    private const TOKEN = 'Qwz4ZZyQ98442QE';

    private \OpenSSLAsymmetricKey $rsa;
    /** @var array{k: string, i: string}|null */
    private ?array $aes = null;
    /** @var list<string> code=…, dans l'ordre */
    public array $calls = [];
    public string $devices = '';
    public bool $wrongPassword = false;

    public function __construct()
    {
        $this->rsa = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]) ?: throw new \RuntimeException('clé RSA');
        $this->devices = implode("\r\n", [
            'name 1 iPad', 'mac 1 D2-E0-66-53-9A-01', 'ip 1 192.168.1.36', 'type 1 1', 'online 1 1', 'up 1 0', 'down 1 0',
            'name 2 Mac', 'mac 2 d6-82-50-88-f1-02', 'ip 2 192.168.1.27', 'type 2 3', 'online 2 1', 'up 2 0', 'down 2 0',
            'name 3 parti', 'mac 3 00-16-3E-00-00-03', 'ip 3 192.168.1.40', 'type 3 1', 'online 3 0', 'up 3 0', 'down 3 0',
            'name 4 amont', 'mac 4 00-16-3E-00-00-04', 'ip 4 192.168.1.2', 'type 4 0', 'online 4 1', 'up 4 0', 'down 4 0',
            'name 5 iot', 'mac 5 00-16-3E-00-00-05', 'ip 5 192.168.1.86', 'type 5 13', 'online 5 1', 'up 5 0', 'down 5 0',
        ]);
    }

    public function router(): \Closure
    {
        return function (string $method, string $url, array $options): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $q);
            $this->calls[] = $q['code'];
            $body = $options['body'] ?? '';
            if (isset($q['id']) && self::TOKEN !== $q['id']) {
                return new MockResponse('', ['http_code' => 401]);
            }

            return new MockResponse(match ($q['code'].'/'.$q['asyn'].'/'.(isset($q['id']) ? 'id' : '-')) {
                '2/1/-' => "00000\r\nmodel\r\nC6\r\n".self::INFO1."\r\n".self::INFO2,
                '16/0/-' => $this->keyResponse(),
                '16/0/id' => $this->setKey($body),
                '7/0/id' => $this->wrongPassword ? '00007' : '00000',
                '2/1/id' => $this->data($body),
                '11/0/id' => '00000',
            });
        };
    }

    private function keyResponse(): string
    {
        $d = openssl_pkey_get_details($this->rsa)['rsa'];

        return "00000\r\n".bin2hex($d['e'])."\r\n".bin2hex($d['n'])."\r\n".self::SEQ;
    }

    private function setKey(string $body): string
    {
        Assert::assertStringStartsWith('set ', $body);
        openssl_private_decrypt((string) hex2bin(substr($body, 4)), $plain, $this->rsa, \OPENSSL_PKCS1_PADDING);
        Assert::assertMatchesRegularExpression('/^k=[0-9a-f]{16}&i=[0-9a-f]{16}$/', $plain);
        parse_str($plain, $aes);
        $this->aes = ['k' => $aes['k'], 'i' => $aes['i']];

        return '00000';
    }

    private function data(string $body): string
    {
        Assert::assertMatchesRegularExpression("/^sign=[0-9a-f]+\r\ndata=.+$/s", $body);
        [$sign, $data] = explode("\r\ndata=", substr($body, 5), 2);
        openssl_private_decrypt((string) hex2bin($sign), $signed, $this->rsa, \OPENSSL_PKCS1_PADDING);
        Assert::assertSame(\sprintf('k=%s&i=%s&s=%d', $this->aes['k'], $this->aes['i'], self::SEQ + \strlen($data)), $signed);
        $request = openssl_decrypt((string) base64_decode($data, true), 'aes-128-cbc', $this->aes['k'], \OPENSSL_RAW_DATA, $this->aes['i']);
        Assert::assertSame('13|1,0,0', $request);

        return base64_encode((string) openssl_encrypt("id 13|1,0,0\r\n".$this->devices."\r\n", 'aes-128-cbc', $this->aes['k'], \OPENSSL_RAW_DATA, $this->aes['i']));
    }
}
