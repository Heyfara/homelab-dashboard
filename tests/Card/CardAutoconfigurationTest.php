<?php

namespace App\Tests\Card;

use App\Card\AsCard;
use App\Card\CardTypeInterface;
use App\Card\CardTypeRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * Garde-fou : toute classe de src/Card/Type porte #[AsCard] et se retrouve dans
 * le registre sous cet identifiant.
 */
final class CardAutoconfigurationTest extends KernelTestCase
{
    public function testEveryCardTypeIsRegisteredUnderItsAttribute(): void
    {
        $registry = self::getContainer()->get(CardTypeRegistry::class);
        $found = 0;

        foreach ((new Finder())->files()->in(__DIR__.'/../../src/Card/Type')->name('*.php') as $file) {
            $class = 'App\\Card\\Type\\'.$file->getBasename('.php');
            $reflection = new \ReflectionClass($class);
            if ($reflection->isAbstract() || !$reflection->implementsInterface(CardTypeInterface::class)) {
                continue;
            }

            $attributes = $reflection->getAttributes(AsCard::class);
            self::assertCount(1, $attributes, "$class doit porter #[AsCard]");
            $type = $attributes[0]->newInstance()->type;

            self::assertTrue($registry->has($type), "type « $type » absent du registre");
            self::assertInstanceOf($class, $registry->get($type));
            ++$found;
        }

        self::assertGreaterThan(0, $found);
    }
}
