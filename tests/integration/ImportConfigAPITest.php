<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';

/**
 * HTTP-harness regression test for importConfig's rebuild-failure SURFACING
 * contract (CF-01-01 / decisions.md D-1).
 *
 * Drives the REAL api.php importConfig handler over HTTP via postAPI (same
 * pattern as ToggleAPITest) so a regression in the surfacing logic is actually
 * caught. api.php previously swallowed $rebuildResult and returned
 * unconditional {success:true}; the fix adds a 'warning' when the runtime
 * rebuild fails. A prior in-process test replicated the handler logic inline
 * and therefore could NOT catch a regression — hence this HTTP-driven version.
 *
 * Fidelity: high (real endpoint over HTTP). Never touches real /etc/samba —
 * the harness's mock Samba status file is toggled to induce the reload failure.
 */
class ImportConfigAPITest extends TestCase
{
    private static ?array $harness = null;

    public static function setUpBeforeClass(): void
    {
        self::$harness = UnraidTestHarness::setup(8901);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$harness) {
            UnraidTestHarness::teardown();
        }
    }

    protected function setUp(): void
    {
        // Clean shares.json + a valid /mnt/user share dir for the import to validate.
        $pluginDir = self::$harness['harness_dir'] . '/boot/config/plugins/custom.smb.shares';
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0755, true);
        }
        file_put_contents($pluginDir . '/shares.json', '[]');
        @mkdir(self::$harness['harness_dir'] . '/mnt/user/imp1', 0755, true);
        // Default: Samba running so a clean import reloads successfully.
        SambaMock::setStatus('running');
    }

    protected function tearDown(): void
    {
        // Leave the mock daemon running for any subsequent test.
        SambaMock::setStatus('running');
    }

    /**
     * When Samba is stopped, the real api.php importConfig must persist the
     * shares (success:true) AND surface the rebuild failure via a 'warning'
     * plus sambaReloaded.success === false. Removing the warning in api.php
     * would turn this test red.
     */
    public function testImportSurfacesRebuildFailureWhenSambaStopped(): void
    {
        SambaMock::setStatus('stopped');

        $shares = [['name' => 'FailRebuildShare', 'path' => '/mnt/user/imp1']];
        $response = $this->postAPI('importConfig', ['config' => json_encode($shares)]);

        $this->assertTrue(
            $response['success'] ?? false,
            'import must report success:true (data saved) even when reload fails; response=' . json_encode($response)
        );
        $this->assertArrayHasKey(
            'warning',
            $response,
            'api.php MUST surface a warning when the Samba rebuild fails (CF-01-01); response=' . json_encode($response)
        );
        $this->assertNotEmpty($response['warning']);
        $this->assertFalse(
            $response['sambaReloaded']['success'] ?? true,
            'sambaReloaded.success must be false when the daemon is stopped'
        );
    }

    /**
     * Control: with Samba running, a clean import returns success with NO
     * warning — proving the warning above is driven by the real failure path,
     * not emitted unconditionally.
     */
    public function testImportSucceedsWithoutWarningWhenSambaRunning(): void
    {
        SambaMock::setStatus('running');

        $shares = [['name' => 'OkShare', 'path' => '/mnt/user/imp1']];
        $response = $this->postAPI('importConfig', ['config' => json_encode($shares)]);

        $this->assertTrue($response['success'] ?? false, 'clean import must succeed; response=' . json_encode($response));
        $this->assertArrayNotHasKey('warning', $response, 'no warning when the reload succeeds; response=' . json_encode($response));
    }

    private function postAPI(string $action, array $params = []): array
    {
        $params['action'] = $action;
        $ch = curl_init(self::$harness['url'] . '/plugins/custom.smb.shares/api.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $result = curl_exec($ch);
        return json_decode($result, true) ?? [];
    }
}
