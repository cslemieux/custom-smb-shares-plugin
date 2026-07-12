<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Unit tests for the Unassigned Devices path policy (REQ-UD-01/02/04).
 *
 * Two layers, mirroring the ExternalPathsTest / ExternalPathValidationTest split:
 *
 *  - Pure predicates (isUdManagedPath, shareExistsWithPath) run on EVERY
 *    platform: they operate on logical paths and never touch realpath().
 *
 *  - validateShare() end-to-end cases depend on stripHarnessRoot producing a
 *    clean logical path (e.g. /mnt/disks/foo). That breaks on macOS where
 *    /tmp realpaths to /private/tmp, so those cases are guarded to a
 *    realpath-stable chroot (Linux/CI) via $stripWorks, exactly as
 *    ExternalPathValidationTest guards its denylist cases. The UD decision
 *    logic itself is fully covered platform-agnostically by the pure-predicate
 *    tests below.
 *
 * Fidelity: high. Uses the ChrootTestEnvironment harness; never touches real
 * /etc/samba. UD candidate directories are created as real dirs inside the
 * chroot so realpath() succeeds and validateShare reaches the UD check.
 */
class UdPathPolicyTest extends TestCase
{
    /** @var bool Whether the harness chroot path is realpath-stable (strip works). */
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

