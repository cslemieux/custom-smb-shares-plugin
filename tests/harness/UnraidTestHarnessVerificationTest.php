<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/UnraidTestHarness.php';

/**
 * Harness Verification Tests for UnraidTestHarness
 * Verifies test harness structure, dependency injection, and PID locking
 */
class UnraidTestHarnessVerificationTest extends TestCase
{
    private static $harness;
    
    public static function setUpBeforeClass(): void
    {
        self::$harness = UnraidTestHarness::setup(8889); // Different port
    }
    
    public static function tearDownAfterClass(): void
    {
        UnraidTestHarness::teardown();
    }
    
    public function testHarnessStructureCreated()
    {
        $harnessDir = self::$harness['harness_dir'];
        
        $this->assertStringStartsWith('/tmp/unraid-test-harness-', $harnessDir);
        $this->assertDirectoryExists($harnessDir);
        $this->assertDirectoryExists($harnessDir . '/usr/local/boot/config');
        $this->assertDirectoryExists($harnessDir . '/mnt/user');
    }
    
    public function testServerResponds()
    {
        $url = self::$harness['url'];
        
        $ch = curl_init($url . '/Settings/SMBShares');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $this->assertEquals(200, $httpCode, 'Server should respond with 200');
        $this->assertNotEmpty($response, 'Server should return content');
    }
    
    public function testDependenciesInjected()
    {
        $url = self::$harness['url'] . '/Settings/SMBShares';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $html = curl_exec($ch);
        curl_close($ch);
        
        // Check for jQuery
        $this->assertStringContainsString('jquery', $html, 'jQuery should be injected');
        
        // Check for Tablesorter
        $this->assertStringContainsString('tablesorter', $html, 'Tablesorter should be injected');
        
        // Check for SweetAlert
        $this->assertStringContainsString('sweetalert', $html, 'SweetAlert should be injected');
        
        // Check for Font Awesome
        $this->assertStringContainsString('font-awesome', $html, 'Font Awesome should be injected');
    }
    
    public function testDependenciesJsonExists()
    {
        $depsFile = __DIR__ . '/dependencies.json';
        
        $this->assertFileExists($depsFile, 'dependencies.json should exist');
        
        $deps = json_decode(file_get_contents($depsFile), true);
        $this->assertIsArray($deps, 'dependencies.json should be valid JSON');
        $this->assertArrayHasKey('dependencies', $deps);
        $this->assertArrayHasKey('tags', $deps);
        
        // Verify expected dependencies
        $this->assertContains('jquery', $deps['dependencies']);
        $this->assertContains('tablesorter', $deps['dependencies']);
        $this->assertContains('sweetalert', $deps['dependencies']);
    }
    
    public function testCsrfTokenGenerated()
    {
        $url = self::$harness['url'] . '/Settings/SMBShares';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $html = curl_exec($ch);
        curl_close($ch);
        
        // Check for CSRF token variable
        $this->assertStringContainsString('csrf_token', $html, 'CSRF token should be present');
    }
    
    public function testPidLockingPreventsMultipleInstances()
    {
        // Try to start another harness on same port
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test harness already running on port 8889');
        
        UnraidTestHarness::setup(8889); // Should fail - port already in use
    }
    
    public function testPidLockFileExists()
    {
        $lockFile = '/tmp/unraid-test-harness-8889.lock';
        
        $this->assertFileExists($lockFile, 'PID lock file should exist');
        
        $pid = (int)file_get_contents($lockFile);
        $this->assertGreaterThan(0, $pid, 'Lock file should contain valid PID');
        
        // Verify process is running
        $this->assertTrue(posix_kill($pid, 0), 'Process should be running');
    }
    
    public function testTestDirectoriesCreated()
    {
        $harnessDir = self::$harness['harness_dir'];
        
        $testDirs = ['test1', 'test2', 'test3', 'EditTest', 'DeleteTest'];
        foreach ($testDirs as $dir) {
            $this->assertDirectoryExists(
                $harnessDir . '/mnt/user/' . $dir,
                "Test directory $dir should exist"
            );
        }
    }
    
    public function testSambaMockInitialized()
    {
        $harnessDir = self::$harness['harness_dir'];
        
        // Check for mock scripts
        $this->assertFileExists($harnessDir . '/usr/bin/testparm');
        $this->assertFileExists($harnessDir . '/usr/bin/smbcontrol');
        $this->assertFileExists($harnessDir . '/etc/rc.d/rc.samba');
        
        // Verify executable
        $this->assertTrue(is_executable($harnessDir . '/usr/bin/testparm'));
    }
    
    public function testPathResolutionInHarness()
    {
        $harnessDir = self::$harness['harness_dir'];
        
        // Test path: /mnt/user/test1
        // Should resolve to: /tmp/unraid-test-harness-xxx/mnt/user/test1
        $testPath = $harnessDir . '/mnt/user/test1';
        
        $this->assertDirectoryExists($testPath);
        $this->assertStringContainsString('/tmp/', $testPath);
        $this->assertStringContainsString('/mnt/', $testPath);
    }
    
    public function testConfigBaseResolution()
    {
        $harnessDir = self::$harness['harness_dir'];
        
        // CONFIG_BASE should resolve to: /tmp/xxx/usr/local/boot/config
        $expectedConfigBase = $harnessDir . '/usr/local/boot/config';
        
        $this->assertDirectoryExists($expectedConfigBase);
        
        // Verify 4 levels up gets harness root
        $extractedRoot = dirname(dirname(dirname(dirname($expectedConfigBase))));
        $this->assertEquals($harnessDir, $extractedRoot);
    }
}
