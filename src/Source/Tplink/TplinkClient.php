<?php

namespace App\Source\Tplink;

use App\Network\ApSnapshot;
use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appareils associés à un point d'accès TP-Link Archer C6 V4 (firmware
 * d'origine), lus comme le fait son interface web. Protocole repris de la
 * bibliothèque Python tplinkrouterc6u (classe TplinkC80Router) :
 *
 *  1. le mot de passe, brouillé par XOR avec une table fixe puis avec deux
 *     valeurs données par le routeur, sert de jeton de session (`id`) ;
 *  2. le routeur donne sa clé RSA, on lui confie une clé AES-128-CBC tirée au
 *     hasard ;
 *  3. chaque requête est chiffrée en AES et signée en RSA (PKCS#1 v1.5) ;
 *  4. on se déconnecte toujours : l'Archer n'accepte qu'une session
 *     d'administration à la fois.
 *
 * Relu au plus une fois par minute, échec compris. Provisoire : à retirer quand
 * le second Flint 2 remplacera l'Archer.
 */
final readonly class TplinkClient
{
    private const KEY = 'RDpbLfCPsJZ7fiv';
    private const ENCODING = 'yLwVl0zKqws7LgKPRQ84Mdt708T1qQ3Ha7xv3H7NyU84p21BriUWBU43odz3iP4rBL3cD02KZciXTysVXiV8'
        .'ngg6vL48rPJyAUw0HurW20xqxv9aYb4M9wK1Ae0wlro510qXeU07kV57fQMc8L6aLgMLwygtc0F10a0Dg70T'
        .'OoouyFhdysuRMO51yY5ZlOZZLEal1h0t9YQW0Ko7oBwmCAHoic4HYbUyVeU3sfQ1xtXcPcf1aT303wAQhv66qzW';
    private const PAD = 187; // « » », complète la plus courte des deux chaînes
    private const DEVICES = '13|1,0,0';
    /** type d'un appareil → radio ; 0 (câble) est ignoré : c'est l'amont de l'Archer */
    private const BANDS = [1 => 'wlan2g', 2 => 'wlan2g', 13 => 'wlan2g', 3 => 'wlan5g', 4 => 'wlan5g', 14 => 'wlan5g'];
    private const TTL = 55;
    private const TIMEOUT = 5;

    private \Closure $clock;

    /**
     * @param ?\Closure(): float $clock
     */
    public function __construct(
        private HttpClientInterface $http,
        private CacheInterface $cache,
        #[Autowire('%env(TPLINK_PASSWORD)%')]
        #[\SensitiveParameter]
        private string $password,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function snapshot(string $address): ApSnapshot
    {
        if ('' === $this->password) {
            throw new SourceUnavailableException('Mot de passe de l’Archer absent (TPLINK_PASSWORD)');
        }

        $result = $this->cache->get('tplink.'.hash('xxh128', $address.'|'.$this->password), function (ItemInterface $item) use ($address): array {
            $item->expiresAfter(self::TTL);
            try {
                return ['snapshot' => $this->read($address)];
            } catch (SourceUnavailableException $e) {
                return ['error' => $e->getMessage()];
            }
        });

        return $result['snapshot'] ?? throw new SourceUnavailableException($result['error']);
    }

    /**
     * Brouillage du mot de passe (et du jeton) par le script de la page de connexion.
     */
    public static function encode(string $text, string $key = self::KEY, string $encoding = self::ENCODING): string
    {
        $t = array_map(mb_ord(...), mb_str_split($text));
        $k = array_map(mb_ord(...), mb_str_split($key));
        $length = max(\count($t), \count($k));
        $out = '';
        for ($i = 0; $i < $length; ++$i) {
            $out .= $encoding[(($t[$i] ?? self::PAD) ^ ($k[$i] ?? self::PAD)) % \strlen($encoding)];
        }

        return $out;
    }

    private function read(string $address): ApSnapshot
    {
        $base = 'http://'.$address.'/';
        $info = self::lines($this->post($base, 2, 1));
        if (\count($info) < 5) {
            throw new SourceUnavailableException('Archer : réponse de connexion inattendue');
        }
        $token = strtr(rawurlencode(self::encode(self::encode($this->password), $info[3], $info[4])), ['%21' => '!', '%28' => '(', '%29' => ')', '%2A' => '*']);

        try {
            $rsa = self::lines($this->post($base, 16, 0, body: 'get'));
            if (\count($rsa) < 4) {
                throw new SourceUnavailableException('Archer : clé RSA illisible');
            }
            [, $e, $n, $seq] = $rsa;
            $pem = self::publicKey($n, $e);
            $key = bin2hex(random_bytes(8));
            $iv = bin2hex(random_bytes(8));
            $this->post($base, 16, 0, $token, 'set '.self::rsa("k=$key&i=$iv", $pem));
            if (1 === preg_match('/^0000[1-9]/', $this->post($base, 7, 0, $token))) {
                throw new SourceUnavailableException('Connexion à l’Archer refusée : vérifier TPLINK_PASSWORD');
            }

            $data = base64_encode((string) openssl_encrypt(self::DEVICES, 'aes-128-cbc', $key, \OPENSSL_RAW_DATA, $iv));
            $sign = '';
            foreach (str_split(\sprintf('k=%s&i=%s&s=%d', $key, $iv, (int) $seq + \strlen($data)), 53) as $chunk) {
                $sign .= self::rsa($chunk, $pem);
            }
            $response = $this->post($base, 2, 1, $token, "sign=$sign\r\ndata=$data");
            $text = str_starts_with($response, '0000') ? $response : (string) openssl_decrypt((string) base64_decode($response, true), 'aes-128-cbc', $key, \OPENSSL_RAW_DATA, $iv);
        } finally {
            try {
                $this->post($base, 11, 0, $token);
            } catch (SourceUnavailableException) {
                // Déjà en échec : l'erreur d'origine compte davantage.
            }
        }

        return new ApSnapshot(($this->clock)(), [], ['wlan2g' => 2.4, 'wlan5g' => 5.0], [], self::stations($text));
    }

    /**
     * @return list<array{mac: string, radio: string, signal: null, inactive: int}>
     */
    private static function stations(string $text): array
    {
        if (1 !== preg_match('/id '.preg_quote(self::DEVICES, '/').'\r?\n(.*?)(?=\r?\nid \d+\||$)/s', $text, $m)) {
            throw new SourceUnavailableException('Connexion à l’Archer refusée ou réponse illisible : vérifier TPLINK_PASSWORD');
        }
        $devices = [];
        foreach (self::lines($m[1]) as $line) {
            $parts = explode(' ', $line, 3);
            if (\count($parts) >= 2 && ctype_digit($parts[1])) {
                $devices[(int) $parts[1]][$parts[0]] = $parts[2] ?? '';
            }
        }

        $stations = [];
        foreach ($devices as $d) {
            $radio = self::BANDS[(int) ($d['type'] ?? -1)] ?? null;
            if ('1' === ($d['online'] ?? '') && null !== $radio && isset($d['mac'])) {
                $stations[] = ['mac' => strtoupper(str_replace('-', ':', $d['mac'])), 'radio' => $radio, 'signal' => null, 'inactive' => 0];
            }
        }

        return $stations;
    }

    private function post(string $base, int $code, int $asyn, ?string $token = null, ?string $body = null): string
    {
        $url = $base.'?code='.$code.'&asyn='.$asyn.(null !== $token ? '&id='.$token : '');
        try {
            $response = $this->http->request('POST', $url, ['body' => $body ?? '', 'timeout' => self::TIMEOUT, 'max_duration' => self::TIMEOUT * 2]);
            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException('Archer injoignable : '.$e->getMessage(), previous: $e);
        }
        // La toute première requête répond parfois en erreur HTTP avec les valeurs attendues.
        if ($status >= 400 && !(2 === $code && null === $token)) {
            throw new SourceUnavailableException(\sprintf('Archer : erreur HTTP %d (code %d), vérifier TPLINK_PASSWORD', $status, $code));
        }

        return $content;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        return preg_split('/\r?\n/', trim($text)) ?: [];
    }

    private static function rsa(string $plain, string $pem): string
    {
        if (!openssl_public_encrypt($plain, $out, $pem, \OPENSSL_PKCS1_PADDING)) {
            throw new SourceUnavailableException('Archer : chiffrement RSA impossible');
        }

        return bin2hex($out);
    }

    /**
     * Clé publique PEM à partir du module et de l'exposant en hexadécimal
     * (SubjectPublicKeyInfo, encodé en DER à la main : pas d'ext-gmp).
     */
    private static function publicKey(string $nHex, string $eHex): string
    {
        $int = static function (string $hex): string {
            $bytes = (string) hex2bin(str_pad(ltrim($hex, '0'), (int) ceil(\strlen(ltrim($hex, '0')) / 2) * 2, '0', \STR_PAD_LEFT));
            if (\ord($bytes[0]) > 0x7F) {
                $bytes = "\x00".$bytes; // entier positif
            }

            return self::der(0x02, $bytes);
        };
        $rsaKey = self::der(0x30, $int($nHex).$int($eHex));
        $algorithm = self::der(0x30, (string) hex2bin('06092a864886f70d0101010500')); // rsaEncryption, NULL
        $spki = self::der(0x30, $algorithm.self::der(0x03, "\x00".$rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function der(int $tag, string $value): string
    {
        $length = \strlen($value);
        if ($length < 0x80) {
            return \chr($tag).\chr($length).$value;
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return \chr($tag).\chr(0x80 | \strlen($bytes)).$bytes.$value;
    }
}
