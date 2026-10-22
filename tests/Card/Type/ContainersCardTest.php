<?php

namespace App\Tests\Card\Type;

use App\Card\Type\ContainersCard;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Host;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\Beszel\BeszelClient;
use App\Source\Container\BeszelContainerSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ContainersCardTest extends TestCase
{
    private const NOW = 1792400000;

    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(
            new Settings(4, 30),
            ['alpha' => new Host('alpha', 'Alpha'), 'beta' => new Host('beta')],
            [
                'photos' => new ServiceDefinition('photos', 'Photos', host: 'alpha', container: 'photos_server'),
                'notes' => new ServiceDefinition('notes', 'Notes', host: 'alpha', container: 'notes'),
                'ailleurs' => new ServiceDefinition('ailleurs', 'Ailleurs', host: 'beta', container: 'ailleurs'),
            ],
            [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function record(string $name, string $status, int $health = 0, int $age = 0, float $cpu = 1.5, float $memory = 200): array
    {
        return ['id' => $name, 'name' => $name, 'status' => $status, 'health' => $health, 'cpu' => $cpu, 'memory' => $memory, 'updated' => (self::NOW - $age) * 1000];
    }

    private function card(): ContainersCard
    {
        $http = new MockHttpClient(static fn (string $method, string $url) => match (true) {
            str_contains($url, 'auth-with-password') => new JsonMockResponse(['token' => 't']),
            str_contains($url, '/systems/') => new JsonMockResponse(['items' => [['id' => 's1', 'name' => 'Alpha', 'status' => 'up']]]),
            str_contains($url, '/containers/') => new JsonMockResponse(['items' => [
                self::record('photos_server', 'Up 7 days', 2, 0, 3.2, 512),
                self::record('redis', 'Up 3 hours'),
                self::record('beszel-agent', 'Up 7 days'),
                self::record('jellyfin', 'Up 2 days', 3),
                self::record('worker', 'Restarting (1) 4 seconds ago'),
                self::record('vieux', 'Up 9 days', 0, 300),
            ]]),
        });

        return new ContainersCard(new BeszelContainerSource(new BeszelClient($http, new ArrayAdapter(), 'http://beszel', 'u', 'p')));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function instance(ContainersCard $card, array $options = []): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('c', 'containers', null, 2, 30, $resolver->resolve(['host' => 'alpha', ...$options]), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(array $options = []): array
    {
        $card = $this->card();

        return $card->fetch($this->instance($card, $options), $this->dashboard);
    }

    public function testOptions(): void
    {
        self::assertEquals(['host' => 'alpha', 'hide' => []], $this->instance($this->card())->options);

        $this->expectException(ExceptionInterface::class);
        $this->instance($this->card(), ['host' => 'beta']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(['title' => 'alpha', 'system' => 'Alpha'], $card->describe($this->instance($card), $this->dashboard));
    }

    public function testProblemsFirstThenByName(): void
    {
        $names = array_column($this->fetch()['containers'], 'name');

        self::assertSame(['notes', 'vieux', 'jellyfin', 'worker', 'beszel-agent', 'photos_server', 'redis'], $names);
    }

    public function testExpectedContainerMissingFromBeszelIsStopped(): void
    {
        $notes = $this->fetch()['containers'][0];

        self::assertSame(['name' => 'notes', 'status' => 'stopped', 'health' => null, 'uptime' => null, 'cpu' => null, 'memory' => null], $notes);
    }

    public function testRunningContainerDetails(): void
    {
        $photos = array_values(array_filter($this->fetch()['containers'], static fn (array $c) => 'photos_server' === $c['name']))[0];

        self::assertSame(['name' => 'photos_server', 'status' => 'running', 'health' => 'healthy', 'uptime' => '7 j', 'cpu' => 3.2, 'memory' => 512.0], $photos);
    }

    public function testHideWithWildcards(): void
    {
        $names = array_column($this->fetch(['hide' => ['beszel-*', 'redis']])['containers'], 'name');

        self::assertNotContains('beszel-agent', $names);
        self::assertNotContains('redis', $names);
    }

    public function testCounts(): void
    {
        self::assertSame(['total' => 7, 'running' => 4, 'problems' => 4], $this->fetch()['counts']);
    }
}
