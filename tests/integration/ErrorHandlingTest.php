<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';

class ErrorHandlingTest extends TestCase
{
    private static $configDir;
    
    public static function setUpBeforeClass(): void
    {
        self::$configDir = ChrootTestEnvironment::setup();
        if (!defined('CONFIG_BASE')) define('CONFIG_BASE', self::$configDir);
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';
    }
    
    
    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        ChrootTestEnvironment::mkdir('user/testshare');
    }
    
    public function testValidShareReturnsNoErrors()
    {
        $share = [
            'name' => 'ValidShare',
            'path' => ChrootTestEnvironment::getMntPath('user/testshare'),
            'create_mask' => '0664',
            'directory_mask' => '0775'
        ];
        
        $errors = validateShare($share);
        $this->assertEmpty($errors, 'Valid share should have no errors');
    }
    
    public function testInvalidShareNameReturnsError()
    {
        $share = [
            'name' => 'Invalid Name!',
            'path' => ChrootTestEnvironment::getMntPath('user/testshare')
        ];
        
        $errors = validateShare($share);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('share name', strtolower($errors[0]));
    }
    
    public function testInvalidPathReturnsError()
    {
        $share = [
            'name' => 'ValidName',
            'path' => '/invalid/path'
        ];
        
        $errors = validateShare($share);
        $this->assertNotEmpty($errors);
    }
    
    public function testNonexistentPathReturnsError()
    {
        $share = [
            'name' => 'ValidName',
            'path' => ChrootTestEnvironment::getMntPath('user/nonexistent')
        ];
        
        $errors = validateShare($share);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('does not exist', $errors[0]);
    }
    
    public function testInvalidCreateMaskReturnsError()
    {
        $share = [
            'name' => 'ValidName',
            'path' => ChrootTestEnvironment::getMntPath('user/testshare'),
            'create_mask' => '9999'
        ];
        
        $errors = validateShare($share);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('mask', strtolower($errors[0]));
    }
    
    public function testMultipleErrorsReturned()
    {
        $share = [
            'name' => 'Invalid Name!',
            'path' => '/invalid/path',
            'create_mask' => 'abcd'
        ];
        
        $errors = validateShare($share);
        $this->assertGreaterThanOrEqual(2, count($errors));
    }

    /**
     * Test TOCTOU fix: validateShare should replace path with resolved realpath
     */
    public function testValidateShareReplacesPathWithRealpath()
    {
        // Create a directory with a symlink
        ChrootTestEnvironment::mkdir('user/realdir');
        $realDir = ChrootTestEnvironment::getMntPath('user/realdir');
        $symlinkDir = ChrootTestEnvironment::getMntPath('user/symlink');
        
        // Create symlink pointing to realdir
        symlink($realDir, $symlinkDir);
        
        $share = [
            'name' => 'TestShare',
            'path' => $symlinkDir  // User provides symlink path
        ];
        
        $errors = validateShare($share);
        
        // Should have no errors
        $this->assertEmpty($errors, 'Symlink to valid directory should pass validation');
        
        // The path should now be the resolved realpath (not the symlink)
        // In test mode, the harness root is stripped, so we check for /mnt/user/realdir
        $this->assertStringContainsString('/mnt/user/realdir', $share['path'], 
            'Path should be replaced with resolved realpath');
        $this->assertStringNotContainsString('symlink', $share['path'],
            'Symlink path should be replaced with real path');
        
        // Cleanup
        unlink($symlinkDir);
    }

    /**
     * Test that symlink pointing outside /mnt/ is rejected
     */
    public function testSymlinkOutsideMntIsRejected()
    {
        // Create a symlink pointing outside /mnt/
        $symlinkDir = ChrootTestEnvironment::getMntPath('user/evil_symlink');
        
        // Create symlink pointing to /tmp (outside /mnt/)
        symlink('/tmp', $symlinkDir);
        
        $share = [
            'name' => 'TestShare',
            'path' => $symlinkDir
        ];
        
        $errors = validateShare($share);
        
        // Should have an error because realpath resolves to /tmp which is outside /mnt/
        $this->assertNotEmpty($errors, 'Symlink pointing outside /mnt/ should be rejected');
        
        // Cleanup
        unlink($symlinkDir);
    }
}
