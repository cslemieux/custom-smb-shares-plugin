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
/**
 * Samba include-hook markers (REQ-INC). Unique to this plugin so they never
 * collide with Unassigned Devices' own include directive in smb.conf.
 */
const HOOK_BEGIN = '# hook for custom smb shares';
const HOOK_END = '# end hook for custom smb shares';

/**
 * Unassigned Devices managed mount roots (REQ-UD). UD already exports SMB for
 * shares under these paths, so the plugin refuses to create a NEW share there.
 */
const UD_EXACT = ['/mnt/disks', '/mnt/remotes'];
const UD_PREFIXES = ['/mnt/disks/', '/mnt/remotes/'];

/**
 * Whether a canonical logical path is managed by Unassigned Devices. REQ-UD-01.
 * True when the path is exactly /mnt/disks or /mnt/remotes, or lives under
 * either of them.
 * @param string $logicalPath Canonical (harness-stripped) logical path
 * @return bool True if the path is UD-managed
 */
function isUdManagedPath(string $logicalPath): bool
{
    $p = rtrim($logicalPath, '/');
    if ($p === '') {
        return false;
    }
    if (in_array($p, UD_EXACT, true)) {
        return true;
    }
    foreach (UD_PREFIXES as $prefix) {
        if (strpos($logicalPath, $prefix) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Whether an existing share already uses the given path. REQ-UD-02 grandfather:
 * an already-configured UD-path share must remain editable, so validateShare()
 * only rejects UD paths that are NOT already present in the current config.
 * @param string $path Canonical logical path to look for
 * @param array<int, array<string, mixed>> $existingShares Current shares
 * @return bool True if any existing share has this exact path
 */
function shareExistsWithPath(string $path, array $existingShares): bool
{
    foreach ($existingShares as $share) {
        if (isset($share['path']) && $share['path'] === $path) {
            return true;
        }
    }
    return false;
}
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

/**
 * Check whether the SMB Shares entry should appear in the top navigation bar.
 * Defaults to true (top bar) so existing installs are not disrupted; only an
 * explicit TOPBAR="disabled" moves the entry to Settings -> User Utilities.
 * NOTE: the .page Cond headers inline this same logic because they run inside
 * the WebGUI menu builder (PageBuilder.php) where lib.php is not loaded; keep
 * this helper and those expressions in sync.
 * @param string|null $configBase Optional config base path (for testing)
 * @return bool True if the top-bar tab should show, false if it is disabled
 */
function isTopbarEnabled(?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $configFile = $base . '/plugins/custom.smb.shares/settings.cfg';
    if (!file_exists($configFile)) {
        return true; // Default to top bar
    }
    $settings = parse_ini_file($configFile);
    return ($settings['TOPBAR'] ?? 'enabled') !== 'disabled';
}



/**
 * Canonical plugin settings with defaults, merged over settings.cfg.
 * Centralizes the defaults so the Settings page and tests agree.
 * @param string|null $configBase Optional config base path (for testing)
 * @return array<string,string> Settings with defaults applied
 */
function loadPluginSettings(?string $configBase = null): array
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $configFile = $base . '/plugins/custom.smb.shares/settings.cfg';
    $settings = [
        'SERVICE'      => 'enabled',
        'BACKUP_COUNT' => '10',
        'TOPBAR'       => 'enabled',
        'ALLOW_EXTERNAL_PATHS' => 'disabled',
    ];
    if (file_exists($configFile)) {
        $loaded = parse_ini_file($configFile);
        if ($loaded) {
            $settings = array_merge($settings, $loaded);
        }
    }
    return $settings;
}

/**
 * Persist plugin settings to settings.cfg as INI key="value" lines.
 * Writes every key present in $settings, so unrelated keys are preserved
 * by the caller passing the full merged settings array.
 * @param array<string,string> $settings Settings to persist
 * @param string|null $configBase Optional config base path (for testing)
 * @return bool True on success
 */
function savePluginSettings(array $settings, ?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $dir = $base . '/plugins/custom.smb.shares';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $configFile = $dir . '/settings.cfg';
    $content = '';
    foreach ($settings as $key => $value) {
        $content .= "$key=\"$value\"\n";
    }
    return file_put_contents($configFile, $content) !== false;
}

/**
 * Whether share paths outside /mnt are allowed (opt-in, default false). Issue #22.
 * Reads ALLOW_EXTERNAL_PATHS from settings.cfg; only an explicit "enabled" turns it on.
 * @param string|null $configBase Optional config base path (for testing)
 * @return bool True if external paths are permitted
 */
function isExternalPathsAllowed(?string $configBase = null): bool
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $configFile = $base . '/plugins/custom.smb.shares/settings.cfg';
    if (!file_exists($configFile)) {
        return false; // Default: only /mnt allowed
    }
    $settings = parse_ini_file($configFile);
    return ($settings['ALLOW_EXTERNAL_PATHS'] ?? 'disabled') === 'enabled';
}

/**
 * Whether a canonical logical path is a denied system directory. Issue #22.
 * Enforced even when external paths are allowed. MUST be called with the
 * canonicalized (realpath, harness-stripped) path so symlink escapes into
 * system directories are caught.
 * @param string $path Canonical logical path
 * @return bool True if the path is the root or a system directory (or subpath)
 */
function isDeniedSystemPath(string $path): bool
{
    $p = rtrim($path, '/');
    if ($p === '') {
        return true; // root '/'
    }
    $denied = ['/boot', '/etc', '/proc', '/sys', '/dev', '/var', '/usr', '/root', '/bin', '/sbin', '/lib', '/lib64'];
    foreach ($denied as $d) {
        if ($p === $d || strpos($p, $d . '/') === 0) {
            return true;
        }
    }
    return false;
}

function logError(string $message): void
{
    syslog(LOG_ERR, "custom.smb.shares: $message");
}

function logInfo(string $message): void
{
    syslog(LOG_INFO, "custom.smb.shares: $message");
}

/**
 * Sanitize a string for safe inclusion in Samba config.
 * Removes newlines and carriage returns to prevent config injection.
 * @param string $value The value to sanitize
 * @return string Sanitized value with newlines stripped
 */
function sanitizeForSambaConfig(string $value): string
{
    return str_replace(["\r", "\n"], '', $value);
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
 * @param array<string, mixed> $data Share data (modified in place - path is replaced with realpath)
 * @param array<int, array<string, mixed>>|null $existingShares Existing shares used for the
 *        Unassigned Devices grandfather check. Defaults to loadShares() when null so existing
 *        call sites continue to work unchanged.
 * @return array<int, string> Array of validation error messages
 */
function validateShare(array &$data, ?array $existingShares = null): array
{
    $errors = [];

    if (empty($data['name']) || preg_match(ConfigRegistry::SHARE_NAME_PATTERN, $data['name'])) {
        $errors[] = 'Invalid share name. Shares cannot contain [ ] " / \\ : ; | < > , ? * = characters.';
    }

    $pathPattern = TestModeDetector::getPathPattern();
    $allowExternal = isExternalPathsAllowed();

    if (empty($data['path'])) {
        $errors[] = 'Path must start with /mnt/';
    } elseif (!$allowExternal && !preg_match($pathPattern, $data['path'])) {
        $errors[] = 'Path must start with /mnt/';
    } elseif ($allowExternal && strpos($data['path'], '/') !== 0) {
        $errors[] = 'Path must be an absolute path (starting with /)';
    } else {
        // Resolve path (prepends harness root in test mode if needed)
        $checkPath = TestModeDetector::resolvePath($data['path']);

        // Canonicalize path to prevent symlink attacks (TOCTOU fix)
        // We store the resolved path to ensure the path used at runtime
        // is the same path that was validated
        $realPath = realpath($checkPath);
        if ($realPath === false) {
            $errors[] = 'Path does not exist: ' . $data['path'];
        } else {
            // Logical (harness-stripped) canonical path: used for the system
            // denylist and for storage. Checking the canonical path means a
            // symlink under /mnt that points at a system dir is still rejected.
            $logicalPath = TestModeDetector::stripHarnessRoot($realPath);
            $isMnt = TestModeDetector::isValidMntPath($realPath);

            if (!$allowExternal && !$isMnt) {
                $errors[] = 'Invalid path: must be under /mnt/';
            } elseif ($allowExternal && !$isMnt && isDeniedSystemPath($logicalPath)) {
                $errors[] = 'Invalid path: system directories cannot be shared (e.g. /boot, /etc, /var)';
            } elseif (!is_dir($realPath)) {
                $errors[] = 'Path is not a directory: ' . $data['path'];
            } elseif (!is_writable($realPath)) {
                $errors[] = 'Path is not writable: ' . $data['path'];
            } else {
                // Store the resolved path to prevent TOCTOU attacks
                // In test mode, strip the harness root prefix for storage
                $data['path'] = $logicalPath;

                // Unassigned Devices path policy (REQ-UD-01/02/04). Applied AFTER
                // canonicalization so $data['path'] is the logical path. This check is
                // INDEPENDENT of ALLOW_EXTERNAL_PATHS and introduces no new config key:
                // UD (/mnt/disks, /mnt/remotes) already exports SMB for these mounts, so
                // a NEW share on a UD-managed path would collide. Existing shares on such
                // paths are grandfathered (edits keep working) via shareExistsWithPath().
                $existing = $existingShares ?? loadShares();
                if (isUdManagedPath($data['path']) && !shareExistsWithPath($data['path'], $existing)) {
                    $errors[] = 'This path is managed by Unassigned Devices (/mnt/disks, /mnt/remotes), '
                        . 'which already provides SMB for it. Use a path under /mnt/user instead.';
                }
            }
        }
    }

    if (
        isset($data['create_mask']) &&
        !empty($data['create_mask']) &&
        !preg_match(ConfigRegistry::OCTAL_MASK_PATTERN, $data['create_mask'])
    ) {
        $errors[] = 'Invalid create mask. Must be 4 octal digits (0-7).';
    }

    if (
        isset($data['directory_mask']) &&
        !empty($data['directory_mask']) &&
        !preg_match(ConfigRegistry::OCTAL_MASK_PATTERN, $data['directory_mask'])
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
 * Normalize a single share entry: trim whitespace from string fields and
 * strip control characters from the name. This protects against stray
 * whitespace/newlines/control chars in shares.json that would otherwise
 * break inline JS string contexts (delete/clone actions).
 *
 * @param array<string, mixed> $share
 * @return array<string, mixed>
 */
function normalizeShare(array $share): array
{
    if (isset($share['name']) && is_string($share['name'])) {
        // Strip control chars (0x00-0x1F, 0x7F) and trim surrounding whitespace
        $share['name'] = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $share['name']) ?? '');
    }
    foreach (['path', 'comment', 'valid_users', 'invalid_users', 'hosts_allow', 'hosts_deny'] as $field) {
        if (isset($share[$field]) && is_string($share[$field])) {
            $share[$field] = trim($share[$field]);
        }
    }
    return $share;
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
    if (!is_array($data)) {
        return [];
    }
    // Normalize on load so existing dirty data on disk gets cleaned up
    // the first time it's read after upgrading to v2026.05.18.
    return array_map('normalizeShare', $data);
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
    // Normalize before persisting so dirty data never reaches disk.
    $shares = array_map('normalizeShare', $shares);
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
        $mtime = filemtime($file);
        $size = filesize($file);
        $backups[] = [
            'filename' => basename($file),
            'date' => date('Y-m-d H:i:s', $mtime !== false ? $mtime : 0),
            'size' => $size !== false ? $size : 0,
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
 * Restore shares from a backup file.
 *
 * Validates that the backup file exists and contains a valid JSON shares
 * array BEFORE overwriting the live shares.json — so a corrupt backup
 * cannot destroy the user's current configuration.
 *
 * Does NOT create an auto-snapshot of the current state. The forum bug
 * "deletes 1 share at the bottom of the screen on each click" was the
 * user watching backup-retention pruning during repeated failed restore
 * attempts (each click created a snapshot then pruned the oldest backup).
 * Users have N retained backups (default 10) to recover from a bad restore.
 *
 * Returns array shape matches reloadSamba(): { success, error }.
 *
 * @param string $filename Backup filename (basename only, validated by caller)
 * @param string|null $configBase
 * @return array{success: bool, error: string}
 */
function restoreBackup(string $filename, ?string $configBase = null): array
{
    $base = $configBase ?? ConfigRegistry::getConfigBase();
    $backupFile = $base . '/plugins/custom.smb.shares/backups/' . $filename;
    $sharesFile = $base . '/plugins/custom.smb.shares/shares.json';

    if (!file_exists($backupFile)) {
        return ['success' => false, 'error' => "Backup file not found: $filename"];
    }

    // Validate backup contents BEFORE overwriting shares.json — protects
    // against a corrupt or truncated backup destroying the live config.
    $contents = file_get_contents($backupFile);
    if ($contents === false) {
        $err = error_get_last();
        return ['success' => false, 'error' => 'Cannot read backup file: ' . ($err['message'] ?? 'unknown error')];
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return ['success' => false, 'error' => 'Backup file does not contain valid JSON share data'];
    }

    if (!@copy($backupFile, $sharesFile)) {
        $err = error_get_last();
        return ['success' => false, 'error' => 'Failed to restore: ' . ($err['message'] ?? 'unknown copy error')];
    }

    return ['success' => true, 'error' => ''];
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
        // Skip disabled shares
        if (isset($share['enabled']) && $share['enabled'] === false) {
            continue;
        }

        // Handle export field - skip if not exported
        $export = $share['export'] ?? 'e';
        if ($export === '-') {
            continue;
        }

        $config .= buildShareConfig($share, $export);
    }
    return $config;
}

/**
 * Build config for a single share
 * @param array<string, mixed> $share Share data
 * @param string $export Export setting
 * @return string Samba config block for this share
 */
function buildShareConfig(array $share, string $export): string
{
    // Sanitize all user-provided string fields to prevent config injection
    $name = sanitizeForSambaConfig($share['name'] ?? '');
    $path = sanitizeForSambaConfig($share['path'] ?? '');
    $comment = sanitizeForSambaConfig($share['comment'] ?? '');

    $config = "[{$name}]\n";
    $config .= "    path = {$path}\n";

    if (!empty($comment)) {
        $config .= "    comment = {$comment}\n";
    }

    // Determine browseable from export setting ('eh' and 'eth' are hidden)
    $isHidden = in_array($export, ['eh', 'eth'], true);
    $config .= "    browseable = " . ($isHidden ? 'no' : 'yes') . "\n";

    $config .= buildCaseSensitiveConfig($share);
    $config .= buildVfsConfig($share, $export);
    $config .= buildSecurityConfig($share);
    $config .= buildPermissionConfig($share);
    $config .= buildHostAccessConfig($share);

    $config .= "\n";
    return $config;
}

/**
 * Build case sensitivity config
 * @param array<string, mixed> $share Share data
 * @return string Config lines for case sensitivity
 */
function buildCaseSensitiveConfig(array $share): string
{
    $caseSensitive = $share['case_sensitive'] ?? 'auto';
    $config = '';

    if ($caseSensitive === 'forced') {
        $config .= "    case sensitive = yes\n";
        $config .= "    default case = lower\n";
        $config .= "    preserve case = no\n";
        $config .= "    short preserve case = no\n";
    } elseif ($caseSensitive === 'yes') {
        $config .= "    case sensitive = yes\n";
    }

    return $config;
}

/**
 * Build VFS config (Time Machine, Fruit)
 * @param array<string, mixed> $share Share data
 * @param string $export Export setting
 * @return string Config lines for VFS
 */
function buildVfsConfig(array $share, string $export): string
{
    $config = '';
    $isTimeMachine = in_array($export, ['et', 'eth'], true);

    if ($isTimeMachine) {
        $config .= "    vfs objects = catia fruit streams_xattr\n";
        $config .= "    fruit:time machine = yes\n";
        if (!empty($share['volsizelimit'])) {
            $volSizeLimit = sanitizeForSambaConfig((string)$share['volsizelimit']);
            $config .= "    fruit:time machine max size = {$volSizeLimit}M\n";
        }
    } elseif (($share['fruit'] ?? 'no') === 'yes') {
        $config .= "    vfs objects = catia fruit streams_xattr\n";
    }

    return $config;
}

/**
 * Build security config (guest access, user lists)
 * @param array<string, mixed> $share Share data
 * @return string Config lines for security
 */
function buildSecurityConfig(array $share): string
{
    $security = $share['security'] ?? 'public';
    $userAccess = [];
    if (!empty($share['user_access'])) {
        $userAccess = is_string($share['user_access'])
            ? json_decode($share['user_access'], true) ?? []
            : $share['user_access'];
    }

    $config = '';

    if ($security === 'public') {
        $config .= "    guest ok = yes\n";
        $config .= "    read only = no\n";
    } elseif ($security === 'secure') {
        $config .= "    guest ok = yes\n";
        $config .= "    read only = yes\n";
        $config .= buildWriteListConfig($userAccess);
    } elseif ($security === 'private') {
        $config .= "    guest ok = no\n";
        $config .= buildPrivateAccessConfig($userAccess);
    }

    return $config;
}

/**
 * Build write list config for secure mode
 * @param array<string, string> $userAccess User access map
 * @return string Config lines for write list
 */
function buildWriteListConfig(array $userAccess): string
{
    $writeUsers = [];
    foreach ($userAccess as $user => $access) {
        if ($access === 'read-write') {
            $writeUsers[] = sanitizeForSambaConfig((string)$user);
        }
    }

    if (!empty($writeUsers)) {
        return "    write list = " . implode(' ', $writeUsers) . "\n";
    }
    return '';
}

/**
 * Build private access config (valid users, write list)
 * @param array<string, string> $userAccess User access map
 * @return string Config lines for private access
 */
function buildPrivateAccessConfig(array $userAccess): string
{
    $validUsers = [];
    $writeUsers = [];

    foreach ($userAccess as $user => $access) {
        $sanitizedUser = sanitizeForSambaConfig((string)$user);
        if ($access === 'read-only') {
            $validUsers[] = $sanitizedUser;
        } elseif ($access === 'read-write') {
            $validUsers[] = $sanitizedUser;
            $writeUsers[] = $sanitizedUser;
        }
    }

    $config = '';
    if (!empty($validUsers)) {
        $config .= "    valid users = " . implode(' ', $validUsers) . "\n";
    }
    if (!empty($writeUsers)) {
        $config .= "    write list = " . implode(' ', $writeUsers) . "\n";
    }
    $config .= "    read only = yes\n";

    return $config;
}

/**
 * Build permission config (masks, force user/group)
 * @param array<string, mixed> $share Share data
 * @return string Config lines for permissions
 */
function buildPermissionConfig(array $share): string
{
    $forceUser = sanitizeForSambaConfig($share['force_user'] ?? '');
    $forceGroup = sanitizeForSambaConfig($share['force_group'] ?? '');
    $createMask = sanitizeForSambaConfig($share['create_mask'] ?? '0664');
    $directoryMask = sanitizeForSambaConfig($share['directory_mask'] ?? '0775');
    $hideDotFiles = sanitizeForSambaConfig($share['hide_dot_files'] ?? 'yes');

    $config = '';
    if (!empty($forceUser)) {
        $config .= "    force user = {$forceUser}\n";
    }
    if (!empty($forceGroup)) {
        $config .= "    force group = {$forceGroup}\n";
    }
    $config .= "    create mask = {$createMask}\n";
    $config .= "    directory mask = {$directoryMask}\n";
    $config .= "    hide dot files = {$hideDotFiles}\n";

    return $config;
}

/**
 * Build host access config (hosts allow/deny)
 * @param array<string, mixed> $share Share data
 * @return string Config lines for host access
 */
function buildHostAccessConfig(array $share): string
{
    $hostsAllow = sanitizeForSambaConfig($share['hosts_allow'] ?? '');
    $hostsDeny = sanitizeForSambaConfig($share['hosts_deny'] ?? '');

    $config = '';
    if (!empty($hostsAllow)) {
        $config .= "    hosts allow = {$hostsAllow}\n";
    }
    if (!empty($hostsDeny)) {
        $config .= "    hosts deny = {$hostsDeny}\n";
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

	/* Restart the recycle bin to adjust for changes. */
	/* If the recycle bin plugin is installed and running, reload the recycle bin. */
	$recycle_script = "/usr/local/emhttp/plugins/recycle.bin/scripts/rc.recycle.bin";

	if ((is_executable($recycle_script)) && (is_file("/var/run/recycle.bin.pid"))) {
		/* The recycle bin will reload samba. */

		$output = [];
		exec(escapeshellarg($recycle_script)." reload 2>&1", $output, $ret);

		if ($ret !== 0) {
			return ['success' => false, 'error' => implode("\n", $output)];
		}
	} else {
		/* If there is no recycle bin, then just reload samba. */

		$output = [];
		exec(escapeshellarg($smbcontrol)." all reload-config 2>&1", $output, $ret);

		if ($ret !== 0) {
			return ['success' => false, 'error' => implode("\n", $output)];
		}
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
 * Ensure the plugin's include hook is present in the main smb.conf.
 *
 * REQ-INC-01/02/03: Injects an idempotent hook block into
 * ConfigRegistry::getSmbConfPath() (the RAM smb.conf) rather than touching
 * smb-extra.conf. The block is delimited by unique markers so it never
 * collides with Unassigned Devices' own include directive (AC-INC-01.3):
 *
 *     # hook for custom smb shares
 *     include = <getSmbCustomConfPath()>
 *     # end hook for custom smb shares
 *
 * Idempotency (AC-INC-01/02): if HOOK_BEGIN is already present the file is
 * left unchanged and true is returned. Otherwise the block is APPENDED to
 * EOF (the default strategy per design; REQ-INC-04 insert-before-marker is a
 * human-gated fallback and is NOT implemented here). Existing smb.conf
 * content (Unraid / UD lines) is preserved verbatim.
 *
 * The write is atomic (temp file + rename) so a partial/failed write can
 * never corrupt the served smb.conf (D-1 finding i).
 *
 * @return bool True if the hook is present (or was added), false on error
 */
function ensureSambaInclude(): bool
{
    $smbConf = ConfigRegistry::getSmbConfPath();
    $pluginConf = ConfigRegistry::getSmbCustomConfPath();

    // Read existing smb.conf (empty string if it does not exist yet).
    $content = @file_get_contents($smbConf);
    if ($content === false) {
        $content = '';
    }

    // Idempotent: if our hook marker is already present, do nothing.
    if (strpos($content, HOOK_BEGIN) !== false) {
        return true;
    }

    // Build the hook block. Ensure exactly one newline separates it from any
    // existing trailing content so we never glue onto a partial last line.
    $block = HOOK_BEGIN . "\n"
        . "include = $pluginConf\n"
        . HOOK_END . "\n";

    $separator = ($content === '' || substr($content, -1) === "\n") ? '' : "\n";
    $newContent = $content . $separator . $block;

    // Ensure the parent directory exists before attempting the write.
    $dir = dirname($smbConf);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // Atomic write: temp file + rename. On any failure, log and return false
    // WITHOUT having clobbered the existing smb.conf.
    $tmp = $smbConf . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $newContent) === false) {
        logError("Failed to write temp smb.conf at $tmp");
        return false;
    }
    if (!@rename($tmp, $smbConf)) {
        @unlink($tmp);
        logError("Failed to rename temp smb.conf into place: $smbConf");
        return false;
    }

    logInfo("Added include hook for custom SMB shares to smb.conf");
    return true;
}


/**
 * Get system users for SMB access configuration
 * @param bool $includeSystemUsers Include system users (uid < 1000) like nobody
 * @return array<int, array{name: string, uid: int}> Array of user info
 */
function getSystemUsers(bool $includeSystemUsers = false): array
{
    $users = [];

    // In test mode, check for mock users file first
    if (TestModeDetector::isTestMode()) {
        $harnessRoot = TestModeDetector::getHarnessRoot();
        $suffix = $includeSystemUsers ? '-all' : '';
        $mockUsersFile = $harnessRoot . '/boot/config/plugins/custom.smb.shares/users' . $suffix . '.json';
        if (file_exists($mockUsersFile)) {
            $content = file_get_contents($mockUsersFile);
            if ($content !== false) {
                $mockUsers = json_decode($content, true);
                if (is_array($mockUsers)) {
                    return $mockUsers;
                }
            }
        }
        // Fall back to regular users file if -all doesn't exist
        if ($includeSystemUsers) {
            $mockUsersFile = $harnessRoot . '/boot/config/plugins/custom.smb.shares/users.json';
            if (file_exists($mockUsersFile)) {
                $content = file_get_contents($mockUsersFile);
                if ($content !== false) {
                    $mockUsers = json_decode($content, true);
                    if (is_array($mockUsers)) {
                        return $mockUsers;
                    }
                }
            }
        }
    }

    // Read /etc/passwd for system users
    $passwdFile = '/etc/passwd';
    if (!file_exists($passwdFile)) {
        return [];
    }

    $passwd = file($passwdFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($passwd === false) {
        return [];
    }

    foreach ($passwd as $line) {
        $parts = explode(':', $line);
        if (count($parts) < 7) {
            continue;
        }

        $username = $parts[0];
        $uid = (int)$parts[2];

        // Include users based on includeSystemUsers flag
        // uid >= 1000 = regular users, uid < 1000 = system users (nobody=99, etc.)
        if ($includeSystemUsers || $uid >= 1000) {
            $users[] = [
                'name' => $username,
                'uid' => $uid
            ];
        }
    }

    // Sort by username
    usort($users, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return $users;
}

/**
 * Get system groups for SMB configuration (force group)
 * @return array<int, array{name: string, gid: int}> Array of group info
 */
function getSystemGroups(): array
{
    $groups = [];

    // In test mode, check for mock groups file first
    if (TestModeDetector::isTestMode()) {
        $harnessRoot = TestModeDetector::getHarnessRoot();
        $mockGroupsFile = $harnessRoot . '/boot/config/plugins/custom.smb.shares/groups.json';
        if (file_exists($mockGroupsFile)) {
            $content = file_get_contents($mockGroupsFile);
            if ($content !== false) {
                $mockGroups = json_decode($content, true);
                if (is_array($mockGroups)) {
                    return $mockGroups;
                }
            }
        }
    }

    // Read /etc/group for system groups
    $groupFile = '/etc/group';
    if (!file_exists($groupFile)) {
        return [];
    }

    $groupData = file($groupFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($groupData === false) {
        return [];
    }

    foreach ($groupData as $line) {
        $parts = explode(':', $line);
        if (count($parts) < 4) {
            continue;
        }

        $groupname = $parts[0];
        $gid = (int)$parts[2];

        $groups[] = [
            'name' => $groupname,
            'gid' => $gid
        ];
    }

    // Sort by group name
    usort($groups, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    return $groups;
}
/**
 * Regenerate and apply the full Samba runtime configuration. REQ-SEAM-01/02.
 *
 * Orchestrates the whole write path in one serialized, crash-safe operation:
 *   1. regenerate smb-custom.conf from shares
 *   2. atomically write it to the RAM path
 *   3. ensure the include hook is present in smb.conf
 *   4. Recycle Bin integration seam (no-op unless a future feature is present)
 *   5. reload Samba
 *
 * Crash-safety (D-1 finding i): the config is written to a temp file and then
 * renamed into place, so a partial or failed write can never corrupt the
 * config that Samba is serving.
 *
 * Concurrency (D-1 finding ii): the regenerate + write + ensure-include
 * critical section is guarded by an advisory file lock (flock LOCK_EX) so two
 * concurrent mutations cannot interleave and corrupt the config.
 *
 * @param array<int, array<string, mixed>>|null $shares Shares to render;
 *        loaded from disk when null.
 * @return array{success: bool, error: string} Result (shape matches reloadSamba)
 */
function rebuildSambaConfig(?array $shares = null): array
{
    if ($shares === null) {
        $shares = loadShares();
    }

    $confPath = ConfigRegistry::getSmbCustomConfPath();
    $dir = dirname($confPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // Advisory lock over the whole regenerate+write+include critical section so
    // concurrent mutations cannot interleave. The lock file lives in the same
    // resolved dir (harness-safe in test mode).
    $lockPath = $dir . '/.rebuild.lock';
    $lock = @fopen($lockPath, 'c');
    $locked = false;
    if ($lock !== false) {
        $locked = flock($lock, LOCK_EX);
    }

    try {
        // Regenerate the config body from the current shares.
        $config = generateSambaConfig($shares);

        // Atomic write: temp file + rename. On failure, leave the existing
        // served config untouched (D-1 finding i).
        $tmp = $confPath . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $config) === false) {
            logError("Failed to write temp smb-custom.conf at $tmp");
            return ['success' => false, 'error' => "Failed to write custom Samba config to $tmp"];
        }
        if (!@rename($tmp, $confPath)) {
            @unlink($tmp);
            logError("Failed to rename temp smb-custom.conf into place: $confPath");
            return ['success' => false, 'error' => "Failed to install custom Samba config at $confPath"];
        }

        // Ensure the include hook is present in smb.conf (AC-SEAM-01.3: propagate
        // an include failure as an overall failure).
        if (!ensureSambaInclude()) {
            return ['success' => false, 'error' => 'Failed to ensure Samba include hook in smb.conf'];
        }

        // === RECYCLE BIN NEXT INTEGRATION SEAM ===
        // REQ-SEAM-03: documented extension point for a future Recycle Bin
        // feature to append its own per-share directives after the base config
        // is written and the include hook is in place, but BEFORE reload. This
        // is a no-op today: the hook is only invoked when a future feature
        // defines applyRecycleBinDirectives().
        if (function_exists('applyRecycleBinDirectives')) {
            applyRecycleBinDirectives($shares, $confPath);
        }
        // === END SEAM ===
    } finally {
        // Always release the advisory lock, even on early return.
        if ($lock !== false) {
            if ($locked) {
                flock($lock, LOCK_UN);
            }
            fclose($lock);
        }
    }

    return reloadSamba();
}

/**
 * Remove the plugin's legacy include directive from smb-extra.conf. REQ-MIG-01/02.
 *
 * Strips ONLY the plugin's own '# Custom SMB Shares plugin' comment line and the
 * old 'include = ...' line that references the legacy flash path
 * (/plugins/custom.smb.shares/smb-custom.conf). All other bytes in
 * smb-extra.conf (e.g. Unassigned Devices' own directives) are preserved
 * verbatim. The write is atomic (temp file + rename). No-op if the file does
 * not exist or contains no plugin lines.
 *
 * @return void
 */
function removeOldSmbExtraInclude(): void
{
    $smbExtraConf = ConfigRegistry::getSmbExtraConfPath();

    $content = @file_get_contents($smbExtraConf);
    if ($content === false) {
        return; // Nothing to migrate.
    }

    $lines = explode("\n", $content);
    $kept = [];
    $changed = false;
    foreach ($lines as $line) {
        $trimmed = trim($line);
        // Drop the plugin's own comment marker.
        if ($trimmed === '# Custom SMB Shares plugin') {
            $changed = true;
            continue;
        }
        // Drop the old include line referencing the legacy flash path only.
        if (
            strpos($trimmed, 'include') === 0 &&
            strpos($line, '/plugins/custom.smb.shares/smb-custom.conf') !== false
        ) {
            $changed = true;
            continue;
        }
        $kept[] = $line;
    }

    if (!$changed) {
        return; // Nothing referenced the plugin; leave file untouched.
    }

    $newContent = implode("\n", $kept);

    // Atomic write: temp file + rename so a partial write cannot corrupt
    // smb-extra.conf (which may hold other tools' directives).
    $tmp = $smbExtraConf . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $newContent) === false) {
        logError("Failed to write temp smb-extra.conf at $tmp during migration");
        return;
    }
    if (!@rename($tmp, $smbExtraConf)) {
        @unlink($tmp);
        logError("Failed to rename temp smb-extra.conf into place during migration: $smbExtraConf");
        return;
    }

    logInfo('Removed legacy custom SMB shares include from smb-extra.conf');
}

/**
 * Migrate the plugin's Samba wiring on upgrade. REQ-MIG-01/02/03.
 *
 * Removes the legacy smb-extra.conf include (old flash-path model) and then
 * rebuilds the runtime config via the new RAM-path + smb.conf-hook model.
 * Never mutates shares.json, so ALL existing shares (including any on
 * UD-managed paths, which are grandfathered) are preserved.
 *
 * @return void
 */
function migrateSambaRuntime(): void
{
    removeOldSmbExtraInclude();
    rebuildSambaConfig();
}
