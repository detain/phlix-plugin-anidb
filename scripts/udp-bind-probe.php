<?php

/**
 * Measurement harness for ANI-S23 (OWNER-GATED: run on the production host
 * only when the owner provides access; it is not run by CI or phpunit).
 *
 * Usage:
 *   php scripts/udp-bind-probe.php listen <port> <reuse:0|1> <label> <seconds>
 *   php scripts/udp-bind-probe.php send   <port>
 *
 * Start two `listen` processes on the same port, then run `send` and see which
 * label(s) report receipt. Sends only to 127.0.0.1; never contacts AniDB.
 */

declare(strict_types=1);

$mode = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);

if ($port < 1025 || $port > 65535) {
    fwrite(STDERR, "usage: listen <port> <reuse> <label> <seconds> | send <port>\n");
    exit(2);
}

$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sock === false) {
    fwrite(STDERR, "socket_create failed\n");
    exit(1);
}

if ($mode === 'send') {
    $n = socket_sendto($sock, 'probe', 5, 0, '127.0.0.1', $port);
    echo $n === 5 ? "sent\n" : "send failed\n";
    exit($n === 5 ? 0 : 1);
}

if ($mode !== 'listen') {
    fwrite(STDERR, "mode must be listen or send\n");
    exit(2);
}

$reuse = ($argv[3] ?? '0') === '1';
$label = (string) ($argv[4] ?? 'p');
$seconds = max(1, (int) ($argv[5] ?? 10));

if ($reuse) {
    socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
}
if (!@socket_bind($sock, '0.0.0.0', $port)) {
    echo "$label: bind FAILED: " . socket_strerror(socket_last_error($sock)) . "\n";
    exit(1);
}
echo "$label: bound port $port reuse=" . ($reuse ? 'on' : 'off') . "\n";
socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $seconds, 'usec' => 0]);
$buf = '';
$got = @socket_recvfrom($sock, $buf, 64, 0, $from, $fromPort);
echo $got === false ? "$label: no datagram within {$seconds}s\n" : "$label: RECEIVED '$buf'\n";
