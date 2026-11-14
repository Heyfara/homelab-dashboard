<?php

namespace App\Tests\Card\Type;

use App\Card\Type\TemperaturesCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\HomeAssistant\HomeAssistantClient;
use App\Tests\Source\HomeAssistant\FakeHomeAssistant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class TemperaturesCardTest extends TestCase
{
    private const NOW = '2026-11-12 14:07:00';

    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'maison' => new ServiceDefinition('maison', 'Home Assistant', url: 'https://ha.example', icon: 'sh-home-assistant'),
        ], []);
    }

    private static function ms(string $at): int
    {
        return (new \DateTimeImmutable($at, new \DateTimeZone('Europe/Paris')))->getTimestamp() * 1000;
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private static function state(string $id, string $state, array $attributes = []): array
    {
        return ['entity_id' => $id, 'state' => $state, 'attributes' => $attributes];
    }

    private function fake(): FakeHomeAssistant
    {
        $temperature = ['device_class' => 'temperature', 'unit_of_measurement' => '°C', 'state_class' => 'measurement'];
        $humidity = ['device_class' => 'humidity', 'unit_of_measurement' => '%'];

        return new FakeHomeAssistant(static fn (array $c): mixed => match ($c['type']) {
            'config/area_registry/list' => [
                ['area_id' => 'salon', 'name' => 'Salon'],
                ['area_id' => 'chambre_a', 'name' => 'Chambre A'],
                ['area_id' => 'chambre_b', 'name' => 'Chambre B'],
                ['area_id' => 'jardin', 'name' => 'Jardin'],
                ['area_id' => 'garage', 'name' => 'Garage'],
            ],
            'config/device_registry/list' => [
                ['id' => 'd-salon', 'area_id' => 'salon'],
                ['id' => 'd-thermo-a', 'area_id' => 'chambre_a'],
                ['id' => 'd-radiateur-a', 'area_id' => 'chambre_a'],
                ['id' => 'd-thermo-b', 'area_id' => 'chambre_b'],
                ['id' => 'd-dehors', 'area_id' => 'jardin'],
            ],
            'config/entity_registry/list_for_display' => ['entity_categories' => ['0' => 'config', '1' => 'diagnostic'], 'entities' => [
                ['ei' => 'sensor.salon_temperature', 'di' => 'd-salon'],
                ['ei' => 'sensor.salon_humidite', 'di' => 'd-salon'],
                ['ei' => 'sensor.salon_processeur', 'di' => 'd-salon', 'ec' => 1],
                ['ei' => 'sensor.thermo_a_temperature', 'di' => 'd-thermo-a'],
                ['ei' => 'sensor.thermo_a_humidity', 'di' => 'd-thermo-a'],
                ['ei' => 'climate.radiateur_a', 'di' => 'd-radiateur-a'],
                ['ei' => 'sensor.radiateur_a_local_temperature', 'di' => 'd-radiateur-a'],
                // Pièce donnée à l'entité, pas à l'appareil.
                ['ei' => 'sensor.thermo_b_temperature', 'di' => 'd-thermo-b', 'ai' => 'chambre_b'],
                ['ei' => 'sensor.dehors_temperature', 'di' => 'd-dehors'],
                ['ei' => 'sensor.dehors_humidite', 'di' => 'd-dehors'],
            ]],
            'get_states' => [
                self::state('sensor.salon_temperature', '20.44', $temperature),
                self::state('sensor.salon_humidite', '77', $humidity),
                self::state('sensor.salon_processeur', '45', $temperature),
                self::state('sensor.thermo_a_temperature', '17.6', $temperature),
                self::state('sensor.thermo_a_humidity', '53.8', $humidity),
                self::state('climate.radiateur_a', 'heat', ['current_temperature' => 17.6, 'temperature' => 18, 'hvac_action' => 'heating']),
                self::state('sensor.radiateur_a_local_temperature', '19.5', $temperature),
                self::state('sensor.thermo_b_temperature', 'unavailable', $temperature),
                self::state('sensor.dehors_temperature', '9.1', $temperature),
                self::state('sensor.dehors_humidite', '85', $humidity),
                self::state('sensor.sans_piece_temperature', '30', $temperature),
            ],
            'recorder/statistics_during_period' => [
                'sensor.salon_temperature' => [
                    ['start' => self::ms('2026-11-12 12:00'), 'mean' => 20.04],
                    ['start' => self::ms('2026-11-12 13:00'), 'mean' => 20.36],
                ],
                'sensor.thermo_a_temperature' => [
                    ['start' => self::ms('2026-11-12 13:00'), 'mean' => 17.5],
                ],
            ],
        });
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(TemperaturesCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('t', 'temperatures', null, 2, 300, $resolver->resolve(['service' => 'maison', ...$options]), 2);
    }

    private function card(FakeHomeAssistant $fake): TemperaturesCard
    {
        return new TemperaturesCard(new HomeAssistantClient($fake, 'jeton'), self::NOW);
    }

    public function testOptions(): void
    {
        $card = $this->card($this->fake());
        self::assertEquals(['service' => 'maison', 'areas' => [], 'outdoor' => null], $this->instance($card)->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($card, ['areas' => ['Salon !']]);
    }

    public function testDescribe(): void
    {
        $card = $this->card($this->fake());

        self::assertSame(['title' => 'Températures', 'url' => 'https://ha.example'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testRoomsFoundByArea(): void
    {
        $fake = $this->fake();
        $card = $this->card($fake);
        $data = $card->fetch($this->instance($card, ['outdoor' => 'jardin']), $this->dashboard);

        self::assertSame(['name' => 'Jardin', 'temperature' => 9.1, 'humidity' => 85.0], $data['outdoor']);
        self::assertSame(['2026-11-12T12:00:00+01:00', '2026-11-12T13:00:00+01:00'], $data['at']);
        self::assertSame([
            // Sondes de diagnostic ignorées ; arrondi au dixième.
            ['area' => 'salon', 'name' => 'Salon', 'temperature' => 20.4, 'humidity' => 77.0, 'history' => [20.0, 20.4], 'climate' => null],
            // Le thermomètre de la pièce passe avant la sonde du radiateur.
            ['area' => 'chambre_a', 'name' => 'Chambre A', 'temperature' => 17.6, 'humidity' => 53.8, 'history' => [null, 17.5], 'climate' => ['target' => 18.0, 'action' => 'heating', 'mode' => 'heat']],
            ['area' => 'chambre_b', 'name' => 'Chambre B', 'temperature' => null, 'humidity' => null, 'history' => [null, null], 'climate' => null],
        ], $data['rooms']);

        $stats = array_values(array_filter($fake->commands, static fn (array $c): bool => 'recorder/statistics_during_period' === $c['type']))[0];
        self::assertSame(['sensor.salon_temperature', 'sensor.thermo_a_temperature', 'sensor.thermo_b_temperature'], $stats['statistic_ids']);
        self::assertSame(['mean'], $stats['types']);
        self::assertSame('hour', $stats['period']);
        self::assertSame('2026-11-11T14:00:00+01:00', $stats['start_time']);
    }

    public function testChosenAreasInOrder(): void
    {
        $card = $this->card($this->fake());
        $data = $card->fetch($this->instance($card, ['areas' => ['chambre_a', 'salon', 'garage']]), $this->dashboard);

        // Garage sans sonde : ignoré ; pas d'extérieur demandé.
        self::assertSame(['chambre_a', 'salon'], array_column($data['rooms'], 'area'));
        self::assertNull($data['outdoor']);
    }
}
