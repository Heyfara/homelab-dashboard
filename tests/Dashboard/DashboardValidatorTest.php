<?php

namespace App\Tests\Dashboard;

use App\Card\CardTypeRegistry;
use App\Dashboard\DashboardValidator;
use App\Dashboard\YamlDashboardLoader;
use App\Tests\Card\FakeCard;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class DashboardValidatorTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function validate(string $yaml): array
    {
        $file = tempnam(sys_get_temp_dir(), 'dash').'.yaml';
        file_put_contents($file, $yaml);

        try {
            $dashboard = (new YamlDashboardLoader())->load($file);
        } finally {
            unlink($file);
        }

        $registry = new CardTypeRegistry(new ServiceLocator(['fake' => static fn () => new FakeCard()]));

        return (new DashboardValidator($registry))->validate($dashboard);
    }

    public function testValidDashboardHasNoErrors(): void
    {
        self::assertSame([], $this->validate(<<<'YAML'
            hosts:
              alpha: ~
            services:
              photos: { name: Photos, host: alpha }
            profiles:
              alice:
                name: Alice
                sections:
                  - title: S
                    cards:
                      - { type: fake, label: Bonjour, service: photos }
            YAML));
    }

    public function testUnknownCardType(): void
    {
        $errors = $this->validate(<<<'YAML'
            profiles:
              alice:
                name: Alice
                sections:
                  - title: Services
                    cards:
                      - { type: fake, label: ok }
                      - { type: meteo }
            YAML);

        self::assertCount(1, $errors);
        self::assertStringContainsString('alice-meteo-', $errors[0]);
        self::assertStringContainsString('« Services »', $errors[0]);
        self::assertStringContainsString('inconnu « meteo » (types connus : fake)', $errors[0]);
    }

    public function testInvalidOptionsAreReportedWithTheCard(): void
    {
        $errors = $this->validate(<<<'YAML'
            profiles:
              alice:
                name: Alice
                sections:
                  - title: S
                    cards:
                      - { type: fake }
                      - { type: fake, label: ok, service: absent }
                      - { type: fake, label: ok, couleur: rouge }
            YAML);

        self::assertCount(3, $errors);
        self::assertStringContainsString('Carte alice-fake-', $errors[0]);
        self::assertStringContainsString('label', $errors[0]);
        self::assertStringContainsString('absent', $errors[1]);
        self::assertStringContainsString('couleur', $errors[2]);
    }

    public function testServiceOnUnknownHost(): void
    {
        $errors = $this->validate(<<<'YAML'
            services:
              photos: { name: Photos, host: gamma }
            profiles: {}
            YAML);

        self::assertSame(['Service « photos » : machine inconnue « gamma » (machines déclarées : aucune).'], $errors);
    }

    public function testDuplicateCardIds(): void
    {
        $errors = $this->validate(<<<'YAML'
            profiles:
              alice:
                name: Alice
                sections:
                  - title: S
                    cards:
                      - { type: fake, label: a, id: meteo }
              bob:
                name: Bob
                sections:
                  - title: S
                    cards:
                      - { type: fake, label: b, id: meteo }
            YAML);

        self::assertSame(['Identifiant de carte « meteo » utilisé plusieurs fois.'], $errors);
    }
}
