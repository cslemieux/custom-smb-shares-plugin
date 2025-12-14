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
    public const SHARE_NAME_PATTERN = '/^[a-zA-Z0-9_-]+$/';
    public const OCTAL_MASK_PATTERN = '/^[0-7]{4}$/';
    public const PATH_PREFIX = '/mnt/';
    public const BACKUP_FILENAME_PATTERN = '/^shares_[\d_-]+\.json$/';

    /**
     * Override config base path (used in tests)
     */
    private static ?string $configBase = null;

    /**
     * Get the configuration base path
     *
     * Priority:
     * 1. Explicitly set value (for tests)
     * 2. CONFIG_BASE constant (if defined)
     * 3. Default '/boot/config' (production fallback)
     *
     * @return string The configuration base path
     */
    public static function getConfigBase(): string
    {
        // Test override takes precedence
        if (self::$configBase !== null) {
            return self::$configBase;
        }

        // Fall back to constant (production)
        if (defined('CONFIG_BASE')) {
            return CONFIG_BASE;
        }

        // Ultimate fallback
        return '/boot/config';
    }

    /**
     * Set the configuration base path
     *
     * This is primarily used in tests to set up isolated environments.
     *
     * @param string $path The config base path to use
     */
    public static function setConfigBase(string $path): void
    {
        self::$configBase = $path;
    }

    /**
     * Reset the configuration to use default (constant or fallback)
     *
     * Call this in test tearDown to ensure clean state for next test.
     */
    public static function reset(): void
    {
        self::$configBase = null;
    }

    /**
     * Check if a custom config base is set
     *
     * @return bool True if setConfigBase() was called and not reset
     */
    public static function isOverridden(): bool
    {
        return self::$configBase !== null;
    }

    /**
     * Get the plugin config directory path
     *
     * @return string Path to plugins/custom.smb.shares/
     */
    public static function getPluginConfigDir(): string
    {
        return self::getConfigBase() . '/plugins/custom.smb.shares';
    }

    /**
     * Get the shares.json file path
     *
     * @return string Path to shares.json
     */
    public static function getSharesFilePath(): string
    {
        return self::getPluginConfigDir() . '/shares.json';
    }

    /**
     * Get the smb-extra.conf file path
     *
     * @return string Path to smb-extra.conf
     */
    public static function getSmbExtraConfPath(): string
    {
        return self::getConfigBase() . '/smb-extra.conf';
    }

    /**
     * Get the plugin's smb-custom.conf file path
     *
     * @return string Path to smb-custom.conf
     */
    public static function getSmbCustomConfPath(): string
    {
        return self::getPluginConfigDir() . '/smb-custom.conf';
    }
}
