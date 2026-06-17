<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Unit tests for issue #22 helpers: isExternalPathsAllowed() and
 * isDeniedSystemPath(). isDeniedSystemPath is a pure function, so these tests
 * verify denylist correctness on any platform.
 */
class ExternalPathsTest extends TestCase
{
    private string $tempDir;
    private string $configFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/external-paths-test-' . uniqid();
        mkdir($this->tempDir . '/plugins/custom.smb.shares', 0755, true);
        $this->configFile = $this->tempDir . '/plugins/custom.smb.shares/settings.cfg';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    // --- isExternalPathsAllowed (default OFF) ---

    public function testDefaultsToFalseWhenNoConfig(): void
    {
        $this->assertFileDoesNotExist($this->configFile);
        $this->assertFalse(isExternalPathsAllowed($this->tempDir));
    }

    public function testTrueWhenEnabled(): void
    {
        file_put_contents($this->configFile, "ALLOW_EXTERNAL_PATHS=enabled\n");
        $this->assertTrue(isExternalPathsAllowed($this->tempDir));
    }

    public function testFalseWhenDisabled(): void
    {
        file_put_contents($this->configFile, "ALLOW_EXTERNAL_PATHS=disabled\n");
        $this->assertFalse(isExternalPathsAllowed($this->tempDir));
    }

    public function testFalseWhenKeyAbsent(): void
    {
        file_put_contents($this->configFile, "SERVICE=enabled\nBACKUP_COUNT=10\n");
        $this->assertFalse(isExternalPathsAllowed($this->tempDir));
    }

    public function testFalseForUnknownValue(): void
    {
        // Only the explicit string 'enabled' turns it on (fail-safe).
        file_put_contents($this->configFile, "ALLOW_EXTERNAL_PATHS=yes\n");
        $this->assertFalse(isExternalPathsAllowed($this->tempDir));
    }

    // --- isDeniedSystemPath (pure; platform-agnostic) ---

    public function testDeniesRoot(): void
    {
        $this->assertTrue(isDeniedSystemPath('/'));
    }

    public function testDeniesSystemDirsAndSubpaths(): void
    {
        $denied = [
            '/boot', '/boot/config', '/etc', '/etc/samba', '/proc', '/sys',
            '/dev', '/var', '/var/log', '/usr', '/usr/bin', '/root',
            '/bin', '/sbin', '/lib', '/lib64', '/lib64/x',
        ];
        foreach ($denied as $p) {
            $this->assertTrue(isDeniedSystemPath($p), "$p should be denied");
        }
    }

    public function testAllowsNonSystemDirs(): void
    {
        $allowed = [
            '/mnt', '/mnt/user', '/mnt/user/foo', '/tmp', '/tmp/share',
            '/srv/data', '/home/user', '/data',
            // Prefix-collision guards: must NOT be treated as system dirs
            '/boots', '/etcfoo', '/variant', '/libreoffice',
        ];
        foreach ($allowed as $p) {
            $this->assertFalse(isDeniedSystemPath($p), "$p should be allowed");
        }
    }

    public function testTrailingSlashNormalized(): void
    {
        $this->assertTrue(isDeniedSystemPath('/boot/'));
        $this->assertFalse(isDeniedSystemPath('/mnt/user/'));
    }
}
