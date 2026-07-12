<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../harness/SambaMock.php';
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Unit tests for the Samba runtime migration (REQ-MIG-01/02/03).
 *
 * removeOldSmbExtraInclude() strips ONLY the plugin's own
 * "# Custom SMB Shares plugin" comment line and the old flash-based include
 * line (referencing /plugins/custom.smb.shares/smb-custom.conf), preserving all
 * other bytes of smb-extra.conf verbatim.
 *
 * migrateSambaRuntime() then rebuilds the runtime config via the new RAM-path +
 * smb.conf-hook model and MUST leave shares.json (including any UD-path shares)
 * unchanged.
 *
 * Fidelity: high. Uses the ChrootTestEnvironment harness + mock testparm /
 * smbcontrol scripts; never touches real /etc/samba.
 */
class MigrationTest extends TestCase
{
    private string $smbExtraConf;
    private string $smbConf;
    private string $smbCustomConf;
    private string $sharesFile;

    public static function setUpBeforeClass(): void
    {
        ChrootTestEnvironment::setup();
    }

    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        TestModeDetector::reset();

        // Mock testparm / smbcontrol so rebuildSambaConfig()'s reload succeeds.
        SambaMock::init(ChrootTestEnvironment::getChrootDir());
        SambaMock::initScripts();

        $this->smbExtraConf = ConfigRegistry::getSmbExtraConfPath();
        $this->smbConf = ConfigRegistry::getSmbConfPath();
        $this->smbCustomConf = ConfigRegistry::getSmbCustomConfPath();
        $this->sharesFile = ConfigRegistry::getSharesFilePath();

