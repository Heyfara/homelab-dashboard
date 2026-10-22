<?php

namespace App\Source\Jellyfin;

final readonly class JellyfinItem
{
    public function __construct(
        public string $id,
        public string $name,
        /** Movie, Episode, Series, MusicAlbum… */
        public string $type,
        public string $serverId,
        /** Élément dont on affiche l'affiche : la série pour un épisode */
        public string $imageItemId,
        /** Absent si l'élément n'a pas d'affiche */
        public ?string $imageTag,
        public ?int $year = null,
        public ?string $seriesName = null,
        public ?int $season = null,
        public ?int $episode = null,
        public ?\DateTimeImmutable $addedAt = null,
    ) {
    }
}
