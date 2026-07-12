<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../harness/SambaMock.php';
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Integration tests for rebuildSambaConfig() and the mutation flows that route
 * through it (REQ-SEAM-01/02/03, REQ-INC-03, REQ-RAM-02, REQ-DRY-02/03).
 *
 * rebuildSambaConfig() writes the RAM working copy (smb-custom.conf), ensures
 * the smb.conf include hook, runs the Recycle Bin seam (no-op today), and
 * reloads Samba via the mock testparm / smbcontrol scripts.
 *
 * Cross-component: add / update / delete / import / restore / toggle / settings
 * each leave the expected active share set, asserted via verifySambaShare()
 * (which reads the RAM custom conf through mock testparm) and/or direct config
 * content. disks_mounted re-injects the include after a simulated smb.conf
 * regeneration. The Recycle Bin seam is a no-op while applyRecycleBinDirectives
 * is undefined. Write-failure handling returns { success:false } without
 * corrupting an existing config (D-1 finding i).
 *
 * Fidelity: high (integration). Never touches real /etc/samba.
 */
class RebuildSambaConfigTest extends TestCase
{
    private string $smbConf;
    private string $smbCustomConf;
    private string $sharesFile;
    private string $configFile;   // mock testparm reads this (the RAM custom conf)
    private string $testparmPath;

    public static function setUpBeforeClass(): void
    {
        ChrootTestEnvironment::setup();
        if (!defined('CONFIG_BASE')) {
            define('CONFIG_BASE', ConfigRegistry::getConfigBase());
        }
    }

    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        TestModeDetector::reset();

        // Mock testparm / smbcontrol; status = running so reload succeeds.
        SambaMock::init(ChrootTestEnvironment::getChrootDir());
        SambaMock::initScripts();

        $this->smbConf = ConfigRegistry::getSmbConfPath();
        $this->smbCustomConf = ConfigRegistry::getSmbCustomConfPath();
        $this->sharesFile = ConfigRegistry::getSharesFilePath();

        $mock = TestModeDetector::getMockScriptPaths();
        $this->configFile = $mock['configFile'];     // == getSmbCustomConfPath()
        $this->testparmPath = $mock['testparm'];

        foreach ([$this->smbConf, $this->smbCustomConf] as $f) {
            $dir = dirname($f);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (file_exists($f)) {
                unlink($f);
            }
        }
        // Seed an Unraid-style smb.conf so include injection has real content
        // to preserve.
        file_put_contents($this->smbConf, "[global]\n    workgroup = WORKGROUP\n");

        $sharesDir = dirname($this->sharesFile);
        if (!is_dir($sharesDir)) {
            mkdir($sharesDir, 0755, true);
        }

