<?php

namespace App\Tests\Card\Type;

use App\Card\Type\C411Card;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\ServiceDefinition;
use App\Dashboard\Model\Settings;
use App\Source\C411\C411Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\OptionsResolver\Exception\InvalidOptionsException;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class C411CardTest extends TestCase
{
    private Dashboard $dashboard;

    protected function setUp(): void
    {
        $this->dashboard = new Dashboard(new Settings(4, 30), [], [
            'c411' => new ServiceDefinition('c411', 'C411', url: 'https://c411.example', icon: 'https://c411.example/favicon.png'),
            'sans-url' => new ServiceDefinition('sans-url', 'Sans adresse'),
        ], []);
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed>|null $edit modifie le profil de la fixture
     */
    private function card(?callable $edit = null): C411Card
    {
        $profile = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/c411-profile.json'), true);
        if (null !== $edit) {
            $profile = $edit($profile);
        }

        return new C411Card(new C411Client(new MockHttpClient(new MockResponse(json_encode($profile))), new ArrayAdapter(), 'cle'));
    }

    private function instance(C411Card $card, array $options = ['service' => 'c411']): CardInstance
    {
        $resolver = new OptionsResolver();
        $card->configureOptions($resolver, $this->dashboard);

        return new CardInstance('c', 'c411', null, 2, 60, $resolver->resolve($options), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(?callable $edit = null): array
    {
        $card = $this->card($edit);

        return $card->fetch($this->instance($card), $this->dashboard);
    }

    public function testServiceNeedsAnUrl(): void
    {
        $this->expectException(InvalidOptionsException::class);

        $this->instance($this->card(), ['service' => 'sans-url']);
    }

    public function testDescribe(): void
    {
        $card = $this->card();

        self::assertSame(
            ['title' => 'C411', 'service' => 'C411', 'url' => 'https://c411.example', 'icon' => 'https://c411.example/favicon.png'],
            $card->describe($this->instance($card), $this->dashboard),
        );
    }

    public function testUser(): void
    {
        self::assertSame(
            ['name' => 'membre', 'avatar' => 'https://c411.example/avatars/48213.webp', 'memberSince' => '2025-03-14T09:12:44+00:00'],
            $this->fetch()['user'],
        );
    }

    public function testNoAvatar(): void
    {
        self::assertNull($this->fetch(static fn (array $p) => ['avatarUrl' => null] + $p)['user']['avatar']);
    }

    public function testLevelProgress(): void
    {
        $level = $this->fetch()['level'];
        unset($level['badge']);

        self::assertSame(['level' => 37, 'xp' => 18420, 'xpIntoLevel' => 310, 'xpToNextLevel' => 239, 'progress' => 0.5647], $level);
    }

    public function testBadgeIsBlueEmblemWithSilverDial(): void
    {
        self::assertSame([
            'emblem' => 'https://c411.example/images/ic-grade/embleme-blue.webp',
            'dial' => 'https://c411.example/images/ic-grade/cadran-silver.webp',
        ], $this->fetch(static function (array $p) {
            $p['class']['progress']['grade']['tier'] = 'bronze';

            return $p;
        })['level']['badge'], 'cadran silver quel que soit le palier');
    }

    public function testClassWithVoiesInFixedOrder(): void
    {
        $class = $this->fetch()['class'];

        self::assertSame('team_resident_mainstream', $class['slug'], 'la classe effective prime');
        self::assertSame(['tier' => 'argent', 'level' => 2], $class['grade']);
        self::assertSame([2, 23, 40, 2.5], [$class['stars'], $class['questsCompleted'], $class['questsTotal'], $class['bonusPercent']]);
        self::assertSame(['seed', 'upload', 'specialization', 'competitivity'], array_column($class['voies'], 'key'));
        self::assertSame(['key' => 'upload', 'star' => false, 'completed' => 5, 'total' => 11], $class['voies'][1]);
    }

    public function testNoClassYet(): void
    {
        self::assertNull($this->fetch(static fn (array $p) => ['class' => null] + $p)['class']);
    }

    public function testTeamZenTransferAndDownload(): void
    {
        $data = $this->fetch();

        self::assertSame(['name' => 'Example Team', 'tag' => 'EXT'], $data['team']);
        self::assertSame(['balance' => 1840, 'earned' => 12650, 'spent' => 10810], $data['zen']);
        self::assertSame(5604932321280, $data['transfer']['uploaded']);
        self::assertSame(3244847792128, $data['transfer']['downloaded']);
        self::assertSame(1.73, $data['transfer']['ratio']);
        self::assertSame(['allowed' => true, 'blockedReason' => null], $data['download']);
        self::assertSame(['active' => true, 'sources' => ['campaign']], $data['freeleech']);
    }

    public function testRatioWithNothingDownloaded(): void
    {
        $data = $this->fetch(static function (array $p) {
            $p['transfer']['downloaded']['total'] = 0;

            return $p;
        });

        self::assertNull($data['transfer']['ratio']);
    }
}
