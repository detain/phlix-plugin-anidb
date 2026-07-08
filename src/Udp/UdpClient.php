<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Udp;

/**
 * AniDB UDP session and command client.
 *
 * Encapsulates the AniDB UDP protocol lifecycle:
 * - Authentication (AUTH command, session key management)
 * - Command execution with automatic re-auth on 506 SESSION EXPIRED
 * - Flood protection (4-second minimum between commands)
 * - Keep-alive pings to maintain the session
 *
 * This class is the "high-level" UDP client that sits above the raw
 * {@see UdpClientInterface} transport seam. It adds:
 * - Session key tracking and automatic append to commands
 * - Flood protection via the {@see WaiterInterface} abstraction
 * - Automatic re-authentication on session expiry (506 responses)
 *
 * The {@see WaiterInterface} allows non-blocking flood protection delays
 * when a non-blocking implementation (e.g. Workerman\Timer) is available.
 *
 * @package Phlix\Anidb\Udp
 */
final class UdpClient
{
    /**
     * Minimum interval between UDP commands in seconds (flood protection).
     */
    private const FLOOD_PROTECTION_INTERVAL_SEC = 4.0;

    /**
     * Ping interval in seconds (30 minutes).
     */
    private const PING_INTERVAL_SEC = 30 * 60;

    /**
     * AniDB UDP API server hostname.
     */
    private const API_HOST = 'api.anidb.net';

    /**
     * AniDB UDP API server port.
     */
    private const API_PORT = 9000;

    /**
     * Active AniDB session key (null if not authenticated).
     */
    private ?string $sessionKey = null;

    /**
     * Timestamp of last API command (for flood protection).
     */
    private float $lastSendTimestamp = 0.0;

    /**
     * Timestamp of last session activity.
     */
    private ?int $lastActivityTime = null;

    /**
     * Plugin settings for authentication.
     *
     * @var array{username: string, api_key: string}
     */
    private array $settings;

    /**
     * Raw UDP transport seam.
     */
    private UdpClientInterface $transport;

    /**
     * Waiter seam for flood-protection delays.
     */
    private WaiterInterface $waiter;

    /**
     * @param array{username: string, api_key: string} $settings AniDB credentials.
     * @param UdpClientInterface|null                   $transport Raw UDP transport. Defaults to
     *                                                                SocketUdpClient bound to AniDB endpoint.
     * @param WaiterInterface|null                     $waiter Flood-protection waiter. Defaults to
     *                                                             ProductionWaiter (blocking).
     */
    public function __construct(
        array $settings,
        ?UdpClientInterface $transport = null,
        ?WaiterInterface $waiter = null,
    ) {
        $this->settings = $settings;
        $this->transport = $transport ?? new SocketUdpClient(
            self::API_HOST,
            self::API_PORT,
        );
        $this->waiter = $waiter ?? new ProductionWaiter();
    }

    /**
     * Open the UDP transport (fixed local port >1024 to avoid multi-port ban).
     *
     * @return void
     *
     * @throws \RuntimeException If socket creation/binding fails.
     */
    public function open(): void
    {
        $this->transport->open();
    }

    /**
     * Close the UDP transport and clear session.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->sessionKey !== null) {
            $this->logout();
        }
        $this->transport->close();
    }

    /**
     * Send a command to AniDB with automatic session management.
     *
     * AUTH is performed lazily on first command when sessionKey is null,
     * keeping the caller non-blocking. The first command after a session
     * expiry (506) will also trigger re-auth.
     *
     * @param string $command   The command to send (without session key).
     * @param int    $retryCount Tracks recursion depth for 506 re-auth retries (max 3).
     *
     * @return string|null Response string or null on timeout.
     */
    public function sendCommand(string $command, int $retryCount = 0): ?string
    {
        // Lazy AUTH: authenticate on first command if not yet authenticated.
        if ($this->sessionKey === null) {
            $this->authenticate();
        }

        // Attach session key
        $fullCommand = $command . '&s=' . $this->sessionKey;

        // Flood protection: enforce minimum interval between sends
        $this->enforceFloodProtection();

        // Keep session alive if needed
        if ($this->needsKeepAlive()) {
            $this->ping();
        }

        $result = $this->udpSend($fullCommand);

        if ($result !== null) {
            $this->lastActivityTime = time();
        }

        // Handle session-expired response: re-authenticate and retry with recursion guard.
        if ($result !== null && str_starts_with(trim($result), '506')) {
            if ($retryCount >= 3) {
                throw new \RuntimeException(
                    'AniDB session expired: re-authentication failed after 3 retries'
                );
            }
            $this->authenticate();
            // Strip the old session key from fullCommand and retry
            $retryCommand = str_replace('&s=' . $this->sessionKey, '', $fullCommand);
            $result = $this->sendCommand($retryCommand, $retryCount + 1);
        }

        return $result;
    }

