<?php

namespace App\Source\Ultracc;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * API « storage/traffic » d'Ultra.cc (script non officiel installé en SSH sur
 * le service, docs.ultra.cc/unofficial-ssh-utilities/storagetraffic-api-endpoint).
 * Route réelle : /ultra-api/total-stats (avec un tiret, la doc dit total_stats).
 */
final readonly class UltraccClient
{
    /** Unités de `quota -s` : binaires (le panneau d'Ultra.cc affiche des Go décimaux). */
    private const UNITS = ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(ULTRACC_API_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
    ) {
    }

    /**
     * @param string $serviceUrl n'importe quelle adresse du service : seuls le schéma et l'hôte comptent
     */
    public function stats(string $serviceUrl): UltraccStats
    {
        if ('' === $this->token) {
            throw new SourceUnavailableException('Jeton de l\'API Ultra.cc absent (ULTRACC_API_TOKEN)');
        }

        $url = \sprintf('%s://%s/ultra-api/total-stats', parse_url($serviceUrl, \PHP_URL_SCHEME) ?? 'https', parse_url($serviceUrl, \PHP_URL_HOST));

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                throw new SourceUnavailableException(match ($status) {
                    401 => 'Jeton de l\'API Ultra.cc refusé (401), vérifier ULTRACC_API_TOKEN',
                    404 => 'API Ultra.cc introuvable (404) : script Ultra-API installé sur la seedbox ?',
                    default => \sprintf('L\'API Ultra.cc a répondu %d', $status),
                });
            }
            $info = $response->toArray()['service_stats_info'] ?? null;
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('API Ultra.cc injoignable : %s', $e->getMessage()), previous: $e);
        }

        if (!\is_array($info) || !isset($info['used_storage_value'], $info['total_storage_value'], $info['free_storage_bytes'], $info['traffic_used_percentage'], $info['last_traffic_reset'], $info['next_traffic_reset'])) {
            throw new SourceUnavailableException('Réponse inattendue de l\'API Ultra.cc');
        }

        $utc = new \DateTimeZone('UTC');

        return new UltraccStats(
            usedBytes: self::toBytes($info['used_storage_value'], $info['used_storage_unit'] ?? 'G'),
            totalBytes: self::toBytes($info['total_storage_value'], $info['total_storage_unit'] ?? 'G'),
            freeBytes: (int) $info['free_storage_bytes'],
            trafficUsedPercent: round((float) $info['traffic_used_percentage'], 2),
            lastTrafficReset: new \DateTimeImmutable($info['last_traffic_reset'], $utc),
            nextTrafficReset: new \DateTimeImmutable($info['next_traffic_reset'], $utc),
        );
    }

    private static function toBytes(int|float $value, string $unit): int
    {
        $factor = self::UNITS[strtoupper($unit)] ?? throw new SourceUnavailableException(\sprintf('Unité de stockage inconnue « %s » dans l\'API Ultra.cc', $unit));

        return (int) round($value * $factor);
    }
}
