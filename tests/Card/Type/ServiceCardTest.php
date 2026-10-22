<?php

namespace App\Tests\Card\Type;

use App\Card\Type\ServiceCard;
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

final class ServiceCardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(
            new Settings(4, 30),
            ['alpha' => new Host('alpha', 'Alpha'), 'beta' => new Host('beta')],
            [
                'photos' => new ServiceDefinition('photos', 'Photos', 'Galerie', 'https://photos.example', 'http://10.0.0.2:2283', 'sh-immich', 'alpha', 'photos_server'),
                'recettes' => new ServiceDefinition('recettes', 'Recettes', url: 'https://recettes.example', host: 'beta', container: 'recettes'),
                'note' => new ServiceDefinition('note', 'Note'),
            ],
            [],
        );
    }

    /**
     * Source de containers simulée : renvoie $state, ou lève $state si c'est une exception.
     */
    private static function containers(ContainerState|\Throwable|null $state): ContainerStatusSource
    {
        return new class($state) implements ContainerStatusSource {
            /** @var list<array{string, string}> */
            public array $calls = [];

            public function __construct(private ContainerState|\Throwable|null $state)
            {
            }

            public function status(Host $host, string $container): ContainerState
            {
                $this->calls[] = [$host->name, $container];
                if ($this->state instanceof \Throwable) {
                    throw $this->state;
                }

                return $this->state ?? throw new \LogicException('aucun appel attendu');
            }

            public function all(Host $host): array
            {
                throw new \LogicException('non utilisé par ServiceCard');
            }
        };
    }

    /**
     * @param callable(string): MockResponse $responder
     */
    private static function probe(callable $responder): HttpProbe
    {
        return new HttpProbe(new MockHttpClient(static fn (string $method, string $url) => $responder($url)));
    }

    private function instance(string $service): CardInstance
    {
        return new CardInstance('c', 'service', null, 1, 30, ['service' => $service]);
    }

    public function testServiceOptionMustExistInCatalog(): void
    {
        $resolver = new OptionsResolver();
        (new ServiceCard(self::containers(null), self::probe(static fn () => new MockResponse())))->configureOptions($resolver, $this->dashboard);

        self::assertSame(['service' => 'photos'], $resolver->resolve(['service' => 'photos']));

        $this->expectException(InvalidOptionsException::class);
        $resolver->resolve(['service' => 'absent']);
    }

    public function testDescribeGivesStaticProps(): void
    {
        $props = (new ServiceCard(self::containers(null), self::probe(static fn () => new MockResponse())))->describe($this->instance('photos'), $this->dashboard);

        self::assertSame([
            'name' => 'Photos',
            'description' => 'Galerie',
            'url' => 'https://photos.example',
            'icon' => 'sh-immich',
            'host' => 'alpha',
        ], $props);
    }

    public function testFetchCombinesContainerAndProbe(): void
    {
        $containers = self::containers(new ContainerState('running', 'healthy', 'Up 7 days', 1.5, 200.0));
        $card = new ServiceCard($containers, self::probe(static function (string $url) {
            self::assertSame('http://10.0.0.2:2283/', $url, 'la sonde vise `check` en priorité');

            return new MockResponse('', ['http_code' => 200]);
        }));

        $data = $card->fetch($this->instance('photos'), $this->dashboard);

        self::assertSame([['alpha', 'photos_server']], $containers->calls);
        self::assertSame(['status' => 'running', 'health' => 'healthy', 'uptime' => 'Up 7 days', 'cpu' => 1.5, 'memory' => 200.0], $data['container']);
        self::assertTrue($data['probe']['up']);
    }

    public function testNoContainerStateWithoutBeszelSystem(): void
    {
        $card = new ServiceCard(self::containers(null), self::probe(static function (string $url) {
            self::assertSame('https://recettes.example/', $url, 'sans `check`, la sonde vise `url`');

            return new MockResponse('', ['http_code' => 200]);
        }));

        $data = $card->fetch($this->instance('recettes'), $this->dashboard);

        self::assertNull($data['container']);
        self::assertTrue($data['probe']['up']);
    }

    public function testNothingToCheck(): void
    {
        $card = new ServiceCard(self::containers(null), self::probe(static fn () => throw new \LogicException('aucun appel attendu')));

        self::assertSame(['container' => null, 'probe' => null], $card->fetch($this->instance('note'), $this->dashboard));
    }

    public function testSourceDownDoesNotHideProbe(): void
    {
        $card = new ServiceCard(
            self::containers(new SourceUnavailableException('Beszel injoignable')),
            self::probe(static fn () => new MockResponse('', ['http_code' => 200])),
        );

        $data = $card->fetch($this->instance('photos'), $this->dashboard);

        self::assertSame(['error' => 'Beszel injoignable'], $data['container']);
        self::assertTrue($data['probe']['up']);
    }
}
