<?php

namespace App\Tests\Profile;

use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Profile;
use App\Dashboard\Model\Section;
use App\Profile\InvalidSettingException;
use App\Profile\ProfileFiles;
use App\Profile\ProfileSettings;
use App\Profile\WeatherLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeatherLocationTest extends TestCase
{
    private string $dir;
    private WeatherLocation $location;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/weather-location-'.bin2hex(random_bytes(4));
        $this->location = new WeatherLocation(new ProfileSettings(new ProfileFiles($this->dir), '2026-10-24T10:00:00+00:00'));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/{,.}*.*', \GLOB_BRACE) ?: []);
        @rmdir($this->dir);
    }

    private static function card(array $options, ?string $profile = 'zoe', string $type = 'weather'): CardInstance
    {
        return new CardInstance('c', $type, null, 2, 1800, $options + ['place' => null, 'latitude' => null, 'longitude' => null, 'location' => null], 2, $profile);
    }

    public function testSavedPlaceIsRoundedAndTrimmed(): void
    {
        $this->location->save('zoe', ['place' => '  Brest ', 'latitude' => 48.39046, 'longitude' => '-4.48628']);

        self::assertSame(
            ['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49, 'custom' => true, 'updatedAt' => '2026-10-24T10:00:00+00:00'],
            $this->location->forCard(self::card(['location' => 'profile'])),
            'arrondi à 0,01° (environ 1 km) : assez pour la météo, sans adresse précise',
        );
    }

    public function testYamlUntilChosenThenAgainAfterReset(): void
    {
        $card = self::card(['place' => 'Maison', 'latitude' => 48.85, 'longitude' => 2.35, 'location' => 'profile']);
        $yaml = ['place' => 'Maison', 'latitude' => 48.85, 'longitude' => 2.35, 'custom' => false, 'updatedAt' => null];
        self::assertSame($yaml, $this->location->forCard($card));

        $this->location->save('zoe', ['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49]);
        self::assertSame('Brest', $this->location->forCard($card)['place']);

        $this->location->reset('zoe');
        self::assertSame($yaml, $this->location->forCard($card));
    }

    public function testNothingWithoutYamlNorChoice(): void
    {
        self::assertNull($this->location->forCard(self::card(['location' => 'profile'])));
        self::assertSame(['place' => null, 'latitude' => null, 'longitude' => null, 'custom' => false, 'updatedAt' => null], $this->location->view(self::card(['location' => 'profile'])), 'la section de « Ma config » s\'affiche quand même');
    }

    public function testSavedPlaceUsedOnlyByCardsThatAskForIt(): void
    {
        $this->location->save('zoe', ['place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49]);

        self::assertSame('Maison', $this->location->forCard(self::card(['place' => 'Maison', 'latitude' => 48.85, 'longitude' => 2.35]))['place']);
    }

    public function testConfigurableCardOfAProfile(): void
    {
        $fixed = self::card(['place' => 'Maison', 'latitude' => 48.85, 'longitude' => 2.35]);
        $chosen = self::card(['place' => 'Maison', 'latitude' => 48.85, 'longitude' => 2.35, 'location' => 'profile']);
        $other = self::card(['location' => 'profile'], type: 'service');

        self::assertSame($chosen, $this->location->configurableCard(new Profile('zoe', 'Zoe', 'slate', [new Section('S', 3, [$other, $fixed, $chosen])])));
        self::assertNull($this->location->configurableCard(new Profile('zoe', 'Zoe', 'slate', [new Section('S', 3, [$fixed])])));
    }

    public static function invalid(): iterable
    {
        yield 'nom vide' => [['place' => '  ', 'latitude' => 48, 'longitude' => 2], 'Donner un nom au lieu.'];
        yield 'nom trop long' => [['place' => str_repeat('a', 81), 'latitude' => 48, 'longitude' => 2], 'Nom du lieu trop long (80 caractères au plus).'];
        yield 'latitude absente' => [['place' => 'X', 'longitude' => 2], 'Latitude attendue : un nombre entre -90 et 90.'];
        yield 'latitude hors limites' => [['place' => 'X', 'latitude' => 91, 'longitude' => 2], 'Latitude attendue : un nombre entre -90 et 90.'];
        yield 'longitude texte' => [['place' => 'X', 'latitude' => 48, 'longitude' => 'est'], 'Longitude attendue : un nombre entre -180 et 180.'];
        yield 'longitude hors limites' => [['place' => 'X', 'latitude' => 48, 'longitude' => -181], 'Longitude attendue : un nombre entre -180 et 180.'];
    }

    #[DataProvider('invalid')]
    public function testInvalidPlaceRefusedWithTheReason(array $input, string $message): void
    {
        try {
            $this->location->save('zoe', $input);
            self::fail('lieu accepté');
        } catch (InvalidSettingException $e) {
            self::assertSame($message, $e->getMessage());
        }
        self::assertNull($this->location->forCard(self::card(['location' => 'profile'])));
    }
}
