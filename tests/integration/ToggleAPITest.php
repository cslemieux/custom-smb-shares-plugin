<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';

/**
 * Tests for toggleShare API endpoint
 */
class ToggleAPITest extends TestCase
{
    private static ?array $harness = null;

    public static function setUpBeforeClass(): void
    {
        self::$harness = UnraidTestHarness::setup(8899);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$harness) {
            UnraidTestHarness::teardown();
        }
    }

    protected function setUp(): void
    {
        // Create test shares
        $pluginDir = self::$harness['harness_dir'] . '/boot/config/plugins/custom.smb.shares';
        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0755, true);
        }
        
        $shares = [
            ['name' => 'testshare', 'path' => '/mnt/user/test', 'enabled' => true],
        ];
        file_put_contents($pluginDir . '/shares.json', json_encode($shares, JSON_PRETTY_PRINT));
    }

    public function testToggleShareDisables(): void
    {
        $response = $this->postAPI('toggleShare', ['name' => 'testshare', 'enabled' => 'false']);
        
        $this->assertTrue($response['success']);
        $this->assertFalse($response['enabled']);
    }

    public function testToggleShareEnables(): void
    {
        // First disable
        $this->postAPI('toggleShare', ['name' => 'testshare', 'enabled' => 'false']);
        
        // Then enable
        $response = $this->postAPI('toggleShare', ['name' => 'testshare', 'enabled' => 'true']);
        
        $this->assertTrue($response['success']);
        $this->assertTrue($response['enabled']);
    }

    public function testToggleShareWithoutNameFails(): void
    {
        $ch = curl_init(self::$harness['url'] . '/plugins/custom.smb.shares/api.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['action' => 'toggleShare']);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $this->assertEquals(400, $httpCode);
        $response = json_decode($result, true);
        $this->assertFalse($response['success']);
    }

    public function testToggleShareNotFoundFails(): void
    {
        $ch = curl_init(self::$harness['url'] . '/plugins/custom.smb.shares/api.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['action' => 'toggleShare', 'name' => 'nonexistent']);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        $this->assertEquals(404, $httpCode);
        $response = json_decode($result, true);
        $this->assertFalse($response['success']);
    }

    public function testToggleUpdatesConfigFile(): void
    {
        // Disable share
        $this->postAPI('toggleShare', ['name' => 'testshare', 'enabled' => 'false']);

        // Config now lives at the RAM path (<harnessRoot>/etc/samba/smb-custom.conf),
        // NOT the old flash path. The harness CONFIG_BASE is <harness_dir>/boot/config,
        // so the harness root (getSmbCustomConfPath resolves against it) is <harness_dir>.
        $configPath = self::$harness['harness_dir'] . '/etc/samba/smb-custom.conf';

        $this->assertFileExists($configPath, 'toggleShare must regenerate the RAM smb-custom.conf');
        $config = file_get_contents($configPath);
        // The disabled share must be excluded from the generated config.
        $this->assertStringNotContainsString('[testshare]', $config);
    }

    public function testToggleReturnssambaReloadedStatus(): void
    {
        $response = $this->postAPI('toggleShare', ['name' => 'testshare', 'enabled' => 'false']);
        
        $this->assertArrayHasKey('sambaReloaded', $response);
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
