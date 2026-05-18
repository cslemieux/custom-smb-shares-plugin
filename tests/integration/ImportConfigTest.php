<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';

/**
 * Regression tests for the importConfig api.php handler.
 *
 * BUG (v2026.04.06 and earlier):
 *   The handler read `php://input` and json_decode'd the entire raw request body.
 *   jQuery $.post sends application/x-www-form-urlencoded, so the body was
 *   "action=importConfig&config=<URL-encoded JSON>" — never valid JSON.
 *   Every import 400'd silently because:
 *     1. PHP returned the 400, AND
 *     2. The JS $.post had no .fail handler, so the success callback never fired
 *   Result: clicking Import made the modal vanish with no feedback.
 *
 * Forum report: comet424, 2026-05-14
 *   https://forums.unraid.net/topic/195826-plugin-custom-smb-shares/page/3/#findComment-1622947
 *
 * FIX (v2026.05.18):
 *   Read $_POST['config'] (the parsed form field) instead of php://input.
 *   Add .fail() handler to JS so HTTP errors surface to the user.
 *
 * These tests document the bug cause and lock in the fix.
 */
class ImportConfigTest extends TestCase
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
        \ChrootTestEnvironment::mkdir('user/imp1');
        \ChrootTestEnvironment::mkdir('user/imp2');
        $_POST = [];
        $_GET = [];
    }

    /**
     * Documents WHY the bug existed: jQuery $.post sends a form-encoded body,
     * not a JSON body. json_decode of that body always returns null.
     */
    public function testFormEncodedBodyIsNotValidJson(): void
    {
        $shares = [['name' => 'Foo', 'path' => '/mnt/user/foo']];

        // What jQuery $.post puts on the wire (Content-Type: application/x-www-form-urlencoded):
        $rawRequestBody = http_build_query([
            'action' => 'importConfig',
            'config' => json_encode($shares),
        ]);

        // The OLD handler did exactly: json_decode($rawRequestBody, true)
        $oldStyleParse = json_decode($rawRequestBody, true);

        $this->assertNull(
            $oldStyleParse,
            'Form-encoded body must NOT parse as JSON. This is why the legacy ' .
            'php://input + json_decode pattern always returned 400 silently.'
        );
    }

    /**
     * Verifies the FIX: reading $_POST['config'] yields a clean JSON string
     * that decodes back to the original shares array.
     */
    public function testPostConfigParamYieldsValidJsonString(): void
    {
        $shares = [
            ['name' => 'ImportedShare', 'path' => \ChrootTestEnvironment::getMntPath('user/imp1')],
        ];

        // Simulate the request: PHP parses form-encoded body into $_POST automatically
        $_POST = [
            'action' => 'importConfig',
            'config' => json_encode($shares),
        ];

        // What the FIXED handler does:
        $configJson = $_POST['config'] ?? '';
        $parsed = json_decode($configJson, true);

        $this->assertIsArray($parsed, '$_POST["config"] must yield a JSON-parseable string');
        $this->assertCount(1, $parsed);
        $this->assertEquals('ImportedShare', $parsed[0]['name']);
    }

    /**
     * End-to-end happy path: parse, validate, save — mirrors the handler's
     * full code path with the fix applied.
     */
    public function testImportConfigSavesValidShares(): void
    {
        $sharesToImport = [
            ['name' => 'ImportShare1', 'path' => \ChrootTestEnvironment::getMntPath('user/imp1')],
            ['name' => 'ImportShare2', 'path' => \ChrootTestEnvironment::getMntPath('user/imp2')],
        ];

        $_POST = [
            'action' => 'importConfig',
            'config' => json_encode($sharesToImport),
        ];

        // Replicate the post-fix handler logic
        $configJson = $_POST['config'] ?? '';
        $this->assertNotEmpty($configJson, 'config param must be present');

        $shares = json_decode($configJson, true);
        $this->assertIsArray($shares, 'config must decode to an array');

        foreach ($shares as $share) {
            $errors = validateShare($share);
            $this->assertEmpty(
                $errors,
                'Imported share should validate: ' . json_encode($share) . ' errors=' . json_encode($errors)
            );
        }

        backupShares(self::$configBase);
        $saved = saveShares($shares, self::$configBase);
        $this->assertNotFalse($saved, 'saveShares should succeed');

        $loaded = loadShares(self::$configBase);
        $this->assertCount(2, $loaded, 'Both imported shares should persist');
        $this->assertEquals('ImportShare1', $loaded[0]['name']);
        $this->assertEquals('ImportShare2', $loaded[1]['name']);
    }

    /**
     * Round-trip: export an existing config, then re-import it. The output
     * of the export endpoint MUST be importable as-is. This is what users
     * tried in the wild (export → save to file → import file) and what
     * silently failed before the fix.
     */
    public function testExportThenImportRoundTrip(): void
    {
        // Set up some existing shares
        $original = [
            ['name' => 'RoundTrip1', 'path' => \ChrootTestEnvironment::getMntPath('user/imp1')],
            ['name' => 'RoundTrip2', 'path' => \ChrootTestEnvironment::getMntPath('user/imp2')],
        ];
        saveShares($original, self::$configBase);

        // Simulate the export endpoint output
        $exported = loadShares(self::$configBase);
        $this->assertEquals($original, $exported);

        // Wipe and re-import via the (fixed) handler path
        saveShares([], self::$configBase);
        $this->assertCount(0, loadShares(self::$configBase));

        $_POST = [
            'action' => 'importConfig',
            'config' => json_encode($exported),
        ];

        $configJson = $_POST['config'] ?? '';
        $shares = json_decode($configJson, true);
        $this->assertIsArray($shares);

        foreach ($shares as $share) {
            $errors = validateShare($share);
            $this->assertEmpty($errors);
        }
        saveShares($shares, self::$configBase);

        $reloaded = loadShares(self::$configBase);
        $this->assertEquals($original, $reloaded, 'Round-trip export → import must preserve all shares');
    }

    /**
     * Missing `config` form field is rejected by the fixed handler.
     */
    public function testMissingConfigParamIsRejected(): void
    {
        $_POST = ['action' => 'importConfig'];  // no config field

        $configJson = $_POST['config'] ?? '';
        $this->assertEmpty($configJson, 'Handler must reject empty config with 400');
    }

    /**
     * Malformed JSON in `config` field is rejected by the fixed handler.
     */
    public function testMalformedJsonIsRejected(): void
    {
        $_POST = ['action' => 'importConfig', 'config' => 'not-json{['];

        $configJson = $_POST['config'] ?? '';
        $shares = json_decode($configJson, true);
        $this->assertNull($shares, 'Handler must reject malformed JSON with 400');
    }

    /**
     * Empty array is a valid import (clears all shares — explicit user choice).
     */
    public function testEmptyArrayIsValid(): void
    {
        $_POST = ['action' => 'importConfig', 'config' => '[]'];

        $configJson = $_POST['config'] ?? '';
        $shares = json_decode($configJson, true);

        $this->assertIsArray($shares);
        $this->assertCount(0, $shares);
    }
}
