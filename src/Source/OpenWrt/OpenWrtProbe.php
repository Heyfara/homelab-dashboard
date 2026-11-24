<?php

namespace App\Source\OpenWrt;

use App\Source\SourceUnavailableException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lit un point d'accès OpenWrt par SSH : la clé du dashboard n'y lance que le
 * script dashboard-probe (`command=` dans authorized_keys), qui répond en JSON.
 *
 * NETWORK_SSH_DIR contient la clé privée (`id_ed25519`) et la clé d'hôte
 * épinglée de chaque point d'accès (`known_hosts`).
 */
final readonly class OpenWrtProbe
{
    /** Délai total d'un relevé, connexion comprise (s) */
    private const TIMEOUT = 6.0;

    private \Closure $run;
    private \Closure $clock;

    /**
     * @param ?\Closure(list<string>, float): array{int, string, string} $run   commande → [code, sortie, erreurs]
     * @param ?\Closure(): float                                         $clock
     */
    public function __construct(
        #[Autowire('%env(NETWORK_SSH_DIR)%')]
        private string $sshDir,
        ?\Closure $run = null,
        ?\Closure $clock = null,
    ) {
        $this->run = $run ?? self::exec(...);
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function snapshot(string $address): OpenWrtSnapshot
    {
        $dir = rtrim($this->sshDir, '/');
        if ('' === $this->sshDir || !is_file($dir.'/id_ed25519') || !is_file($dir.'/known_hosts')) {
            throw new SourceUnavailableException('Clé SSH du dashboard absente (NETWORK_SSH_DIR : id_ed25519 et known_hosts)');
        }

        [$code, $out, $err] = ($this->run)([
            'ssh', '-i', $dir.'/id_ed25519',
            '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'UserKnownHostsFile='.$dir.'/known_hosts',
            '-o', 'ConnectTimeout=3', '-o', 'LogLevel=ERROR',
            'root@'.$address, 'dashboard-probe',
        ], self::TIMEOUT);
        $readAt = ($this->clock)();

        if (0 !== $code) {
            throw new SourceUnavailableException(\sprintf('%s injoignable en SSH : %s', $address, trim($err) ?: 'code '.$code));
        }

        return OpenWrtSnapshot::fromJson($out, $readAt);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string, string}
     */
    private static function exec(array $argv, float $timeout): array
    {
        $proc = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($proc)) {
            return [-1, '', 'lancement de ssh impossible'];
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = $err = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = $except = null;
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                proc_terminate($proc, 9);
                $err .= 'délai dépassé';
                break;
            }
            if (false === stream_select($read, $write, $except, 0, (int) min(200_000, $left * 1e6))) {
                break;
            }
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            if (feof($pipes[1]) && feof($pipes[2])) {
                break;
            }
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [str_ends_with($err, 'délai dépassé') ? -1 : $code, $out, $err];
    }
}
