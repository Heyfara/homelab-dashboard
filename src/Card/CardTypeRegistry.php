<?php

namespace App\Card;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Tous les types de carte, indexés par l'identifiant de leur #[AsCard].
 */
final class CardTypeRegistry
{
    /**
     * @param ServiceLocator<CardTypeInterface> $types
     */
    public function __construct(
        #[AutowireLocator(AsCard::TAG, indexAttribute: 'type')]
        private readonly ServiceLocator $types,
    ) {
    }

    public function has(string $type): bool
    {
        return $this->types->has($type);
    }

    public function get(string $type): CardTypeInterface
    {
        if (!$this->types->has($type)) {
            throw new UnknownCardTypeException($type, $this->types());
        }

        return $this->types->get($type);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        $types = array_keys($this->types->getProvidedServices());
        sort($types);

        return $types;
    }

    /**
     * Flux supplémentaires de la carte et leur rythme (voir CardFeedsInterface).
     *
     * @return array<string, int>
     */
    public function feeds(CardInstance $card): array
    {
        $type = $this->get($card->type);

        return $type instanceof CardFeedsInterface ? $type->feeds($card) : [];
    }

    /**
     * Options de la carte après validation et valeurs par défaut de son type.
     *
     * @return array<string, mixed>
     *
     * @throws UnknownCardTypeException
     * @throws \Symfony\Component\OptionsResolver\Exception\ExceptionInterface
     */
    public function resolveOptions(CardInstance $card, Dashboard $dashboard): array
    {
        $resolver = new OptionsResolver();
        $this->get($card->type)->configureOptions($resolver, $dashboard);

        return $resolver->resolve($card->options);
    }
}
