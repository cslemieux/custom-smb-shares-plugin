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

    $shareName = trim($_POST['name'] ?? '');

    if (empty($shareName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Share name required']);
        exit;
    }

    $shares = loadShares();

    // Find and remove share by name (race-condition safe)
    $found = false;
    foreach ($shares as $i => $share) {
        if ($share['name'] === $shareName) {
            unset($shares[$i]);
            $found = true;
            break;
        }
    }

    if (!$found) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Share not found']);
        exit;
    }

    // Backup before making changes
    backupShares();

    $shares = array_values($shares);
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

    // Verify share is removed from Samba
    $verified = verifySambaShare($shareName, false);

    echo json_encode([
        'success' => true,
        'verified' => $verified,
        'message' => $verified
            ? 'Share "' . htmlspecialchars($shareName) . '" deleted and verified'
            : 'Share "' . htmlspecialchars($shareName) . '" deleted but still appears in Samba'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
