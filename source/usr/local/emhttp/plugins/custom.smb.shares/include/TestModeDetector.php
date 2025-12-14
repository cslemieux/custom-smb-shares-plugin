<?php

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace
// Unraid plugins don't use namespaces - they're loaded directly by the WebGUI

/**
 * Centralized test mode detection for the Custom SMB Shares plugin.
 *
 * Test mode is active when:
 * - PHPUNIT_TEST constant is defined, OR
 * - CONFIG_BASE contains /tmp/ or /private/tmp/ (macOS)
 *
 * This class eliminates scattered test mode detection throughout lib.php.
 */
class TestModeDetector
{
    private static ?bool $isTestMode = null;
    private static ?string $harnessRoot = null;

    /**
     * Check if running in test mode
     */
    public static function isTestMode(): bool
    {
        if (self::$isTestMode !== null) {
            return self::$isTestMode;
        }

        // Check PHPUNIT_TEST constant
        if (defined('PHPUNIT_TEST')) {
            self::$isTestMode = true;
            return true;
        }

        // Check CONFIG_BASE for temp directory patterns
        if (defined('CONFIG_BASE')) {
            $configBase = CONFIG_BASE;
            if (strpos($configBase, '/tmp/') !== false || strpos($configBase, '/private/tmp/') !== false) {
                self::$isTestMode = true;
                return true;
            }
        }

        self::$isTestMode = false;
        return false;
    }

    /**
     * Get the harness root directory in test mode
     *
     * @return string Harness root path or empty string if not in test mode
     */
    public static function getHarnessRoot(): string
    {
        if (self::$harnessRoot !== null) {
            return self::$harnessRoot;
        }

        if (!self::isTestMode() || !defined('CONFIG_BASE')) {
            self::$harnessRoot = '';
            return '';
        }

        // CONFIG_BASE: /tmp/xxx/usr/local/boot/config or /private/tmp/xxx/usr/local/boot/config
        // Go up 4 levels: config -> boot -> local -> usr -> harness root
        self::$harnessRoot = dirname(dirname(dirname(dirname(CONFIG_BASE))));
        return self::$harnessRoot;
    }

    /**
     * Get the path pattern for validation based on test mode
     *
     * In test mode: /mnt/ can appear anywhere in path
     * In production: path must start with /mnt/
     */
    public static function getPathPattern(): string
    {
        return self::isTestMode() ? '#/mnt/#' : '#^/mnt/#';
    }

    /**
     * Resolve a path for validation, prepending harness root in test mode
     *
     * @param string $path The path to resolve
     * @return string The resolved path
     */
    public static function resolvePath(string $path): string
    {
        if (!self::isTestMode()) {
            return $path;
        }

        // If path already starts with /tmp/, don't prepend harness root
        if (strpos($path, '/tmp/') === 0 || strpos($path, '/private/tmp/') === 0) {
            return $path;
        }

        $harnessRoot = self::getHarnessRoot();
        return $harnessRoot ? $harnessRoot . $path : $path;
    }

    /**
     * Validate that a canonicalized path is under /mnt/
     *
     * @param string $realPath The canonicalized (realpath) path
     * @return bool True if path is valid
     */
    public static function isValidMntPath(string $realPath): bool
    {
        if (self::isTestMode()) {
            // In test mode, /mnt/ can appear anywhere
            return strpos($realPath, '/mnt/') !== false;
        }
        // In production, must start with /mnt/
        return strpos($realPath, '/mnt/') === 0;
    }

    /**
     * Strip harness root prefix from a path in test mode
     *
     * Used to convert a resolved realpath back to a storage-friendly path.
     * In production, returns the path unchanged.
     *
     * @param string $path The path to strip
     * @return string The path with harness root removed (if applicable)
     */
    public static function stripHarnessRoot(string $path): string
    {
        if (!self::isTestMode()) {
            return $path;
        }

        $harnessRoot = self::getHarnessRoot();
        if ($harnessRoot && strpos($path, $harnessRoot) === 0) {
            return substr($path, strlen($harnessRoot));
        }

        return $path;
    }

    /**
     * Get mock script paths for Samba commands in test mode
     *
     * @return array{testparm: string, smbcontrol: string, configFile: string}|null
     *         Returns null if not in test mode
     */
    public static function getMockScriptPaths(): ?array
    {
        if (!self::isTestMode() || !defined('CONFIG_BASE')) {
            return null;
        }

        // Normalize macOS /private/tmp/ to /tmp/
        $configBase = str_replace('/private/tmp/', '/tmp/', CONFIG_BASE);
        $harnessRoot = dirname(dirname(dirname(dirname($configBase))));

        return [
            'testparm' => $harnessRoot . '/usr/bin/testparm',
            'smbcontrol' => $harnessRoot . '/usr/bin/smbcontrol',
            'configFile' => $configBase . '/plugins/custom.smb.shares/smb-extra.conf',
        ];
    }

    /**
     * Reset cached values (useful for testing)
     */
    public static function reset(): void
    {
        self::$isTestMode = null;
        self::$harnessRoot = null;
    }
}
