<?php

namespace App\Profile;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Profile;

/**
 * Lieu de la météo d'un profil. Une carte `weather` avec `location: profile`
 * prend le lieu choisi dans « Ma config » ; tant qu'il n'y en a pas, celui du
 * YAML (place, latitude, longitude) sert de lieu par défaut.
 */
final readonly class WeatherLocation
{
    public const SETTING = 'weather';
    public const PROFILE = 'profile';

    public function __construct(private ProfileSettings $settings)
    {
    }

    /**
     * Lieu affiché par la carte, null si ni le profil ni le YAML n'en donnent.
     *
     * @return array{place: ?string, latitude: float|int, longitude: float|int, custom: bool, updatedAt: ?string}|null
     */
    public function forCard(CardInstance $card): ?array
    {
        $o = $card->options;
        $saved = self::PROFILE === ($o['location'] ?? null) && null !== $card->profile ? $this->settings->get($card->profile, self::SETTING) : null;
        if (null !== $saved) {
            return ['place' => (string) $saved['place'], 'latitude' => (float) $saved['latitude'], 'longitude' => (float) $saved['longitude'], 'custom' => true, 'updatedAt' => $saved['updatedAt'] ?? null];
        }
        if (null === ($o['latitude'] ?? null) || null === ($o['longitude'] ?? null)) {
            return null;
        }

        return ['place' => $o['place'] ?? null, 'latitude' => $o['latitude'], 'longitude' => $o['longitude'], 'custom' => false, 'updatedAt' => null];
    }

    /**
     * Section météo de « Ma config » : le lieu de la carte, ou des valeurs vides
     * s'il n'y en a pas encore (la section s'affiche quand même).
     *
     * @return array{place: ?string, latitude: float|int|null, longitude: float|int|null, custom: bool, updatedAt: ?string}
     */
    public function view(CardInstance $card): array
    {
        return $this->forCard($card) ?? ['place' => null, 'latitude' => null, 'longitude' => null, 'custom' => false, 'updatedAt' => null];
    }

    /** La carte météo du profil dont le lieu se choisit dans « Ma config » (la première), sinon null. */
    public function configurableCard(Profile $profile): ?CardInstance
    {
        foreach ($profile->cards() as $card) {
            if ('weather' === $card->type && self::PROFILE === ($card->options['location'] ?? null)) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Coordonnées arrondies à 0,01° (environ 1 km) : assez pour la météo, sans
     * garder d'adresse précise.
     *
     * @param array<string, mixed> $input place, latitude, longitude
     *
     * @throws InvalidSettingException lieu incomplet ou hors limites, message pour l'écran
     */
    public function save(string $profile, array $input): void
    {
        $place = trim(\is_string($input['place'] ?? null) ? $input['place'] : '');
        if ('' === $place) {
            throw new InvalidSettingException('Donner un nom au lieu.');
        }
        if (mb_strlen($place) > 80) {
            throw new InvalidSettingException('Nom du lieu trop long (80 caractères au plus).');
        }

        $this->settings->set($profile, self::SETTING, [
            'place' => $place,
            'latitude' => self::coordinate($input['latitude'] ?? null, 90, 'Latitude attendue : un nombre entre -90 et 90.'),
            'longitude' => self::coordinate($input['longitude'] ?? null, 180, 'Longitude attendue : un nombre entre -180 et 180.'),
        ]);
    }

    /** Oublie le lieu choisi : retour à celui du YAML. */
    public function reset(string $profile): void
    {
        $this->settings->remove($profile, self::SETTING);
    }

    private static function coordinate(mixed $value, int $limit, string $error): float
    {
        if (!is_numeric($value) || abs((float) $value) > $limit) {
            throw new InvalidSettingException($error);
        }

        return round((float) $value, 2);
    }
}
