<?php
use PHPUnit\Framework\TestCase;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';

/**
 * Samba Mock Integration Tests
 * 
 * Tests plugin interactions with mocked Samba service
 */
class SambaMockTest extends TestCase
{
    private static $driver;
    private static $harness;
    private static $baseUrl;
    private static $screenshotDir;
    
    public static function setUpBeforeClass(): void
    {
        self::$harness = UnraidTestHarness::setup(8888);
        self::$baseUrl = self::$harness['url'];
        
        self::$screenshotDir = __DIR__ . '/../../screenshots';
        if (!is_dir(self::$screenshotDir)) {
            mkdir(self::$screenshotDir, 0755, true);
        }
        
        $options = new ChromeOptions();
        $options->addArguments(['--headless=new', '--no-sandbox', '--disable-dev-shm-usage', '--window-size=1920,1080']);
        
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        
        self::$driver = RemoteWebDriver::create('http://localhost:9515', $capabilities, 30000, 30000);
    }
    
    public static function tearDownAfterClass(): void
    {
        if (self::$driver) {
            self::$driver->quit();
        }
        UnraidTestHarness::teardown();
    }
    
    private function takeScreenshot($name)
    {
        $filename = self::$screenshotDir . '/samba-' . $name . '.png';
        self::$driver->takeScreenshot($filename);
    }
    
    public function testSambaStatusDisplayed()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('samba-status'))
        );
        
        $this->takeScreenshot('01-status-displayed');
        
        $status = self::$driver->findElement(WebDriverBy::id('samba-status'))->getText();
        $this->assertStringContainsString('running', strtolower($status));
    }
    
    public function testAddShareWritesConfig()
    {
        // Clear existing config
        SambaMock::writeConfig('');
        SambaMock::clearLog();
        
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Fill form
        $nameField = self::$driver->findElement(WebDriverBy::name('name'));
        $nameField->sendKeys('TestShare');
        
        $pathField = self::$driver->findElement(WebDriverBy::name('path'));
        $pathField->sendKeys('/mnt/user/test');
        
        $this->takeScreenshot('02-form-filled');
        
        // Submit
        $submitButton = self::$driver->findElement(WebDriverBy::cssSelector('input[type="submit"]'));
        $submitButton->click();
        
        // Wait for success
        sleep(2);
        
        $this->takeScreenshot('03-share-added');
        
        // Verify config was written
        $config = SambaMock::readConfig();
        $this->assertStringContainsString('[TestShare]', $config);
        $this->assertStringContainsString('path = /mnt/user/test', $config);
        
        // Verify logged
        $log = SambaMock::getLog();
        $this->assertStringContainsString('Config written', $log);
    }
    
    public function testReloadSambaAfterChange()
    {
        SambaMock::clearLog();
        
        // Trigger reload via plugin
        self::$driver->get(self::$baseUrl . '/plugins/custom.smb.shares/reload.php');
        
        sleep(1);
        
        // Check log
        $log = SambaMock::getLog();
        $this->assertStringContainsString('reload', strtolower($log));
    }
    
    public function testConfigValidation()
    {
        // Write valid config
        $validConfig = "[TestShare]\npath = /mnt/user/test\nbrowseable = yes\n";
        SambaMock::writeConfig($validConfig);
        
        $result = SambaMock::validateConfig();
        $this->assertTrue($result['valid']);
        $this->assertStringContainsString('[TestShare]', $result['output']);
    }
    
    public function testInvalidConfigDetected()
    {
        // Write invalid config (no shares)
        SambaMock::writeConfig("# Empty config\n");
        
        $result = SambaMock::validateConfig();
        $this->assertFalse($result['valid']);
    }
    
    public function testGetSharesFromConfig()
    {
        $config = "[Share1]\npath = /mnt/user/share1\n\n[Share2]\npath = /mnt/user/share2\n";
        SambaMock::writeConfig($config);
        
        $shares = SambaMock::getShares();
        $this->assertCount(2, $shares);
        $this->assertContains('Share1', $shares);
        $this->assertContains('Share2', $shares);
    }
    
    public function testSambaServiceControl()
    {
        // Test stop
        SambaMock::setStatus('stopped');
        $this->assertEquals('stopped', SambaMock::getStatus());
        
        // Test start
        SambaMock::setStatus('running');
        $this->assertEquals('running', SambaMock::getStatus());
    }
}
