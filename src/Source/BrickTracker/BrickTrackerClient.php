<?php

namespace App\Source\BrickTracker;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * BrickTracker (1.4) n'a pas d'API de lecture : on lit ses pages HTML, en
 * s'appuyant sur ce qui bouge peu (attributs data-set-id, badges alt="Theme",
 * libellés de la page Statistiques). Un changement de gabarit donne une
 * erreur explicite plutôt que des chiffres faux.
 */
final readonly class BrickTrackerClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        /** Autorité de certification à approuver (mkcert en dev), sinon celles du système */
        #[Autowire('%env(TLS_CA_FILE)%')]
        private ?string $caFile = null,
    ) {
    }

    /**
     * Derniers sets ajoutés, tels que la page d'accueil les montre.
     *
     * @return list<BrickSet>
     */
    public function latestSets(string $baseUrl): array
    {
        $sets = [];
        foreach ($this->page($baseUrl, '/')->querySelectorAll('[data-set-id]') as $card) {
            $badge = static fn (string $alt): ?\Dom\Element => $card->querySelector(\sprintf('.badge[alt="%s"]', $alt));
            $number = (string) $card->getAttribute('data-rebrickable-id');

            $sets[] = new BrickSet(
                (string) $card->getAttribute('data-set-id'),
                $number,
                trim((string) $card->querySelector('.card-header a')?->getAttribute('title')),
                $badge('Theme')?->getAttribute('title') ?: null,
                self::number($badge('Parts')?->textContent),
                self::number($badge('Minifigures')?->textContent),
                (string) ($card->querySelector('img.card-last-img')?->getAttribute('src') ?? \sprintf('/data/sets/%s.jpg', $number)),
            );
        }

        return $sets;
    }

    public function statistics(string $baseUrl): BrickStats
    {
        $page = $this->page($baseUrl, '/statistics/');
        $value = static function (string $label) use ($page): int {
            foreach ($page->querySelectorAll('small') as $small) {
                if ($label === trim($small->textContent)) {
                    return self::number($small->previousElementSibling?->textContent);
                }
            }
            throw new SourceUnavailableException(\sprintf('« %s » introuvable dans la page Statistiques de BrickTracker : mise en page changée ?', $label));
        };

        return new BrickStats($value('Total Sets'), $value('Total Parts'), $value('Minifigures'), $value('Missing Parts'), $value('Damaged Parts'));
    }

    /** « 17,480 » → 17480 ; absent → 0 */
    private static function number(?string $text): int
    {
        return (int) preg_replace('/\D/', '', (string) $text);
    }

    private function page(string $baseUrl, string $path): \Dom\HTMLDocument
    {
        $options = ['timeout' => 5, 'max_redirects' => 0];
        if (null !== $this->caFile && '' !== $this->caFile) {
            $options['cafile'] = $this->caFile;
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($baseUrl, '/').$path, $options);
            if ($response->getStatusCode() >= 300 && $response->getStatusCode() < 400) {
                throw new SourceUnavailableException(\sprintf('BrickTracker demande une connexion pour %s : la carte ne lit que des pages publiques', $path));
            }
            $html = $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('BrickTracker injoignable : %s', $e->getMessage()), previous: $e);
        }

        return \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);
    }
}
