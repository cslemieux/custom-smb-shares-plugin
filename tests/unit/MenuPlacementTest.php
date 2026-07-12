<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Tests for menu-placement logic (issue #21).
 * Exercises the REAL isTopbarEnabled() helper via its $configBase override.
 */
class MenuPlacementTest extends TestCase
{
    private string $tempDir;
    private string $configFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/menu-placement-test-' . uniqid();
        mkdir($this->tempDir . '/plugins/custom.smb.shares', 0755, true);
        $this->configFile = $this->tempDir . '/plugins/custom.smb.shares/settings.cfg';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    /** REQ-MENU-06: defaults to top bar when no config exists. */
    public function testTopbarDefaultsToEnabledWhenNoConfig(): void
    {
        $this->assertFileDoesNotExist($this->configFile);
        $this->assertTrue(isTopbarEnabled($this->tempDir));
    }

    /** REQ-MENU-01: explicit enabled -> top bar. */
    public function testTopbarEnabledWhenExplicitlyEnabled(): void
    {
        file_put_contents($this->configFile, "TOPBAR=enabled\n");
        $this->assertTrue(isTopbarEnabled($this->tempDir));
    }

    /** REQ-MENU-02: explicit disabled -> not in top bar. */
    public function testTopbarDisabledWhenExplicitlyDisabled(): void
    {
        file_put_contents($this->configFile, "TOPBAR=disabled\n");
        $this->assertFalse(isTopbarEnabled($this->tempDir));
    }

    public function testTopbarDisabledWithQuotedValue(): void
    {
        file_put_contents($this->configFile, 'TOPBAR="disabled"' . "\n");
        $this->assertFalse(isTopbarEnabled($this->tempDir));
    }

    public function testTopbarEnabledWithQuotedValue(): void
    {
        file_put_contents($this->configFile, 'TOPBAR="enabled"' . "\n");
        $this->assertTrue(isTopbarEnabled($this->tempDir));
    }

    /** Fail-safe: any value other than 'disabled' keeps the top bar. */
    public function testTopbarDefaultsToEnabledForUnknownValue(): void
    {
        file_put_contents($this->configFile, "TOPBAR=garbage\n");
        $this->assertTrue(isTopbarEnabled($this->tempDir));
    }

    /** REQ-MENU-06: upgrade scenario - cfg exists but lacks TOPBAR. */
    public function testTopbarDefaultsToEnabledWhenKeyAbsent(): void
    {
        file_put_contents($this->configFile, "SERVICE=enabled\nBACKUP_COUNT=10\n");
        $this->assertTrue(isTopbarEnabled($this->tempDir));
    }

    /** TOPBAR is independent of SERVICE (plugin can be disabled but still top-bar). */
    public function testTopbarIndependentOfService(): void
    {
        file_put_contents($this->configFile, "SERVICE=disabled\nTOPBAR=enabled\n");
        $this->assertTrue(isTopbarEnabled($this->tempDir));
    }
}
