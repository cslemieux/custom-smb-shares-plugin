<?php

declare(strict_types=1);

/**
 * Configuration Registry
 *
 * Provides a testable way to manage configuration paths.
 *
 * In production, this falls back to the CONFIG_BASE constant.
 * In tests, the config base can be set/reset for proper isolation.
 *
 * Why this exists:
 * - PHP constants cannot be redefined once set
 * - PHPUnit runs all tests in a single process
 * - Each test needs its own isolated config directory
 * - This registry allows tests to override the config path
 *
 * Usage in production:
 *   $path = ConfigRegistry::getConfigBase(); // Returns CONFIG_BASE or '/boot/config'
 *
 * Usage in tests:
 *   ConfigRegistry::setConfigBase($testChrootPath);
 *   // ... run test ...
 *   ConfigRegistry::reset(); // Clean up for next test
 */
class ConfigRegistry
{
    // Validation patterns
    public const SHARE_NAME_PATTERN = '/^\s+|\s+$|[\x00-\x1F\x7F\[\]"\/\\\\:;|<>,\?\*=]/u';
    public const OCTAL_MASK_PATTERN = '/^[0-7]{4}$/';
    public const PATH_PREFIX = '/mnt/';
    public const BACKUP_FILENAME_PATTERN = '/^shares_[\d_-]+\.json$/';

    // Samba runtime config paths (absolute, production)
    public const SMB_CUSTOM_CONF = '/etc/samba/smb-custom.conf';
    public const SMB_CONF = '/etc/samba/smb.conf';

    /**
     * Override config base path (used in tests)
     */
    private static ?string $configBase = null;

    public static function getConfigBase(): string
    {
        if (self::$configBase !== null) {
            return self::$configBase;
        }
        if (defined('CONFIG_BASE')) {
            return CONFIG_BASE;
        }
        return '/boot/config';
    }

    public static function setConfigBase(string $path): void
    {
        self::$configBase = $path;
    }

    public static function reset(): void
    {
        self::$configBase = null;
    }

    public static function isOverridden(): bool
    {
        return self::$configBase !== null;
    }

    public static function getPluginConfigDir(): string
    {
        return self::getConfigBase() . '/plugins/custom.smb.shares';
    }

    public static function getSharesFilePath(): string
    {
        return self::getPluginConfigDir() . '/shares.json';
    }

    /**
     * Get the smb-extra.conf file path
     *
     * Used by migration to locate and remove the old include directive.
     * Points to the flash-based extra conf, not the runtime RAM path.
     *
     * @return string Path to smb-extra.conf
     */
    public static function getSmbExtraConfPath(): string
    {
        return self::getConfigBase() . '/smb-extra.conf';
    }

    /**
     * Get the plugin's smb-custom.conf file path (RAM / runtime location)
     *
     * Production: /etc/samba/smb-custom.conf
     * Test mode:  <harnessRoot>/etc/samba/smb-custom.conf
     *
     * @return string Path to smb-custom.conf
     */
    public static function getSmbCustomConfPath(): string
    {
        return self::withHarnessPrefix(self::SMB_CUSTOM_CONF);
    }

    /**
     * Get the main smb.conf file path (RAM / runtime location)
     *
     * Production: /etc/samba/smb.conf
     * Test mode:  <harnessRoot>/etc/samba/smb.conf
     *
     * @return string Path to smb.conf
     */
    public static function getSmbConfPath(): string
    {
        return self::withHarnessPrefix(self::SMB_CONF);
    }

    /**
     * Prepend the harness root to an absolute path when running in test mode.
     *
     * This is a simple prefix-only helper intentionally distinct from
     * TestModeDetector::resolvePath(), which has /tmp/ special-casing logic
     * designed for share paths under /mnt/. Samba config paths like
     * /etc/samba/smb.conf must always be prefixed in test mode without any
     * special-casing -- hence the dedicated helper here.
     *
     * In production (not test mode) the path is returned unchanged.
     *
     * @param string $absolute An absolute path (must begin with /)
     * @return string The path, prefixed with harnessRoot in test mode
     */
    private static function withHarnessPrefix(string $absolute): string
    {
        if (!TestModeDetector::isTestMode()) {
            return $absolute;
        }
        $harnessRoot = TestModeDetector::getHarnessRoot();
        return $harnessRoot !== '' ? $harnessRoot . $absolute : $absolute;
    }
}
