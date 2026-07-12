<?php

declare(strict_types=1);

require_once __DIR__ . '/include/lib.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'getUsers') {
    $users = [];
    $lines = file('/etc/passwd');
    if ($lines !== false) {
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 3 && $parts[2] >= 1000) { // Regular users
                $users[] = $parts[0];
            }
        }
    }
    // Add system users that might be useful
    array_unshift($users, 'nobody', 'root');
    echo json_encode(array_unique($users));
    exit;
}

if ($action === 'getGroups') {
    $groups = [];
    $lines = file('/etc/group');
    if ($lines !== false) {
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            $groups[] = '@' . $parts[0];
        }
    }
    echo json_encode($groups);
    exit;
}

if ($action === 'searchUsers') {
    $query = strtolower($_GET['query'] ?? '');
    if (strlen($query) < 2) {
        echo json_encode([]);
        exit;
    }

    $results = [];

    // Search users
    $lines = file('/etc/passwd');
    if ($lines !== false) {
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 5) {
                $username = $parts[0];
                $uid = (int)$parts[2];
                $fullname = $parts[4];

                // Only Unraid users (UID >= 1000, not root)
                if ($uid >= 1000 && $username !== 'root') {
                    if (
                        strpos(strtolower($username), $query) !== false ||
                        strpos(strtolower($fullname), $query) !== false
                    ) {
                        $results[] = [
                            'name' => $username,
                            'type' => 'user',
                            'fullname' => $fullname
                        ];
                    }
                }
            }
        }
    }

    // Search groups
    $lines = file('/etc/group');
    if ($lines !== false) {
        foreach ($lines as $line) {
            $parts = explode(':', $line);
            if (count($parts) >= 4) {
                $groupname = $parts[0];
                $members = !empty($parts[3]) ? explode(',', trim($parts[3])) : [];

                if (strpos(strtolower($groupname), $query) !== false) {
                    $results[] = [
                        'name' => '@' . $groupname,
                        'type' => 'group',
                        'members' => count($members)
                    ];
                }
            }
        }
    }

    // Limit to 10 results
    echo json_encode(array_slice($results, 0, 10));
    exit;
}

if ($action === 'getShare') {
    $index = intval($_GET['index'] ?? -1);
    $shares = loadShares();
    if (isset($shares[$index])) {
        echo json_encode($shares[$index]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Share not found']);
    }
    exit;
}

if ($action === 'exportConfig') {
    $shares = loadShares();
    echo json_encode(['success' => true, 'config' => $shares]);
    exit;
}

if ($action === 'importConfig') {
    // jQuery $.post sends application/x-www-form-urlencoded with `config` field
    // containing the JSON string. Read from $_POST['config'], not php://input
    // (which contains the entire form-encoded body, not just the config payload).
    $configJson = $_POST['config'] ?? '';
    if ($configJson === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing config parameter']);
        exit;
    }
    $shares = json_decode($configJson, true);

    if (!is_array($shares)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid configuration format: not valid JSON']);
        exit;
    }

    // Validate each share
    foreach ($shares as &$share) {
        $errors = validateShare($share);
        if (!empty($errors)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid share: ' . implode(', ', $errors)]);
            exit;
        }
    }
    unset($share); // Break reference

    // Backup existing config before overwriting
    backupShares();

    saveShares($shares);
    $rebuildResult = rebuildSambaConfig($shares);

    if (!$rebuildResult['success']) {
        // The import was persisted to disk but the Samba runtime rebuild failed.
        // Report success: true so the UI knows the data was saved, but include a
        // warning so the caller can surface the runtime failure to the user.
        echo json_encode([
            'success' => true,
            'message' => 'Configuration imported successfully',
            'warning' => 'Samba config rebuild failed: ' . $rebuildResult['error'],
            'sambaReloaded' => $rebuildResult,
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Configuration imported successfully',
            'sambaReloaded' => $rebuildResult,
        ]);
    }
    exit;
}

if ($action === 'reloadSamba') {
    $result = rebuildSambaConfig();
    if ($result['success']) {
        echo json_encode(['success' => true, 'message' => 'Samba reloaded successfully']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $result['error']]);
    }
    exit;
}

if ($action === 'toggleShare') {
    $name = $_POST['name'] ?? '';
    $enabled = isset($_POST['enabled']) ? $_POST['enabled'] === 'true' : null;

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Share name required']);
        exit;
    }

    $shares = loadShares();
    $index = findShareIndex($shares, $name);

    if ($index === -1) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Share not found']);
        exit;
    }

    // Toggle or set explicit value
    if ($enabled === null) {
        $shares[$index]['enabled'] = !($shares[$index]['enabled'] ?? true);
    } else {
        $shares[$index]['enabled'] = $enabled;
    }

    saveShares($shares);

    // Regenerate Samba config and reload via single seam
    $sambaResult = rebuildSambaConfig($shares);

    echo json_encode([
        'success' => true,
        'enabled' => $shares[$index]['enabled'],
        'sambaReloaded' => $sambaResult
    ]);
    exit;
}

if ($action === 'createBackup') {
    $result = backupShares();
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Backup created', 'filename' => basename($result)]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to create backup']);
    }
    exit;
}

if ($action === 'listBackups') {
    $backups = listBackups();
    echo json_encode(['success' => true, 'backups' => $backups]);
    exit;
}

if ($action === 'viewBackup') {
    $filename = $_GET['filename'] ?? $_POST['filename'] ?? '';
    if (empty($filename) || !preg_match(ConfigRegistry::BACKUP_FILENAME_PATTERN, $filename)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid backup filename']);
        exit;
    }
    $content = viewBackup($filename);
    if ($content === false) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Backup not found']);
        exit;
    }
    echo json_encode(['success' => true, 'config' => $content]);
    exit;
}

if ($action === 'restoreBackup') {
    $filename = $_GET['filename'] ?? $_POST['filename'] ?? '';
    if (empty($filename) || !preg_match(ConfigRegistry::BACKUP_FILENAME_PATTERN, $filename)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid backup filename']);
        exit;
    }
    // restoreBackup() validates the backup contents and creates a recovery
    // snapshot of the current state before overwriting (so failed restores
    // don't destroy live config or burn through backup retention).
    $result = restoreBackup($filename);
    if (!$result['success']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $result['error']]);
        exit;
    }

    // Regenerate the Samba config file from the restored shares and reload via single seam.
    $shares = loadShares();
    $sambaResult = rebuildSambaConfig($shares);

    echo json_encode([
        'success' => true,
        'message' => 'Backup restored successfully',
        'sambaReloaded' => $sambaResult,
    ]);
    exit;
}

if ($action === 'deleteBackup') {
    $filename = $_GET['filename'] ?? $_POST['filename'] ?? '';
    if (empty($filename) || !preg_match(ConfigRegistry::BACKUP_FILENAME_PATTERN, $filename)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid backup filename']);
        exit;
    }
    $result = deleteBackup($filename);
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Backup deleted']);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to delete backup']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
