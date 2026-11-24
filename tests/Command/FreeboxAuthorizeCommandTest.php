<?php

namespace App\Tests\Command;

use App\Command\FreeboxAuthorizeCommand;
use App\Source\Freebox\FreeboxClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class FreeboxAuthorizeCommandTest extends TestCase
{
    /**
     * @param list<string> $statuses réponses successives du suivi
     */
    private function authorize(array $statuses, int &$waits = 0): array
    {
        $http = new MockHttpClient(static function (string $method, string $url) use (&$statuses) {
            return str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/login/authorize/')
                ? new JsonMockResponse(['success' => true, 'result' => ['app_token' => 'nouveau-jeton', 'track_id' => 7]])
                : new JsonMockResponse(['success' => true, 'result' => ['status' => array_shift($statuses)]]);
        });
        $command = new FreeboxAuthorizeCommand(new FreeboxClient($http, new ArrayAdapter(), 'http://box.test', ''), static function () use (&$waits): void {
            ++$waits;
        });
        $output = new BufferedOutput();

        return [$command($output), $output->fetch()];
    }

    public function testPrintsTheTokenOnceAccepted(): void
    {
        $waits = 0;
        [$code, $display] = $this->authorize(['pending', 'pending', 'granted'], $waits);

        self::assertSame(Command::SUCCESS, $code);
        self::assertSame(2, $waits);
        self::assertStringContainsString('écran de la Freebox', $display);
        self::assertStringContainsString('FREEBOX_APP_TOKEN=nouveau-jeton', $display);
    }

    public function testRefusalFails(): void
    {
        [$code, $display] = $this->authorize(['denied']);

        self::assertSame(Command::FAILURE, $code);
        self::assertStringContainsString('denied', $display);
        self::assertStringNotContainsString('nouveau-jeton', $display);
    }
}
