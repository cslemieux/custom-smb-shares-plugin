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

    $newShare = sanitizeShareData([
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

    $errors = validateShare($newShare);
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => implode(', ', $errors)]);
        exit;
    }

    $shares = loadShares();
    $shareName = $newShare['name'] ?? '';

    if (findShareIndex($shares, $shareName) !== -1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Share name already exists']);
        exit;
    }

    $shares[] = $newShare;
    saveShares($shares);

    logInfo("Share added: " . $shareName);

    // Ensure our include directive is in smb-extra.conf
    ensureSambaInclude();

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

    // Verify share is now active in Samba
    $verified = verifySambaShare($shareName, true);

    echo json_encode([
        'success' => true,
        'verified' => $verified,
        'message' => $verified
            ? 'Share "' . htmlspecialchars($shareName) . '" added and verified'
            : 'Share "' . htmlspecialchars($shareName) . '" added but verification failed'
    ]);
} catch (Exception $e) {
    logError("Add share failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
