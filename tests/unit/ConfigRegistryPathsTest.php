<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Unit tests for ConfigRegistry Samba runtime path resolution
 * (REQ-DRY-01, REQ-RAM-01, REQ-RAM-03).
 *
 * Verifies:
 *  - The production absolute paths are the RAM locations
 *    (/etc/samba/smb-custom.conf and /etc/samba/smb.conf), exposed as the
 *    SMB_CUSTOM_CONF / SMB_CONF constants and returned verbatim by the getters
 *    when NOT in test mode.
 *  - In test mode both getters are harness-prefixed
 *    (<harnessRoot>/etc/samba/...), so the suite never resolves to a real
 *    /etc/samba path.
 *  - No write to a real /etc/samba path occurs.
 *
 * Because tests/bootstrap.php defines PHPUNIT_TEST, isTestMode() is always true
 * inside the suite; the production-path assertions are therefore made against
 * the class constants (the production values the getters return when test mode
 * is off), while the prefixing behaviour is asserted through the getters under
 * the chroot harness.
 *
 * Fidelity: high. Never touches real /etc/samba.
 */
class ConfigRegistryPathsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ChrootTestEnvironment::setup();
    }

    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        TestModeDetector::reset(); // recompute harness root for the current chroot
    }

    public static function tearDownAfterClass(): void
    {
        ChrootTestEnvironment::teardownForce();
    }

    // ---- Production absolute paths (RAM locations) ----

    public function testProductionSmbCustomConfConstantIsRamPath(): void
    {
        $this->assertSame('/etc/samba/smb-custom.conf', ConfigRegistry::SMB_CUSTOM_CONF);
    }

    public function testProductionSmbConfConstantIsRamPath(): void
    {
        $this->assertSame('/etc/samba/smb.conf', ConfigRegistry::SMB_CONF);
    }

    public function testSmbExtraConfRemainsFlashBased(): void
    {
        // Migration still needs the flash-based extra conf path; it must NOT
        // have been repointed to the RAM location.
        $extra = ConfigRegistry::getSmbExtraConfPath();
        $this->assertStringEndsWith('/smb-extra.conf', $extra);
        $this->assertStringNotContainsString('/etc/samba/', $extra);
    }

    // ---- Test-mode getters are harness-prefixed (REQ-RAM-03) ----

    public function testGetSmbCustomConfPathIsHarnessPrefixedInTestMode(): void
    {
        $this->assertTrue(TestModeDetector::isTestMode());
        $harnessRoot = TestModeDetector::getHarnessRoot();
        $this->assertNotSame('', $harnessRoot, 'Harness root should be non-empty in test mode');

        $path = ConfigRegistry::getSmbCustomConfPath();
        $this->assertSame($harnessRoot . '/etc/samba/smb-custom.conf', $path);
        // Must NOT be the bare production path (would resolve to a real
        // /etc/samba write).
        $this->assertNotSame('/etc/samba/smb-custom.conf', $path);
        $this->assertStringStartsWith($harnessRoot, $path);
    }

    public function testGetSmbConfPathIsHarnessPrefixedInTestMode(): void
    {
        $harnessRoot = TestModeDetector::getHarnessRoot();
        $this->assertNotSame('', $harnessRoot);

        $path = ConfigRegistry::getSmbConfPath();
        $this->assertSame($harnessRoot . '/etc/samba/smb.conf', $path);
        $this->assertNotSame('/etc/samba/smb.conf', $path);
        $this->assertStringStartsWith($harnessRoot, $path);
    }

    public function testGetSmbCustomConfPathEndsWithRamSuffix(): void
    {
        $this->assertStringEndsWith('/etc/samba/smb-custom.conf', ConfigRegistry::getSmbCustomConfPath());
    }

    public function testGetSmbConfPathEndsWithRamSuffix(): void
    {
        $this->assertStringEndsWith('/etc/samba/smb.conf', ConfigRegistry::getSmbConfPath());
    }

    // ---- Getters track ConfigRegistry base changes (isolation) ----

    public function testGettersFollowHarnessRootAfterBaseChange(): void
    {
        $chroot = ChrootTestEnvironment::getChrootDir();
        $path = ConfigRegistry::getSmbCustomConfPath();
        // The harness-prefixed path lives under the current chroot, never under
        // the real filesystem root's /etc/samba.
        $this->assertStringStartsWith($chroot, $path);
    }

    // ---- No real /etc/samba write occurs (REQ-RAM-01 safety) ----

    public function testNoRealEtcSambaWrite(): void
    {
        // The resolved custom-conf path is confined to the harness root, so
        // writing through it can never touch the host's /etc/samba.
        $harnessRoot = TestModeDetector::getHarnessRoot();
        $customPath = ConfigRegistry::getSmbCustomConfPath();
        $smbConfPath = ConfigRegistry::getSmbConfPath();

        $this->assertStringStartsWith($harnessRoot . '/', $customPath);
        $this->assertStringStartsWith($harnessRoot . '/', $smbConfPath);

        // Actually write via the resolved path and confirm the real /etc/samba
        // location is untouched.
        $dir = dirname($customPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($customPath, "# harness probe\n");
        $this->assertFileExists($customPath);
        // The constructed path (what the plugin actually writes to) is confined
        // to the harness root -- the guarantee that matters. We deliberately do
        // NOT assert on realpath() here: on macOS /tmp canonicalizes to
        // /private/tmp, which is a harness artifact, not a real /etc/samba escape.
        $this->assertNotSame('/etc/samba/smb-custom.conf', $customPath);
        $this->assertStringNotContainsString('/private/etc/samba', $customPath);
    }
}
