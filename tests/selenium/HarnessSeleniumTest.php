<?php
use PHPUnit\Framework\TestCase;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';

/**
 * Selenium Tests with Unraid Test Harness
 * 
 * Tests plugin UI in isolated environment with auth bypass
 */
class HarnessSeleniumTest extends TestCase
{
    private static $driver;
    private static $harness;
    private static $baseUrl;
    private static $screenshotDir;
    
    public static function setUpBeforeClass(): void
    {
        // Setup test harness
        self::$harness = UnraidTestHarness::setup(8888);
        self::$baseUrl = self::$harness['url'];
        
        // Create screenshot directory
        self::$screenshotDir = __DIR__ . '/../../screenshots';
        if (!is_dir(self::$screenshotDir)) {
            mkdir(self::$screenshotDir, 0755, true);
        }
        
        // Setup Chrome driver
        $options = new ChromeOptions();
        $options->addArguments([
            '--headless=new',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--window-size=1920,1080'
        ]);
        
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        
        self::$driver = RemoteWebDriver::create(
            'http://localhost:9515',
            $capabilities,
            30000,
            30000
        );
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
        $filename = self::$screenshotDir . '/' . $name . '.png';
        self::$driver->takeScreenshot($filename);
        echo "\nScreenshot saved: $filename\n";
    }
    
    public function testPageLoadsWithoutAuth()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Wait for page load
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::tagName('body')
            )
        );
        
        $this->takeScreenshot('01-page-load');
        
        // Verify no redirect to login
        $currentUrl = self::$driver->getCurrentURL();
        $this->assertStringContainsString('SMBShares', $currentUrl);
    }
    
    public function testCSRFTokenPresent()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        $csrfToken = self::$driver->executeScript(
            'return typeof csrf_token !== "undefined" ? csrf_token : null;'
        );
        
        $this->assertNotNull($csrfToken);
        $this->assertNotEmpty($csrfToken);
    }
    
    public function testFormRendersCorrectly()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::id('addShareForm')
            )
        );
        
        $this->takeScreenshot('02-form-rendered');
        
        // Check form fields exist
        $nameField = self::$driver->findElement(WebDriverBy::name('name'));
        $pathField = self::$driver->findElement(WebDriverBy::name('path'));
        
        $this->assertNotNull($nameField);
        $this->assertNotNull($pathField);
    }
    
    public function testClientSideValidation()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Try invalid share name
        $nameField = self::$driver->findElement(WebDriverBy::name('name'));
        $nameField->sendKeys('invalid name!');
        
        $this->takeScreenshot('03-invalid-input');
        
        $isInvalid = self::$driver->executeScript(
            'return $("input[name=name]")[0].validity.patternMismatch;'
        );
        
        $this->assertTrue($isInvalid);
    }
    
    public function testSharesTableVisible()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::id('shares-table')
            )
        );
        
        $this->takeScreenshot('04-shares-table');
        
        $table = self::$driver->findElement(WebDriverBy::id('shares-table'));
        $this->assertNotNull($table);
    }
}
