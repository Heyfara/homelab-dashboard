<?php

namespace App\Notifications;

use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Profile;
use App\Profile\ProfileFiles;
use App\Source\Ntfy\NtfyClient;
use App\Source\Ntfy\NtfyMessage;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Messages ntfy des sujets suivis par un profil (cloche), avec ce que la
 * personne a déjà lu. Le « lu » est rangé dans `<profil>.notifications.json`
 * (voir ProfileFiles) : identifiant du message → date de lecture.
 */
final readonly class ProfileNotifications
{
    private const FILE = 'notifications';

    /** Les messages sont relus au plus toutes les 15 s, quel que soit le nombre d'onglets ouverts. */
    private const CACHE_SECONDS = 15;

    /** Un « lu » est oublié après ce délai : ntfy a effacé le message depuis (cache-duration de 14 jours). */
    private const KEEP_READ = '-15 days';

    public function __construct(
        private NtfyClient $ntfy,
        private CacheInterface $cache,
        private ProfileFiles $files,
        /** Instant présent (ISO 8601), pour les tests ; sinon maintenant */
        private ?string $now = null,
    ) {
    }

    /**
     * @return array{topics: list<array{id: string, name: string}>, link: ?string, unread: int, messages: list<array<string, mixed>>}
     */
    public function list(Profile $profile, Dashboard $dashboard): array
    {
        $settings = $dashboard->notifications ?? throw new \LogicException('Section notifications absente');
        $topics = $profile->notifications;
        $messages = $this->cache->get(
            'ntfy.'.hash('xxh128', $settings->url.'|'.implode(',', $topics)),
            function (ItemInterface $item) use ($settings, $topics): array {
                $item->expiresAfter(self::CACHE_SECONDS);

                return $this->ntfy->messages($settings->url, $topics);
            },
        );

        $read = $this->read($profile->slug);
        $paris = new \DateTimeZone('Europe/Paris');
        $list = array_map(static fn (NtfyMessage $m): array => [
            'id' => $m->id,
            'time' => $m->time->setTimezone($paris)->format(\DATE_ATOM),
            'topic' => $m->topic,
            'title' => $m->title,
            'message' => $m->message,
            'priority' => $m->priority,
            'tags' => $m->tags,
            'click' => $m->click,
            'read' => isset($read[$m->id]),
        ], $messages);

        return [
            'topics' => array_map(static fn (string $id): array => ['id' => $id, 'name' => $settings->topics[$id] ?? $id], $topics),
            'link' => $settings->link,
            'unread' => \count(array_filter($list, static fn (array $m): bool => !$m['read'])),
            'messages' => $list,
        ];
    }

    /**
     * @param list<string> $ids
     */
    public function markRead(string $profile, array $ids): void
    {
        $now = new \DateTimeImmutable($this->now ?? 'now');
        $forget = $now->modify(self::KEEP_READ)->format(\DATE_ATOM);
        $this->files->update($profile, self::FILE, static function (array $data) use ($ids, $now, $forget): array {
            $read = \is_array($data['read'] ?? null) ? $data['read'] : [];
            foreach ($ids as $id) {
                $read[$id] ??= $now->format(\DATE_ATOM);
            }

            return ['version' => 1, 'read' => array_filter($read, static fn (mixed $at): bool => \is_string($at) && $at >= $forget)];
        });
    }

    /**
     * @return array<string, string> identifiant → date de lecture
     */
    private function read(string $profile): array
    {
        $read = $this->files->read($profile, self::FILE)['read'] ?? [];

        return \is_array($read) ? $read : [];
    }
}