        // Real share directories so any later validateShare stays green (not
        // strictly required here since we call rebuild directly).
        ChrootTestEnvironment::mkdir('user/alpha');
        ChrootTestEnvironment::mkdir('user/bravo');
        ChrootTestEnvironment::mkdir('user/charlie');
    }

    public static function tearDownAfterClass(): void
    {
        ChrootTestEnvironment::teardownForce();
    }

    /** verifySambaShare against the RAM custom conf via mock testparm. */
    private function shareActive(string $name): bool
    {
        return verifySambaShare($name, true, $this->configFile, $this->testparmPath);
    }

    /** @param array<int,array<string,mixed>> $shares */
    private function seedShares(array $shares): void
    {
        file_put_contents($this->sharesFile, json_encode($shares, JSON_PRETTY_PRINT));
    }

    private function mntShare(string $name, string $sub, bool $enabled = true): array
    {
        return ['name' => $name, 'path' => '/mnt/user/' . $sub, 'security' => 'public', 'enabled' => $enabled];
    }

    // ---- Core: rebuildSambaConfig writes RAM conf + include + reloads ----

    public function testRebuildWritesRamConfEnsuresIncludeAndReloads(): void
    {
        $shares = [$this->mntShare('Alpha', 'alpha')];
        $result = rebuildSambaConfig($shares);

        $this->assertTrue($result['success'], 'reload should succeed: ' . ($result['error'] ?? ''));
        // RAM working copy written.
        $this->assertFileExists($this->smbCustomConf);
        $this->assertStringContainsString('[Alpha]', file_get_contents($this->smbCustomConf));
        // Include hook injected into smb.conf.
        $smb = file_get_contents($this->smbConf);
        $this->assertStringContainsString(HOOK_BEGIN, $smb);
        $this->assertStringContainsString('include = ' . $this->smbCustomConf, $smb);
        // Existing global content preserved.
        $this->assertStringContainsString('workgroup = WORKGROUP', $smb);
        // Active per testparm.
        $this->assertTrue($this->shareActive('Alpha'));
    }

    public function testRebuildLoadsSharesFromDiskWhenNull(): void
    {
        $this->seedShares([$this->mntShare('Bravo', 'bravo')]);
        $result = rebuildSambaConfig(); // null -> loadShares()
        $this->assertTrue($result['success']);
        $this->assertTrue($this->shareActive('Bravo'));
    }

    // ---- Mutation flows: add / update / delete / import / restore / toggle / settings ----

    public function testAddFlowLeavesShareActive(): void
    {
        // add.php effect: append to shares.json, then rebuild.
        $shares = [$this->mntShare('Alpha', 'alpha')];
        $shares[] = $this->mntShare('Bravo', 'bravo');
        $this->seedShares($shares);
        $this->assertTrue(rebuildSambaConfig($shares)['success']);
        $this->assertTrue($this->shareActive('Alpha'));
        $this->assertTrue($this->shareActive('Bravo'));
    }

    public function testUpdateFlowReflectsEditedShareSet(): void
    {
        // update.php effect: replace a share definition, then rebuild.
        $shares = [$this->mntShare('Alpha', 'alpha'), $this->mntShare('Bravo', 'bravo')];
        rebuildSambaConfig($shares);
        // Rename Bravo -> Charlie (an "update").
        $shares[1] = $this->mntShare('Charlie', 'charlie');
        $this->assertTrue(rebuildSambaConfig($shares)['success']);
        $this->assertTrue($this->shareActive('Alpha'));
        $this->assertTrue($this->shareActive('Charlie'));
        $this->assertFalse($this->shareActive('Bravo'));
    }

    public function testDeleteFlowRemovesShare(): void
    {
        $shares = [$this->mntShare('Alpha', 'alpha'), $this->mntShare('Bravo', 'bravo')];
        rebuildSambaConfig($shares);
        $this->assertTrue($this->shareActive('Bravo'));
        // delete.php effect: drop Bravo, rebuild.
        $remaining = [$this->mntShare('Alpha', 'alpha')];
        $this->assertTrue(rebuildSambaConfig($remaining)['success']);
        $this->assertTrue($this->shareActive('Alpha'));
        $this->assertFalse($this->shareActive('Bravo'));
    }

    public function testImportFlowReplacesShareSet(): void
    {
        rebuildSambaConfig([$this->mntShare('Alpha', 'alpha')]);
        // importConfig effect: saveShares(new set) then rebuild (REQ-INC-03).
        $imported = [$this->mntShare('Bravo', 'bravo'), $this->mntShare('Charlie', 'charlie')];
        $this->seedShares($imported);
        $this->assertTrue(rebuildSambaConfig($imported)['success']);
        $this->assertTrue($this->shareActive('Bravo'));
        $this->assertTrue($this->shareActive('Charlie'));
        $this->assertFalse($this->shareActive('Alpha'));
    }

    public function testRestoreFlowAppliesRestoredShareSet(): void
    {
        rebuildSambaConfig([$this->mntShare('Charlie', 'charlie')]);
        // restoreBackup effect: shares.json replaced from a backup, then rebuild.
        $restored = [$this->mntShare('Alpha', 'alpha')];
        $this->seedShares($restored);
        $this->assertTrue(rebuildSambaConfig($restored)['success']);
        $this->assertTrue($this->shareActive('Alpha'));
        $this->assertFalse($this->shareActive('Charlie'));
    }

    public function testToggleFlowDisablesShare()
    {
        // toggleShare effect: flip enabled=false, rebuild -> share excluded.
        $shares = [$this->mntShare('Alpha', 'alpha'), $this->mntShare('Bravo', 'bravo')];
        rebuildSambaConfig($shares);
        $this->assertTrue($this->shareActive('Bravo'));

        $shares[1]['enabled'] = false; // toggle off
        $this->assertTrue(rebuildSambaConfig($shares)['success']);
        $this->assertTrue($this->shareActive('Alpha'));
        $this->assertFalse($this->shareActive('Bravo'), 'Disabled share must be excluded from generated config');
    }

    public function testSettingsFlowRebuildsFromCurrentShares()
    {
        // Settings "SERVICE=enabled" effect: rebuild with no args (loads disk).
        $this->seedShares([$this->mntShare('Alpha', 'alpha')]);
        $this->assertTrue(rebuildSambaConfig()['success']);
        $this->assertTrue($this->shareActive('Alpha'));
    }

    // ---- disks_mounted: re-inject include after smb.conf regeneration ----

    public function testDisksMountedReinjectsIncludeAfterSmbConfRegeneration()
    {
        // Initial rebuild injects the hook.
        rebuildSambaConfig([$this->mntShare('Alpha', 'alpha')]);
        $this->assertStringContainsString(HOOK_BEGIN, file_get_contents($this->smbConf));

        // Simulate Unraid regenerating smb.conf on array start (disks_mounted):
        // the hook marker is gone.
        file_put_contents($this->smbConf, "[global]\n    workgroup = REGENERATED\n");
        $this->assertStringNotContainsString(HOOK_BEGIN, file_get_contents($this->smbConf));

        // disks_mounted effect: rebuildSambaConfig() re-injects the include.
        $this->seedShares([$this->mntShare('Alpha', 'alpha')]);
        $this->assertTrue(rebuildSambaConfig()['success']);

        $smb = file_get_contents($this->smbConf);
        $this->assertStringContainsString(HOOK_BEGIN, $smb, 'Include hook must be re-injected after regeneration');
        $this->assertStringContainsString('include = ' . $this->smbCustomConf, $smb);
        $this->assertStringContainsString('workgroup = REGENERATED', $smb, 'Regenerated content preserved');
        $this->assertTrue($this->shareActive('Alpha'));
    }

    // ---- Recycle Bin seam: no-op when applyRecycleBinDirectives is undefined ----

    public function testRecycleBinSeamIsNoOpWhenFunctionUndefined()
    {
        $this->assertFalse(
            function_exists('applyRecycleBinDirectives'),
            'Precondition: the Recycle Bin seam function must be undefined in this build'
        );
        $result = rebuildSambaConfig([$this->mntShare('Alpha', 'alpha')]);
        $this->assertTrue($result['success']);
        // Config is exactly the base generated config -- the seam added nothing.
        $expected = generateSambaConfig([$this->mntShare('Alpha', 'alpha')]);
        $this->assertSame($expected, file_get_contents($this->smbCustomConf));
    }

    // ---- Write-failure handling (D-1 finding i): no corruption ----

    public function testWriteFailureReturnsFalseAndDoesNotCorruptExistingConfig()
    {
        // Establish a good existing config first.
        rebuildSambaConfig([$this->mntShare('Alpha', 'alpha')]);
        $good = file_get_contents($this->smbCustomConf);
        $this->assertStringContainsString('[Alpha]', $good);

        // Make the RAM conf directory unwritable so the temp-file write fails.
        $dir = dirname($this->smbCustomConf);
        @chmod($dir, 0500);

        // If the process can still write despite 0500 (e.g. running as root),
        // this failure path can't be exercised on this platform.
        $probe = @file_put_contents($dir . '/.wtest', 'x');
        if ($probe !== false) {
            @unlink($dir . '/.wtest');
            @chmod($dir, 0755);
            $this->markTestSkipped('Cannot make dir unwritable (running as root?); write-failure path is platform-guarded.');
        }

        $result = rebuildSambaConfig([$this->mntShare('Bravo', 'bravo')]);

        // Restore writability before assertions/teardown.
        @chmod($dir, 0755);

        $this->assertFalse($result['success'], 'rebuild must report failure when the RAM conf cannot be written');
        // The previously-good config is untouched (atomic temp+rename: the temp
        // write failed, so the live config was never replaced).
        $this->assertSame($good, file_get_contents($this->smbCustomConf), 'Existing config must not be corrupted on write failure');
        $this->assertStringContainsString('[Alpha]', file_get_contents($this->smbCustomConf));
        $this->assertStringNotContainsString('[Bravo]', file_get_contents($this->smbCustomConf));
    }
}
