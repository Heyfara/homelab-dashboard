<?php

namespace App\Source\Ntfy;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Lecture des messages gardés par le serveur ntfy du homelab (rôle Ansible
 * service-ntfy), avec le jeton d'un compte qui ne peut que lire. Le serveur
 * garde lui-même l'historique (cache-duration) : on relit tout à chaque fois.
 */
final readonly class NtfyClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%env(NTFY_TOKEN)%')]
        #[\SensitiveParameter]
        private string $token,
    ) {
    }

    /**
     * @param list<string> $topics
     *
     * @return list<NtfyMessage> le plus récent d'abord
     */
    public function messages(string $baseUrl, array $topics): array
    {
        if ([] === $topics) {
            return [];
        }
        if ('' === $this->token) {
            throw new SourceUnavailableException('Jeton ntfy absent (NTFY_TOKEN)');
        }

        $url = \sprintf('%s/%s/json?poll=1&since=all', rtrim($baseUrl, '/'), implode(',', array_map('rawurlencode', $topics)));
        try {
            $response = $this->httpClient->request('GET', $url, ['headers' => ['Authorization' => 'Bearer '.$this->token], 'timeout' => 5]);
            $status = $response->getStatusCode();
            if (401 === $status || 403 === $status) {
                throw new SourceUnavailableException(\sprintf('Jeton ntfy refusé (%d), vérifier NTFY_TOKEN', $status));
            }
            if ($status >= 300) {
                throw new SourceUnavailableException(\sprintf('ntfy a répondu %d', $status));
            }
            $body = $response->getContent();
        } catch (ExceptionInterface $e) {
            throw new SourceUnavailableException(\sprintf('ntfy injoignable : %s', $e->getMessage()), 0, $e);
        }

        $messages = [];
        foreach (explode("\n", $body) as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event) || 'message' !== ($event['event'] ?? null) || !isset($event['id'], $event['time'], $event['topic'])) {
                continue;
            }
            $messages[] = new NtfyMessage(
                (string) $event['id'],
                new \DateTimeImmutable('@'.(int) $event['time']),
                (string) $event['topic'],
                isset($event['title']) && '' !== $event['title'] ? (string) $event['title'] : null,
                (string) ($event['message'] ?? ''),
                (int) ($event['priority'] ?? 3),
                array_values(array_map('strval', (array) ($event['tags'] ?? []))),
                isset($event['click']) && '' !== $event['click'] ? (string) $event['click'] : null,
            );
        }
        usort($messages, static fn (NtfyMessage $a, NtfyMessage $b): int => $b->time <=> $a->time);

        return $messages;
    }
}
