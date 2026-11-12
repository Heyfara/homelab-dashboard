<?php

namespace App\Source\WebSocket;

/**
 * Client WebSocket minimal (RFC 6455), pour des échanges question-réponse
 * courts : ouvrir, envoyer et lire quelques messages texte, fermer.
 *
 * Pas de bibliothèque : seuls les messages texte, la fragmentation, ping/pong
 * et la fermeture sont gérés, ce qui suffit à l'API de Home Assistant. Pas
 * d'extensions (compression permessage-deflate non demandée).
 */
final class WebSocketConnection implements WebSocket
{
    private const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
    private const TEXT = 0x1;
    private const CONTINUATION = 0x0;
    private const CLOSE = 0x8;
    private const PING = 0x9;
    private const PONG = 0xA;

    /** Au-delà, un message est refusé (protection mémoire). */
    private const MAX_MESSAGE = 32 * 1024 * 1024;

    private bool $closed = false;

    /**
     * @param resource $stream
     */
    private function __construct(private $stream)
    {
    }

    /**
     * Ouvre une connexion `ws://` ou `wss://` (TLS vérifié, avec $caFile si donné).
     */
    public static function connect(string $url, ?string $caFile = null, float $timeout = 5.0): self
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? '';
        $host = $parts['host'] ?? '';
        if (!\in_array($scheme, ['ws', 'wss'], true) || '' === $host) {
            throw new WebSocketException(\sprintf('Adresse WebSocket invalide : %s', $url));
        }
        $tls = 'wss' === $scheme;
        $port = $parts['port'] ?? ($tls ? 443 : 80);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        $ssl = ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true];
        if (null !== $caFile && '' !== $caFile) {
            $ssl['cafile'] = $caFile;
        }
        $context = stream_context_create(['ssl' => $ssl]);
        $errno = 0;
        $error = '';
        $stream = @stream_socket_client(($tls ? 'tls' : 'tcp')."://$host:$port", $errno, $error, $timeout, \STREAM_CLIENT_CONNECT, $context);
        if (false === $stream) {
            throw new WebSocketException(\sprintf('Connexion impossible à %s:%d (%s)', $host, $port, '' !== $error ? $error : 'erreur '.$errno));
        }

        $defaultPort = $tls ? 443 : 80;

        return self::handshake($stream, $port === $defaultPort ? $host : "$host:$port", $path, base64_encode(random_bytes(16)), $timeout);
    }

    /**
     * Demande de passage en WebSocket sur un flux déjà ouvert. Public pour les
     * tests (clé fixée) ; sinon passer par connect().
     *
     * @param resource $stream
     */
    public static function handshake($stream, string $host, string $path, string $key, float $timeout): self
    {
        $connection = new self($stream);
        $connection->setTimeout($timeout);
        $connection->write(
            "GET $path HTTP/1.1\r\n".
            "Host: $host\r\n".
            "Upgrade: websocket\r\n".
            "Connection: Upgrade\r\n".
            "Sec-WebSocket-Key: $key\r\n".
            "Sec-WebSocket-Version: 13\r\n\r\n",
        );

        $head = '';
        while (!str_ends_with($head, "\r\n\r\n")) {
            $line = fgets($stream);
            if (false === $line) {
                $connection->failIfTimedOut();
                throw new WebSocketException('Le serveur a coupé la connexion pendant l\'ouverture du WebSocket.');
            }
            $head .= $line;
            if (\strlen($head) > 16384) {
                throw new WebSocketException('Réponse d\'ouverture du WebSocket trop longue.');
            }
        }

        $lines = explode("\r\n", trim($head));
        $status = array_shift($lines);
        if (!preg_match('#^HTTP/1\.1 101\b#', $status)) {
            throw new WebSocketException(\sprintf('Le serveur refuse le WebSocket : %s', $status));
        }
        $headers = [];
        foreach ($lines as $line) {
            [$name, $value] = explode(':', $line, 2) + [1 => ''];
            $headers[strtolower(trim($name))] = trim($value);
        }
        if (($headers['sec-websocket-accept'] ?? '') !== base64_encode(sha1($key.self::GUID, true))) {
            throw new WebSocketException('Réponse d\'ouverture du WebSocket invalide (Sec-WebSocket-Accept).');
        }

        return $connection;
    }

    public function setTimeout(float $seconds): void
    {
        stream_set_timeout($this->stream, (int) $seconds, (int) round(fmod($seconds, 1) * 1_000_000));
    }

    public function send(string $text): void
    {
        $this->sendFrame(self::TEXT, $text);
    }

    public function receive(): string
    {
        $message = '';
        $started = false;
        while (true) {
            [$fin, $opcode, $payload] = $this->readFrame();
            switch ($opcode) {
                case self::PING:
                    $this->sendFrame(self::PONG, $payload);
                    continue 2;
                case self::PONG:
                    continue 2;
                case self::CLOSE:
                    $this->closed = true;
                    $code = \strlen($payload) >= 2 ? unpack('n', $payload)[1] : null;
                    throw new WebSocketException(\sprintf('Le serveur a fermé le WebSocket%s.', null !== $code ? " (code $code)" : ''));
                case self::TEXT:
                case self::CONTINUATION:
                    if (self::TEXT === $opcode && $started) {
                        throw new WebSocketException('Message WebSocket mal découpé.');
                    }
                    $started = true;
                    $message .= $payload;
                    if (\strlen($message) > self::MAX_MESSAGE) {
                        throw new WebSocketException('Message WebSocket trop long.');
                    }
                    if ($fin) {
                        return $message;
                    }
                    continue 2;
                default:
                    throw new WebSocketException(\sprintf('Trame WebSocket non gérée (type %d).', $opcode));
            }
        }
    }

    public function close(): void
    {
        if (!$this->closed) {
            $this->closed = true;
            try {
                $this->sendFrame(self::CLOSE, pack('n', 1000));
            } catch (WebSocketException) {
                // Connexion déjà coupée : rien à dire au serveur.
            }
        }
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        $length = \strlen($payload);
        $frame = \chr(0x80 | $opcode);
        if ($length < 126) {
            $frame .= \chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= \chr(0x80 | 126).pack('n', $length);
        } else {
            $frame .= \chr(0x80 | 127).pack('J', $length);
        }
        // Le client masque toujours ses trames (RFC 6455, § 5.3).
        $mask = random_bytes(4);
        // XOR de chaînes : le résultat a la longueur de la plus courte, ici le contenu.
        $this->write($frame.$mask.($payload ^ str_repeat($mask, intdiv($length, 4) + 1)));
    }

    /**
     * @return array{bool, int, string} fin, opcode, contenu
     */
    private function readFrame(): array
    {
        [$b0, $b1] = array_values(unpack('C2', $this->read(2)));
        $length = $b1 & 0x7F;
        if (126 === $length) {
            $length = unpack('n', $this->read(2))[1];
        } elseif (127 === $length) {
            $length = unpack('J', $this->read(8))[1];
        }
        if ($length > self::MAX_MESSAGE) {
            throw new WebSocketException('Message WebSocket trop long.');
        }
        $mask = ($b1 & 0x80) ? $this->read(4) : null;
        $payload = $length > 0 ? $this->read($length) : '';
        if (null !== $mask) {
            $payload ^= str_repeat($mask, intdiv($length, 4) + 1);
        }

        return [(bool) ($b0 & 0x80), $b0 & 0x0F, $payload];
    }

    private function read(int $length): string
    {
        $data = '';
        while (\strlen($data) < $length) {
            $chunk = fread($this->stream, $length - \strlen($data));
            if (false === $chunk || '' === $chunk) {
                $this->failIfTimedOut();
                if (feof($this->stream)) {
                    throw new WebSocketException('Le serveur a coupé la connexion WebSocket.');
                }
                continue;
            }
            $data .= $chunk;
        }

        return $data;
    }

    private function write(string $data): void
    {
        while ('' !== $data) {
            $written = @fwrite($this->stream, $data);
            if (false === $written || 0 === $written) {
                $this->failIfTimedOut();
                throw new WebSocketException('Écriture impossible sur la connexion WebSocket.');
            }
            $data = substr($data, $written);
        }
    }

    private function failIfTimedOut(): void
    {
        if (stream_get_meta_data($this->stream)['timed_out'] ?? false) {
            throw new WebSocketException('Pas de réponse du serveur WebSocket dans le délai.');
        }
    }
}
