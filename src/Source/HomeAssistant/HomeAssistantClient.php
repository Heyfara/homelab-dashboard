<?php

namespace App\Source\HomeAssistant;

use App\Source\SourceUnavailableException;
use App\Source\WebSocket\WebSocket;
use App\Source\WebSocket\WebSocketConnector;
use App\Source\WebSocket\WebSocketException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * API WebSocket de Home Assistant, en question-réponse : une connexion par
 * lecture (authentification, commandes, fermeture). Partagé par toutes les
 * cartes de Home Assistant. Pas d'abonnement (subscribe_*) : le temps réel
 * demanderait un processus qui reste connecté, hors de PHP-FPM.
 *
 * Le jeton (HOME_ASSISTANT_TOKEN) est celui d'un utilisateur HA dédié, non
 * administrateur ; il ne quitte jamais le serveur.
 */
final readonly class HomeAssistantClient
{
    public function __construct(
        private WebSocketConnector $connector,
        #[Autowire('%env(HOME_ASSISTANT_TOKEN)%')]
        private string $token = '',
    ) {
    }

    /**
     * Envoie les commandes ensemble et renvoie le résultat de chacune, sous sa clé.
     *
     * @param array<string, array<string, mixed>> $commands clé => commande, sans `id` (ex. ['type' => 'get_states'])
     *
     * @return array<string, mixed>
     *
     * @throws SourceUnavailableException
     */
    public function query(string $baseUrl, array $commands): array
    {
        if ('' === $this->token) {
            throw new SourceUnavailableException('Jeton Home Assistant manquant (HOME_ASSISTANT_TOKEN).');
        }

        try {
            $ws = $this->connector->open(self::webSocketUrl($baseUrl));
        } catch (WebSocketException $e) {
            throw new SourceUnavailableException('Home Assistant injoignable : '.$e->getMessage(), previous: $e);
        }

        try {
            $this->authenticate($ws);

            $keys = [];
            $id = 0;
            foreach ($commands as $key => $command) {
                $keys[++$id] = $key;
                $ws->send(json_encode(['id' => $id] + $command, \JSON_THROW_ON_ERROR));
            }

            $results = [];
            while (\count($results) < \count($keys)) {
                $message = self::decode($ws->receive());
                if ('result' !== ($message['type'] ?? null) || !isset($keys[$message['id'] ?? null])) {
                    continue; // événement ou réponse étrangère
                }
                $key = $keys[$message['id']];
                if (true !== ($message['success'] ?? false)) {
                    $error = $message['error'] ?? [];
                    throw new SourceUnavailableException(\sprintf(
                        'Home Assistant refuse « %s » : %s%s',
                        $commands[$key]['type'] ?? '?',
                        $error['message'] ?? 'erreur inconnue',
                        'unauthorized' === ($error['code'] ?? null) ? ' (commande réservée aux administrateurs ?)' : '',
                    ));
                }
                $results[$key] = $message['result'] ?? null;
            }

            return array_replace(array_intersect_key($commands, $results), $results);
        } catch (WebSocketException $e) {
            throw new SourceUnavailableException('Home Assistant injoignable : '.$e->getMessage(), previous: $e);
        } finally {
            $ws->close();
        }
    }

    private function authenticate(WebSocket $ws): void
    {
        $hello = self::decode($ws->receive());
        if ('auth_required' !== ($hello['type'] ?? null)) {
            throw new SourceUnavailableException('Réponse inattendue de Home Assistant à la connexion.');
        }
        $ws->send(json_encode(['type' => 'auth', 'access_token' => $this->token], \JSON_THROW_ON_ERROR));
        $answer = self::decode($ws->receive());
        if ('auth_ok' !== ($answer['type'] ?? null)) {
            throw new SourceUnavailableException('Jeton Home Assistant refusé (HOME_ASSISTANT_TOKEN) : '.($answer['message'] ?? 'raison inconnue'));
        }
    }

    /** https://hôte → wss://hôte/api/websocket, http → ws. */
    private static function webSocketUrl(string $baseUrl): string
    {
        return preg_replace('#^http(s?)://#', 'ws$1://', rtrim($baseUrl, '/')).'/api/websocket';
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SourceUnavailableException('Réponse illisible de Home Assistant.', previous: $e);
        }

        return \is_array($data) ? $data : [];
    }
}
