<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';

/**
 * E2E tests for toggle switch and feature/security icons.
 * Tests actual browser behavior, not just API.
 */
class ToggleAndIconsUITest extends TestCase
{
    private static ?RemoteWebDriver $driver = null;
    private static ?array $harness = null;
    
    public static function setUpBeforeClass(): void
    {
        self::$harness = UnraidTestHarness::setup(8893);
        
        $options = new ChromeOptions();
        $options->addArguments([
            '--headless=new',
            '--window-size=1920,1080',
            '--no-sandbox',
            '--disable-dev-shm-usage'
        ]);
        
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        
        try {
            self::$driver = RemoteWebDriver::create(
                'http://localhost:9515',
                $capabilities,
                5000,
                5000
            );
        } catch (\Exception $e) {
            self::markTestSkipped('ChromeDriver not available: ' . $e->getMessage());
        }
    }
    
    public static function tearDownAfterClass(): void
    {
        if (self::$driver) {
            self::$driver->quit();
        }
        if (self::$harness) {
            UnraidTestHarness::teardown();
        }
    }
    
    protected function setUp(): void
    {
        if (!self::$driver) {
            $this->markTestSkipped('ChromeDriver not available');
        }
        
        // Create test shares with various settings
        $this->createTestShares();
    }
    
    private function createTestShares(): void
    {
        $configDir = self::$harness['harness_dir'] . '/boot/config';
        $sharesFile = $configDir . '/plugins/custom.smb.shares/shares.json';
        
        $testShares = [
            [
                'name' => 'EnabledShare',
                'path' => '/mnt/user/enabled',
                'comment' => 'Enabled share',
                'enabled' => true,
                'export' => 'e',
                'security' => 'public'
            ],
            [
                'name' => 'DisabledShare',
                'path' => '/mnt/user/disabled',
                'comment' => 'Disabled share',
                'enabled' => false,
                'export' => 'e',
                'security' => 'public'
            ],
            [
                'name' => 'MacShare',
                'path' => '/mnt/user/mac',
                'comment' => 'macOS share',
                'enabled' => true,
                'export' => 'e',
                'security' => 'public',
                'fruit' => 'yes'
            ],
            [
                'name' => 'TimeMachine',
                'path' => '/mnt/user/timemachine',
                'comment' => 'Time Machine backup',
                'enabled' => true,
                'export' => 'et',
                'security' => 'private'
            ],
            [
                'name' => 'HiddenShare',
                'path' => '/mnt/user/hidden',
                'comment' => 'Hidden share',
                'enabled' => true,
                'export' => 'eh',
                'security' => 'secure'
            ]
        ];
        
        file_put_contents($sharesFile, json_encode($testShares, JSON_PRETTY_PRINT));
    }
    
