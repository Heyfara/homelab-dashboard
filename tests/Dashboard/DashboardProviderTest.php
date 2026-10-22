<?php

namespace App\Tests\Dashboard;

use App\Card\CardTypeRegistry;
use App\Dashboard\DashboardProvider;
use App\Dashboard\DashboardValidator;
use App\Dashboard\InvalidDashboardException;
use App\Dashboard\YamlDashboardLoader;
use App\Tests\Card\FakeCard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DashboardProviderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/dash-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    private function provider(string $path, ?ArrayAdapter $cache = null, string $codeVersion = 'v1'): DashboardProvider
    {
        $registry = new CardTypeRegistry(new ServiceLocator(['fake' => static fn () => new FakeCard()]));

        return new DashboardProvider(new YamlDashboardLoader(), new DashboardValidator($registry), $registry, $cache ?? new ArrayAdapter(), $path, $this->dir, $codeVersion);
    }

    private function write(string $yaml, string $name = 'dashboard.yaml'): string
    {
        file_put_contents($this->dir.'/'.$name, $yaml);

        return $this->dir.'/'.$name;
    }

    private const VALID = <<<'YAML'
        profiles:
          alice:
            name: Alice
            sections:
              - title: S
                cards:
                  - { type: fake, label: Bonjour }
        YAML;

    public function testCardOptionsAreResolved(): void
    {
        $dashboard = $this->provider($this->write(self::VALID))->get();

        self::assertEquals(['label' => 'Bonjour', 'service' => null, 'loud' => false], $dashboard->profiles['alice']->sections[0]->cards[0]->options);
    }

    public function testRelativePathIsResolvedFromProjectDir(): void
    {
        $this->write(self::VALID);

        self::assertNotNull($this->provider('dashboard.yaml')->get()->profile('alice'));
    }

    public function testInvalidDashboardThrowsAllErrors(): void
    {
        $path = $this->write(<<<'YAML'
            profiles:
              alice:
                name: Alice
                sections:
                  - title: S
                    cards:
                      - { type: fake }
                      - { type: meteo }
            YAML);

        try {
            $this->provider($path)->get();
            self::fail('exception attendue');
        } catch (InvalidDashboardException $e) {
            self::assertCount(2, $e->errors);
        }
    }

    public function testReloadsWhenFileChanges(): void
    {
        $cache = new ArrayAdapter();
        $path = $this->write(self::VALID);
        self::assertSame('Alice', $this->provider($path, $cache)->get()->profile('alice')?->name);

        file_put_contents($path, str_replace('name: Alice', 'name: Alicia', self::VALID));
        touch($path, time() + 10);

        self::assertSame('Alicia', $this->provider($path, $cache)->get()->profile('alice')?->name);
    }

    public function testNewCodeInvalidatesTheCache(): void
    {
        // Le dashboard en cache porte les options complétées par le code des
        // cartes : un code modifié (nouvelle option, nouveau défaut) le périme.
        $cache = new ArrayAdapter();
        $path = $this->write(self::VALID);
        $this->provider($path, $cache, 'v1')->get();

        $mtime = filemtime($path);
        file_put_contents($path, str_replace('name: Alice', 'name: Alicia', self::VALID));
        touch($path, $mtime);

        self::assertSame('Alicia', $this->provider($path, $cache, 'v2')->get()->profile('alice')?->name);
    }

    public function testCodeVersionFollowsCardAndDashboardSources(): void
    {
        mkdir($this->dir.'/src/Card/Type', recursive: true);
        file_put_contents($this->dir.'/src/Card/Type/X.php', '<?php');
        $before = DashboardProvider::codeVersion($this->dir);

        touch($this->dir.'/src/Card/Type/X.php', time() + 5);
        clearstatcache();

        self::assertNotSame($before, DashboardProvider::codeVersion($this->dir));
        unlink($this->dir.'/src/Card/Type/X.php');
        rmdir($this->dir.'/src/Card/Type');
        rmdir($this->dir.'/src/Card');
        rmdir($this->dir.'/src');
    }

    public function testCachesBetweenRequestsWhileFileIsUnchanged(): void
    {
        $cache = new ArrayAdapter();
        $path = $this->write(self::VALID);
        $this->provider($path, $cache)->get();

        // Contenu changé mais même date : le cache sert l'ancienne version.
        $mtime = filemtime($path);
        file_put_contents($path, str_replace('name: Alice', 'name: Alicia', self::VALID));
        touch($path, $mtime);

        self::assertSame('Alice', $this->provider($path, $cache)->get()->profile('alice')?->name);
    }
}
