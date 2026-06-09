<?php

namespace ZuqongTech\Kronos\ReactPHP\WebSocket;

use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Io\ServerRequest;
use React\Http\Message\Response;
use React\Http\Middleware\StreamingRequestMiddleware;
use React\Socket\SocketServer;
use React\Stream\ThroughStream;
use ZuqongTech\Kronos\ReactPHP\Contracts\KronosDaemonComponent;

/**
 * KronosWebSocketServer — real-time workflow run status broadcaster.
 *
 * Pure ReactPHP WebSocket server — no Ratchet dependency.
 * Clients connect via:
 *   const ws = new WebSocket('ws://localhost:6001/kronos?run_id=<uuid>');
 *   const ws = new WebSocket('ws://localhost:6001/kronos?run_id=*');
 */
class KronosWebSocketServer implements KronosDaemonComponent
{
    /** @var array<string, array<string, ServerRequest>> run_id → connId → connection */
    protected array $subscriptions = [];

    /** @var array<string, callable> connId → send callable */
    protected array $senders = [];

    protected ?SocketServer $socket = null;

    /**
     * Attach the WebSocket server to the shared event loop.
     */
    public function attach(LoopInterface $loop): void
    {
        if (!config('kronos.reactphp.websocket.enabled', false)) {
            echo '[Kronos] WebSocket server disabled — skipping.'.PHP_EOL;

            return;
        }

        $port = config('kronos.reactphp.websocket.port', 6001);
        $address = '0.0.0.0:'.$port;

        $this->socket = new SocketServer($address, [], $loop);

        $httpServer = new HttpServer(
            new StreamingRequestMiddleware,
            fn (ServerRequestInterface $serverRequest): Response => $this->handleRequest($serverRequest),
        );

        $httpServer->listen($this->socket);

        echo '[Kronos] WebSocket server listening on ws://'.$address.PHP_EOL;
    }

    public function detach(): void
    {
        $this->socket?->close();
        $this->subscriptions = [];
        $this->senders = [];
        echo '[Kronos] WebSocket server detached.'.PHP_EOL;
    }

    // ── Request handler ───────────────────────────────────────────────────────

    private function handleRequest(ServerRequestInterface $serverRequest): Response
    {
        // Only accept WebSocket upgrades
        $upgrade = $serverRequest->getHeaderLine('Upgrade');
        if (strtolower($upgrade) !== 'websocket') {
            return new Response(400, [], 'Expected WebSocket upgrade');
        }

        parse_str($serverRequest->getUri()->getQuery(), $params);
        $runId = $params['run_id'] ?? '*';
        $connId = uniqid('ws_', true);

        // Perform the HTTP → WebSocket handshake
        $key = $serverRequest->getHeaderLine('Sec-WebSocket-Key');
        $accept = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

        return new Response(
            101,
            [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $accept,
            ],
            $this->buildConnection($runId, $connId),
        );
    }

    private function buildConnection(
        string $runId,
        string $connId,
    ): ThroughStream {
        $throughStream = new ThroughStream;

        // Register sender
        $this->senders[$connId] = function (string $payload) use ($throughStream): void {
            $throughStream->write($this->encodeFrame($payload));
        };

        // Subscribe
        $this->subscriptions[$runId] ??= [];
        $this->subscriptions[$runId][$connId] = true;

        // Send connected acknowledgement
        ($this->senders[$connId])(json_encode([
            'type' => 'connected',
            'run_id' => $runId,
            'ts' => now()->toIso8601String(),
        ]));

        echo '[Kronos] WebSocket client connected — run_id: '.$runId.PHP_EOL;

        $throughStream->on('close', function () use ($runId, $connId): void {
            unset($this->senders[$connId]);
            unset($this->subscriptions[$runId][$connId]);

            if (empty($this->subscriptions[$runId])) {
                unset($this->subscriptions[$runId]);
            }

            echo '[Kronos] WebSocket client disconnected — run_id: '.$runId.PHP_EOL;
        });

        return $throughStream;
    }

    // ── Broadcasting ──────────────────────────────────────────────────────────

    /**
     * Broadcast a JSON payload to all subscribers of its run_id + wildcard '*'.
     */
    public function broadcast(string $payload): void
    {
        $data = json_decode($payload, true);
        $runId = $data['run_id'] ?? null;

        $targets = array_merge(
            $runId ? array_keys($this->subscriptions[$runId] ?? []) : [],
            array_keys($this->subscriptions['*'] ?? []),
        );

        foreach (array_unique($targets) as $connId) {
            $sender = $this->senders[$connId] ?? null;
            if ($sender !== null) {
                $sender($payload);
            }
        }
    }

    /**
     * Push a typed event for a run directly from KronosOrchestrator.
     */
    public function push(string $runId, string $event, array $data = []): void
    {
        $this->broadcast(json_encode(array_merge($data, [
            'run_id' => $runId,
            'event' => $event,
            'ts' => now()->toIso8601String(),
        ])));
    }

    // ── WebSocket framing ─────────────────────────────────────────────────────

    /**
     * Encode a text payload as a WebSocket frame (RFC 6455, opcode 0x1).
     */
    private function encodeFrame(string $payload): string
    {
        $len = strlen($payload);

        if ($len < 126) {
            return "\x81".chr($len).$payload;
        }

        if ($len < 65536) {
            return "\x81\x7E".pack('n', $len).$payload;
        }

        return "\x81\x7F".pack('J', $len).$payload;
    }
}