        // Real directories inside the chroot so realpath() resolves and the
        // path reaches the UD policy check (rather than "does not exist").
        ChrootTestEnvironment::mkdir('user/data');     // /mnt/user/data  (allowed)
        ChrootTestEnvironment::mkdir('cache/appdata'); // /mnt/cache/...   (allowed)
        ChrootTestEnvironment::mkdir('disk1/media');   // /mnt/disk1/...   (allowed)
        ChrootTestEnvironment::mkdir('disks');         // /mnt/disks       (UD)
        ChrootTestEnvironment::mkdir('disks/foo');     // /mnt/disks/foo   (UD)
        ChrootTestEnvironment::mkdir('remotes');       // /mnt/remotes     (UD)
        ChrootTestEnvironment::mkdir('remotes/bar');   // /mnt/remotes/bar (UD)
    }

    public static function tearDownAfterClass(): void
    {
        ChrootTestEnvironment::teardownForce();
    }

    /** Toggle ALLOW_EXTERNAL_PATHS in the chroot settings.cfg. */
    private function setExternal(bool $on): void
    {
        $dir = ConfigRegistry::getConfigBase() . '/plugins/custom.smb.shares';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $dir . '/settings.cfg',
            'ALLOW_EXTERNAL_PATHS="' . ($on ? 'enabled' : 'disabled') . "\"\n"
        );
    }

    /**
     * validateShare requires its argument by reference; callers pass a variable.
     * Optional $existing drives the grandfather check.
     * @param array<int,array<string,mixed>>|null $existing
     * @return array<int,string>
     */
    private function validate(string $name, string $path, ?array $existing = null): array
    {
        $share = ['name' => $name, 'path' => $path];
        return validateShare($share, $existing);
    }

    private function requireStrip(): void
    {
        if (!self::$stripWorks) {
            $this->markTestSkipped(
                'Harness chroot is not realpath-stable (e.g. macOS /tmp -> /private/tmp); '
                . 'UD decision logic is covered platform-agnostically by the isUdManagedPath / '
                . 'shareExistsWithPath tests in this file.'
            );
        }
    }

    // ============================================================
    // Pure predicate: isUdManagedPath() -- runs on every platform
    // ============================================================

    public function testIsUdManagedAllowsUserCacheDiskPaths(): void
    {
        $this->assertFalse(isUdManagedPath('/mnt/user'));
        $this->assertFalse(isUdManagedPath('/mnt/user/data'));
        $this->assertFalse(isUdManagedPath('/mnt/cache'));
        $this->assertFalse(isUdManagedPath('/mnt/cache/appdata'));
        $this->assertFalse(isUdManagedPath('/mnt/disk1'));
        $this->assertFalse(isUdManagedPath('/mnt/disk1/media'));
        $this->assertFalse(isUdManagedPath('/mnt/disk12/media'));
    }

    public function testIsUdManagedMatchesDisksAndRemotes(): void
    {
        // Bare roots.
        $this->assertTrue(isUdManagedPath('/mnt/disks'));
        $this->assertTrue(isUdManagedPath('/mnt/remotes'));
        // Trailing-slash roots normalize to the same result.
        $this->assertTrue(isUdManagedPath('/mnt/disks/'));
        $this->assertTrue(isUdManagedPath('/mnt/remotes/'));
        // Subpaths.
        $this->assertTrue(isUdManagedPath('/mnt/disks/foo'));
        $this->assertTrue(isUdManagedPath('/mnt/disks/foo/bar'));
        $this->assertTrue(isUdManagedPath('/mnt/remotes/bar'));
        $this->assertTrue(isUdManagedPath('/mnt/remotes/server/share'));
    }

    public function testIsUdManagedRejectsEmptyAndRoot(): void
    {
        $this->assertFalse(isUdManagedPath(''));
        $this->assertFalse(isUdManagedPath('/'));
    }

    // ============================================================
    // Pure predicate: shareExistsWithPath() -- grandfather helper
    // ============================================================

    public function testShareExistsWithPathMatchesExactLogicalPath(): void
    {
        $existing = [
            ['name' => 'a', 'path' => '/mnt/user/data'],
            ['name' => 'uddisk', 'path' => '/mnt/disks/foo'],
        ];
        $this->assertTrue(shareExistsWithPath('/mnt/disks/foo', $existing));
        $this->assertFalse(shareExistsWithPath('/mnt/disks/other', $existing));
        $this->assertFalse(shareExistsWithPath('/mnt/disks/foo', []));
    }

    // ============================================================
    // validateShare() end-to-end -- guarded to realpath-stable /tmp
    // ============================================================

    public function testValidateRejectsNewDisksShareWhenExternalOff(): void
    {
        $this->requireStrip();
        $this->setExternal(false);
        $errors = $this->validate('uddisk', ChrootTestEnvironment::getMntPath('disks/foo'), []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unassigned Devices', $errors[0]);
        $this->assertStringContainsString('/mnt/user', $errors[0]);
    }

    public function testValidateRejectsNewRemotesShareWhenExternalOff(): void
    {
        $this->requireStrip();
        $this->setExternal(false);
        $errors = $this->validate('udremote', ChrootTestEnvironment::getMntPath('remotes/bar'), []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unassigned Devices', $errors[0]);
        $this->assertStringContainsString('/mnt/user', $errors[0]);
    }

    public function testValidateRejectsNewDisksShareWhenExternalOn(): void
    {
        $this->requireStrip();
        $this->setExternal(true);
        $errors = $this->validate('uddisk', ChrootTestEnvironment::getMntPath('disks/foo'), []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unassigned Devices', $errors[0]);
        $this->assertStringContainsString('/mnt/user', $errors[0]);
    }

    public function testValidateRejectsNewRemotesShareWhenExternalOn(): void
    {
        $this->requireStrip();
        $this->setExternal(true);
        $errors = $this->validate('udremote', ChrootTestEnvironment::getMntPath('remotes/bar'), []);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Unassigned Devices', $errors[0]);
        $this->assertStringContainsString('/mnt/user', $errors[0]);
    }

    public function testValidateGrandfathersExistingDisksShare(): void
    {
        $this->requireStrip();
        // Stored path is the LOGICAL path (/mnt/disks/foo), matching what
        // validateShare canonicalizes $data['path'] to.
        $existing = [
            ['name' => 'uddisk', 'path' => '/mnt/disks/foo'],
        ];
        $this->setExternal(false);
        $errors = $this->validate('uddisk', ChrootTestEnvironment::getMntPath('disks/foo'), $existing);
        $this->assertEmpty($errors, 'Existing UD-path share must be grandfathered: ' . implode('; ', $errors));
    }

    public function testValidateGrandfathersExistingRemotesShareBothModes(): void
    {
        $this->requireStrip();
        $existing = [
            ['name' => 'udremote', 'path' => '/mnt/remotes/bar'],
        ];
        foreach ([false, true] as $external) {
            $this->setExternal($external);
            $errors = $this->validate('udremote', ChrootTestEnvironment::getMntPath('remotes/bar'), $existing);
            $this->assertEmpty(
                $errors,
                'Existing UD-path share must be grandfathered (external=' . var_export($external, true) . '): '
                    . implode('; ', $errors)
            );
        }
    }

    public function testValidateAcceptsUserPathBothModes(): void
    {
        // /mnt/user is never UD-managed regardless of strip behaviour, but the
        // realpath/strip must still yield a valid /mnt path; guard to be safe.
        $this->requireStrip();
        foreach ([false, true] as $external) {
            $this->setExternal($external);
            $errors = $this->validate('ok', ChrootTestEnvironment::getMntPath('user/data'), []);
            $this->assertEmpty(
                $errors,
                '/mnt/user share must be accepted (external=' . var_export($external, true) . '): '
                    . implode('; ', $errors)
            );
        }
    }
}
