<?php

namespace App\Tests\Card\Type;

use App\Card\Type\EnergyCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\HomeAssistant\HomeAssistantClient;
use App\Source\SourceUnavailableException;
use App\Tests\Source\HomeAssistant\FakeHomeAssistant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class EnergyCardTest extends TestCase
{
    private const NOW = '2026-11-12 14:07:00';

    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'maison' => new ServiceDefinition('maison', 'Home Assistant', url: 'https://ha.example', check: 'http://ha:8123', icon: 'sh-home-assistant'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    /** Heure de Paris → horodatage HA (millisecondes). */
    private static function ms(string $at): int
    {
        return (new \DateTimeImmutable($at, new \DateTimeZone('Europe/Paris')))->getTimestamp() * 1000;
    }

    /**
     * @param array<string, mixed>|null $prefs
     */
    private function fake(?array $prefs = null, bool $down = false): FakeHomeAssistant
    {
        $prefs ??= ['energy_sources' => [
            ['type' => 'grid', 'stat_energy_from' => 'sensor.compteur', 'entity_energy_price' => null, 'number_energy_price' => 0.2, 'stat_energy_to' => null],
            ['type' => 'solar', 'stat_energy_from' => 'sensor.panneaux_energie'],
        ]];

        return new FakeHomeAssistant(static fn (array $c): mixed => match ($c['type']) {
            'energy/get_prefs' => $prefs,
            'get_states' => [
                ['entity_id' => 'sensor.panneaux_puissance', 'state' => '412.5', 'attributes' => ['unit_of_measurement' => 'W']],
                ['entity_id' => 'sensor.compteur_puissance', 'state' => '630', 'attributes' => ['unit_of_measurement' => 'VA']],
                ['entity_id' => 'sensor.prix', 'state' => '0.25', 'attributes' => []],
                ['entity_id' => 'sensor.en_panne', 'state' => 'unavailable', 'attributes' => []],
            ],
            'recorder/statistics_during_period' => 'hour' === $c['period'] ? [
                'sensor.compteur' => [
                    ['start' => self::ms('2026-11-12 12:00'), 'end' => self::ms('2026-11-12 13:00'), 'change' => 0.6],
                    ['start' => self::ms('2026-11-12 13:00'), 'end' => self::ms('2026-11-12 14:00'), 'change' => 0.3],
                ],
                'sensor.panneaux_energie' => [
                    ['start' => self::ms('2026-11-12 13:00'), 'end' => self::ms('2026-11-12 14:00'), 'change' => 0.4004],
                ],
            ] : [
                'sensor.compteur' => [
                    ['start' => self::ms('2026-11-11 00:00'), 'end' => self::ms('2026-11-12 00:00'), 'change' => 9.5],
                    ['start' => self::ms('2026-11-12 00:00'), 'end' => self::ms('2026-11-13 00:00'), 'change' => 4.0],
                ],
                'sensor.panneaux_energie' => [
                    ['start' => self::ms('2026-11-11 00:00'), 'end' => self::ms('2026-11-12 00:00'), 'change' => 2.25],
                    ['start' => self::ms('2026-11-12 00:00'), 'end' => self::ms('2026-11-13 00:00'), 'change' => 1.5],
                ],
            ],
        }, $down);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(EnergyCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('e', 'energy', null, 2, 60, $resolver->resolve(['service' => 'maison', ...$options]), 2);
    }

    private function card(FakeHomeAssistant $fake): EnergyCard
    {
        return new EnergyCard(new HomeAssistantClient($fake, 'jeton'), self::NOW);
    }

    public function testOptions(): void
    {
        $card = $this->card($this->fake());
        self::assertEquals(['service' => 'maison', 'solar_power' => null, 'grid_power' => null], $this->instance($card)->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($card, ['service' => 'sans-url']);
    }

    public function testRefusesSomethingElseThanASensor(): void
    {
        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card($this->fake()), ['solar_power' => 'switch.prise']);
    }

    public function testDescribe(): void
    {
        $card = $this->card($this->fake());

        self::assertSame(
            ['title' => 'Énergie', 'url' => 'https://ha.example/energy'],
            $card->describe($this->instance($card), $this->dashboard),
        );
    }

    public function testFetch(): void
    {
        $fake = $this->fake();
        $card = $this->card($fake);
        $data = $card->fetch($this->instance($card, ['solar_power' => 'sensor.panneaux_puissance', 'grid_power' => 'sensor.compteur_puissance']), $this->dashboard);

        self::assertSame(['solar' => 412.5, 'grid' => 630.0], $data['now']);
        self::assertSame(0.2, $data['price']);
        self::assertEqualsWithDelta(['solar' => 1.5, 'grid' => 4.0, 'cost' => 0.8, 'saved' => 0.3], $data['today'], 1e-9);
        // Puissance moyenne de chaque heure : kWh × 1 000 = W.
        self::assertSame([
            'at' => ['2026-11-12T12:00:00+01:00', '2026-11-12T13:00:00+01:00'],
            'solar' => [null, 400.0],
            'grid' => [600.0, 300.0],
        ], $data['day']);
        self::assertSame([
            'at' => ['2026-11-11', '2026-11-12'],
            'solar' => [2.25, 1.5],
            'grid' => [9.5, 4.0],
        ], $data['month']);

        $stats = array_values(array_filter($fake->commands, static fn (array $c): bool => 'recorder/statistics_during_period' === $c['type']));
        self::assertSame(['sensor.compteur', 'sensor.panneaux_energie'], $stats[0]['statistic_ids']);
        self::assertSame('2026-11-11T14:00:00+01:00', $stats[0]['start_time']);
        self::assertSame('2026-11-12T14:00:00+01:00', $stats[0]['end_time']);
        self::assertSame('hour', $stats[0]['period']);
        self::assertSame('2026-10-14T00:00:00+02:00', $stats[1]['start_time']);
        self::assertSame('day', $stats[1]['period']);
    }

    public function testPriceFromAnEntityAndUnavailableSensors(): void
    {
        $card = $this->card($this->fake(['energy_sources' => [
            ['type' => 'grid', 'stat_energy_from' => 'sensor.compteur', 'entity_energy_price' => 'sensor.prix', 'number_energy_price' => null],
        ]]));
        $data = $card->fetch($this->instance($card, ['grid_power' => 'sensor.en_panne']), $this->dashboard);

        self::assertSame(['solar' => null, 'grid' => null], $data['now']);
        self::assertSame(0.25, $data['price']);
        self::assertNull($data['today']['solar']);
        self::assertNull($data['today']['saved']);
        self::assertEqualsWithDelta(1.0, $data['today']['cost'], 1e-9);
        self::assertSame([null, null], $data['month']['solar']);
    }

    public function testNoEnergyConfiguration(): void
    {
        $card = $this->card($this->fake(['energy_sources' => []]));

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Tableau Énergie de Home Assistant non configuré');
        $card->fetch($this->instance($card), $this->dashboard);
    }

    public function testHomeAssistantDown(): void
    {
        $card = $this->card($this->fake(down: true));

        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessage('Home Assistant injoignable');
        $card->fetch($this->instance($card), $this->dashboard);
    }
}
