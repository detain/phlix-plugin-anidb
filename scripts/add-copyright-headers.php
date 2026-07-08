#!/usr/bin/env php
<?php

/**
 * Idempotent copyright header inserter for PHP files.
 *
 * Scans for .php files excluding vendor/, .git/, and generated files.
 * Inserts copyright docblock only if the file lacks detain@interserver.net.
 */

$rootDir = $argv[1] ?? __DIR__;
$copyright = <<<'COPYRIGHT'
/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

COPYRIGHT;

$processed = 0;
$skipped = 0;
$errors = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

/** @var SplFileInfo $file */
foreach ($iterator as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    $path = $file->getPathname();

    // Exclude paths
    if (
        str_contains($path, '/vendor/') ||
        str_contains($path, '/.git/') ||
        str_contains($path, '/node_modules/') ||
        str_contains($path, '/generated/') ||
        str_contains($path, '/.phpunit/') ||
        str_contains($path, '/.phpstan/')
    ) {
        continue;
    }

    $content = file_get_contents($path);

    // Skip if already has copyright
    if (str_contains($content, 'detain@interserver.net')) {
        $skipped++;
        continue;
    }

    // Must start with <?php
    if (!str_starts_with(trim($content), '<?php')) {
        $skipped++;
        continue;
    }

    // Find position after <?php
    $phpOpenPos = strpos($content, '<?php');
    if ($phpOpenPos === false) {
        $skipped++;
        continue;
    }

    // Find end of <?php tag (could be <?php or <?php\n or <?php\r\n)
    $afterPhp = substr($content, $phpOpenPos + 5);
    $nlPos = strpos($afterPhp, "\n");
    $crPos = strpos($afterPhp, "\r");

    if ($nlPos !== false && $crPos !== false) {
        $endTagPos = min($nlPos, $crPos) + 1;
    } elseif ($nlPos !== false) {
        $endTagPos = $nlPos + 1;
    } elseif ($crPos !== false) {
        $endTagPos = $crPos + 1;
    } else {
        // <?php at end of file with no newline
        $endTagPos = strlen($afterPhp);
    }

    $insertPos = $phpOpenPos + 5 + $endTagPos;

    // Build new content: <?php + header + rest
    $before = substr($content, 0, $insertPos);
    $after = substr($content, $insertPos);

    // Normalize: ensure exactly one blank line after header
    $newContent = rtrim($before) . "\n\n" . ltrim($copyright) . ltrim($after);

    if (file_put_contents($path, $newContent) === false) {
        echo "ERROR: Failed to write: $path\n";
        $errors++;
    } else {
        echo "ADDED: $path\n";
        $processed++;
    }
}

echo "\n--- Summary ---\n";
echo "Processed: $processed\n";
echo "Skipped (already have copyright or non-PHP): $skipped\n";
echo "Errors: $errors\n";
