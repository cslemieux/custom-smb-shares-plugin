<?php

declare(strict_types=1);

require_once 'include/lib.php';

header('Content-Type: application/json');

try {
    // Check if plugin is enabled
    if (!isPluginEnabled()) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Plugin is disabled. Enable it in Settings first.']);
        exit;
    }

    // CSRF validation is handled globally by Unraid in local_prepend.php

    $originalName = trim($_POST['original_name'] ?? '');

    // Process permissions if provided
    $readList = [];
    $writeList = [];
    if (!empty($_POST['permissions'])) {
        $permissions = json_decode($_POST['permissions'], true);
        if (is_array($permissions)) {
            $readList = $permissions['read'] ?? [];
            $writeList = $permissions['write'] ?? [];
        }
    }

    $updatedShare = sanitizeShareData([
        'name' => $_POST['name'] ?? '',
        'path' => $_POST['path'] ?? '',
        'comment' => $_POST['comment'] ?? '',
        'export' => $_POST['export'] ?? 'e',
        'volsizelimit' => $_POST['volsizelimit'] ?? '',
        'case_sensitive' => $_POST['case_sensitive'] ?? 'auto',
        'security' => $_POST['security'] ?? 'public',
        'user_access' => $_POST['user_access'] ?? '{}',
        'hosts_allow' => $_POST['hosts_allow'] ?? '',
        'hosts_deny' => $_POST['hosts_deny'] ?? '',
        'fruit' => $_POST['fruit'] ?? 'no',
        'create_mask' => $_POST['create_mask'] ?? '0664',
        'directory_mask' => $_POST['directory_mask'] ?? '0775',
        'force_user' => $_POST['force_user'] ?? 'nobody',
        'force_group' => $_POST['force_group'] ?? 'users',
        'hide_dot_files' => $_POST['hide_dot_files'] ?? 'yes'
    ]);

    $shareName = $updatedShare['name'] ?? '';

    $errors = validateShare($updatedShare);
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
        exit;
    }

    $shares = loadShares();

    // Find share by name instead of index (race-condition safe)
    $found = false;
    $lookupName = !empty($originalName) ? $originalName : $shareName;
    foreach ($shares as $i => $share) {
        if ($share['name'] === $lookupName) {
            $shares[$i] = $updatedShare;
            $found = true;
            break;
        }
    }

    if (!$found) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Share not found']);
        exit;
    }
    saveShares($shares);

    $config = generateSambaConfig($shares);
    $configPath = CONFIG_BASE . '/plugins/custom.smb.shares/smb-custom.conf';
    $configDir = dirname($configPath);
    if (!is_dir($configDir)) {
        mkdir($configDir, 0755, true);
    }
    file_put_contents($configPath, $config);

    $result = reloadSamba();
    if (!$result['success']) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to reload Samba: ' . $result['error']]);
        exit;
    }

    // Verify share is still active in Samba
    $verified = verifySambaShare($shareName, true);

    echo json_encode([
        'success' => true,
        'verified' => $verified,
        'message' => $verified
            ? 'Share "' . htmlspecialchars($shareName) . '" updated and verified'
            : 'Share "' . htmlspecialchars($shareName) . '" updated but verification failed'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
