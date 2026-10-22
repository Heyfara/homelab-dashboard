<?php

namespace App\Tests\Dashboard;

use App\Dashboard\InvalidDashboardException;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\YamlDashboardLoader;
use PHPUnit\Framework\TestCase;

final class YamlDashboardLoaderTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../fixtures/dashboard.yaml';

    private function load(string $yaml): Dashboard
    {
        $file = tempnam(sys_get_temp_dir(), 'dash').'.yaml';
        file_put_contents($file, $yaml);

        try {
            return (new YamlDashboardLoader())->load($file);
        } finally {
            unlink($file);
        }
    }

    public function testLoadsSettings(): void
    {
        $dashboard = (new YamlDashboardLoader())->load(self::FIXTURE);

        self::assertSame(3, $dashboard->settings->columns);
        self::assertSame(20, $dashboard->settings->refresh);
    }

    public function testSettingsHaveDefaults(): void
    {
        $dashboard = $this->load("profiles: {}\n");

        self::assertSame(4, $dashboard->settings->columns);
        self::assertSame(30, $dashboard->settings->refresh);
    }

    public function testLoadsHosts(): void
    {
        $dashboard = (new YamlDashboardLoader())->load(self::FIXTURE);

        self::assertSame(['alpha', 'beta'], array_keys($dashboard->hosts));
        self::assertSame('Alpha', $dashboard->hosts['alpha']->beszelSystem);
        self::assertNull($dashboard->hosts['beta']->beszelSystem);
    }

    public function testLoadsServiceCatalog(): void
    {
        $dashboard = (new YamlDashboardLoader())->load(self::FIXTURE);

        $photos = $dashboard->services['photos'];
        self::assertSame('photos', $photos->id);
        self::assertSame('Photos', $photos->name);
        self::assertSame('Galerie de la famille', $photos->description);
        self::assertSame('https://photos.example', $photos->url);
        self::assertSame('http://10.0.0.2:2283', $photos->check);
        self::assertSame('sh-immich', $photos->icon);
        self::assertSame('alpha', $photos->host);
        self::assertSame('photos_server', $photos->container);

        $recettes = $dashboard->services['recettes'];
        self::assertNull($recettes->description);
        self::assertNull($recettes->container);
    }

    public function testLoadsProfilesInOrder(): void
    {
        $dashboard = (new YamlDashboardLoader())->load(self::FIXTURE);

        self::assertSame(['alice', 'bob'], array_keys($dashboard->profiles));
        self::assertSame('Alice', $dashboard->profiles['alice']->name);
        self::assertSame('indigo', $dashboard->profiles['alice']->color);
        self::assertSame('slate', $dashboard->profiles['bob']->color, 'couleur par défaut');
    }

    public function testSectionColumnsFallBackToSettings(): void
    {
        $sections = (new YamlDashboardLoader())->load(self::FIXTURE)->profiles['alice']->sections;

        self::assertSame('Services', $sections[0]->title);
        self::assertSame(3, $sections[0]->columns);
        self::assertSame(2, $sections[1]->columns);
        self::assertSame([], $sections[1]->cards);
    }

    public function testCardReservedKeysAndOptions(): void
    {
        $cards = (new YamlDashboardLoader())->load(self::FIXTURE)->profiles['alice']->sections[0]->cards;

        self::assertSame('service', $cards[0]->type);
        self::assertNull($cards[0]->title);
        self::assertSame(1, $cards[0]->span);
        self::assertSame(1, $cards[0]->rows, 'une rangée par défaut');
        self::assertSame('alice', $cards[0]->profile, 'la carte connaît son profil');
        self::assertSame(20, $cards[0]->refresh, 'refresh hérité des réglages');
        self::assertSame(['service' => 'photos'], $cards[0]->options, 'les clés non réservées sont des options');

        self::assertSame('Cuisine', $cards[1]->title);
        self::assertSame(2, $cards[1]->span);
        self::assertSame(2, $cards[1]->rows);
        self::assertSame(60, $cards[1]->refresh);
        self::assertSame(['service' => 'recettes'], $cards[1]->options);
    }

    public function testCardIdIsExplicitOrDerivedFromProfileTypeAndOptions(): void
    {
        $dashboard = (new YamlDashboardLoader())->load(self::FIXTURE);
        $cards = $dashboard->profiles['alice']->sections[0]->cards;

        self::assertMatchesRegularExpression('/^alice-service-[0-9a-f]{6}$/', $cards[0]->id);
        self::assertSame('recettes-alice', $cards[1]->id);
        self::assertMatchesRegularExpression('/^bob-service-[0-9a-f]{6}$/', $dashboard->profiles['bob']->sections[0]->cards[0]->id);
    }

    /** L'ordre enregistré par une personne vise des identifiants : ils ne dépendent pas de la position. */
    public function testDerivedIdSurvivesMovesAndInsertions(): void
    {
        $before = $this->ids(<<<'YAML'
            - { type: service, service: photos }
            - { type: service, service: films }
            YAML);
        $after = $this->ids(<<<'YAML'
            - { type: service, service: torrents }
            - { type: service, service: films, span: 2, refresh: 60 }
            - { type: service, service: photos }
            YAML);

        self::assertSame($before[0], $after[2]);
        self::assertSame($before[1], $after[1], 'span, rows, refresh et title ne changent pas l\'identifiant');
        self::assertNotSame($before[0], $before[1]);
    }

    public function testIdenticalCardsGetNumberedIds(): void
    {
        $ids = $this->ids(<<<'YAML'
            - { type: service, service: photos }
            - { type: service, service: photos }
            YAML);

        self::assertSame($ids[0].'-2', $ids[1]);
    }

    public function testFindsCardAndProfileById(): void
    {
        $dashboard = (new YamlDashboardLoader())->load(self::FIXTURE);
        $bob = $dashboard->profiles['bob']->sections[0]->cards[0]->id;

        self::assertSame('recettes', $dashboard->card($bob)?->options['service']);
        self::assertNull($dashboard->card('inconnue'));
        self::assertSame('Bob', $dashboard->profile('bob')?->name);
        self::assertNull($dashboard->profile('personne'));
    }

    public function testCardWithoutTypeIsRejected(): void
    {
        $this->expectException(InvalidDashboardException::class);
        $this->expectExceptionMessageMatches('/"type".*profiles\.alice\.sections\.0\.cards\.0/');

        $this->load(<<<'YAML'
            profiles:
              alice:
                name: Alice
                sections:
                  - title: S
                    cards:
                      - service: photos
            YAML);
    }

    public function testInvalidProfileSlugIsRejected(): void
    {
        $this->expectException(InvalidDashboardException::class);
        $this->expectExceptionMessageMatches('/Alice Martin/');

        $this->load("profiles:\n  Alice Martin:\n    name: Alice\n");
    }

    public function testUnknownColorIsRejected(): void
    {
        $this->expectException(InvalidDashboardException::class);
        $this->expectExceptionMessageMatches('/fuchsia-fluo/');

        $this->load("profiles:\n  alice:\n    name: Alice\n    color: fuchsia-fluo\n");
    }

    public function testSpanMustBePositive(): void
    {
        $this->expectException(InvalidDashboardException::class);
        $this->expectExceptionMessageMatches('/span/');

        $this->load(<<<'YAML'
            profiles:
              alice:
                name: Alice
                sections:
                  - title: S
                    cards:
                      - { type: service, span: 0 }
            YAML);
    }

    public function testRowsMustBeBetweenOneAndSix(): void
    {
        $this->expectException(InvalidDashboardException::class);
        $this->expectExceptionMessageMatches('/rows/');

        $this->load(<<<'YAML'
            profiles:
              alice:
                name: Alice
                sections:
                  - title: S
                    cards:
                      - { type: service, rows: 7 }
            YAML);
    }

    public function testMissingFileIsReported(): void
    {
        $this->expectException(InvalidDashboardException::class);
        $this->expectExceptionMessageMatches('/introuvable/');

        (new YamlDashboardLoader())->load('/nulle/part.yaml');
    }

    public function testYamlSyntaxErrorIsReported(): void
    {
        $this->expectException(InvalidDashboardException::class);

        $this->load("profiles: [\n");
    }

    /**
     * @return list<string> identifiants des cartes d'une section unique
     */
    private function ids(string $cards): array
    {
        $file = sys_get_temp_dir().'/ids-'.bin2hex(random_bytes(4)).'.yaml';
        file_put_contents($file, "profiles:\n  alice:\n    name: Alice\n    sections:\n      - title: S\n        cards:\n".preg_replace('/^/m', '          ', $cards)."\n");
        try {
            $dashboard = (new YamlDashboardLoader())->load($file);
        } finally {
            unlink($file);
        }

        return array_map(static fn ($c) => $c->id, $dashboard->profiles['alice']->sections[0]->cards);
    }
}
