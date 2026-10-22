<?php

namespace App\Card\Image;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signe les adresses d'images que le dashboard relaie : le relais ne sert que
 * ce que le serveur a lui-même produit, jamais un chemin choisi par le navigateur.
 */
final readonly class ImageUrlSigner
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        #[\SensitiveParameter]
        private string $secret,
    ) {
    }

    public function url(string $cardId, string $path): string
    {
        return \sprintf('/api/cards/%s/image?%s', rawurlencode($cardId), http_build_query(['path' => $path, 'sig' => $this->sign($cardId, $path)]));
    }

    public function verify(string $cardId, string $path, string $signature): bool
    {
        return hash_equals($this->sign($cardId, $path), $signature);
    }

    private function sign(string $cardId, string $path): string
    {
        return substr(hash_hmac('sha256', $cardId."\n".$path, $this->secret), 0, 32);
    }
}
