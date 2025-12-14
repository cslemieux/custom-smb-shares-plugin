<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../harness/SambaMock.php';

/**
 * Samba Interaction Tests
 * 
 * Verifies plugin correctly interacts with Samba service
 */
class SambaInteractionTest extends TestCase
{
    private $configDir;
    
    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        $this->configDir = ChrootTestEnvironment::setup();
        
        if (!defined('CONFIG_BASE')) {
            define('CONFIG_BASE', $this->configDir);
        }
        
        // Initialize Samba mock with harness root (not CONFIG_BASE)
        SambaMock::init(ChrootTestEnvironment::getChrootDir());
        SambaMock::initScripts();  // Create mock scripts immediately for lib.php functions
        
        // Create test directories
        ChrootTestEnvironment::mkdir('user/testshare');
        
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';
    }
    
    public function testGenerateSambaConfigCreatesValidConfig()
    {
        $shares = [
            [
                'name' => 'TestShare',
                'path' => ChrootTestEnvironment::getMntPath('user/testshare'),
                'comment' => 'Test share',
                'security' => 'public'
            ]
        ];
        
        $config = generateSambaConfig($shares);
        
        // Write to mock
        SambaMock::writeConfig($config);
        
        // Validate
        $result = SambaMock::validateConfig();
        $this->assertTrue($result['valid'], 'Generated config should be valid');
        $this->assertStringContainsString('[TestShare]', $result['output']);
    }
    
    public function testMultipleSharesInConfig()
    {
        $shares = [
            ['name' => 'Share1', 'path' => ChrootTestEnvironment::getMntPath('user/share1')],
            ['name' => 'Share2', 'path' => ChrootTestEnvironment::getMntPath('user/share2')],
            ['name' => 'Share3', 'path' => ChrootTestEnvironment::getMntPath('user/share3')]
        ];
        
        $config = generateSambaConfig($shares);
        SambaMock::writeConfig($config);
        
        $foundShares = SambaMock::getShares();
        $this->assertCount(3, $foundShares);
        $this->assertContains('Share1', $foundShares);
        $this->assertContains('Share2', $foundShares);
        $this->assertContains('Share3', $foundShares);
    }
    
    public function testConfigIncludesAllShareProperties()
    {
        $shares = [
            [
                'name' => 'FullShare',
                'path' => ChrootTestEnvironment::getMntPath('user/full'),
                'comment' => 'Full featured share',
                'security' => 'private',
                'user_access' => json_encode(['user1' => 'read-only', 'user2' => 'read-write'])
            ]
        ];
        
        $config = generateSambaConfig($shares);
        
        $this->assertStringContainsString('[FullShare]', $config);
        $this->assertStringContainsString('comment = Full featured share', $config);
        $this->assertStringContainsString('browseable = yes', $config);
        $this->assertStringContainsString('guest ok = no', $config);
        $this->assertStringContainsString('valid users = user1 user2', $config);
        $this->assertStringContainsString('write list = user2', $config);
        $this->assertStringContainsString('force user = nobody', $config);
        $this->assertStringContainsString('force group = users', $config);
        $this->assertStringContainsString('create mask = 0664', $config);
        $this->assertStringContainsString('directory mask = 0775', $config);
    }
    
    public function testReloadSambaFunction()
    {
        SambaMock::clearLog();
        SambaMock::setStatus('running');
        
        // Create valid config first
        $config = "[TestShare]\npath = /mnt/user/test\n";
        SambaMock::writeConfig($config);
        
        // Note: reloadSamba() uses hardcoded paths, so we test the mock directly
        $result = SambaMock::reload();
        
        $this->assertTrue($result['success'], 'Reload should succeed when Samba is running');
        
        $log = SambaMock::getLog();
        $this->assertStringContainsString('reload', strtolower($log));
    }
    
    public function testReloadFailsWhenSambaStopped()
    {
        SambaMock::setStatus('stopped');
        
        $result = reloadSamba();
        
        $this->assertFalse($result['success'], 'Reload should fail when Samba is stopped');
    }
    
    public function testEmptySharesGeneratesEmptyConfig()
    {
        $config = generateSambaConfig([]);
        
        $this->assertEmpty(trim($config), 'Empty shares should generate empty config');
    }
    
    public function testShareWithSpecialCharactersInComment()
    {
        $shares = [
            [
                'name' => 'SpecialShare',
                'path' => ChrootTestEnvironment::getMntPath('user/special'),
                'comment' => 'Share with "quotes" and \'apostrophes\''
            ]
        ];
        
        $config = generateSambaConfig($shares);
        SambaMock::writeConfig($config);
        
        $result = SambaMock::validateConfig();
        $this->assertTrue($result['valid'], 'Config with special chars should be valid');
    }
    
    public function testConfigWithSecureShare()
    {
        $shares = [
            [
                'name' => 'SecureShare',
                'path' => ChrootTestEnvironment::getMntPath('user/secure'),
                'security' => 'secure',
                'user_access' => json_encode(['admin' => 'read-write'])
            ]
        ];
        
        $config = generateSambaConfig($shares);
        
        // Secure mode: guest read, specific users write
        $this->assertStringContainsString('guest ok = yes', $config);
        $this->assertStringContainsString('read only = yes', $config);
        $this->assertStringContainsString('write list = admin', $config);
        
        SambaMock::writeConfig($config);
        $shareConfig = SambaMock::getShareConfig('SecureShare');
        $this->assertStringContainsString('read only = yes', $shareConfig);
    }
    
    public function testConfigWithHiddenShare()
    {
        $shares = [
            [
                'name' => 'HiddenShare',
                'path' => ChrootTestEnvironment::getMntPath('user/hidden'),
                'export' => 'eh'  // Yes (hidden)
            ]
        ];
        
        $config = generateSambaConfig($shares);
        
        $this->assertStringContainsString('browseable = no', $config);
    }

    public function testConfigWithTimeMachineShare()
    {
        $shares = [
            [
                'name' => 'TimeMachine',
                'path' => ChrootTestEnvironment::getMntPath('user/tm'),
                'export' => 'et',
                'volsizelimit' => '500000'
            ]
        ];
        
        $config = generateSambaConfig($shares);
        
        $this->assertStringContainsString('vfs objects = catia fruit streams_xattr', $config);
        $this->assertStringContainsString('fruit:time machine = yes', $config);
        $this->assertStringContainsString('fruit:time machine max size = 500000M', $config);
        $this->assertStringContainsString('browseable = yes', $config);
    }

    public function testConfigWithNoExport()
    {
        $shares = [
            [
                'name' => 'DisabledShare',
                'path' => ChrootTestEnvironment::getMntPath('user/disabled'),
                'export' => '-'
            ]
        ];
        
        $config = generateSambaConfig($shares);
        
        // Share should not appear in config at all
        $this->assertStringNotContainsString('DisabledShare', $config);
    }
    
    public function testSambaStatusCheck()
    {
        SambaMock::setStatus('running');
        $this->assertEquals('running', SambaMock::getStatus());
        
        SambaMock::setStatus('stopped');
        $this->assertEquals('stopped', SambaMock::getStatus());
    }
    
    public function testConfigPersistence()
    {
        $shares = [
            ['name' => 'PersistentShare', 'path' => ChrootTestEnvironment::getMntPath('user/persist')]
        ];
        
        $config = generateSambaConfig($shares);
        SambaMock::writeConfig($config);
        
        // Read back
        $readConfig = SambaMock::readConfig();
        $this->assertEquals($config, $readConfig);
    }
    
    public function testInvalidConfigDetection()
    {
        // Write invalid config (malformed syntax - unclosed bracket)
        SambaMock::writeConfig("[InvalidShare\npath = /mnt/user/test\n");
        
        $result = SambaMock::validateConfig();
        $this->assertFalse($result['valid'], 'Invalid config should be detected');
        $this->assertNotEquals(0, $result['exit_code']);
    }

    public function testConfigWithCaseSensitiveForced()
    {
        $shares = [
            [
                'name' => 'LowerCase',
                'path' => ChrootTestEnvironment::getMntPath('user/lower'),
                'case_sensitive' => 'forced'
            ]
        ];
        
        $config = generateSambaConfig($shares);
        
        $this->assertStringContainsString('case sensitive = yes', $config);
        $this->assertStringContainsString('default case = lower', $config);
        $this->assertStringContainsString('preserve case = no', $config);
    }
}
