<?php

/**
 * One-shot settings fix-up for the AniDB title-dump URL.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\TitleDump;

/**
 * Migrates a stored `title_dump_url` setting off the dead plain-`http` scheme.
 *
 * ## Why this exists
 *
 * The manifest used to default `title_dump_url` to
 * `http://anidb.net/api/anime-titles.dat.gz`. AniDB sits behind Cloudflare,
 * which answers that plain-http URL with **403** — the download therefore never
 * produced any bytes and `title_index.json` stayed at 2 bytes (`[]`) on every
 * install, silently disabling the entire offline alias index. The identical
 * `https` URL answers **200** with ~1.4 MB of gzip.
 *
 * Correcting the manifest default alone fixes nothing on an EXISTING install:
 * the host loader merges `array_merge($manifestDefaults, $storedSettings)`, so a
 * value already persisted in the plugins table wins over the new default
 * forever. The stored value must therefore be fixed up where it enters the
 * plugin — {@see \Phlix\Anidb\AnidbMetadataProvider::__construct()}, the single
 * choke point through which the host delivers persisted settings.
 *
 * ## Deliberately narrow
 *
 * Only AniDB's own hosts are rewritten (`anidb.net` and any `*.anidb.net`
 * subdomain), decided on the parsed URL **host** — never a substring match, so
 * `http://evil.example/anidb.net/x` is not touched. An operator-configured
 * third-party mirror keeps whatever scheme the operator set: some mirrors serve
 * plain http only, and silently rewriting them would break a working install to
 * fix one that is already broken.
 *
 * Pure string work — no I/O, no state, safe to call from a constructor in a
 * resident-memory worker.
 *
 * @package Phlix\Anidb\TitleDump
 * @since 0.4.1
 */
final class TitleDumpUrlMigration
{
    /**
     * The manifest default, kept in lock-step with `plugin.json`
     * (`settings.title_dump_url.default`) by TitleDumpUrlMigrationTest.
     */
    public const DEFAULT_URL = 'https://anidb.net/api/anime-titles.dat.gz';

    /**
     * The scheme prefix being migrated away from.
     */
    private const INSECURE_SCHEME = 'http://';

    /**
     * The scheme prefix being migrated to.
     */
    private const SECURE_SCHEME = 'https://';

    /**
     * AniDB's registered domain. The rewrite applies to this host and to any
     * subdomain of it (e.g. `api.anidb.net`), and to nothing else.
     */
    private const CANONICAL_HOST = 'anidb.net';

    /**
     * Resolve the effective title-dump URL from a persisted setting value.
     *
     * Missing, non-string, or blank values fall back to {@see self::DEFAULT_URL};
     * a legacy insecure AniDB URL is upgraded to https; anything else is
     * returned verbatim.
     *
     * @param mixed $configured Raw value read from the persisted settings map.
     *
     * @return string Effective URL to hand to {@see TitleDumpIndexer}.
     */
    public static function resolve(mixed $configured): string
    {
        if (!is_string($configured)) {
            return self::DEFAULT_URL;
        }

        $trimmed = trim($configured);
        if ($trimmed === '') {
            return self::DEFAULT_URL;
        }

        return self::migrate($trimmed);
    }

    /**
     * Upgrade a legacy insecure AniDB URL to https, leaving everything else
     * untouched. Idempotent.
     *
     * @param string $url Configured URL.
     *
     * @return string Migrated URL, or $url unchanged.
     */
    public static function migrate(string $url): string
    {
        if (!self::isLegacyInsecureAnidbUrl($url)) {
            return $url;
        }

        // Swap ONLY the scheme; path, query and fragment are preserved verbatim
        // so a mirror-style path under anidb.net still resolves.
        return self::SECURE_SCHEME . substr($url, strlen(self::INSECURE_SCHEME));
    }

    /**
     * Whether the given URL is a plain-http URL pointing at an AniDB host.
     *
     * @param string $url Configured URL.
     *
     * @return bool True when {@see self::migrate()} would rewrite it.
     */
    public static function isLegacyInsecureAnidbUrl(string $url): bool
    {
        if (stripos($url, self::INSECURE_SCHEME) !== 0) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        // Hosts are case-insensitive and may carry a fully-qualified trailing dot.
        $host = rtrim(strtolower($host), '.');

        return $host === self::CANONICAL_HOST
            || str_ends_with($host, '.' . self::CANONICAL_HOST);
    }
}
