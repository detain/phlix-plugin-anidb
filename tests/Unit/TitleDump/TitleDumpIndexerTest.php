<?php

/**
 * <one-line description>.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */
declare(strict_types=1);

namespace Phlix\Anidb\Tests\Unit\TitleDump;

use Phlix\Anidb\AnimeResponseParser;
use Phlix\Anidb\TitleDump\TitleDumpIndexer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TitleDumpIndexer.
 *
 * Covers parsing of anime-titles.dat.gz format, index building, and mtime guard.
 */
final class TitleDumpIndexerTest extends TestCase
{
    /**
     * Sample anime-titles.dat content (uncompressed).
     * Includes comments, blank lines, and varied title types.
     */
    private const FIXTURE_DAT = <<<'DAT'
# aid|type|lang|title
1|main|x-jat|Bokura no Leader
1|main|ja|僕らのリーダー
1|official|en|The Leader of Our Group
1|synonym|x-jat|Bokura no Leader
2|main|x-jat|Utena
2|official|en|Revolutionary Girl Utena
2|short|en|Utena
3|main|x-jat|Fate/stay night
3|official|en|Fate/stay night
3|synonym|en|Fate/Stay Night
DAT;

    /**
     * gzipped version of FIXTURE_DAT — pre-computed once.
     */
    private static ?string $fixtureGz = null;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureGz = gzencode(self::FIXTURE_DAT, 6);
    }

    // -------------------------------------------------------------------------
    // parse()
    // -------------------------------------------------------------------------

    public function test_parse_produces_grouped_index_with_correct_shape(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse(self::FIXTURE_DAT);

        $this->assertIsArray($result);

        // Should be grouped by AID
        $this->assertCount(3, $result);

        // Entry 1: AID 1
        $this->assertSame(1, $result[0]['aid']);
        $this->assertIsArray($result[0]['titles']);
        $this->assertCount(4, $result[0]['titles']);

        // Entry 2: AID 2
        $this->assertSame(2, $result[1]['aid']);
        $this->assertCount(3, $result[1]['titles']);

        // Entry 3: AID 3
        $this->assertSame(3, $result[2]['aid']);
        $this->assertCount(3, $result[2]['titles']);
    }

    /**
     * M-ANIDB-23 law: persisted title entries carry RAW fields only. The
     * former 'lower_title' bloated every entry in title_index.json with
     * derived state that validateSchema() stripped anyway; case/length are
     * now minted in memory once per load by TitleDumpManager instead.
     */
    public function test_parse_persists_raw_fields_only_no_derived_keys(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse(self::FIXTURE_DAT);

        foreach ($result as $entry) {
            foreach ($entry['titles'] as $titleEntry) {
                $this->assertSame(['title', 'type', 'lang'], array_keys($titleEntry));
            }
        }
    }

    public function test_parse_skips_comment_lines(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("# comment line\n1|main|x-jat|Test\n# another comment\n");

        $this->assertCount(1, $result);
        $this->assertSame('Test', $result[0]['titles'][0]['title']);
    }

    public function test_parse_skips_blank_lines(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("\n\n1|main|x-jat|Test\n\n\n2|main|x-jat|Test2\n");

        $this->assertCount(2, $result);
    }

    public function test_parse_skips_malformed_lines(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("1|main|x-jat|Valid\nmalformed\n1|only-two-parts\n2|main|x-jat|Also Valid\n");

        $this->assertCount(2, $result);
        $this->assertSame('Valid', $result[0]['titles'][0]['title']);
        $this->assertSame('Also Valid', $result[1]['titles'][0]['title']);
    }

    public function test_parse_skips_invalid_aid(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("0|main|x-jat|Invalid AID 0\n1|main|x-jat|Valid\n-1|main|x-jat|Negative AID\n");

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['aid']);
    }

    /**
     * PG-155 (reviews anidb__srphp #6[m] / anidb__secaudit #9[m]): the AID
     * column used to pass through a bare `(int)` cast, so leading-digit
     * garbage like "1abc" indexed under AID 1 and a digit column beyond
     * PHP_INT_MAX saturated to PHP_INT_MAX — both poisoned the shared index
     * with rows no AniDB lookup can legitimately reference, exactly the
     * overflow class M-ANIDB-16 closed on the UDP path. parse() now applies
     * the same ctype_digit + MAX_AID ceiling and drops violating lines.
     */
    public static function ceilingAidProvider(): array
    {
        return [
            'leading-digit garbage' => ['1abc'],
            'trailing garbage'      => ['12x'],
            'negative'              => ['-5'],
            'plus-prefixed'         => ['+5'],
            'saturating overflow'   => ['99999999999999999999'],
            'just above ceiling'    => ['10000001'],
            'far above ceiling'     => ['40000000'],
        ];
    }

    /**
     * @dataProvider ceilingAidProvider
     */
    public function test_parse_drops_aid_violating_the_shared_ceiling(string $aidColumn): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("{$aidColumn}|main|x-jat|Violating\n7|main|x-jat|Valid\n");

        $this->assertCount(1, $result, 'only the in-range line may be indexed');
        $this->assertSame(7, $result[0]['aid']);
    }

    public function test_parse_keeps_aid_at_the_ceiling_boundary(): void
    {
        // The ceiling is inclusive, mirroring AnimeResponseParser's AID arm
        // ($aid > MAX_AID refuses): MAX itself is legitimate, MAX+1 is not.
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse(
            AnimeResponseParser::MAX_AID . "|main|x-jat|At ceiling\n"
            . (AnimeResponseParser::MAX_AID + 1) . "|main|x-jat|Above ceiling\n"
        );

        $this->assertCount(1, $result);
        $this->assertSame(AnimeResponseParser::MAX_AID, $result[0]['aid']);
    }

    public function test_parse_reports_refused_aid_lines_loudly(): void
    {
        // Review M-ANIDB-16's "loud" discipline: dropping is not silent. The
        // notice names the count and the ceiling — never the violating line
        // content, which is untrusted dump data (log-injection class).
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $logFile = $tmpDir . '/stderr.log';
        $restoreLog = $this->sinkErrorLog($logFile);

        try {
            $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
            $indexer->parse("1abc|main|x-jat|Garbage aid\n99999999999999999999|main|x-jat|Overflow aid\n7|main|x-jat|Valid\n");

            $logged = file_get_contents($logFile);
            $this->assertIsString($logged);
            $this->assertStringContainsString('REFUSING 2 dump lines with malformed or out-of-range AID', $logged);
            $this->assertStringNotContainsString('Garbage aid', $logged, 'violating line content must not reach the log');
        } finally {
            $restoreLog();
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_parse_does_not_report_a_clean_dump(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $logFile = $tmpDir . '/stderr.log';
        $restoreLog = $this->sinkErrorLog($logFile);

        try {
            $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
            $indexer->parse(self::FIXTURE_DAT);

            $logged = @file_get_contents($logFile);
            $this->assertStringNotContainsString(
                'out-of-range AID',
                is_string($logged) ? $logged : '',
                'a clean dump must not carry a refusal notice'
            );
        } finally {
            $restoreLog();
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_parse_groups_multiple_titles_under_same_aid(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse(self::FIXTURE_DAT);

        // AID 1 has 4 titles (main x2, official, synonym)
        $aid1Entry = null;
        foreach ($result as $entry) {
            if ($entry['aid'] === 1) {
                $aid1Entry = $entry;
                break;
            }
        }

        $this->assertNotNull($aid1Entry);
        $this->assertCount(4, $aid1Entry['titles']);

        $titleValues = array_column($aid1Entry['titles'], 'title');
        $this->assertContains('Bokura no Leader', $titleValues);
        $this->assertContains('僕らのリーダー', $titleValues);
        $this->assertContains('The Leader of Our Group', $titleValues);
    }

    public function test_parse_preserves_type_and_lang_fields(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("1|main|x-jat|Bokura no Leader\n1|official|en|The Leader\n1|synonym|x-jat|Bokura\n");

        $titles = $result[0]['titles'];

        $mainTitle = null;
        $officialTitle = null;
        $synonymTitle = null;
        foreach ($titles as $t) {
            if ($t['type'] === 'main') {
                $mainTitle = $t;
            }
            if ($t['type'] === 'official') {
                $officialTitle = $t;
            }
            if ($t['type'] === 'synonym') {
                $synonymTitle = $t;
            }
        }

        $this->assertNotNull($mainTitle);
        $this->assertSame('main', $mainTitle['type']);
        $this->assertSame('x-jat', $mainTitle['lang']);
        $this->assertSame('Bokura no Leader', $mainTitle['title']);

        $this->assertNotNull($officialTitle);
        $this->assertSame('official', $officialTitle['type']);
        $this->assertSame('en', $officialTitle['lang']);
        $this->assertSame('The Leader', $officialTitle['title']);

        $this->assertNotNull($synonymTitle);
        $this->assertSame('synonym', $synonymTitle['type']);
    }

    public function test_parse_returns_empty_array_for_empty_content(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("");

        $this->assertCount(0, $result);
    }

    public function test_parse_returns_empty_array_for_only_comments(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');
        $result = $indexer->parse("# comment\n# another\n");

        $this->assertCount(0, $result);
    }

    // -------------------------------------------------------------------------
    // isStale()
    // -------------------------------------------------------------------------

    public function test_isStale_returns_true_for_nonexistent_file(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/nonexistent_dir_12345', 'http://example.com/anime-titles.dat.gz');

        $this->assertTrue($indexer->isStale('/tmp/nonexistent_dir_12345/title_index.json'));
    }

    public function test_isStale_returns_false_for_fresh_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'title_index_') . '.json';
        file_put_contents($tmpFile, '[]');

        try {
            $indexer = new TitleDumpIndexer(dirname($tmpFile), 'http://example.com/anime-titles.dat.gz');

            $this->assertFalse($indexer->isStale($tmpFile));
        } finally {
            unlink($tmpFile);
        }
    }

    public function test_isStale_returns_true_for_old_file(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'title_index_') . '.json';
        file_put_contents($tmpFile, '[]');
        // Set mtime to 25 hours ago
        touch($tmpFile, time() - (25 * 3600));

        try {
            $indexer = new TitleDumpIndexer(dirname($tmpFile), 'http://example.com/anime-titles.dat.gz');

            $this->assertTrue($indexer->isStale($tmpFile));
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * PG-152 (review anidb__srphp #5[m]): a future-dated index used to read
     * "fresh forever" because `(time() - $mtime) > MAX_AGE` is negative —
     * never > 86400. A backwards NTP step, a restored snapshot, or the very
     * `touch -d` the SM-0.3a runbook hands operators froze the daily refresh
     * for the whole skew. The marker path already treats the future as dead
     * ({@see TitleDumpIndexer::markerIsLive()}); isStale now mirrors that rule.
     */
    public function test_isStale_returns_true_for_future_dated_index(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'title_index_') . '.json';
        file_put_contents($tmpFile, '[]');
        // One hour ahead of the clock — any of the skew causes above.
        $this->assertTrue(touch($tmpFile, time() + 3600));

        try {
            $indexer = new TitleDumpIndexer(dirname($tmpFile), 'http://example.com/anime-titles.dat.gz');

            $this->assertTrue($indexer->isStale($tmpFile), 'a future mtime means the clock or the file moved — treat as stale, not immortal');
        } finally {
            unlink($tmpFile);
        }
    }

    /**
     * PG-152, second arm: an unstatable mtime (filemtime() === false — the
     * file vanished between is_file() and the stat, or an EIO on the mount)
     * used to subtract as `time() - false` → "0 seconds old" → fresh, again
     * freezing the refresh while proving nothing about the file. The rule
     * mirrors markerIsLive(): unknown age is reclaimable/stale, never fresh.
     */
    public function test_isStale_treats_unreadable_mtime_as_stale(): void
    {
        $indexer = new TitleDumpIndexer('/tmp/cache', 'http://example.com/anime-titles.dat.gz');

        $method = new \ReflectionMethod(TitleDumpIndexer::class, 'indexMtimeIsFresh');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($indexer, false), 'false mtime must never mean fresh');
        $this->assertFalse($method->invoke($indexer, time() + 1), 'future mtime must never mean fresh');
        $this->assertTrue($method->invoke($indexer, time()), 'current mtime is fresh');
        $this->assertTrue($method->invoke($indexer, time() - TitleDumpIndexer::MAX_AGE_SECONDS), 'the age boundary itself stays fresh');
        $this->assertFalse($method->invoke($indexer, time() - TitleDumpIndexer::MAX_AGE_SECONDS - 1), 'one second past the boundary is stale');
    }

    // -------------------------------------------------------------------------
    // downloadAndIndex() — integration via mocked HTTP client
    // -------------------------------------------------------------------------

    public function test_downloadAndIndex_writes_correct_json_shape(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $gzData = gzencode(self::FIXTURE_DAT, 6);
        $this->assertNotNull($gzData);

        $httpCallCount = 0;

        // B5: HTTP client now uses callback-based interface (string $url, callable $onResult): void
        $httpClient = static function (string $url, callable $onResult) use ($gzData, &$httpCallCount): void {
            ++$httpCallCount;
            $onResult($gzData);
        };

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            $result = $indexer->downloadAndIndex();

            $this->assertTrue($result);
            $this->assertSame(1, $httpCallCount);

            $indexFile = $tmpDir . '/title_index.json';
            $this->assertFileExists($indexFile);

            /** @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $loaded */
            $loaded = json_decode(file_get_contents($indexFile), true);

            $this->assertCount(3, $loaded);
            $this->assertArrayHasKey('aid', $loaded[0]);
            $this->assertArrayHasKey('titles', $loaded[0]);
            $this->assertIsArray($loaded[0]['titles']);
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_downloadAndIndex_does_not_re_download_within_24h(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $gzData = gzencode(self::FIXTURE_DAT, 6);
        $this->assertNotNull($gzData);

        $httpCallCount = 0;

        // B5: HTTP client now uses callback-based interface (string $url, callable $onResult): void
        $httpClient = static function (string $url, callable $onResult) use ($gzData, &$httpCallCount): void {
            ++$httpCallCount;
            $onResult($gzData);
        };

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            // First call: should download and index
            $result1 = $indexer->downloadAndIndex();
            $this->assertTrue($result1);
            $this->assertSame(1, $httpCallCount);

            // Second call: index is fresh (not stale), should NOT re-download
            $result2 = $indexer->downloadAndIndex();
            $this->assertTrue($result2);
            $this->assertSame(1, $httpCallCount); // Still 1, no second HTTP call
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_downloadAndIndex_re_downloads_when_stale(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $gzData1 = gzencode("1|main|x-jat|Old Title\n", 6);
        $gzData2 = gzencode("1|main|x-jat|New Title\n", 6);
        $this->assertNotNull($gzData1);
        $this->assertNotNull($gzData2);

        $callCount = 0;
        // B5: HTTP client now uses callback-based interface (string $url, callable $onResult): void
        $httpClient = static function (string $url, callable $onResult) use ($gzData1, $gzData2, &$callCount): void {
            ++$callCount;
            $onResult($callCount === 1 ? $gzData1 : $gzData2);
        };

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            // First call: downloads initial data
            $indexer->downloadAndIndex();
            $this->assertSame(1, $callCount);

            // Touch the index file to make it stale (25 hours old)
            $indexFile = $tmpDir . '/title_index.json';
            touch($indexFile, time() - (25 * 3600));

            // Third call: should re-download because stale
            $result = $indexer->downloadAndIndex();
            $this->assertTrue($result);
            $this->assertSame(2, $callCount); // Second HTTP call was made

            // Verify the new content is in the index
            /** @var list<array{aid: int, titles: list<array{title: string, type: string, lang: string}>}> $loaded */
            $loaded = json_decode(file_get_contents($indexFile), true);
            $this->assertSame('New Title', $loaded[0]['titles'][0]['title']);
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_downloadAndIndex_returns_false_on_http_failure(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        // B5: HTTP client now uses callback-based interface, invoke callback with null on failure
        $httpClient = static function (string $url, callable $onResult): void {
            $onResult(null);
        };

        $log = $tmpDir . '/error.log';
        $restore = $this->sinkErrorLog($log); // M-ANIDB-19: a fail-closed refusal must be visible, not silent

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            $result = $indexer->downloadAndIndex();

            $this->assertFalse($result);
            $this->assertStringContainsString(
                'received no response body',
                (string) file_get_contents($log),
                'the fail-closed download refusal logged no reason',
            );
        } finally {
            $restore();
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_downloadAndIndex_returns_false_on_gzdecode_failure(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        // B5: HTTP client returns non-gzip data, which will fail gzdecode
        $httpClient = static function (string $url, callable $onResult): void {
            $onResult('not gzipped data');
        };

        $log = $tmpDir . '/error.log';
        $restore = $this->sinkErrorLog($log); // M-ANIDB-19: a fail-closed refusal must be visible, not silent

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            $result = $indexer->downloadAndIndex();

            $this->assertFalse($result);
            $this->assertStringContainsString(
                'not valid gzip',
                (string) file_get_contents($log),
                'the fail-closed gzdecode refusal logged no reason',
            );
        } finally {
            $restore();
            $this->recursiveDelete($tmpDir);
        }
    }

    // -------------------------------------------------------------------------
    // M-ANIDB-28 — scheme policy at the boundary, size caps before the parse
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function illegalDumpUrlProvider(): array
    {
        return [
            'ftp scheme'        => ['ftp://user:secret@files.example/dump.gz'],
            'file scheme'       => ['file:///etc/passwd'],
            'phar scheme'       => ['phar://payload.phar/dump.gz'],
            'data scheme'       => ['data://text/plain;base64,AAA='],
            'no scheme at all'  => ['example.com/anime-titles.dat.gz'],
            'empty string'      => [''],
        ];
    }

    /**
     * @dataProvider illegalDumpUrlProvider
     */
    public function test_constructor_refuses_non_http_scheme_before_any_fetch(string $url): void
    {
        $thrown = null;
        try {
            new TitleDumpIndexer('/tmp/unused', $url, static function (string $u, callable $c): void {
                self::fail('a fetch was attempted for an illegal scheme');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertStringContainsString('must use the http or https scheme', $thrown->getMessage());
        // The URL is admin-settable and may embed credentials: the message names
        // the scheme, never the URL.
        $this->assertStringNotContainsString('secret', $thrown->getMessage());
    }

    public function test_download_refuses_a_body_over_the_compressed_cap(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $oversize = str_repeat('x', TitleDumpIndexer::MAX_DOWNLOAD_BYTES + 1);
        $httpClient = static function (string $url, callable $onResult) use ($oversize): void {
            $onResult($oversize);
        };

        $log = $tmpDir . '/error.log';
        $restore = $this->sinkErrorLog($log);

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            $this->assertFalse($indexer->downloadAndIndex());
            $this->assertStringContainsString(
                'compressed-download cap',
                (string) file_get_contents($log),
                'the oversize refusal logged no reason',
            );
            $this->assertFileDoesNotExist($tmpDir . '/title_index.json', 'the refused body was written anyway');
        } finally {
            $restore();
            $this->recursiveDelete($tmpDir);
        }
    }

    public function test_download_refuses_a_decompressed_payload_at_the_cap(): void
    {
        // A payload decompressing to at least MAX_DECOMPRESSED_BYTES is refused
        // fail-closed: the legitimate dump is an order of magnitude smaller, so
        // reaching the cap means truncation or a gzip bomb, never a good file.
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $bomb = gzencode(str_repeat('A', TitleDumpIndexer::MAX_DECOMPRESSED_BYTES + 1), 6);
        $this->assertNotFalse($bomb);
        $this->assertLessThan(
            TitleDumpIndexer::MAX_DOWNLOAD_BYTES,
            strlen($bomb),
            'fixture must pass the compressed cap to isolate the decompressed cap',
        );

        $httpClient = static function (string $url, callable $onResult) use ($bomb): void {
            $onResult($bomb);
        };

        $log = $tmpDir . '/error.log';
        $restore = $this->sinkErrorLog($log);

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'http://example.com/anime-titles.dat.gz', $httpClient);

            $this->assertFalse($indexer->downloadAndIndex());
            $this->assertStringContainsString(
                'decompressed size reached',
                (string) file_get_contents($log),
                'the decompressed-cap refusal logged no reason',
            );
            $this->assertFileDoesNotExist($tmpDir . '/title_index.json', 'the refused payload was written anyway');
        } finally {
            $restore();
            $this->recursiveDelete($tmpDir);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Point error_log at a scratch file; the returned closure restores the
     * previous setting (this PHP build has no restore_error_log()).
     */
    /**
     * Review anidb__srphp M2, failure shape (a): a body that decodes to zero
     * valid entries used to be written as `[]`, reported SUCCESS, released the
     * in-flight marker, and left the offline index dead for a full
     * MAX_AGE_SECONDS. It must now be a failed attempt: nothing written,
     * marker held (its TTL is the retry floor), loud in the log.
     */
    public function test_downloadAndIndex_refuses_body_that_yields_zero_valid_entries(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $gzData = gzencode("# only comments\n# and more comments\n", 6);
        $this->assertNotFalse($gzData);

        $logFile = $tmpDir . '/stderr.log';
        $restoreLog = $this->sinkErrorLog($logFile);

        try {
            $httpClient = static function (string $url, callable $onResult) use ($gzData): void {
                $onResult($gzData);
            };
            $indexer = new TitleDumpIndexer($tmpDir, 'https://example.test/anime-titles.dat.gz', $httpClient);

            $this->assertFalse(
                $indexer->downloadAndIndex(),
                'an empty parse result must be treated as a failed download attempt'
            );

            $this->assertFileDoesNotExist(
                $tmpDir . '/title_index.json',
                'a failed attempt must not persist an empty success record'
            );

            // Deliberate: the marker stays so IN_FLIGHT_TTL_SECONDS throttles retries.
            $this->assertFileExists($tmpDir . '/title_index.download.lock');

            $logged = file_get_contents($logFile);
            $this->assertIsString($logged);
            $this->assertStringContainsString('zero valid entries', $logged);
        } finally {
            $restoreLog();
            $this->recursiveDelete($tmpDir);
        }
    }

    /**
     * Review anidb__srphp M2, failure shape (b): json_encode() returning false
     * (invalid UTF-8 from a corrupt mirror) used to flow into
     * file_put_contents(mixed), which wrote a 0-byte file and returned 0 —
     * `0 !== false` read back as SUCCESS. An unencodable index must fail loud.
     */
    public function test_writeIndex_fails_loud_when_index_cannot_be_encoded(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);

        $logFile = $tmpDir . '/stderr.log';
        $restoreLog = $this->sinkErrorLog($logFile);

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'https://example.test/anime-titles.dat.gz', static function (): void {
            });

            $corrupt = [[
                'aid'    => 1,
                'titles' => [[
                    'title' => "\xFF\xFE not valid UTF-8",
                    'type'  => 'main',
                    'lang'  => 'en',
                ]],
            ]];

            $method = new \ReflectionMethod(TitleDumpIndexer::class, 'writeIndex');
            $method->setAccessible(true);

            $this->assertFalse($method->invoke($indexer, $corrupt));

            $this->assertFileDoesNotExist(
                $tmpDir . '/title_index.json',
                'an unencodable index must never reach the filesystem'
            );

            $logged = file_get_contents($logFile);
            $this->assertIsString($logged);
            $this->assertStringContainsString('could not be JSON-encoded', $logged);
        } finally {
            $restoreLog();
            $this->recursiveDelete($tmpDir);
        }
    }

    /**
     * PG-153 (review anidb__secaudit #1[m]): file_put_contents() FOLLOWS a
     * symlink at the destination — an attacker who can plant
     * `title_index.json -> <victim>` in the cache dir (the documented
     * world-writable misconfiguration) turned every refresh into a write
     * through their link. writeIndex now refuses a symlinked index path
     * loudly; the target must survive byte-identical.
     */
    public function test_writeIndex_refuses_a_symlinked_index_path(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $victim = $tmpDir . '/victim.txt';
        $indexFile = $tmpDir . '/title_index.json';
        file_put_contents($victim, 'ORIGINAL');
        $this->assertTrue(symlink($victim, $indexFile));

        $logFile = $tmpDir . '/stderr.log';
        $restoreLog = $this->sinkErrorLog($logFile);

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'https://example.test/anime-titles.dat.gz', static function (): void {
            });

            $method = new \ReflectionMethod(TitleDumpIndexer::class, 'writeIndex');
            $method->setAccessible(true);

            $this->assertFalse(
                $method->invoke($indexer, [['aid' => 1, 'titles' => [['title' => 'X', 'type' => 'main', 'lang' => 'en']]]]),
                'a symlinked index path must refuse the write, never follow it'
            );
            $this->assertSame('ORIGINAL', file_get_contents($victim), 'the symlink target must be untouched');
            $this->assertTrue(is_link($indexFile), 'the refusal must not consume or replace the link either');

            $logged = file_get_contents($logFile);
            $this->assertIsString($logged);
            $this->assertStringContainsString('symbolic link', $logged);
        } finally {
            $restoreLog();
            $this->recursiveDelete($tmpDir);
        }
    }

    /**
     * PG-153 (reviews anidb__srphp #3[m] / anidb__secaudit #1): the in-place
     * overwrite tore — a reader (TitleDumpManager::ensureLoaded) could stat a
     * present file, open it mid-truncate, and decode a zero/partial body,
     * and it could not replace an index left read-only by an earlier
     * owner-mismatch. Writing a uniquely-named temp file and rename()ing it
     * over the destination makes the swap atomic (POSIX replaces the whole
     * directory entry, destination file mode irrelevant), and a rename
     * produces a NEW inode with a NEW mtime — so the manager's
     * reload-on-moved provenance law keeps firing.
     */
    public function test_writeIndex_replaces_atomically_and_leaves_no_temp_files(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        $indexFile = $tmpDir . '/title_index.json';

        // Pre-existing read-only index (an owner-mismatch/stale-flock relic):
        // the old in-place write could not touch it; atomic rename can.
        file_put_contents($indexFile, '[]');
        chmod($indexFile, 0444);

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'https://example.test/anime-titles.dat.gz', static function (): void {
            });

            $method = new \ReflectionMethod(TitleDumpIndexer::class, 'writeIndex');
            $method->setAccessible(true);

            $oldInode = @lstat($indexFile)['ino'];

            $this->assertTrue($method->invoke($indexer, [[
                'aid'    => 42,
                'titles' => [['title' => 'Hellsing', 'type' => 'main', 'lang' => 'x-jat']],
            ]]));

            $this->assertStringContainsString('Hellsing', file_get_contents($indexFile));
            $this->assertNotSame(@lstat($indexFile)['ino'], $oldInode, 'rename must yield a NEW inode so the manager reloads on moved');

            $strays = array_values(array_diff(scandir($tmpDir), ['.', '..', 'title_index.json']));
            $this->assertSame([], $strays, 'the temp step must not litter the cache dir');
        } finally {
            $this->recursiveDelete($tmpDir);
        }
    }

    /**
     * PG-153, failure leg: when the final rename fails, the temp file must
     * not survive to accumulate on every retry. A read-only DIRECTORY makes
     * the temp create itself fail — the write must report false and leave
     * the directory exactly as it found it.
     */
    public function test_writeIndex_leaves_nothing_behind_when_the_temp_write_fails(): void
    {
        $tmpDir = sys_get_temp_dir() . '/title_dump_test_' . uniqid();
        mkdir($tmpDir, 0755, true);
        chmod($tmpDir, 0555);

        try {
            $indexer = new TitleDumpIndexer($tmpDir, 'https://example.test/anime-titles.dat.gz', static function (): void {
            });

            $method = new \ReflectionMethod(TitleDumpIndexer::class, 'writeIndex');
            $method->setAccessible(true);

            $this->assertFalse(@$method->invoke($indexer, [['aid' => 1, 'titles' => []]]));
        } finally {
            chmod($tmpDir, 0755);
            $this->recursiveDelete($tmpDir);
        }
    }

    private function sinkErrorLog(string $file): \Closure
    {
        $previous = ini_get('error_log');
        ini_set('error_log', $file);

        return static function () use ($previous): void {
            ini_set('error_log', is_string($previous) ? $previous : '');
        };
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
