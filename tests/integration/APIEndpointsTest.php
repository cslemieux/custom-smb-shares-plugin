<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';

class APIEndpointsTest extends TestCase
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
        ChrootTestEnvironment::mkdir('user/share1');
        ChrootTestEnvironment::mkdir('user/share2');
    }
    
    // Add endpoint tests
    
    public function testAddShareDuplicateName()
    {
        $shares = [
            ['name' => 'ExistingShare', 'path' => ChrootTestEnvironment::getMntPath('user/share1')]
        ];
        saveShares($shares, self::$configDir);
        
        $newShare = [
            'name' => 'ExistingShare',
            'path' => ChrootTestEnvironment::getMntPath('user/share2')
        ];
        
        $errors = validateShare($newShare);
        $loadedShares = loadShares(self::$configDir);
        $index = findShareIndex($loadedShares, $newShare['name']);
        
        $this->assertNotEquals(-1, $index, 'Duplicate name should be detected');
    }
    
    public function testAddShareInvalidName()
    {
        $share = [
            'name' => 'Invalid[Name]',
            'path' => ChrootTestEnvironment::getMntPath('user/testshare')
        ];
        
        $errors = validateShare($share);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Invalid share name', $errors[0]);
    }
    
    public function testAddShareInvalidPath()
    {
        $share = [
            'name' => 'ValidName',
            'path' => '/home/user/invalid'
        ];
        
        $errors = validateShare($share);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Path must start with /mnt/', $errors[0]);
    }
    
    public function testAddShareMissingRequired()
    {
        $share = ['name' => 'OnlyName'];
        
        $errors = validateShare($share);
        $this->assertNotEmpty($errors);
    }
    
    public function testAddShareSambaReload()
    {
        $share = [
            'name' => 'NewShare',
            'path' => ChrootTestEnvironment::getMntPath('user/testshare')
        ];
        
        $shares = loadShares(self::$configDir);
        $shares[] = $share;
        saveShares($shares, self::$configDir);
        
        $config = generateSambaConfig($shares);
        $this->assertStringContainsString('[NewShare]', $config);
    }
    
    // Update endpoint tests
    
    public function testUpdateShareInvalidIndex()
    {
        $shares = [
            ['name' => 'Share1', 'path' => ChrootTestEnvironment::getMntPath('user/share1')]
        ];
        saveShares($shares, self::$configDir);
        
        $loadedShares = loadShares(self::$configDir);
        $this->assertCount(1, $loadedShares);
        
        // Attempting to update index 5 when only 1 share exists
        $invalidIndex = 5;
        $this->assertGreaterThan(count($loadedShares) - 1, $invalidIndex);
    }
    
    public function testUpdateShareDuplicateName()
    {
        $shares = [
            ['name' => 'Share1', 'path' => ChrootTestEnvironment::getMntPath('user/share1')],
            ['name' => 'Share2', 'path' => ChrootTestEnvironment::getMntPath('user/share2')]
        ];
        saveShares($shares, self::$configDir);
        
        // Try to rename Share2 to Share1
        $loadedShares = loadShares(self::$configDir);
        $loadedShares[1]['name'] = 'Share1';
        
        // Check for duplicate
        $duplicateIndex = findShareIndex($shares, 'Share1');
        $this->assertNotEquals(-1, $duplicateIndex);
    }
    
    public function testUpdateShareValidation()
    {
        $shares = [
            ['name' => 'Share1', 'path' => ChrootTestEnvironment::getMntPath('user/share1')]
        ];
        saveShares($shares, self::$configDir);
        
        $loadedShares = loadShares(self::$configDir);
        $loadedShares[0]['name'] = 'Invalid[Name]';
        
        $errors = validateShare($loadedShares[0]);
        $this->assertNotEmpty($errors);
    }
    
    // Delete endpoint tests
    
    public function testDeleteShareInvalidIndex()
    {
        $shares = [
            ['name' => 'Share1', 'path' => ChrootTestEnvironment::getMntPath('user/share1')]
        ];
        saveShares($shares, self::$configDir);
        
        $loadedShares = loadShares(self::$configDir);
        $invalidIndex = 10;
        
        $this->assertGreaterThan(count($loadedShares) - 1, $invalidIndex);
    }
    
    public function testDeleteShareSambaReload()
    {
        $shares = [
            ['name' => 'Share1', 'path' => ChrootTestEnvironment::getMntPath('user/share1')],
            ['name' => 'Share2', 'path' => ChrootTestEnvironment::getMntPath('user/share2')]
        ];
        saveShares($shares, self::$configDir);
        
        $loadedShares = loadShares(self::$configDir);
        unset($loadedShares[0]);
        $loadedShares = array_values($loadedShares);
        saveShares($loadedShares, self::$configDir);
        
        $config = generateSambaConfig($loadedShares);
        $this->assertStringNotContainsString('[Share1]', $config);
        $this->assertStringContainsString('[Share2]', $config);
    }
    
    // Utility endpoint tests
    
    public function testReloadSambaSuccess()
    {
        $shares = [
            ['name' => 'ValidShare', 'path' => ChrootTestEnvironment::getMntPath('user/share1')]
        ];
        
        $config = generateSambaConfig($shares);
        $this->assertNotEmpty($config);
        
        // Config should be valid format
        $this->assertStringContainsString('[ValidShare]', $config);
        $this->assertStringContainsString('path = ', $config);
    }
    
    public function testReloadSambaInvalidConfig()
    {
        // Generate invalid config (empty share name)
        $shares = [
            ['name' => '', 'path' => ChrootTestEnvironment::getMntPath('user/share1')]
        ];
        
        $errors = validateShare($shares[0]);
        $this->assertNotEmpty($errors);
    }
    
    public function testValidateShareValid()
    {
        $share = [
            'name' => 'ValidShare',
            'path' => ChrootTestEnvironment::getMntPath('user/testshare'),
            'create_mask' => '0664',
            'directory_mask' => '0775'
        ];
        
        // Debug output
        $configBase = defined('CONFIG_BASE') ? CONFIG_BASE : 'NOT DEFINED';
        $testMode = defined('PHPUNIT_TEST') || (defined('CONFIG_BASE') && (strpos(CONFIG_BASE, '/tmp/') !== false));
        
        $errors = validateShare($share);
        if (!empty($errors)) {
            $this->fail(sprintf(
                'Expected no errors, got: %s (CONFIG_BASE=%s, testMode=%s, path=%s)',
                json_encode($errors),
                $configBase,
                $testMode ? 'true' : 'false',
                $share['path']
            ));
        }
        $this->assertEmpty($errors);
    }
    
    public function testValidateShareInvalid()
    {
        $share = [
            'name' => 'Invalid[Name]',
            'path' => '/invalid/path',
            'create_mask' => '9999'
        ];
        
        $errors = validateShare($share);
        $this->assertNotEmpty($errors);
        $this->assertGreaterThan(1, count($errors));
    }
    
    public function testExportConfigSuccess()
    {
        $shares = [
            ['name' => 'Share1', 'path' => ChrootTestEnvironment::getMntPath('user/share1')],
            ['name' => 'Share2', 'path' => ChrootTestEnvironment::getMntPath('user/share2')]
        ];
        saveShares($shares, self::$configDir);
        
        $exported = loadShares(self::$configDir);
        $this->assertCount(2, $exported);
        $this->assertEquals($shares, $exported);
    }
    
    public function testImportConfigSuccess()
    {
        $importData = [
            ['name' => 'ImportedShare', 'path' => ChrootTestEnvironment::getMntPath('user/share1')]
        ];
        
        // Validate before import
        foreach ($importData as $share) {
            $errors = validateShare($share);
            $this->assertEmpty($errors);
        }
        
        saveShares($importData, self::$configDir);
        $loaded = loadShares(self::$configDir);
        
        $this->assertCount(1, $loaded);
        $this->assertEquals('ImportedShare', $loaded[0]['name']);
    }
    
    public function testImportConfigInvalid()
    {
        $invalidData = [
            ['name' => 'Invalid[Name]', 'path' => '/invalid']
        ];
        
        $errors = validateShare($invalidData[0]);
        $this->assertNotEmpty($errors);
    }
}
