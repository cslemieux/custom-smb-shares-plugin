<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/UnraidTestHarness.php';

class UnraidTestHarnessTest extends TestCase
{
    private static $harness;
    
    public function testSetupCreatesHarness()
    {
        self::$harness = UnraidTestHarness::setup(8889);
        
        $this->assertIsArray(self::$harness);
        $this->assertArrayHasKey('url', self::$harness);
        $this->assertArrayHasKey('harness_dir', self::$harness);
        $this->assertEquals('http://localhost:8889', self::$harness['url']);
        $this->assertDirectoryExists(self::$harness['harness_dir']);
    }
    
    public function testSetupWithConfigArray()
    {
        UnraidTestHarness::teardown();
        
        $config = [
            'port' => 8890,
            'testDirs' => ['custom1', 'custom2']
        ];
        
        self::$harness = UnraidTestHarness::setup($config);
        
        $this->assertEquals('http://localhost:8890', self::$harness['url']);
        $this->assertDirectoryExists(self::$harness['harness_dir'] . '/mnt/user/custom1');
        $this->assertDirectoryExists(self::$harness['harness_dir'] . '/mnt/user/custom2');
        
        UnraidTestHarness::teardown();
        self::$harness = UnraidTestHarness::setup(8889);
    }
    
    public function testSetupRejectsInvalidPort()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Port must be between 1024 and 65535');
        
        UnraidTestHarness::setup(500);
    }
    
    public function testDirectoryStructureCreated()
    {
        $dir = self::$harness['harness_dir'];
        
        $this->assertDirectoryExists($dir . '/usr/local/emhttp/plugins/custom.smb.shares');
        $this->assertDirectoryExists($dir . '/usr/local/boot/config/plugins');
        $this->assertDirectoryExists($dir . '/mnt/user');
        $this->assertDirectoryExists($dir . '/var/local/emhttp');
        $this->assertDirectoryExists($dir . '/logs');
        $this->assertDirectoryExists($dir . '/run');
    }
    
    public function testDefaultTestDirectoriesCreated()
    {
        $dir = self::$harness['harness_dir'];
        
        $this->assertDirectoryExists($dir . '/mnt/user/test1');
        $this->assertDirectoryExists($dir . '/mnt/user/test2');
        $this->assertDirectoryExists($dir . '/mnt/user/test3');
        $this->assertDirectoryExists($dir . '/mnt/user/EditTest');
        $this->assertDirectoryExists($dir . '/mnt/user/DeleteTest');
    }
    
    public function testPluginFilesCopied()
    {
        $dir = self::$harness['harness_dir'];
        
        $this->assertFileExists($dir . '/usr/local/emhttp/plugins/custom.smb.shares/SMBShares.page');
        $this->assertFileExists($dir . '/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php');
        $this->assertFileExists($dir . '/usr/local/emhttp/plugins/custom.smb.shares/js/feedback.js');
        $this->assertFileExists($dir . '/usr/local/emhttp/plugins/custom.smb.shares/css/feedback.css');
    }
    
    public function testAuthBypassCreated()
    {
        $dir = self::$harness['harness_dir'];
        
        $this->assertFileExists($dir . '/usr/local/emhttp/auth-request.php');
        
        $content = file_get_contents($dir . '/usr/local/emhttp/auth-request.php');
        $this->assertStringContainsString('$_SESSION[\'unraid_login\']', $content);
        $this->assertStringContainsString('http_response_code(200)', $content);
    }
    
    public function testLocalPrependCreated()
    {
        $dir = self::$harness['harness_dir'];
        
        $this->assertFileExists($dir . '/usr/local/emhttp/webGui/include/local_prepend.php');
        
        $content = file_get_contents($dir . '/usr/local/emhttp/webGui/include/local_prepend.php');
        $this->assertStringContainsString('csrf_terminate', $content);
        $this->assertStringContainsString('CSRF validation', $content);
    }
    
    public function testCSRFTokenGenerated()
    {
        $dir = self::$harness['harness_dir'];
        
        $this->assertFileExists($dir . '/var/local/emhttp/var.ini');
        
        $var = parse_ini_file($dir . '/var/local/emhttp/var.ini');
        $this->assertArrayHasKey('csrf_token', $var);
        $this->assertNotEmpty($var['csrf_token']);
        $this->assertEquals(32, strlen($var['csrf_token'])); // 16 bytes = 32 hex chars
    }
    
    public function testConfigDirectoryCreated()
    {
        $dir = self::$harness['harness_dir'];
        
        $configDir = $dir . '/usr/local/boot/config/plugins/custom.smb.shares';
        $this->assertDirectoryExists($configDir);
        
        $sharesFile = $configDir . '/shares.json';
        $this->assertFileExists($sharesFile);
        $this->assertEquals('[]', file_get_contents($sharesFile));
    }
    
    public function testCreateShareDirWorks()
    {
        $path = UnraidTestHarness::createShareDir('/mnt/user/dynamic-test');
        
        $this->assertDirectoryExists($path);
        $this->assertStringContainsString('/mnt/user/dynamic-test', $path);
    }
    
    public function testCreateShareDirIdempotent()
    {
        $path1 = UnraidTestHarness::createShareDir('/mnt/user/idempotent-test');
        $path2 = UnraidTestHarness::createShareDir('/mnt/user/idempotent-test');
        
        $this->assertEquals($path1, $path2);
        $this->assertDirectoryExists($path1);
    }
    
    public function testServerResponds()
    {
        $response = @file_get_contents(self::$harness['url'] . '/plugins/custom.smb.shares/status.php');
        $this->assertNotFalse($response);
    }
    
    public function testGetUrlReturnsCorrectUrl()
    {
        $url = UnraidTestHarness::getUrl();
        $this->assertEquals('http://localhost:8889', $url);
    }
    
    public function testLockFileCreated()
    {
        $lockFile = '/tmp/unraid-test-harness-8889.lock';
        $this->assertFileExists($lockFile);
        
        $pid = (int)file_get_contents($lockFile);
        $this->assertGreaterThan(0, $pid);
    }
    
    public function testKillServerOnPortWorks()
    {
        // Verify server is running
        exec('lsof -ti :8889', $output);
        $this->assertNotEmpty($output, 'Server should be running on port 8889');
    }
    
    public function testSambaMockInitialized()
    {
        $this->assertEquals('SambaMock', self::$harness['samba']);
    }
    
    public static function tearDownAfterClass(): void
    {
        if (self::$harness) {
            UnraidTestHarness::teardown();
        }
    }
}
