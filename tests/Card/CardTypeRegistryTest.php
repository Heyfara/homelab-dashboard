<?php

namespace App\Tests\Card;

use App\Card\CardTypeRegistry;
use App\Card\UnknownCardTypeException;
use App\Dashboard\Model\CardInstance;
use App\Dashboard\Model\Dashboard;
use App\Dashboard\Model\Settings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\OptionsResolver\Exception\MissingOptionsException;

final class CardTypeRegistryTest extends TestCase
{
    private function registry(): CardTypeRegistry
    {
        return new CardTypeRegistry(new ServiceLocator(['fake' => static fn () => new FakeCard()]));
    }

    private function dashboard(): Dashboard
    {
        return new Dashboard(new Settings(4, 30), [], [], []);
    }

    public function testListsAndReturnsTypes(): void
    {
        $registry = $this->registry();

        self::assertSame(['fake'], $registry->types());
        self::assertTrue($registry->has('fake'));
        self::assertFalse($registry->has('nope'));
        self::assertInstanceOf(FakeCard::class, $registry->get('fake'));
    }

    public function testUnknownTypeNamesKnownTypes(): void
    {
        $this->expectException(UnknownCardTypeException::class);
        $this->expectExceptionMessage('Type de carte inconnu « nope » (types connus : fake)');

        $this->registry()->get('nope');
    }

    public function testResolvesOptionsWithDefaults(): void
    {
        $card = new CardInstance('c', 'fake', null, 1, 30, ['label' => 'Bonjour']);

        self::assertEquals(
            ['label' => 'Bonjour', 'service' => null, 'loud' => false],
            $this->registry()->resolveOptions($card, $this->dashboard()),
        );
    }

    public function testFeedsOfACard(): void
    {
        self::assertSame(['live' => 5], $this->registry()->feeds(new CardInstance('c', 'fake', null, 1, 30, [])));
    }

    public function testResolveRejectsInvalidOptions(): void
    {
        $this->expectException(MissingOptionsException::class);

        $this->registry()->resolveOptions(new CardInstance('c', 'fake', null, 1, 30, []), $this->dashboard());
    }
}