    /**
     * Test that toggle switches render correctly
     */
    public function testToggleSwitchesRender(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('.share-toggle')
            )
        );
        
        // Should have 5 toggle switches
        $toggles = self::$driver->findElements(WebDriverBy::cssSelector('.share-toggle'));
        $this->assertCount(5, $toggles, 'Should have 5 toggle switches');
    }
    
    /**
     * Test that enabled share has checked toggle
     */
    public function testEnabledShareHasCheckedToggle(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('.share-toggle[data-share="EnabledShare"]')
            )
        );
        
        $toggle = self::$driver->findElement(
            WebDriverBy::cssSelector('.share-toggle[data-share="EnabledShare"]')
        );
        
        $this->assertTrue($toggle->isSelected(), 'Enabled share toggle should be checked');
    }
    
    /**
     * Test that disabled share has unchecked toggle and dimmed row
     */
    public function testDisabledShareHasUncheckedToggleAndDimmedRow(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('.share-toggle[data-share="DisabledShare"]')
            )
        );
        
        $toggle = self::$driver->findElement(
            WebDriverBy::cssSelector('.share-toggle[data-share="DisabledShare"]')
        );
        
        $this->assertFalse($toggle->isSelected(), 'Disabled share toggle should be unchecked');
        
        // Check row has share-disabled class
        $row = self::$driver->findElement(
            WebDriverBy::xpath("//input[@data-share='DisabledShare']/ancestor::tr")
        );
        $classes = $row->getAttribute('class');
        $this->assertStringContainsString('share-disabled', $classes, 'Disabled share row should have share-disabled class');
    }
    
    /**
     * Test macOS icon appears for fruit-enabled share
     */
    public function testMacOSIconAppearsForFruitShare(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('table.custom-shares-table')
            )
        );
        
        // Find MacShare row - share name is in a span.exec
        $macRow = self::$driver->findElement(
            WebDriverBy::xpath("//span[contains(@class,'exec') and contains(text(),'MacShare')]/ancestor::tr")
        );
        
        $appleIcon = $macRow->findElements(WebDriverBy::cssSelector('.fa-apple'));
        $this->assertCount(1, $appleIcon, 'MacShare should have apple icon');
    }
    
    /**
     * Test Time Machine icons appear correctly
     */
    public function testTimeMachineIconsAppear(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('table.custom-shares-table')
            )
        );
        
        // Find TimeMachine row
        $tmRow = self::$driver->findElement(
            WebDriverBy::xpath("//span[contains(@class,'exec') and contains(text(),'TimeMachine')]/ancestor::tr")
        );
        
        // Should have apple icon (Time Machine enables fruit)
        $appleIcon = $tmRow->findElements(WebDriverBy::cssSelector('.fa-apple'));
        $this->assertGreaterThanOrEqual(1, count($appleIcon), 'TimeMachine should have apple icon');
        
        // Should have clock icon
        $clockIcon = $tmRow->findElements(WebDriverBy::cssSelector('.fa-clock-o'));
        $this->assertCount(1, $clockIcon, 'TimeMachine should have clock icon');
    }
    
    /**
     * Test hidden share icon appears
     */
    public function testHiddenShareIconAppears(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('table.custom-shares-table')
            )
        );
        
        // Find HiddenShare row
        $hiddenRow = self::$driver->findElement(
            WebDriverBy::xpath("//span[contains(@class,'exec') and contains(text(),'HiddenShare')]/ancestor::tr")
        );
        
        // Should have eye-slash icon
        $eyeSlashIcon = $hiddenRow->findElements(WebDriverBy::cssSelector('.fa-eye-slash'));
        $this->assertCount(1, $eyeSlashIcon, 'HiddenShare should have eye-slash icon');
    }
    
    /**
     * Test private security shows lock icon
     */
    public function testPrivateSecurityShowsLockIcon(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('table.custom-shares-table')
            )
        );
        
        // Find TimeMachine row (which has private security)
        $tmRow = self::$driver->findElement(
            WebDriverBy::xpath("//span[contains(@class,'exec') and contains(text(),'TimeMachine')]/ancestor::tr")
        );
        
        // Should have lock icon
        $lockIcon = $tmRow->findElements(WebDriverBy::cssSelector('.fa-lock'));
        $this->assertCount(1, $lockIcon, 'Private share should have lock icon');
        
        // Should show "Private" text
        $this->assertStringContainsString('Private', $tmRow->getText());
    }
    
    /**
     * Test secure security shows unlock-alt icon
     */
    public function testSecureSecurityShowsUnlockIcon(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('table.custom-shares-table')
            )
        );
        
        // Find HiddenShare row (which has secure security)
        $hiddenRow = self::$driver->findElement(
            WebDriverBy::xpath("//span[contains(@class,'exec') and contains(text(),'HiddenShare')]/ancestor::tr")
        );
        
        // Should have unlock-alt icon
        $unlockIcon = $hiddenRow->findElements(WebDriverBy::cssSelector('.fa-unlock-alt'));
        $this->assertCount(1, $unlockIcon, 'Secure share should have unlock-alt icon');
        
        // Should show "Secure" text
        $this->assertStringContainsString('Secure', $hiddenRow->getText());
    }
    
    /**
     * Test Clone button appears in Actions column
     */
    public function testCloneButtonAppearsInActions(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('table.custom-shares-table')
            )
        );
        
        // Find Clone links - use contains for onclick attribute
        $cloneLinks = self::$driver->findElements(
            WebDriverBy::xpath("//a[contains(@onclick,'cloneShare')]")
        );
        
        $this->assertCount(5, $cloneLinks, 'Should have 5 Clone buttons (one per share)');
    }
    
    /**
     * Test Clone button has correct icon
     */
    public function testCloneButtonHasCorrectIcon(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBShares');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('table.custom-shares-table')
            )
        );
        
        // Find first Clone link
        $cloneLink = self::$driver->findElement(
            WebDriverBy::xpath("//a[contains(@onclick,'cloneShare')]")
        );
        
        // Should have clone icon
        $cloneIcon = $cloneLink->findElements(WebDriverBy::cssSelector('.fa-clone'));
        $this->assertCount(1, $cloneIcon, 'Clone button should have clone icon');
    }
}
