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
     * Max bytes of an unexpected AUTH-failure body kept in an exception.
     */
    private const AUTH_FAILURE_BODY_MAX = 120;

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
     * Valid reply-origin hosts for datagram origin validation.
     *
     * When null it is resolved lazily from {@see API_HOST} (hostname + all its
     * A-record IPs) so the numeric peer IP reported by `recvfrom()` can be
     * compared like-for-like. Injectable so tests can assert origin validation
     * against a realistic dotted-quad without live DNS.
     *
     * @var list<string>|null
     */
    private ?array $validOriginHosts;

    /**
     * @param array{username: string, api_key: string} $settings AniDB credentials.
     * @param UdpClientInterface|null                   $transport Raw UDP transport. Defaults to
     *                                                                SocketUdpClient bound to AniDB endpoint.
     * @param WaiterInterface|null                     $waiter Flood-protection waiter. Defaults to
     *                                                             ProductionWaiter (blocking).
     * @param list<string>|null                        $validOriginHosts Explicit set of acceptable
     *                                                             reply-origin hosts (IPs and/or the
     *                                                             hostname). Defaults to null → resolved
     *                                                             from API_HOST via gethostbynamel().
     */
    public function __construct(
        array $settings,
        ?UdpClientInterface $transport = null,
        ?WaiterInterface $waiter = null,
        ?array $validOriginHosts = null,
    ) {
        $this->settings = $settings;
        $this->transport = $transport ?? new SocketUdpClient(
            self::API_HOST,
            self::API_PORT,
        );
        $this->waiter = $waiter ?? new ProductionWaiter();
        $this->validOriginHosts = $validOriginHosts;
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
     * deferring all socket work off the construction path. The first command
     * after a session
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
            // Retry with the ORIGINAL command (which carries no session key);
            // sendCommand() re-appends the freshly-minted key. NB: authenticate()
            // has already replaced $this->sessionKey, so str_replace()-ing the
            // NEW key out of $fullCommand (which still holds the OLD key) stripped
            // the wrong key and left a stale &s= in the retried command.
            $result = $this->sendCommand($command, $retryCount + 1);
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
     * Posture note: the AniDB UDP API has no encrypted transport — the
     * username and API password travel in a cleartext datagram. This is
     * protocol-inherent (there is no TCP/TLS alternative); operators should
     * use a dedicated AniDB account whose API password differs from the
     * site login password. See README ("Protocol & credential posture").
     *
     * @return void
     *
     * @throws \RuntimeException If credentials are unusable or AUTH fails.
     */
    public function authenticate(): void
    {
        $this->requireCredentials();

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
     * Assert usable credentials exist before any AUTH command is built.
     *
     * The constructor's array{username, api_key} shape is documentation-time
     * only — plugin settings can deliver a missing key, a non-string value, or
     * an empty string (fresh installs before an API password is entered).
     * Without this guard the sprintf() in authenticate() emitted an undefined-
     * key warning and sent a malformed AUTH datagram the server rejected
     * opaquely. Fail fast with a descriptive error instead.
     *
     * @return void
     *
     * @throws \RuntimeException If either credential is absent, non-string, or empty.
     */
    private function requireCredentials(): void
    {
        $unusable = [];

        foreach (['username', 'api_key'] as $key) {
            $value = $this->settings[$key] ?? null;
            if (!is_string($value) || $value === '') {
                $unusable[] = $key;
            }
        }

        if ($unusable !== []) {
            throw new \RuntimeException(
                'anidb: username/api_key settings are required (missing or empty: '
                . implode(', ', $unusable) . ')'
            );
        }
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

        return new \RuntimeException('AniDB AUTH failed: ' . self::scrubAuthFailureBody($response));
    }

    /**
     * Sanitize an unexpected AUTH datagram for embedding in an exception.
     *
     * The generic failure arm sees whatever bytes arrived; a malformed or
     * interleaved reply can still carry `200/201 <session-key>` frames
     * (review M-ANIDB-29). Keys are redacted, whitespace collapsed, and the
     * body bounded so logs never capture credentials or unbounded payloads.
     */
    private static function scrubAuthFailureBody(string $response): string
    {
        $collapsed = (string) preg_replace('/\s+/u', ' ', $response);
        $scrubbed = (string) preg_replace('/\b(200|201)\s+\S+/', '$1 [session-key-redacted]', $collapsed);

        if (strlen($scrubbed) > self::AUTH_FAILURE_BODY_MAX) {
            return substr($scrubbed, 0, self::AUTH_FAILURE_BODY_MAX) . '…';
        }

        return $scrubbed;
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

        // S2/S3 origin validation: reject replies not from api.anidb.net:9000.
        // This prevents forged responses from being accepted.
        if ($response !== null) {
            if (!$this->isValidReplyOrigin($this->transport->lastReplyHost(), $this->transport->lastReplyPort())) {
                return null; // Spoofed or misrouted reply — signal caller to retry
            }
        }

        return $response;
    }

    /**
     * Whether a datagram reply came from the legitimate AniDB endpoint.
     *
     * The datagram peer reported by `recvfrom()` is a numeric IP, NEVER a
     * hostname — so comparing it against the `api.anidb.net` hostname constant
     * (the original bug) could never succeed and every real reply was dropped.
     * We instead compare like-for-like against the RESOLVED IPs of the API host.
     *
     * @param string|null $replyHost Reply source host (IP) reported by the transport.
     * @param int|null    $replyPort Reply source port reported by the transport.
     *
     * @return bool True when the origin is trusted.
     */
    private function isValidReplyOrigin(?string $replyHost, ?int $replyPort): bool
    {
        if ($replyPort !== self::API_PORT || $replyHost === null) {
            return false;
        }

        return in_array($replyHost, $this->resolveValidOriginHosts(), true);
    }

    /**
     * Resolve (and memoize) the set of acceptable reply-origin hosts.
     *
     * @return list<string> Hostname + its resolved A-record IPs.
     */
    private function resolveValidOriginHosts(): array
    {
        if ($this->validOriginHosts !== null) {
            return $this->validOriginHosts;
        }

        $hosts = [self::API_HOST];
        $ips = @gethostbynamel(self::API_HOST);
        if (is_array($ips)) {
            $hosts = array_merge($hosts, $ips);
        }

        return $this->validOriginHosts = array_values(array_unique($hosts));
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
