<?php

require_once __DIR__ . '/TestModeDetector.php';
require_once __DIR__ . '/ConfigRegistry.php';

// Define CONFIG_BASE constant for backward compatibility
// New code should use ConfigRegistry::getConfigBase() instead
if (!defined('CONFIG_BASE')) {
    define('CONFIG_BASE', '/boot/config');
}

/**
 * Check if the plugin is enabled
 * @param string|null $configBase Optional config base path (for testing)
 * @return bool True if enabled, false if disabled
 */
function isPluginEnabled(?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $configFile = $base . '/plugins/custom.smb.shares/settings.cfg';
    if (!file_exists($configFile)) {
        return true; // Default to enabled
    }
    $settings = parse_ini_file($configFile);
    return ($settings['SERVICE'] ?? 'enabled') === 'enabled';
}

function logError(string $message): void
{
    syslog(LOG_ERR, "custom.smb.shares: $message");
}

function logInfo(string $message): void
{
    syslog(LOG_INFO, "custom.smb.shares: $message");
}

// CSRF validation is handled globally by Unraid in local_prepend.php
// All POST requests are automatically validated before reaching plugin code
// No need to validate csrf_token in plugin code

/**
 * Extract harness root directory from CONFIG_BASE in test mode
 * @deprecated Use TestModeDetector::getHarnessRoot() instead
 * @return string Harness root path or empty string if not in test mode
 */
function getHarnessRoot(): string
{
    return TestModeDetector::getHarnessRoot();
}

/**
 * @param array<string, mixed> $data
 * @return array<int, string>
 */
function validateShare(array $data): array
{
    $errors = [];

    if (empty($data['name']) || !preg_match('/^[a-zA-Z0-9_-]+$/', $data['name'])) {
        $errors[] = 'Invalid share name. Use only letters, numbers, hyphens, and underscores.';
    }

    $pathPattern = TestModeDetector::getPathPattern();

    if (empty($data['path']) || !preg_match($pathPattern, $data['path'])) {
        $errors[] = 'Path must start with /mnt/';
    } else {
        // Resolve path (prepends harness root in test mode if needed)
        $checkPath = TestModeDetector::resolvePath($data['path']);

        // Canonicalize path to prevent symlink attacks
        $realPath = realpath($checkPath);
        if ($realPath === false) {
            $errors[] = 'Path does not exist: ' . $data['path'];
        } else {
            if (!TestModeDetector::isValidMntPath($realPath)) {
                $errors[] = 'Invalid path: must be under /mnt/';
            } elseif (!is_dir($realPath)) {
                $errors[] = 'Path is not a directory: ' . $data['path'];
            } elseif (!is_writable($realPath)) {
                $errors[] = 'Path is not writable: ' . $data['path'];
            }
        }
    }

    if (
        isset($data['create_mask']) &&
        !empty($data['create_mask']) &&
        !preg_match('/^[0-7]{4}$/', $data['create_mask'])
    ) {
        $errors[] = 'Invalid create mask. Must be 4 octal digits (0-7).';
    }

    if (
        isset($data['directory_mask']) &&
        !empty($data['directory_mask']) &&
        !preg_match('/^[0-7]{4}$/', $data['directory_mask'])
    ) {
        $errors[] = 'Invalid directory mask. Must be 4 octal digits (0-7).';
    }

    return $errors;
}

/**
 * Sanitize share data from POST request
 *
 * Trims whitespace and removes empty string values.
 * Does NOT escape HTML - output escaping is done in templates.
 * Does NOT validate - use validateShare() for validation.
 *
 * @param array<string, mixed> $data Raw POST data
 * @return array<string, mixed> Sanitized data with empty strings removed
 */
function sanitizeShareData(array $data): array
{
    return array_filter(
        array_map(fn($v) => is_string($v) ? trim($v) : $v, $data),
        fn($v) => $v !== ''
    );
}

/**
 * @param string|null $configBase
 * @return array<int, array<string, mixed>>
 */
function loadShares(?string $configBase = null): array
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.smb.shares/shares.json';
    if (!file_exists($file)) {
        return [];
    }
    $content = file_get_contents($file);
    if ($content === false) {
        return [];
    }
    $data = json_decode($content, true);
    return is_array($data) ? $data : [];
}

/**
 * @param array<int, array<string, mixed>> $shares
 * @param string|null $configBase
 * @return int|false
 */
function saveShares(array $shares, ?string $configBase = null)
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.smb.shares/shares.json';
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return file_put_contents($file, json_encode($shares, JSON_PRETTY_PRINT));
}

