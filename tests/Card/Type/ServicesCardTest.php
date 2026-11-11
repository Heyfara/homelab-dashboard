<?php

namespace App\Tests\Card\Type;

use App\Card\Type\ServicesCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Container\ContainerState;
use App\Source\Container\ContainerStatusSource;
use App\Source\Http\HttpProbe;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ServicesCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(
            new Settings(4, 30),
            ['alpha' => new Host('alpha', 'Alpha'), 'beta' => new Host('beta', 'Beta'), 'gamma' => new Host('gamma')],
            [
                'photos' => new ServiceDefinition('photos', 'Photos', 'Galerie', 'https://photos.example', 'http://10.0.0.2:2283', 'sh-immich', 'alpha', 'photos_server'),
                'films' => new ServiceDefinition('films', 'Films', url: 'https://films.example', host: 'alpha', container: 'films'),
                'recettes' => new ServiceDefinition('recettes', 'Recettes', url: 'https://recettes.example', host: 'beta', container: 'recettes'),
                'wiki' => new ServiceDefinition('wiki', 'Wiki', url: 'https://wiki.example', host: 'gamma', container: 'wiki'),
                'note' => new ServiceDefinition('note', 'Note'),
            ],
            [],
        );
    }

    /**
     * États par machine (nom Beszel) ; une exception = Beszel en panne pour cette machine.
     *
     * @param array<string, array<string, ContainerState>|\Throwable> $byHost
     */
    private static function containers(array $byHost): ContainerStatusSource
    {
        return new class($byHost) implements ContainerStatusSource {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private array $byHost)
            {
            }

            public function status(Host $host, string $container): ContainerState
            {
                throw new \LogicException('le lanceur lit tous les containers d’une machine d’un coup');
            }

            public function all(Host $host): array
            {
                $this->calls[] = $host->name;
                $states = $this->byHost[$host->name] ?? [];
                if ($states instanceof \Throwable) {
                    throw $states;
                }

                return $states;
            }
        };
    }

    private static function probe(): HttpProbe
    {
        return new HttpProbe(new MockHttpClient(static fn (string $method, string $url) => match ($url) {
            'https://recettes.example/' => new MockResponse('', ['error' => 'Connection refused']),
            default => new MockResponse('', ['http_code' => 200]),
        }));
    }

    private function instance(array $services): CardInstance
    {
        return new CardInstance('c', 'services', null, 2, 30, ['services' => $services]);
    }

    private function card(?ContainerStatusSource $containers = null): ServicesCard
    {
        return new ServicesCard($containers ?? self::containers([]), self::probe());
    }

    public function testServicesMustExistInCatalog(): void
    {
        $resolver = new OptionsResolver();
        $this->card()->configureOptions($resolver, $this->dashboard);

        self::assertSame(['services' => ['photos', 'note']], $resolver->resolve(['services' => ['photos', 'note']]));

        $this->expectException(InvalidOptionsException::class);
        $this->expectExceptionMessageMatches('/absent/');
        $resolver->resolve(['services' => ['photos', 'absent']]);
    }

    public function testAtLeastOneService(): void
    {
        $resolver = new OptionsResolver();
        $this->card()->configureOptions($resolver, $this->dashboard);

        $this->expectException(InvalidOptionsException::class);
        $resolver->resolve(['services' => []]);
    }

    public function testDescribeListsServicesInOrder(): void
    {
        $props = $this->card()->describe($this->instance(['recettes', 'photos']), $this->dashboard);

        self::assertSame([
            ['id' => 'recettes', 'name' => 'Recettes', 'description' => null, 'url' => 'https://recettes.example', 'icon' => null],
            ['id' => 'photos', 'name' => 'Photos', 'description' => 'Galerie', 'url' => 'https://photos.example', 'icon' => 'sh-immich'],
        ], $props['services']);
    }

    public function testFetchReadsEachMachineOnceAndProbesEveryService(): void
    {
        $containers = self::containers([
            'alpha' => ['photos_server' => new ContainerState('running', 'healthy', 'Up 7 days', 1.5, 200.0)],
            'beta' => ['recettes' => new ContainerState('running', null, 'Up 2 hours', 0.1, 50.0)],
        ]);

        $data = $this->card($containers)->fetch($this->instance(['photos', 'films', 'recettes', 'note']), $this->dashboard);

        self::assertSame(['alpha', 'beta'], $containers->calls, 'une lecture Beszel par machine, pas par service');
        self::assertSame(['photos', 'films', 'recettes', 'note'], array_keys($data['status']));
        self::assertSame('running', $data['status']['photos']['container']['status']);
        self::assertSame('stopped', $data['status']['films']['container']['status'], 'absent de Beszel = arrêté');
        self::assertFalse($data['status']['recettes']['probe']['up']);
        self::assertTrue($data['status']['photos']['probe']['up']);
        self::assertSame(['container' => null, 'probe' => null], $data['status']['note']);
    }

    public function testMachineWithoutBeszelHasNoContainerState(): void
    {
        $data = $this->card()->fetch($this->instance(['wiki']), $this->dashboard);

        self::assertNull($data['status']['wiki']['container']);
        self::assertTrue($data['status']['wiki']['probe']['up']);
    }

    public function testBeszelDownForOneMachineDoesNotHideTheRest(): void
    {
        $containers = self::containers([
            'alpha' => new SourceUnavailableException('Beszel ne reçoit plus les données de Alpha'),
            'beta' => ['recettes' => new ContainerState('running', null, null, null, null)],
        ]);

        $data = $this->card($containers)->fetch($this->instance(['photos', 'recettes']), $this->dashboard);

        self::assertSame(['error' => 'Beszel ne reçoit plus les données de Alpha'], $data['status']['photos']['container']);
        self::assertTrue($data['status']['photos']['probe']['up']);
        self::assertSame('running', $data['status']['recettes']['container']['status']);
    }
}
