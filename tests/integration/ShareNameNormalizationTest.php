<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';

/**
 * Regression tests for share name normalization (Bug 3 of v2026.05.18).
 *
 * BUG (v2026.04.06 and earlier):
 *   The Delete and Clone buttons used inline onclick="deleteShare('<?=
 *   htmlspecialchars($name) ?>')". htmlspecialchars() does NOT escape
 *   newlines, carriage returns, or control characters. When a share's name
 *   had any of those characters (e.g., from a stray copy-paste or import
 *   from a foreign system), the rendered HTML produced syntactically
 *   invalid JavaScript:
 *
 *       <a onclick="deleteShare('Foo
 *       Bar')">  ← newline inside string literal = SyntaxError
 *
 *   The browser silently dropped the broken handler and the click did
 *   nothing. Forum reporter saw this as "first row delete is glitched."
 *   Re-saving the share normalized the data and "fixed" it; restoring
 *   from backup brought the dirty data back.
 *
 * Forum report: comet424, 2026-05-15 (bug #1 of 5)
 *
 * FIX (v2026.05.18):
 *   - normalizeShare() trims surrounding whitespace and strips control
 *     characters from name + other string fields
 *   - loadShares() normalizes on read (cleans existing dirty data on
 *     first read after upgrade)
 *   - saveShares() normalizes on write (dirty data never reaches disk)
 *   - SMBShares.page Delete/Clone use data-share-name + delegated handler
 *     (attribute encoding is safe for any character)
 */
class ShareNameNormalizationTest extends TestCase
{
    private static string $configBase;

    public static function setUpBeforeClass(): void
    {
        self::$configBase = \ChrootTestEnvironment::setup();
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';
    }

    public static function tearDownAfterClass(): void
    {
        \ChrootTestEnvironment::teardown();
    }

    protected function setUp(): void
    {
        \ChrootTestEnvironment::reset();
    }

    /**
     * normalizeShare() trims whitespace from name.
     */
    public function testTrimsLeadingTrailingWhitespaceFromName(): void
    {
        $share = ['name' => "  Media  ", 'path' => '/mnt/user/media'];
        $normalized = normalizeShare($share);
        $this->assertSame('Media', $normalized['name']);
    }

    /**
     * normalizeShare() strips control characters from name.
     * This is the actual root cause of the broken-onclick bug.
     */
    public function testStripsControlCharsFromName(): void
    {
        // Name with embedded newline, carriage return, tab, null byte
        $dirty = "Foo\nBar\rBaz\tQux\x00End";
        $share = ['name' => $dirty, 'path' => '/mnt/user/foo'];
        $normalized = normalizeShare($share);

        $this->assertSame('FooBarBazQuxEnd', $normalized['name']);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $normalized['name']);
    }

    /**
     * BOM (zero-width / byte-order-mark style invisible chars at start)
     * gets cleaned up too.
     */
    public function testStripsLeadingControlChars(): void
    {
        $share = ['name' => "\x00\x01Media", 'path' => '/mnt/user/media'];
        $normalized = normalizeShare($share);
        $this->assertSame('Media', $normalized['name']);
    }

    /**
     * Trims other string fields too (path, comment, valid_users, etc.)
     * since they're rendered into Samba config and bad whitespace breaks parsing.
     */
    public function testTrimsOtherStringFields(): void
    {
        $share = [
            'name' => 'Test',
            'path' => '  /mnt/user/test  ',
            'comment' => '  hello  ',
            'valid_users' => '  alice,bob  ',
        ];
        $normalized = normalizeShare($share);

        $this->assertSame('/mnt/user/test', $normalized['path']);
        $this->assertSame('hello', $normalized['comment']);
        $this->assertSame('alice,bob', $normalized['valid_users']);
    }

    /**
     * Non-string fields (booleans, ints, arrays) are passed through unchanged.
     */
    public function testNonStringFieldsArePassedThrough(): void
    {
        $share = [
            'name' => 'Test',
            'path' => '/mnt/user/test',
            'enabled' => true,
            'security' => 'private',
            'create_mask' => '0664',
        ];
        $normalized = normalizeShare($share);

        $this->assertTrue($normalized['enabled']);
        $this->assertSame('private', $normalized['security']);
        $this->assertSame('0664', $normalized['create_mask']);
    }

    /**
     * loadShares() applies normalization — existing dirty data on disk
     * gets cleaned up automatically on first read after upgrade.
     */
    public function testLoadSharesNormalizesDirtyDataOnDisk(): void
    {
        // Plant dirty shares.json that pre-dates the normalization fix
        $dirty = [
            ['name' => "  DirtyOne  ", 'path' => '/mnt/user/d1'],
            ['name' => "Two\nWithNewline", 'path' => '/mnt/user/d2'],
        ];
        $sharesFile = self::$configBase . '/plugins/custom.smb.shares/shares.json';
        if (!is_dir(dirname($sharesFile))) {
            mkdir(dirname($sharesFile), 0755, true);
        }
        file_put_contents($sharesFile, json_encode($dirty));

        $loaded = loadShares(self::$configBase);

        $this->assertSame('DirtyOne', $loaded[0]['name']);
        $this->assertSame('TwoWithNewline', $loaded[1]['name']);
    }

    /**
     * saveShares() applies normalization — even if a caller passes dirty
     * data (e.g., from import), it never reaches disk.
     */
    public function testSaveSharesNormalizesBeforeWriting(): void
    {
        $dirty = [
            ['name' => "  Imported\nWeird  ", 'path' => '  /mnt/user/x  '],
        ];

        saveShares($dirty, self::$configBase);

        $sharesFile = self::$configBase . '/plugins/custom.smb.shares/shares.json';
        $written = json_decode(file_get_contents($sharesFile), true);

        $this->assertSame('ImportedWeird', $written[0]['name']);
        $this->assertSame('/mnt/user/x', $written[0]['path']);
    }

    /**
     * Round-trip through loadShares + saveShares preserves clean names.
     */
    public function testRoundTripPreservesCleanNames(): void
    {
        $clean = [
            ['name' => 'Media', 'path' => '/mnt/user/media'],
            ['name' => 'Backups', 'path' => '/mnt/user/backups', 'comment' => 'Daily snapshots'],
        ];

        saveShares($clean, self::$configBase);
        $reloaded = loadShares(self::$configBase);

        $this->assertSame('Media', $reloaded[0]['name']);
        $this->assertSame('Backups', $reloaded[1]['name']);
        $this->assertSame('Daily snapshots', $reloaded[1]['comment']);
    }

    /**
     * Empty/missing name field doesn't crash normalization.
     */
    public function testEmptyNameDoesNotCrash(): void
    {
        $shares = [
            ['name' => '', 'path' => '/mnt/user/x'],
            ['path' => '/mnt/user/y'],
        ];
        $normalized = array_map('normalizeShare', $shares);
        $this->assertSame('', $normalized[0]['name']);
        $this->assertArrayNotHasKey('name', $normalized[1]);  // unchanged
    }
}
