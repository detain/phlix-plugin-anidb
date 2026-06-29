<?php

declare(strict_types=1);

namespace Phlix\Anidb;

use Phlix\Media\Metadata\MetadataProviderInterface;

/**
 * Host-interface adapter that exposes {@see AnidbMetadataProvider} through the
 * server's {@see \Phlix\Media\Metadata\MetadataProviderInterface} contract so
 * the host {@see \Phlix\Media\Metadata\MetadataManager} can actually consume
 * AniDB metadata.
 *
 * ## Why an adapter (and not implement the interface on the provider directly)?
 *
 * The provider ({@see AnidbMetadataProvider}) owns the heavy AniDB UDP session
 * (socket, flood protection, title dump) and a `lookup(string $filePath)` shaped
 * for filename-based matching. The host registry instead drives providers via a
 * `search()/getDetails()/getImages()` triad keyed by an *external id*. This thin
 * adapter bridges the two without entangling the UDP logic with the host
 * contract, mirroring how `Phlix\Plugins\Oidc\Plugin` / `Phlix\Plugins\Ldap\Plugin`
 * build a dedicated provider object and register THAT with their registry
 * (`AuthProviderRegistry`) rather than registering the lifecycle entry class.
 *
 * ## Reachability of `MetadataProviderInterface`
 *
 * `Phlix\Media\Metadata\MetadataProviderInterface` lives in the `phlix-server`
 * repo (PSR-4 `Phlix\` => `src/`), NOT in `phlix-shared`. At runtime that is fine:
 * Phlix is a resident-memory Workerman process, so the server's Composer
 * autoloader is already registered when {@see \Phlix\Plugins\PluginLoader::enable()}
 * `require_once`'s the plugin's own `vendor/autoload.php`. Requiring the plugin
 * autoloader ADDS the `Phlix\Anidb\` prefix; it never unregisters the server's
 * `Phlix\` => `src/` mapping, so this interface resolves in production.
 *
 * For unit tests (where the server is absent) the test bootstrap defines a
 * minimal stub of the exact same FQCN — see `tests/bootstrap.php`.
 *
 * ## External-id convention
 *
 * The "external id" handed back from {@see search()} and consumed by
 * {@see getDetails()}/{@see getImages()} is the AniDB AID rendered as a decimal
 * string (e.g. `"1"`). `getDetails()` parses it back to an int and fetches the
 * full anime record via the wrapped provider.
 *
 * @package Phlix\Anidb
 * @since 0.2.0
 */
final class AnidbMetadataProviderAdapter implements MetadataProviderInterface
{
    /**
     * Canonical source name advertised to the host registry.
     */
    public const SOURCE_NAME = 'anidb';

    /**
     * The wrapped AniDB provider that owns the UDP session and lookup logic.
     */
    private AnidbMetadataProvider $provider;

    /**
     * @param AnidbMetadataProvider $provider Live, already-enabled provider.
     */
    public function __construct(AnidbMetadataProvider $provider)
    {
        $this->provider = $provider;
    }

    /**
     * Search AniDB for anime matching a free-text query (e.g. a series title).
     *
     * Resolves the query to an AID via the wrapped provider's title-dump /
     * ANIME-by-name path, then returns it as a single best-match result in the
     * host's expected shape. AniDB's UDP API does not expose ranked multi-result
     * search, so at most one result is returned.
     *
     * @param string               $query   Search query (e.g. anime title).
     * @param array<string, mixed> $options Search options (year/language); unused
     *                                       by AniDB but accepted for contract parity.
     *
     * @return array<int, array{id: string, title: string, overview?: string, poster_path?: string}>
     *         Zero or one search result.
     */
    public function search(string $query, array $options = []): array
    {
        $aid = $this->provider->resolveAidByTitle($query);
        if ($aid === null) {
            return [];
        }

        $details = $this->provider->fetchAnimeMetadata($aid);
        if ($details === []) {
            // We have an AID but could not enrich it; still return a usable stub
            // so the host can call getDetails() with the id.
            return [[
                'id'    => (string) $aid,
                'title' => $query,
            ]];
        }

        $result = [
            'id'    => (string) $aid,
            'title' => self::stringOr($details['title'] ?? null, $query),
        ];

        $overview = $details['overview'] ?? null;
        if (is_string($overview) && $overview !== '') {
            $result['overview'] = $overview;
        }

        $poster = $details['poster_url'] ?? null;
        if (is_string($poster) && $poster !== '') {
            $result['poster_path'] = $poster;
        }

        return [$result];
    }

    /**
     * Fetch the full AniDB metadata record for an external id (the AID).
     *
     * @param string               $externalId AniDB AID as a decimal string.
     * @param array<string, mixed> $options    Additional options (language); unused.
     *
     * @return array<string, mixed> Detailed metadata, or `[]` when not found.
     */
    public function getDetails(string $externalId, array $options = []): array
    {
        $aid = self::parseAid($externalId);
        if ($aid === null) {
            return [];
        }

        return $this->provider->fetchAnimeMetadata($aid);
    }

    /**
     * Fetch image URLs for an external id (the AID), grouped by image type.
     *
     * AniDB's basic anime record carries a single poster (`picname`). It is
     * surfaced under the `poster` group; AniDB provides no backdrops/banners
     * through this path, so those groups are omitted.
     *
     * @param string $externalId AniDB AID as a decimal string.
     *
     * @return array<string, array<int, array{url: string, width?: int, height?: int}>>
     *         Images keyed by type (`poster`).
     */
    public function getImages(string $externalId): array
    {
        $aid = self::parseAid($externalId);
        if ($aid === null) {
            return [];
        }

        $details = $this->provider->fetchAnimeMetadata($aid);
        $poster = $details['poster_url'] ?? null;
        if (!is_string($poster) || $poster === '') {
            return [];
        }

        return [
            'poster' => [
                ['url' => $poster],
            ],
        ];
    }

    /**
     * Provider-name aliases this implementation answers to.
     *
     * @return array<string> Always `['anidb']`.
     */
    public function getProviders(): array
    {
        return [self::SOURCE_NAME];
    }

    /**
     * Canonical source name of this provider.
     *
     * @return string Always `'anidb'`.
     */
    public function getSourceName(): string
    {
        return self::SOURCE_NAME;
    }

    /**
     * Parse a decimal AID string into a positive int, or null when invalid.
     */
    private static function parseAid(string $externalId): ?int
    {
        $trimmed = trim($externalId);
        if ($trimmed === '' || !ctype_digit($trimmed)) {
            return null;
        }
        $aid = (int) $trimmed;
        return $aid > 0 ? $aid : null;
    }

    /**
     * Return $value as a non-empty string, otherwise the fallback.
     */
    private static function stringOr(mixed $value, string $fallback): string
    {
        return (is_string($value) && $value !== '') ? $value : $fallback;
    }
}