        foreach ([$this->smbConf, $this->smbCustomConf] as $f) {
            $dir = dirname($f);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            if (file_exists($f)) {
                unlink($f);
            }
        }
        $extraDir = dirname($this->smbExtraConf);
        if (!is_dir($extraDir)) {
            mkdir($extraDir, 0755, true);
        }
        if (file_exists($this->smbExtraConf)) {
            unlink($this->smbExtraConf);
        }
        $sharesDir = dirname($this->sharesFile);
        if (!is_dir($sharesDir)) {
            mkdir($sharesDir, 0755, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        ChrootTestEnvironment::teardownForce();
    }

    // ---- removeOldSmbExtraInclude(): strips only plugin lines ----

    public function testRemovesOnlyPluginCommentAndOldIncludeLine(): void
    {
        $userContent = "# Unassigned Devices\n"
            . "include = /boot/config/plugins/unassigned.devices/smb-settings.conf\n"
            . "\n"
            . "[SomeUserShare]\n"
            . "    path = /mnt/user/foo\n";
        $pluginLines = "# Custom SMB Shares plugin\n"
            . "include = /boot/config/plugins/custom.smb.shares/smb-custom.conf\n";
        file_put_contents($this->smbExtraConf, $userContent . $pluginLines);

        removeOldSmbExtraInclude();

        $result = file_get_contents($this->smbExtraConf);
        // Plugin's own lines are gone.
        $this->assertStringNotContainsString('# Custom SMB Shares plugin', $result);
        $this->assertStringNotContainsString(
            'include = /boot/config/plugins/custom.smb.shares/smb-custom.conf',
            $result
        );
        // Every user/UD line is preserved verbatim.
        $this->assertStringContainsString('# Unassigned Devices', $result);
        $this->assertStringContainsString(
            'include = /boot/config/plugins/unassigned.devices/smb-settings.conf',
            $result
        );
        $this->assertStringContainsString('[SomeUserShare]', $result);
        $this->assertStringContainsString('path = /mnt/user/foo', $result);
    }

    public function testPreservesUserContentByteForByteWhenNoPluginLines(): void
    {
        // smb-extra.conf that has NO plugin lines must be left untouched.
        $userContent = "# Unassigned Devices\n"
            . "include = /boot/config/plugins/unassigned.devices/smb-settings.conf\n";
        file_put_contents($this->smbExtraConf, $userContent);

        removeOldSmbExtraInclude();

        $this->assertSame($userContent, file_get_contents($this->smbExtraConf));
    }

    public function testNoOpWhenSmbExtraConfMissing(): void
    {
        // No smb-extra.conf on disk: removeOldSmbExtraInclude must not create it.
        $this->assertFileDoesNotExist($this->smbExtraConf);
        removeOldSmbExtraInclude();
        $this->assertFileDoesNotExist($this->smbExtraConf);
    }

    public function testKeepsUnrelatedIncludeLines(): void
    {
        // An include line that does NOT reference the plugin's flash path must
        // survive (only the plugin's own flash include is stripped).
        $content = "include = /etc/samba/some-other.conf\n"
            . "# Custom SMB Shares plugin\n"
            . "include = /boot/config/plugins/custom.smb.shares/smb-custom.conf\n";
        file_put_contents($this->smbExtraConf, $content);

        removeOldSmbExtraInclude();

        $result = file_get_contents($this->smbExtraConf);
        $this->assertStringContainsString('include = /etc/samba/some-other.conf', $result);
        $this->assertStringNotContainsString('# Custom SMB Shares plugin', $result);
        $this->assertStringNotContainsString('/plugins/custom.smb.shares/smb-custom.conf', $result);
    }

    // ---- migrateSambaRuntime(): full migration ----

    public function testMigrateProducesRamConfAndInjectsInclude(): void
    {
        // Seed shares.json (incl. a grandfathered UD-path share) and an old
        // smb-extra.conf with the plugin's legacy include.
        $shares = [
            ['name' => 'UserShare', 'path' => '/mnt/user/data', 'security' => 'public'],
            ['name' => 'LegacyUd', 'path' => '/mnt/disks/foo', 'security' => 'public'],
        ];
        file_put_contents($this->sharesFile, json_encode($shares, JSON_PRETTY_PRINT));

        $extra = "# Custom SMB Shares plugin\n"
            . "include = /boot/config/plugins/custom.smb.shares/smb-custom.conf\n";
        file_put_contents($this->smbExtraConf, $extra);

        // Start from an smb.conf that already has Unraid global content.
        file_put_contents($this->smbConf, "[global]\n    workgroup = WORKGROUP\n");

        migrateSambaRuntime();

        // 1. RAM custom conf is produced with the enabled shares.
        $this->assertFileExists($this->smbCustomConf);
        $customContent = file_get_contents($this->smbCustomConf);
        $this->assertStringContainsString('[UserShare]', $customContent);
        $this->assertStringContainsString('[LegacyUd]', $customContent);

        // 2. smb.conf now has the hook include pointing at the RAM conf.
        $smbConfContent = file_get_contents($this->smbConf);
        $this->assertStringContainsString(HOOK_BEGIN, $smbConfContent);
        $this->assertStringContainsString('include = ' . $this->smbCustomConf, $smbConfContent);

        // 3. Legacy flash include removed from smb-extra.conf.
        $extraContent = file_get_contents($this->smbExtraConf);
        $this->assertStringNotContainsString(
            'include = /boot/config/plugins/custom.smb.shares/smb-custom.conf',
            $extraContent
        );
    }

    public function testMigrateLeavesSharesJsonUnchanged(): void
    {
        // shares.json (including a UD-path share) must be byte-for-byte identical
        // after migration -- migration never mutates share definitions.
        $shares = [
            ['name' => 'UserShare', 'path' => '/mnt/user/data', 'security' => 'public'],
            ['name' => 'LegacyUd', 'path' => '/mnt/disks/foo', 'security' => 'public'],
        ];
        $json = json_encode($shares, JSON_PRETTY_PRINT);
        file_put_contents($this->sharesFile, $json);
        $before = file_get_contents($this->sharesFile);

        file_put_contents($this->smbConf, "[global]\n    workgroup = WORKGROUP\n");

        migrateSambaRuntime();

        $after = file_get_contents($this->sharesFile);
        $this->assertSame($before, $after, 'migrateSambaRuntime must not modify shares.json');
        // The UD-path share definition is still present verbatim.
        $decoded = json_decode($after, true);
        $paths = array_column($decoded, 'path');
        $this->assertContains('/mnt/disks/foo', $paths);
        $this->assertContains('/mnt/user/data', $paths);
    }
}
