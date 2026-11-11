<?php

namespace App\Card;

/**
 * Réponse de GET /api/cards/{id}.
 */
final readonly class CardResult implements \JsonSerializable
{
    public const OK = 'ok';
    public const ERROR = 'error';

    /**
     * @param array<string, mixed>|null $data
     */
    private function __construct(
        public string $status,
        public ?array $data,
        public ?string $error,
        /** Moment où les données ont été lues à la source (pas celui de la réponse) */
        public \DateTimeImmutable $fetchedAt,
        /** Valeur plus vieille que le `refresh` de la carte, relue en arrière-plan : le front la redemande bientôt */
        public bool $stale = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(array $data, \DateTimeImmutable $fetchedAt, bool $stale = false): self
    {
        return new self(self::OK, $data, null, $fetchedAt, $stale);
    }

    public static function error(string $message): self
    {
        return new self(self::ERROR, null, $message, new \DateTimeImmutable());
    }

    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'data' => $this->data,
            'error' => $this->error,
            'fetchedAt' => $this->fetchedAt->format(\DATE_ATOM),
            'stale' => $this->stale,
        ];
    }
}
