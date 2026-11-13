<?php

/**
 * Cache-directory candidate validation (symlink / world-writable / ownership).
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb;

/**
 * Single choke point every cache-dir route passes through (extracted from
 * AnidbMetadataProvider so the ANI-S12 refusals are testable directly).
 */
final class CacheDirGuard
{
    /**
     * Fail loud on a relative or world-writable cache path and canonicalize
     * an existing one.
     *
     * A relative configured value would silently resolve against the worker's
     * current directory — dump data landing somewhere nobody administers —
     * so only absolute paths are accepted. An existing directory is returned
     * as its realpath, so a symlinked configured path cannot quietly redirect
     * where the index is written; not-yet-created paths pass through
     * unchanged (the indexer creates them lazily; realpath cannot pre-exist).
     *
     * PG-153 (review anidb__secaudit #3[m]): plugin.json and README both
     * decree the directory MUST NOT be world-writable — "the index is served
     * from it" — and until now that sentence was enforced nowhere. In such a
     * directory any local user can plant or swap title_index.json (or the
     * in-flight marker, whose whole symlink analysis in the indexer assumes a
     * non-hostile parent), which is exactly the activation-by-misconfiguration
     * this choke point already refuses for relative paths — same rule, same
     * loud construction-time failure. Group-writable stays lawful: the
     * documented invariant is about the WORLD bit.
     *
     * @param string $candidate Configured cache directory (non-empty).
     * @param string $source    Human name of the configuration source.
     * @param bool   $requireOwned When true (the shared-temp fallback), a symlink
     *     at the path or an existing directory not owned by the effective uid
     *     is refused too: a pre-created hostile leaf in a shared parent is the
     *     dangling-symlink + fopen('x') creation primitive (ANI-S12).
     * @param ?int   $euid Effective uid override (null = the process's own); lets the
     *     foreign-owner refusal be exercised without a second system user.
     *
     * @return string Absolute cache directory (canonicalized when it exists).
     *
     * @throws \InvalidArgumentException If the candidate is not an absolute
     *     path, or an existing one carries the other-write bit.
     */
    public static function require(
        string $candidate,
        string $source,
        bool $requireOwned = false,
        ?int $euid = null,
    ): string
    {
        if (!str_starts_with($candidate, '/')) {
            throw new \InvalidArgumentException(
                'AnidbMetadataProvider: ' . $source . ' must name an absolute directory '
                    . '(a relative path resolves against the worker CWD); got "' . $candidate . '".',
            );
        }

        if ($requireOwned && is_link($candidate)) {
            throw new \InvalidArgumentException(
                'AnidbMetadataProvider: ' . $source . ' must not be a symlink (a planted link in a '
                    . 'shared temp directory could redirect the index); got "' . $candidate . '". '
                    . 'Set cache_dir or PHLIX_ANIDB_CACHE_DIR to a directory you own.',
            );
        }

        $resolved = realpath($candidate);

        if ($resolved === false) {
            return $candidate; // not created yet — nothing to stat, lazy mkdir owns it
        }

        $permissions = @fileperms($resolved);

        if ($permissions !== false && ($permissions & 0o002) !== 0) {
            // %o of a file's mode leads with the type digits (040777); the
            // last four are the permission bits operators reason about, and
            // a sticky bit renders in the recovered-mode hint's source value.
            $mode = substr(sprintf('%o', $permissions), -4);

            throw new \InvalidArgumentException(
                'AnidbMetadataProvider: ' . $source . ' must not name a world-writable directory '
                    . '(mode "' . $mode . '" — the title index is served from here, and any local user '
                    . 'could plant or swap it; the plugin.json cache_dir contract forbids the '
                    . 'other-write bit). Recover with `chmod o-w` on it; got "' . $candidate . '".',
            );
        }

        if ($requireOwned) {
            $owner = @fileowner($resolved);
            $euid ??= function_exists('posix_geteuid') ? posix_geteuid() : null;

            if ($euid !== null && $owner !== false && $owner !== $euid) {
                throw new \InvalidArgumentException(
                    'AnidbMetadataProvider: ' . $source . ' exists but is owned by uid ' . $owner
                        . ', not the effective uid ' . $euid . ' — another local user pre-created it; '
                        . 'refusing. Set cache_dir or PHLIX_ANIDB_CACHE_DIR to a directory you own; got "'
                        . $candidate . '".',
                );
            }
        }

        return $resolved;
    }
}
