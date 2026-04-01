<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

class ShareValidationTest extends TestCase {
    private static $configDir;
    
    public static function setUpBeforeClass(): void
    {
        self::$configDir = ChrootTestEnvironment::setup();
    }
    
    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        ChrootTestEnvironment::mkdir('user/data');
    }
    
    public static function tearDownAfterClass(): void
    {
        ChrootTestEnvironment::teardown();
    }
    
    public function testValidShare() {
        $share = [
            'name' => 'test_share',
            'path' => ChrootTestEnvironment::getMntPath('user/data'),
            'create_mask' => '0664'
        ];
        
        $errors = validateShare($share);
        $this->assertEmpty($errors);
    }
    
    public function testInvalidShareName() {
        $share = ['name' => 'test[share]', 'path' => ChrootTestEnvironment::getMntPath('user/data')];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('share name', strtolower($result[0]));
    }
    
    public function testInvalidPath() {
        $share = ['name' => 'test', 'path' => '/home/data'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testInvalidMask() {
        $share = ['name' => 'test', 'path' => ChrootTestEnvironment::getMntPath('user/data'), 'create_mask' => '999'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('mask', strtolower($result[0]));
    }
}
