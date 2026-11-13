# AniDB UDP wire capture, 2026-11-13 (redacted)

Credentialed-capture gate opened by the owner for what the API key alone
allows. No password was supplied, so **no AUTH was ever attempted**. Total
traffic: 4 packets to `api.anidb.net:9000` in two single-threaded runs, at
least 3 s apart, no retries. Account name, API key and salts are redacted;
`<USER>`, `<API_KEY>`, `<SALT>` are placeholders.

## Observed

| Sent (plaintext) | Received | Notes |
|------------------|----------|-------|
| `PING` | `300 PONG\n` | Unauthenticated, one datagram. A real result frame: not a 001 ACK-only overhead frame and not `200`. |
| `ENCRYPT user=<USER>&type=1` | `209 <SALT> ENCRYPTION ENABLED\n` | `<SALT>` is 8 alphanumerics, fresh per request. No `201 ... CHALLENGE` exists on this API. |
| (after ENCRYPT, same local port) 16-byte ciphertext of `PING` | 16-byte ciphertext that decrypts to `300 PONG\n` | Proves the key derivation and padding end to end without a password. |

Cipher: `key = md5(<API_KEY> . <SALT>)` as 16 RAW bytes; AES-128-ECB with
standard PKCS#7 padding in both directions (`openssl_encrypt(..., 'aes-128-ecb', $key, OPENSSL_RAW_DATA)`).

## Consequences for the client

- ANI-6 / ANI-S19: PING is answered by `300 PONG`, so no PING/LOGOUT
  result-wait exemption is needed; the old `200 PONG` test fixture was wrong.
- ANI-S21: the md5 `auth=` challenge leg built on a guessed `201 <chal>
  CHALLENGE` reply was falsified and has been replaced by this ENCRYPT
  handshake (`UdpClient::negotiateEncryption()`, `AnidbCipher`).

## Not captured (needs an authenticated session / password)

- The encrypted AUTH reply itself and the multi-entry `ANIME` 231
  continuation shape (ANI-S20) remain uncaptured; the client drains
  non-matching 2xx frames defensively until that is observed.
- Whether a session-bearing `PING s=<key>` extends the session, and whether
  the server drops encryption state at LOGOUT/506 (the client assumes it does
  and renegotiates on every AUTH).
