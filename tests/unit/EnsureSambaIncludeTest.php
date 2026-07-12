<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Unit tests for ensureSambaInclude() (REQ-INC-01/02).
 *
 * ensureSambaInclude() injects an idempotent, uniquely-marked hook block
 *
 *     # hook for custom smb shares
 *     include = <getSmbCustomConfPath()>
 *     # end hook for custom smb shares
 *
 * into the RAM smb.conf (ConfigRegistry::getSmbConfPath()). Verifies:
 *  - a first call on a harness smb.conf adds exactly ONE hook block + include;
 *  - a second call is a no-op (idempotent on the HOOK_BEGIN marker);
 *  - pre-existing Unraid / Unassigned-Devices content is preserved verbatim;
 *  - smb-extra.conf is NEVER written by ensureSambaInclude (the old flash-based
 *    model is gone).
 *
 * Fidelity: high. Operates entirely on the harness-prefixed smb.conf; never
 * touches real /etc/samba.
 */
class EnsureSambaIncludeTest extends TestCase
{
    private string $smbConf;
    private string $smbCustomConf;
    private string $smbExtraConf;

    public static function setUpBeforeClass(): void
    {
        ChrootTestEnvironment::setup();
    }

    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        TestModeDetector::reset(); // recompute harness root for the current chroot

        $this->smbConf = ConfigRegistry::getSmbConfPath();
        $this->smbCustomConf = ConfigRegistry::getSmbCustomConfPath();
        $this->smbExtraConf = ConfigRegistry::getSmbExtraConfPath();

        // Start each test from a clean smb.conf under the harness.
        $dir = dirname($this->smbConf);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (file_exists($this->smbConf)) {
            unlink($this->smbConf);
        }
        // Remove any stray extra conf so we can prove ensureSambaInclude never
        // creates it.
        if (file_exists($this->smbExtraConf)) {
            unlink($this->smbExtraConf);
        }
    }

    public static function tearDownAfterClass(): void
    {
        ChrootTestEnvironment::teardownForce();
    }

    private function writeSmbConf(string $content): void
    {
        file_put_contents($this->smbConf, $content);
    }

    /** Count occurrences of the hook-begin marker. */
    private function hookCount(string $content): int
    {
        return substr_count($content, HOOK_BEGIN);
    }

    // ---- Injection into an existing smb.conf ----

    public function testInjectsExactlyOneHookBlockAndInclude(): void
    {
        $existing = "[global]\n    workgroup = WORKGROUP\n    server string = Unraid\n";
        $this->writeSmbConf($existing);

        $this->assertTrue(ensureSambaInclude());

        $content = file_get_contents($this->smbConf);
        // Exactly one hook block.
        $this->assertSame(1, $this->hookCount($content));
        $this->assertStringContainsString(HOOK_BEGIN, $content);
        $this->assertStringContainsString(HOOK_END, $content);
        // The include line points at the RAM custom conf.
        $this->assertStringContainsString('include = ' . $this->smbCustomConf, $content);
        // Exactly one include line for our custom conf.
        $this->assertSame(1, substr_count($content, 'include = ' . $this->smbCustomConf));
        // Pre-existing content preserved verbatim.
        $this->assertStringContainsString($existing, $content);
    }

    public function testInjectsWhenSmbConfDoesNotExist(): void
    {
        // No smb.conf on disk yet: ensureSambaInclude creates it with the hook.
        $this->assertFileDoesNotExist($this->smbConf);
        $this->assertTrue(ensureSambaInclude());
        $this->assertFileExists($this->smbConf);
        $content = file_get_contents($this->smbConf);
        $this->assertSame(1, $this->hookCount($content));
        $this->assertStringContainsString('include = ' . $this->smbCustomConf, $content);
    }

    // ---- Idempotency (REQ-INC-02) ----

    public function testSecondCallIsNoOp(): void
    {
        $existing = "[global]\n    workgroup = WORKGROUP\n";
        $this->writeSmbConf($existing);

        $this->assertTrue(ensureSambaInclude());
        $afterFirst = file_get_contents($this->smbConf);

        $this->assertTrue(ensureSambaInclude());
        $afterSecond = file_get_contents($this->smbConf);

        // Byte-for-byte identical: no duplicate block, no drift.
        $this->assertSame($afterFirst, $afterSecond);
        $this->assertSame(1, $this->hookCount($afterSecond));
        $this->assertSame(1, substr_count($afterSecond, 'include = ' . $this->smbCustomConf));
    }

    public function testRepeatedCallsNeverDuplicate(): void
    {
        $this->writeSmbConf("[global]\n    workgroup = WORKGROUP\n");
        for ($i = 0; $i < 5; $i++) {
            $this->assertTrue(ensureSambaInclude());
        }
        $content = file_get_contents($this->smbConf);
        $this->assertSame(1, $this->hookCount($content));
        $this->assertSame(1, substr_count($content, HOOK_END));
    }

    // ---- Pre-existing Unraid / UD content preserved verbatim ----

    public function testPreservesUnraidAndUdContentVerbatim(): void
    {
        // Simulate an smb.conf that Unraid generated AND that Unassigned
        // Devices has already appended its own include to.
        $existing = "[global]\n"
            . "    workgroup = WORKGROUP\n"
            . "    security = user\n"
            . "\n"
            . "# Unassigned Devices share include\n"
            . "include = /boot/config/plugins/unassigned.devices/smb-settings.conf\n";
        $this->writeSmbConf($existing);

        $this->assertTrue(ensureSambaInclude());
        $content = file_get_contents($this->smbConf);

        // Every original line survives unchanged.
        $this->assertStringContainsString($existing, $content);
        // UD's own include is untouched (our marker-delimited block does not
        // collide with it).
        $this->assertStringContainsString(
            'include = /boot/config/plugins/unassigned.devices/smb-settings.conf',
            $content
        );
        // Our block was appended after the existing content.
        $this->assertStringContainsString(HOOK_BEGIN, $content);
        $this->assertTrue(strpos($content, $existing) < strpos($content, HOOK_BEGIN));
    }

    // ---- smb-extra.conf is NEVER written (REQ-INC-01) ----

    public function testNeverWritesSmbExtraConf(): void
    {
        $this->writeSmbConf("[global]\n    workgroup = WORKGROUP\n");
        $this->assertFileDoesNotExist($this->smbExtraConf);

        $this->assertTrue(ensureSambaInclude());

        // ensureSambaInclude must not have created smb-extra.conf.
        $this->assertFileDoesNotExist($this->smbExtraConf);
    }

    public function testLeavesExistingSmbExtraConfUntouched(): void
    {
        // A pre-existing smb-extra.conf (e.g. from another tool) must be left
        // byte-for-byte unchanged by ensureSambaInclude.
        $extraDir = dirname($this->smbExtraConf);
        if (!is_dir($extraDir)) {
            mkdir($extraDir, 0755, true);
        }
        $extraContent = "# some other tool's directives\ninclude = /somewhere/other.conf\n";
        file_put_contents($this->smbExtraConf, $extraContent);

        $this->writeSmbConf("[global]\n    workgroup = WORKGROUP\n");
        $this->assertTrue(ensureSambaInclude());

        $this->assertSame($extraContent, file_get_contents($this->smbExtraConf));
    }
}
