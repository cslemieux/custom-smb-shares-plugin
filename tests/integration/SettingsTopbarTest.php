<?php

declare(strict_types=1);

require_once __DIR__ . '/IntegrationTestBase.php';

/**
 * Integration tests for TOPBAR setting persistence (issue #21, REQ-MENU-04).
 * Uses the REAL loadPluginSettings()/savePluginSettings()/isTopbarEnabled()
 * helpers (the same code path the Settings page uses) against an isolated
 * temp config base.
 */
class SettingsTopbarTest extends IntegrationTestBase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/settings-topbar-test-' . uniqid();
        mkdir($this->tempDir . '/plugins/custom.smb.shares', 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    /** REQ-MENU-06: defaults include TOPBAR=enabled. */
    public function testDefaultsIncludeTopbarEnabled(): void
    {
        $s = loadPluginSettings($this->tempDir);
        $this->assertSame('enabled', $s['TOPBAR']);
        $this->assertSame('enabled', $s['SERVICE']);
        $this->assertSame('10', $s['BACKUP_COUNT']);
    }

    /** REQ-MENU-04.1/04.3: saving TOPBAR=disabled persists and preserves others. */
    public function testSaveTopbarPreservesOtherSettings(): void
    {
        $settings = loadPluginSettings($this->tempDir);
        $settings['SERVICE'] = 'disabled';
        $settings['BACKUP_COUNT'] = '25';
        $settings['TOPBAR'] = 'disabled';
        $this->assertTrue(savePluginSettings($settings, $this->tempDir));

        $reloaded = loadPluginSettings($this->tempDir);
        $this->assertSame('disabled', $reloaded['TOPBAR']);
        $this->assertSame('disabled', $reloaded['SERVICE']);
        $this->assertSame('25', $reloaded['BACKUP_COUNT']);
        $this->assertFalse(isTopbarEnabled($this->tempDir));
    }

    /** REQ-MENU-04.2: toggling back to enabled persists. */
    public function testSaveTopbarEnabledPersists(): void
    {
        $settings = loadPluginSettings($this->tempDir);
        $settings['TOPBAR'] = 'disabled';
        savePluginSettings($settings, $this->tempDir);
        $this->assertFalse(isTopbarEnabled($this->tempDir));

        $settings = loadPluginSettings($this->tempDir);
        $settings['TOPBAR'] = 'enabled';
        savePluginSettings($settings, $this->tempDir);
        $this->assertTrue(isTopbarEnabled($this->tempDir));
    }

    /** REQ-MENU-06 + 04.3: upgrade cfg without TOPBAR keeps existing values when toggled. */
    public function testTogglingTopbarKeepsExistingSettingsOnUpgrade(): void
    {
        file_put_contents(
            $this->tempDir . '/plugins/custom.smb.shares/settings.cfg',
            "SERVICE=\"enabled\"\nBACKUP_COUNT=\"7\"\n"
        );

        // Upgrade: TOPBAR default applied, existing values preserved.
        $settings = loadPluginSettings($this->tempDir);
        $this->assertSame('enabled', $settings['TOPBAR']);
        $this->assertSame('7', $settings['BACKUP_COUNT']);
        $this->assertSame('enabled', $settings['SERVICE']);

        // Disable top bar; existing settings must survive.
        $settings['TOPBAR'] = 'disabled';
        savePluginSettings($settings, $this->tempDir);

        $reloaded = loadPluginSettings($this->tempDir);
        $this->assertSame('disabled', $reloaded['TOPBAR']);
        $this->assertSame('7', $reloaded['BACKUP_COUNT']);
        $this->assertSame('enabled', $reloaded['SERVICE']);
    }
}
