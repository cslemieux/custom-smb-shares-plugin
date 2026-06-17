<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Integration tests for issue #22: validateShare path validation with the
 * ALLOW_EXTERNAL_PATHS setting, end-to-end through the chroot harness.
 *
 * Robust cases (mnt acceptance, external acceptance, OFF rejection, symlink-OFF,
 * nonexistent) run on every platform. The two denylist-WHEN-ENABLED cases depend
 * on stripHarnessRoot producing a clean logical path, which breaks on macOS where
 * /tmp realpaths to /private/tmp; they are guarded to realpath-stable /tmp (Linux/CI).
 * Denylist correctness itself is fully covered platform-agnostically by
 * ExternalPathsTest (isDeniedSystemPath unit tests).
 */
class ExternalPathValidationTest extends TestCase
{
    /** @var bool Whether the harness chroot path is realpath-stable (denylist strip works). */
    private static bool $stripWorks = false;

    public static function setUpBeforeClass(): void
    {
        ChrootTestEnvironment::setup();
        $chroot = ChrootTestEnvironment::getChrootDir();
        self::$stripWorks = (realpath($chroot) === $chroot);
    }

    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        TestModeDetector::reset(); // recompute harness root for the current chroot
        ChrootTestEnvironment::mkdir('user/data'); // /mnt/user/data
    }

    public static function tearDownAfterClass(): void
    {
        ChrootTestEnvironment::teardownForce();
    }

    private function setExternal(bool $on): void
    {
        $dir = ConfigRegistry::getConfigBase() . '/plugins/custom.smb.shares';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/settings.cfg', 'ALLOW_EXTERNAL_PATHS="' . ($on ? 'enabled' : 'disabled') . "\"\n");
    }

    private function mnt(string $sub): string
    {
        return ChrootTestEnvironment::getMntPath($sub);
    }

    private function ext(string $abs): string
    {
        // A path under the chroot but OUTSIDE /mnt (an "external" path).
        return ChrootTestEnvironment::getChrootDir() . $abs;
    }

    /**
     * Helper: validateShare requires its argument by reference, so callers must
     * pass a variable (not an array literal).
     * @return array<int,string>
     */
    private function validate(string $name, string $path): array
    {
        $share = ['name' => $name, 'path' => $path];
        return validateShare($share);
    }

    // --- OFF (default): /mnt only ---

    public function testOffRejectsExternalPath(): void
    {
        $this->setExternal(false);
        ChrootTestEnvironment::createShareDir('/srv/data');
        $errors = $this->validate('ext', $this->ext('/srv/data'));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('/mnt/', $errors[0]);
    }

    public function testOffAcceptsMntPath(): void
    {
        $this->setExternal(false);
        $this->assertEmpty($this->validate('ok', $this->mnt('user/data')));
    }

    // --- ON: external allowed, /mnt still works ---

    public function testOnAcceptsExternalWritableDir(): void
    {
        $this->setExternal(true);
        ChrootTestEnvironment::createShareDir('/srv/data');
        $this->assertEmpty($this->validate('ext', $this->ext('/srv/data')));
    }

    public function testOnStillAcceptsMntPath(): void
    {
        $this->setExternal(true);
        $this->assertEmpty($this->validate('ok', $this->mnt('user/data')));
    }

    public function testOnRejectsRelativePath(): void
    {
        $this->setExternal(true);
        $errors = $this->validate('rel', 'relative/path');
        $this->assertNotEmpty($errors);
    }

    // --- preserved checks (both modes) ---

    public function testNonexistentPathRejected(): void
    {
        $this->setExternal(true);
        $errors = $this->validate('ne', $this->ext('/srv/does-not-exist'));
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not exist', strtolower($errors[0]));
    }

    // --- symlink escape (OFF): robust everywhere via isValidMntPath ---

    public function testSymlinkEscapeBlockedWhenOff(): void
    {
        $this->setExternal(false);
        ChrootTestEnvironment::createShareDir('/boot');
        $link = $this->mnt('user/evil');
        @unlink($link);
        symlink(ChrootTestEnvironment::getChrootDir() . '/boot', $link);
        $errors = $this->validate('evil', $link);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('/mnt/', $errors[0]);
    }

    // --- denylist WHEN ENABLED: guarded to realpath-stable /tmp (Linux/CI) ---

    public function testOnBlocksSystemDirsWhenEnabled(): void
    {
        if (!self::$stripWorks) {
            $this->markTestSkipped('Harness chroot is not realpath-stable (e.g. macOS /tmp -> /private/tmp); denylist logic is covered by ExternalPathsTest.');
        }
        $this->setExternal(true);
        foreach (['/boot/config', '/etc/x', '/var/x', '/usr/x', '/root/x'] as $sys) {
            ChrootTestEnvironment::createShareDir($sys);
            $errors = $this->validate('sys', $this->ext($sys));
            $this->assertNotEmpty($errors, "$sys should be rejected when enabled");
            $this->assertStringContainsString('system director', strtolower($errors[0]), "$sys message");
        }
    }

    public function testSymlinkEscapeBlockedWhenOnViaDenylist(): void
    {
        if (!self::$stripWorks) {
            $this->markTestSkipped('Harness chroot is not realpath-stable; denylist logic is covered by ExternalPathsTest.');
        }
        $this->setExternal(true);
        ChrootTestEnvironment::createShareDir('/boot');
        $link = $this->mnt('user/evil2');
        @unlink($link);
        symlink(ChrootTestEnvironment::getChrootDir() . '/boot', $link);
        $errors = $this->validate('evil2', $link);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('system director', strtolower($errors[0]));
    }
}
