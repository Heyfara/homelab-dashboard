<?php

namespace App\Tests\Source\Dagu;

use App\Card\CardActionRefusedException;
use App\Source\Dagu\DaguRunner;
use App\Source\SourceUnavailableException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DaguRunnerTest extends TestCase
{
    private const URL = 'https://taches.test';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    private function runner(MockResponse $response, string $key = 'cle-operateur'): DaguRunner
    {
        return new DaguRunner(new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return $response;
        }), $key);
    }

    public function testStartWithParams(): void
    {
        $id = $this->runner(new JsonMockResponse(['dagRunId' => 'run-9']))->start(self::URL.'/', 'declaration', ['MONTANT' => '120', 'TEST' => 'false']);

        self::assertSame('run-9', $id);
        [$method, $url, $options] = $this->requests[0];
        self::assertSame('POST', $method);
        self::assertSame('https://taches.test/api/v1/dags/declaration/start', $url);
        self::assertContains('Authorization: Bearer cle-operateur', $options['headers']);
        self::assertSame(['params' => '{"MONTANT":"120","TEST":"false"}', 'singleton' => true], json_decode($options['body'], true));
    }

    public function testStartRefusedWhileRunning(): void
    {
        $this->expectExceptionObject(new CardActionRefusedException('La tâche declaration tourne déjà.'));
        $this->runner(new JsonMockResponse(['code' => 'already_running', 'message' => 'DAG is already running'], ['http_code' => 409]))->start(self::URL, 'declaration', []);
    }

    public function testStop(): void
    {
        $this->runner(new MockResponse('', ['http_code' => 200]))->stop(self::URL, 'declaration', 'run-9');

        self::assertSame(['POST', 'https://taches.test/api/v1/dag-runs/declaration/run-9/stop'], \array_slice($this->requests[0], 0, 2));
    }

    public function testMissingKey(): void
    {
        $this->expectExceptionObject(new SourceUnavailableException('Clé API Dagu de lancement absente (DAGU_OPERATOR_API_KEY)'));
        $this->runner(new MockResponse(''), '')->start(self::URL, 'declaration', []);
    }

    public function testRefusedKey(): void
    {
        $this->expectExceptionObject(new SourceUnavailableException('Clé API Dagu refusée (403), vérifier DAGU_OPERATOR_API_KEY (rôle operator)'));
        $this->runner(new JsonMockResponse(['message' => 'forbidden'], ['http_code' => 403]))->stop(self::URL, 'declaration', 'run-9');
    }

    public function testUnreachable(): void
    {
        $this->expectException(SourceUnavailableException::class);
        $this->expectExceptionMessageMatches('/^Dagu injoignable : /');
        $this->runner(new MockResponse('', ['error' => 'Connexion refusée']))->start(self::URL, 'declaration', []);
    }
}