/**
 * Create a timestamped backup of shares.json
 * @param string|null $configBase
 * @return string|false Path to backup file, or false on failure
 */
function backupShares(?string $configBase = null)
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.smb.shares/shares.json';
    
    if (!file_exists($file)) {
        return false;
    }
    
    $backupDir = $base . '/plugins/custom.smb.shares/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d_H-i-s');
    $backupFile = $backupDir . '/shares_' . $timestamp . '.json';
    
    if (copy($file, $backupFile)) {
        // Keep only configured number of backups
        pruneBackups($backupDir, getBackupCount($configBase));
        return $backupFile;
    }
    return false;
}

/**
 * Remove old backups, keeping only the most recent $keep files
 * @param string $backupDir
 * @param int $keep
 */
function pruneBackups(string $backupDir, int $keep): void
{
    $files = glob($backupDir . '/shares_*.json');
    if ($files === false || count($files) <= $keep) {
        return;
    }
    
    // Sort by modification time, oldest first
    usort($files, function ($a, $b) {
        return filemtime($a) - filemtime($b);
    });
    
    // Remove oldest files
    $toRemove = count($files) - $keep;
    for ($i = 0; $i < $toRemove; $i++) {
        unlink($files[$i]);
    }
}

/**
 * List all backups with metadata
 * @param string|null $configBase
 * @return array<int, array{filename: string, date: string, size: int, shares: int}>
 */
function listBackups(?string $configBase = null): array
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $backupDir = $base . '/plugins/custom.smb.shares/backups';
    
    if (!is_dir($backupDir)) {
        return [];
    }
    
    $files = glob($backupDir . '/shares_*.json');
    if ($files === false) {
        return [];
    }
    
    $backups = [];
    foreach ($files as $file) {
        $content = file_get_contents($file);
        $shares = $content ? json_decode($content, true) : [];
        $backups[] = [
            'filename' => basename($file),
            'date' => date('Y-m-d H:i:s', filemtime($file)),
            'size' => filesize($file),
            'shares' => is_array($shares) ? count($shares) : 0
        ];
    }
    
    // Sort by date, newest first
    usort($backups, function ($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
    
    return $backups;
}

/**
 * View backup content
 * @param string $filename
 * @param string|null $configBase
 * @return array<int, array<string, mixed>>|false
 */
function viewBackup(string $filename, ?string $configBase = null)
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.smb.shares/backups/' . $filename;
    
    if (!file_exists($file)) {
        return false;
    }
    
    $content = file_get_contents($file);
    if ($content === false) {
        return false;
    }
    
    return json_decode($content, true);
}

/**
 * Restore from backup
 * @param string $filename
 * @param string|null $configBase
 * @return bool
 */
function restoreBackup(string $filename, ?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $backupFile = $base . '/plugins/custom.smb.shares/backups/' . $filename;
    $sharesFile = $base . '/plugins/custom.smb.shares/shares.json';
    
    if (!file_exists($backupFile)) {
        return false;
    }
    
    return copy($backupFile, $sharesFile);
}

/**
 * Delete a backup
 * @param string $filename
 * @param string|null $configBase
 * @return bool
 */
function deleteBackup(string $filename, ?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $file = $base . '/plugins/custom.smb.shares/backups/' . $filename;
    
    if (!file_exists($file)) {
        return false;
    }
    
    return unlink($file);
}

/**
 * Get backup count setting
 * @param string|null $configBase
 * @return int
 */
function getBackupCount(?string $configBase = null): int
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $settingsFile = $base . '/plugins/custom.smb.shares/settings.cfg';
    
    if (!file_exists($settingsFile)) {
        return 10; // default
    }
    
    $settings = parse_ini_file($settingsFile);
    return (int)($settings['BACKUP_COUNT'] ?? 10);
}

/**
 * @param array<int, array<string, mixed>> $shares
 * @param string $name
 * @return int
 */
function findShareIndex(array $shares, string $name): int
{
    foreach ($shares as $index => $share) {
        if ($share['name'] === $name) {
            return $index;
        }
    }
    return -1;
}

/**
 * @param array<int, array<string, mixed>> $shares
 * @return string
 */
