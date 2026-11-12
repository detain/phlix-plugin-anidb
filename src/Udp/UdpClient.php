<?php

/**
 * AniDB UDP session and command client.
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
 * - Flood protection (minimum interval between ANY packets — see
 *   {@see self::FLOOD_PROTECTION_INTERVAL_SEC}; the server counts PINGs and
 *   AUTH retries toward the same rate limit as commands)
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
     * Minimum interval between UDP packets in seconds (flood protection).
     *
     * AniDB forbids more than one packet per interval and counts every
     * datagram from the client — commands, PING, AUTH and LOGOUT alike.
     * The gate runs inside {@see self::udpSend()}, the single point all
     * packets leave through, so no sender path can bypass it.
     */
    private const FLOOD_PROTECTION_INTERVAL_SEC = 4.0;

    /**
     * Ping interval in seconds (30 minutes).
     */
    private const PING_INTERVAL_SEC = 30 * 60;

    /**
     * AniDB UDP API server hostname — the single source of truth: the transport
     * dial (SocketUdpClient defaults, provider wiring) and reply-origin
     * validation both consume it, so a one-sided edit cannot desync them.
     */
    public const API_HOST = 'api.anidb.net';

    /**
     * AniDB UDP API server port.
     */
    public const API_PORT = 9000;

    /**
     * Max bytes of an unexpected AUTH-failure body kept in an exception.
     */
    private const AUTH_FAILURE_BODY_MAX = 120;

    /**
     * TTL for the lazily-resolved reply-origin allowlist, in seconds (1 hour).
     *
     * PG-158 (review anidb__srphp #9[i]): AniDB rotates the A records behind
     * api.anidb.net, and the old memo lived for the process lifetime — in a
     * resident Workerman worker that never restarts, every genuine reply
     * became a silent origin-validation failure once the addresses turned
     * over, and the provider degraded to permanent timeouts naming no cause.
     * A memo that expires hourly re-resolves on the next validation after a
     * rotation. Lists explicitly injected through the constructor are the
     * trusted seam, not a DNS memo, and never expire.
     */
    private const ORIGIN_HOSTS_TTL_SEC = 3600;

    /**
     * Minimum interval between rejected-origin log lines, in seconds.
     *
     * The flood gate caps packets at one per 4 s, so an unthrottled rejection
     * line could storm the shared error log at fifteen lines a minute during
     * a rotation; one named line every 5 minutes is plenty to diagnose it.
     */
    private const ORIGIN_REJECT_LOG_INTERVAL_SEC = 300;

    /**
     * Setting key → the exact settings-form label (plugin.json `label`) the
     * credential-guard message prints, quoted, so an operator finds the field
     * on the form verbatim instead of decoding raw setting keys. Kept in
     * lockstep with plugin.json by UdpClientCredentialGuardTest, which reads
     * the manifest labels and asserts the thrown message carries them.
     */
    private const CREDENTIAL_LABELS = [
        'username' => 'AniDB Username',
        'api_key' => 'AniDB UDP API Key',
    ];

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
     * PG-158: lazily-resolved lists are a memo governed by
     * {@see ORIGIN_HOSTS_TTL_SEC}; a constructor-injected list is the trusted
     * set and never expires (see {@see self::$originHostsFromMemo}).
     *
     * @var list<string>|null
     */
    private ?array $validOriginHosts;

    /**
     * Whether {@see self::$validOriginHosts} is the lazy DNS memo (true) or
     * the constructor-injected trusted set (false).
     */
    private bool $originHostsFromMemo = false;

    /**
     * time() at which the lazy origin memo was last resolved.
     */
    private int $originHostsResolvedAt = 0;

    /**
     * time() at which a rejected reply origin was last named in the log.
     *
     * Zero at boot so the first rejection logs immediately.
     */
    private int $lastOriginRejectLogAt = 0;

    /**
     * Name-resolution seam: hostname → its A-record list.
     *
     * @var callable(string): (list<string>|false|null)
     */
    private $originHostsResolver;

    /**
     * @param array{username: string, api_key: string} $settings AniDB credentials.
     * @param UdpClientInterface|null                   $transport Raw UDP transport. Defaults to
     *                                                                SocketUdpClient bound to AniDB endpoint.
     * @param WaiterInterface|null                     $waiter Flood-protection waiter. Defaults to
     *                                                             ProductionWaiter (blocking).
     * @param list<string>|null                        $validOriginHosts Explicit set of acceptable
     *                                                             reply-origin hosts (IPs and/or the
     *                                                             hostname). Defaults to null → resolved
     *                                                             from API_HOST (TTL-memoized, see
     *                                                             {@see ORIGIN_HOSTS_TTL_SEC}).
     * @param callable(string):(list<string>|false|null)|null $originHostsResolver
     *                                                             Seam for the lazy A-record lookup.
     *                                                             Defaults to null → @gethostbynamel().
     *                                                             Injectable so TTL tests can count
     *                                                             resolutions without live DNS.
     */
    public function __construct(
        array $settings,
        ?UdpClientInterface $transport = null,
        ?WaiterInterface $waiter = null,
        ?array $validOriginHosts = null,
        ?callable $originHostsResolver = null,
    ) {
        $this->settings = $settings;
        $this->transport = $transport ?? new SocketUdpClient(
            self::API_HOST,
            self::API_PORT,
        );
        $this->waiter = $waiter ?? new ProductionWaiter();
        $this->validOriginHosts = $validOriginHosts;
        $this->originHostsResolver = $originHostsResolver ?? self::resolveAnidbHosts(...);
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

        // Keep session alive if needed. Both the PING and the command below
        // pay the flood interval inside udpSend() — every packet, no bypass
        // (an enforce-before-ping call here used to gate only the pair's
        // first packet and let the command follow its PING back-to-back).
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
     * '&s=' to a null sessionKey. The flood interval is NOT bypassed: AUTH
     * passes the same gate inside udpSend() as every other packet. At boot
     * it is the first send and the gate opens without waiting; on a 506
     * re-auth the AUTH datagram waits its turn behind the command that just
     * expired the session.
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
     * an empty string (fresh installs before the AniDB UDP API Key is entered).
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

        foreach (self::CREDENTIAL_LABELS as $key => $label) {
            $value = $this->settings[$key] ?? null;
            if (!is_string($value) || $value === '') {
                $unusable[] = $label;
            }
        }

        if ($unusable !== []) {
            $quote = static fn (string $label): string => '"' . $label . '"';
            throw new \RuntimeException(sprintf(
                'AniDB: the %s settings are required (missing or empty: %s)',
                implode(' and ', array_map($quote, self::CREDENTIAL_LABELS)),
                implode(', ', array_map($quote, $unusable))
            ));
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
     * Code semantics follow the AniDB UDP API definition: 500 LOGIN FAILED,
     * 503 CLIENT VERSION OUTDATED, 504 CLIENT BANNED (that version of the
     * client software is banned — NOT the user account), 555 BANNED (a
     * temporary ban; the code exists to tell developers what they are doing
     * wrong — typically API misuse such as flooding). Labels below keep the
     * two bans distinguishable at a glance in logs, and the 504 label says
     * outright that the user's account is not the thing that is banned.
     *
     * Every arm that echoes server bytes (504, 555 and the generic arm)
     * routes them through scrubAuthFailureBody() — session keys redacted,
     * whitespace collapsed, length bounded (M-ANIDB-29) — so no datagram
     * reaches an exception message raw.
     *
     * @param string $response Raw response string.
     *
     * @return \RuntimeException
     */
    public function parseAuthFailure(string $response): \RuntimeException
    {
        if (str_starts_with($response, '500')) {
            return new \RuntimeException(
                'AniDB AUTH failed: invalid "' . self::CREDENTIAL_LABELS['username'] . '" or "'
                . self::CREDENTIAL_LABELS['api_key'] . '" (the key is the API password set in your'
                . ' AniDB profile, not your login password)'
            );
        }
        if (str_starts_with($response, '503')) {
            return new \RuntimeException('AniDB AUTH failed: client version outdated');
        }
        if (str_starts_with($response, '504')) {
            return new \RuntimeException(self::withScrubbedDetail(
                'AniDB AUTH failed: this client version is banned (your account is not)',
                substr($response, 3)
            ));
        }
        if (str_starts_with($response, '555')) {
            return new \RuntimeException(self::withScrubbedDetail(
                'AniDB AUTH failed: temporarily banned',
                substr($response, 3)
            ));
        }

        return new \RuntimeException('AniDB AUTH failed: ' . self::scrubAuthFailureBody($response));
    }

    /**
     * Append the scrubbed server detail to a ban label as "<label> — <detail>",
     * or return the bare label when the reply carried no detail (no dangling
     * separator).
     */
    private static function withScrubbedDetail(string $label, string $detail): string
    {
        $scrubbed = self::scrubAuthFailureBody($detail);

        return $scrubbed === '' ? $label : $label . ' — ' . $scrubbed;
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
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $response));
        $scrubbed = (string) preg_replace('/\b(200|201)\s+\S+/', '$1 [session-key-redacted]', $collapsed);

        if (strlen($scrubbed) > self::AUTH_FAILURE_BODY_MAX) {
            return substr($scrubbed, 0, self::AUTH_FAILURE_BODY_MAX) . '…';
        }

        return $scrubbed;
    }

    /**
     * Low-level UDP send/receive via the transport seam.
     *
     * The single choke point every packet leaves through — the flood gate
     * runs here so commands, PING, AUTH and LOGOUT all pay the minimum
     * interval. The boot AUTH is still immediate: with no previous send
     * (lastSendTimestamp 0.0) the gate has nothing to space behind.
     *
     * @param string $data Command string to send.
     *
     * @return string|null Response string or null on timeout or invalid origin.
     *
     * @throws \RuntimeException If the UDP socket is not open.
     */
    private function udpSend(string $data): ?string
    {
        $this->enforceFloodProtection();

        $response = $this->transport->send($data);

        // S2/S3 origin validation: reject replies not from api.anidb.net:9000.
        // This prevents forged responses from being accepted.
        if ($response !== null) {
            $replyHost = $this->transport->lastReplyHost();
            $replyPort = $this->transport->lastReplyPort();

            if (!$this->isValidReplyOrigin($replyHost, $replyPort)) {
                // PG-158: name the rejected origin (throttled) so a DNS
                // rotation is diagnosable — then signal the caller to retry.
                $this->logRejectedOrigin($replyHost, $replyPort);

                return null; // Spoofed or misrouted reply
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
     * Resolve the set of acceptable reply-origin hosts — TTL-memoized.
     *
     * A lazily-resolved memo older than {@see ORIGIN_HOSTS_TTL_SEC} is
     * re-resolved on the next validation, so an AniDB A-record rotation heals
     * within the TTL instead of silently dropping every genuine reply for the
     * process lifetime (PG-158). A constructor-injected list is the trusted
     * set — returned verbatim, never expired, never resolved.
     *
     * @return list<string> Hostname + its resolved A-record IPs.
     */
    private function resolveValidOriginHosts(): array
    {
        if ($this->validOriginHosts !== null) {
            if (!$this->originHostsFromMemo) {
                return $this->validOriginHosts;
            }

            if (time() - $this->originHostsResolvedAt < self::ORIGIN_HOSTS_TTL_SEC) {
                return $this->validOriginHosts;
            }
        }

        $hosts = [self::API_HOST];
        $resolved = ($this->originHostsResolver)(self::API_HOST);
        if (is_array($resolved)) {
            $hosts = array_merge($hosts, $resolved);
        }

        $this->validOriginHosts = array_values(array_unique($hosts));
        $this->originHostsFromMemo = true;
        $this->originHostsResolvedAt = time();

        return $this->validOriginHosts;
    }

    /**
     * Default name-resolution seam: the A records of the API host.
     *
     * @return list<string>|false False when DNS resolution fails — the caller
     *                            treats it as "hostname only" (unchanged posture).
     */
    private static function resolveAnidbHosts(string $host): array|false
    {
        return @gethostbynamel($host);
    }

    /**
     * Log a rejected reply origin, at most once per throttle window.
     *
     * PG-158 (review anidb__srphp #9[i]): the rejection used to be invisible —
     * udpSend() returned null and AUTH surfaced the generic "no response
     * (timeout or network failure)", leaving a permanent-timeout provider no
     * line to name the cause. The message carries only the origin peer (as
     * reported by recvfrom(), truncated to a bounded length so no reply
     * payload can hide inside it), never the reply bytes themselves.
     */
    private function logRejectedOrigin(?string $replyHost, ?int $replyPort): void
    {
        $now = time();
        if ($now - $this->lastOriginRejectLogAt < self::ORIGIN_REJECT_LOG_INTERVAL_SEC) {
            return;
        }
        $this->lastOriginRejectLogAt = $now;

        error_log(sprintf(
            'AniDB UDP: reply rejected — origin %s:%s is not in the %s allowlist '
            . '(the resolved set refreshes within %d s). Throttled to once per %d s.',
            $replyHost === null ? '(unknown)' : substr($replyHost, 0, 64),
            $replyPort === null ? '(unknown)' : (string) $replyPort,
            self::API_HOST,
            self::ORIGIN_HOSTS_TTL_SEC,
            self::ORIGIN_REJECT_LOG_INTERVAL_SEC,
        ));
    }

    /**
     * Enforce the minimum interval between UDP packets.
     *
     * Called by {@see self::udpSend()} before EVERY datagram leaves, so the
     * gate covers commands, PING, AUTH and LOGOUT alike — AniDB counts them
     * all toward one per-client rate limit and exceeding it drops further
     * packets without feedback.
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

        // Stamp AFTER the (possible) wait so the next gate measures from the
        // moment this packet is about to leave, not from before its wait.
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
}