    /**
     * Authenticate to AniDB via AUTH command.
     *
     * AUTH is special: it has no session key (the server assigns one in the
     * response), so we bypass sendCommand() which would incorrectly append
     * '&s=' to a null sessionKey. AUTH also bypasses flood protection since
     * it's the first packet sent; the server enforces the rate limit.
     *
     * @return void
     *
     * @throws \RuntimeException If AUTH fails.
     */
    public function authenticate(): void
    {
        $cmd = sprintf(
            'AUTH user=%s&pass=%s&protover=3&client=phlix&clientver=1',
            urlencode($this->settings['username']),
            urlencode($this->settings['api_key'])
        );

        // Send AUTH directly — bypass sendCommand() which appends '&s='.
        $response = $this->udpSend($cmd);

        if ($response === null) {
            throw new \RuntimeException('AniDB AUTH: no response (timeout or network failure)');
        }

        // Parse: "200 SESSION_KEY LOGIN ACCEPTED" or "201 SESSION_KEY ..."
        if (!preg_match('/^(200|201)\s+(\S+)\s+/', $response, $matches)) {
            throw $this->parseAuthFailure($response);
        }

        $this->sessionKey = $matches[2];
        $this->lastActivityTime = time();
    }

    /**
     * Send LOGOUT to AniDB and clear session.
     *
     * @return void
     */
    public function logout(): void
    {
        if ($this->sessionKey === null) {
            return;
        }

        $this->udpSend('LOGOUT s=' . $this->sessionKey);
        $this->sessionKey = null;
        $this->lastActivityTime = null;
    }

    /**
     * Send a PING to keep the session alive.
     *
     * @return void
     */
    private function ping(): void
    {
        if ($this->sessionKey === null) {
            return;
        }

        $this->udpSend('PING s=' . $this->sessionKey);
        $this->lastActivityTime = time();
    }

    /**
     * Check if the session needs a keepalive ping.
     *
     * @return bool True if ping should be sent.
     */
    private function needsKeepAlive(): bool
    {
        if ($this->lastActivityTime === null || $this->sessionKey === null) {
            return false;
        }

        return (time() - $this->lastActivityTime) >= self::PING_INTERVAL_SEC;
    }

    /**
     * Parse an AUTH failure response and return an appropriate exception.
     *
     * @param string $response Raw response string.
     *
     * @return \RuntimeException
     */
    public function parseAuthFailure(string $response): \RuntimeException
    {
        if (str_starts_with($response, '500')) {
            return new \RuntimeException('AniDB AUTH failed: Invalid username or API password');
        }
        if (str_starts_with($response, '503')) {
            return new \RuntimeException('AniDB AUTH failed: Client version outdated');
        }
        if (str_starts_with($response, '504')) {
            return new \RuntimeException('AniDB AUTH failed: Client banned — ' . substr($response, 4));
        }
        if (str_starts_with($response, '555')) {
            return new \RuntimeException('AniDB AUTH failed: Banned — ' . substr($response, 4));
        }

        return new \RuntimeException('AniDB AUTH failed: ' . $response);
    }

    /**
     * Low-level UDP send/receive via the transport seam.
     *
     * @param string $data Command string to send.
     *
     * @return string|null Response string or null on timeout or invalid origin.
     *
     * @throws \RuntimeException If the UDP socket is not open.
     */
    private function udpSend(string $data): ?string
    {
        $this->lastSendTimestamp = microtime(true);

        $response = $this->transport->send($data);

        // S2/S3 origin validation: reject replies not from api.anidb.net:9000
        // This prevents forged responses from being accepted.
        if ($response !== null) {
            $replyHost = $this->transport->lastReplyHost();
            $replyPort = $this->transport->lastReplyPort();

            if ($replyHost !== self::API_HOST || $replyPort !== self::API_PORT) {
                return null; // Spoofed or misrouted reply — signal caller to retry
            }
        }

        return $response;
    }

    /**
     * Enforce the 4-second minimum between UDP commands.
     *
     * Uses the injected {@see WaiterInterface} instead of blocking `usleep()`,
     * allowing non-blocking implementations to yield to the event loop.
     *
     * @return void
     */
    private function enforceFloodProtection(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastSendTimestamp;

        $waitTime = self::FLOOD_PROTECTION_INTERVAL_SEC - $elapsed;
        if ($waitTime > 0) {
            $this->waiter->wait($waitTime);
        }

        $this->lastSendTimestamp = microtime(true);
    }

    /**
     * Whether the client has an active session.
     *
     * @return bool True if authenticated.
     */
    public function isAuthenticated(): bool
    {
        return $this->sessionKey !== null;
    }

    /**
     * Get the current session key (for testing).
     *
     * @return string|null
     */
    public function getSessionKey(): ?string
    {
        return $this->sessionKey;
    }
}