function generateSambaConfig(array $shares): string
{
    $config = '';
    foreach ($shares as $share) {
        // Handle export field - skip if not exported
        $export = $share['export'] ?? 'e';
        if ($export === '-') {
            continue; // Don't export this share
        }

        $config .= "[{$share['name']}]\n";
        $config .= "    path = {$share['path']}\n";

        if (!empty($share['comment'])) {
            $config .= "    comment = {$share['comment']}\n";
        }

        // Determine browseable from export setting
        // 'eh' and 'eth' are hidden variants
        $isHidden = in_array($export, ['eh', 'eth'], true);
        $config .= "    browseable = " . ($isHidden ? 'no' : 'yes') . "\n";

        // Case-sensitive names (auto/yes/forced -> auto/yes/lower)
        $caseSensitive = $share['case_sensitive'] ?? 'auto';
        if ($caseSensitive === 'forced') {
            $config .= "    case sensitive = yes\n";
            $config .= "    default case = lower\n";
            $config .= "    preserve case = no\n";
            $config .= "    short preserve case = no\n";
        } elseif ($caseSensitive === 'yes') {
            $config .= "    case sensitive = yes\n";
        }

        // Time Machine support (et, eth)
        $isTimeMachine = in_array($export, ['et', 'eth'], true);
        if ($isTimeMachine) {
            $config .= "    vfs objects = catia fruit streams_xattr\n";
            $config .= "    fruit:time machine = yes\n";
            if (!empty($share['volsizelimit'])) {
                $config .= "    fruit:time machine max size = {$share['volsizelimit']}M\n";
            }
        } elseif (($share['fruit'] ?? 'no') === 'yes') {
            // Enhanced macOS support (fruit VFS) for non-Time Machine shares
            $config .= "    vfs objects = catia fruit streams_xattr\n";
        }

        // Security mode handling
        $security = $share['security'] ?? 'public';
        $userAccess = [];
        if (!empty($share['user_access'])) {
            $userAccess = is_string($share['user_access'])
                ? json_decode($share['user_access'], true) ?? []
                : $share['user_access'];
        }

        if ($security === 'public') {
            // Public: guest ok, everyone can read/write
            $config .= "    guest ok = yes\n";
            $config .= "    read only = no\n";
        } elseif ($security === 'secure') {
            // Secure: guest read, users configurable
            $config .= "    guest ok = yes\n";
            $config .= "    read only = yes\n";

            // Build write list from user_access
            $writeUsers = [];
            foreach ($userAccess as $user => $access) {
                if ($access === 'read-write') {
                    $writeUsers[] = $user;
                }
            }
            if (!empty($writeUsers)) {
                $config .= "    write list = " . implode(' ', $writeUsers) . "\n";
            }
        } elseif ($security === 'private') {
            // Private: no guest, users configurable
            $config .= "    guest ok = no\n";

            // Build valid users, read list, write list
            $validUsers = [];
            $readUsers = [];
            $writeUsers = [];

            foreach ($userAccess as $user => $access) {
                if ($access === 'read-only') {
                    $validUsers[] = $user;
                    $readUsers[] = $user;
                } elseif ($access === 'read-write') {
                    $validUsers[] = $user;
                    $writeUsers[] = $user;
                }
            }

            if (!empty($validUsers)) {
                $config .= "    valid users = " . implode(' ', $validUsers) . "\n";
            }
            if (!empty($writeUsers)) {
                $config .= "    write list = " . implode(' ', $writeUsers) . "\n";
                $config .= "    read only = yes\n";
            } else {
                $config .= "    read only = yes\n";
            }
        }

        // Apply permission settings (use defaults if not specified)
        $forceUser = $share['force_user'] ?? 'nobody';
        $forceGroup = $share['force_group'] ?? 'users';
        $createMask = $share['create_mask'] ?? '0664';
        $directoryMask = $share['directory_mask'] ?? '0775';
        $hideDotFiles = $share['hide_dot_files'] ?? 'yes';

        if (!empty($forceUser)) {
            $config .= "    force user = {$forceUser}\n";
        }
        if (!empty($forceGroup)) {
            $config .= "    force group = {$forceGroup}\n";
        }
        $config .= "    create mask = {$createMask}\n";
        $config .= "    directory mask = {$directoryMask}\n";
        $config .= "    hide dot files = {$hideDotFiles}\n";

        // Host-based access control
        if (!empty($share['hosts_allow'])) {
            $config .= "    hosts allow = {$share['hosts_allow']}\n";
        }
        if (!empty($share['hosts_deny'])) {
            $config .= "    hosts deny = {$share['hosts_deny']}\n";
        }

        $config .= "\n";
    }
    return $config;
}

/**
 * Verify a share exists (or doesn't exist) in Samba config
 * @param string $shareName Share name to check
 * @param bool $shouldExist True to verify exists, false to verify doesn't exist
 * @param string|null $configFilePath Optional config file path (for testing)
 * @param string|null $testparmPath Optional testparm path (for testing)
 * @return bool True if verification passes
 */
