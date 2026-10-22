<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends KernelTestCase
{
    private function tester(string $name): CommandTester
    {
        return new CommandTester((new Application(self::bootKernel()))->find($name));
    }

    public function testLintValidFile(): void
    {
        $tester = $this->tester('dashboard:lint');

        self::assertSame(Command::SUCCESS, $tester->execute(['file' => __DIR__.'/../fixtures/dashboard.yaml']));
        self::assertStringContainsString('2 profils, 23 cartes', $tester->getDisplay());
    }

    public function testLintUsesConfiguredFileByDefault(): void
    {
        self::assertSame(Command::SUCCESS, $this->tester('dashboard:lint')->execute([]));
    }

    public function testLintInvalidFileListsErrors(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'dash').'.yaml';
        file_put_contents($file, "services:\n  a: { name: A, host: zz }\nprofiles:\n  x:\n    name: X\n    sections:\n      - title: S\n        cards:\n          - { type: meteo }\n");
        $tester = $this->tester('dashboard:lint');

        try {
            self::assertSame(Command::FAILURE, $tester->execute(['file' => $file]));
        } finally {
            unlink($file);
        }

        self::assertStringContainsString('meteo', $tester->getDisplay());
        self::assertStringContainsString('zz', $tester->getDisplay());
    }

    public function testCardTypesListsOnePerLine(): void
    {
        $tester = $this->tester('dashboard:card-types');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertContains('service', explode("\n", trim($tester->getDisplay())));
    }
}