function verifySambaShare(
    string $shareName,
    bool $shouldExist = true,
    ?string $configFilePath = null,
    ?string $testparmPath = null
): bool {
    if ($configFilePath === null || $testparmPath === null) {
        $mockPaths = TestModeDetector::getMockScriptPaths();
        $testparm = $testparmPath ?? ($mockPaths !== null ? $mockPaths['testparm'] : 'testparm');
        $configFile = $configFilePath ?? ($mockPaths !== null ? $mockPaths['configFile'] : '/etc/samba/smb.conf');
    } else {
        $testparm = $testparmPath;
        $configFile = $configFilePath;
    }

    // Get list of shares from testparm
    exec("$testparm -s " . escapeshellarg($configFile) . " 2>/dev/null | grep -E '^\\[' | tr -d '[]'", $output, $ret);

    if ($ret !== 0) {
        return false;
    }

    $shareExists = in_array($shareName, $output, true);
    return $shouldExist ? $shareExists : !$shareExists;
}

/**
 * @return array{success: bool, error: string}
 */
function reloadSamba(): array
{
    $mockPaths = TestModeDetector::getMockScriptPaths();

    if ($mockPaths !== null) {
        $testparm = $mockPaths['testparm'];
        $smbcontrol = $mockPaths['smbcontrol'];
        $configFile = $mockPaths['configFile'];

        // Verify mock scripts exist
        if (!file_exists($testparm) || !is_executable($testparm)) {
            return ['success' => false, 'error' => "Mock testparm not found or not executable: $testparm"];
        }
        if (!file_exists($smbcontrol) || !is_executable($smbcontrol)) {
            return ['success' => false, 'error' => "Mock smbcontrol not found or not executable: $smbcontrol"];
        }
    } else {
        $testparm = 'testparm';
        $smbcontrol = 'smbcontrol';
        $configFile = '/etc/samba/smb.conf';
    }

    exec("$testparm -s " . escapeshellarg($configFile) . " 2>&1", $output, $ret);
    if ($ret !== 0) {
        return ['success' => false, 'error' => 'Invalid Samba configuration: ' . implode("\n", $output)];
    }

    exec(escapeshellarg($smbcontrol) . " all reload-config 2>&1", $output, $ret);
    if ($ret !== 0) {
        return ['success' => false, 'error' => implode("\n", $output)];
    }
    return ['success' => true, 'error' => ''];
}

/**
 * Get Samba status (chroot-aware)
 * @return array{running: bool, output: string}
 */
function getSambaStatus(): array
{
    if (TestModeDetector::isTestMode()) {
        $configBase = str_replace('/private/tmp/', '/tmp/', ConfigRegistry::getConfigBase());
        $harnessRoot = dirname(dirname(dirname(dirname($configBase))));
        $rcSamba = $harnessRoot . '/etc/rc.d/rc.samba';

        // Verify mock script exists
        if (!file_exists($rcSamba) || !is_executable($rcSamba)) {
            return ['running' => false, 'output' => "Mock rc.samba not found or not executable: $rcSamba"];
        }
    } else {
        $rcSamba = '/etc/rc.d/rc.samba';
    }

    exec(escapeshellarg($rcSamba) . " status 2>&1", $output, $ret);
    $running = ($ret === 0 && strpos(implode(' ', $output), 'running') !== false);

    return ['running' => $running, 'output' => implode("\n", $output)];
}

/**
 * Ensure our plugin's config is included in smb-extra.conf
 * Adds include directive if not already present
 * @return bool True if include is present (or was added), false on error
 */
function ensureSambaInclude(): bool
{
    $smbExtraConf = ConfigRegistry::getSmbExtraConfPath();
    $pluginConf = ConfigRegistry::getSmbCustomConfPath();
    $includeLine = "include = $pluginConf";

    // In test mode, skip this (mock environment)
    if (TestModeDetector::isTestMode()) {
        return true;
    }

    // Read existing smb-extra.conf
    $content = @file_get_contents($smbExtraConf) ?: '';

    // Check if include already exists
    if (strpos($content, $includeLine) !== false) {
        return true;
    }

    // Append include directive with comment
    $addition = "\n# Custom SMB Shares plugin\n$includeLine\n";

    if (file_put_contents($smbExtraConf, $content . $addition) === false) {
        logError("Failed to add include directive to $smbExtraConf");
        return false;
    }

    logInfo("Added include directive for custom SMB shares to smb-extra.conf");
    return true;
}
