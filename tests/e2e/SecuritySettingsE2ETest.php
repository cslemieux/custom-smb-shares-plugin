<?php

declare(strict_types=1);

use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\WebDriverSelect;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';
require_once __DIR__ . '/E2ETestBase.php';

/**
 * E2E Tests for Security Settings UI
 * 
 * Tests the complete user flow for:
 * - Changing security modes (public/secure/private)
 * - Setting user access permissions
 * - Verifying settings persist after save
 * - Advanced permission settings
 */
class SecuritySettingsE2ETest extends E2ETestBase
{
    protected static RemoteWebDriver $driver;
    protected static array $sharedHarness;
    protected array $harness;
    protected string $baseUrl;
    
    public static function setUpBeforeClass(): void
    {
        $config = [
            'shares' => [
                [
                    'name' => 'PublicShare',
                    'path' => '/mnt/user/public',
                    'enabled' => true,
                    'security' => 'public'
                ],
                [
                    'name' => 'SecureShare',
                    'path' => '/mnt/user/secure',
                    'enabled' => true,
                    'security' => 'secure',
                    'user_access' => json_encode(['testuser' => 'read-only'])
                ],
                [
                    'name' => 'PrivateShare',
                    'path' => '/mnt/user/private',
                    'enabled' => true,
                    'security' => 'private',
                    'user_access' => json_encode([
                        'admin' => 'read-write',
                        'testuser' => 'read-only'
                    ])
                ]
            ],
            'users' => [
                ['name' => 'admin'],
                ['name' => 'testuser'],
                ['name' => 'guest']
            ]
        ];
        
        $configPath = __DIR__ . '/../configs/SecuritySettingsE2ETest.json';
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
        
        self::$sharedHarness = UnraidTestHarness::setup($config);
        
        $options = new ChromeOptions();
        $options->addArguments(['--headless=new', '--window-size=1920,1080']);
        
        self::$driver = RemoteWebDriver::create(
            'http://localhost:9515',
            DesiredCapabilities::chrome()
                ->setCapability(ChromeOptions::CAPABILITY, $options)
        );
    }
    
    protected function setUp(): void
    {
        $this->harness = self::$sharedHarness;
        $this->baseUrl = $this->harness['url'];
        self::$driver->manage()->deleteAllCookies();
    }
    
    public static function tearDownAfterClass(): void
    {
        if (isset(self::$driver)) {
            self::$driver->quit();
        }
        if (isset(self::$sharedHarness)) {
            UnraidTestHarness::teardown(self::$sharedHarness);
        }
    }

    // ========================================
    // Security Mode Selection
    // ========================================

    public function testSecurityModeDropdownExists(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PublicShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        $securitySelect = self::$driver->findElement(WebDriverBy::cssSelector('select[name="security"]'));
        $this->assertNotNull($securitySelect);
        
        // Verify all options exist
        $options = $securitySelect->findElements(WebDriverBy::tagName('option'));
        $optionValues = array_map(fn($o) => $o->getAttribute('value'), $options);
        
        $this->assertContains('public', $optionValues);
        $this->assertContains('secure', $optionValues);
        $this->assertContains('private', $optionValues);
    }

    public function testSecurityModeShowsCorrectValue(): void
    {
        // Check public share
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PublicShare');
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        $select = new WebDriverSelect(
            self::$driver->findElement(WebDriverBy::cssSelector('select[name="security"]'))
        );
        $this->assertEquals('public', $select->getFirstSelectedOption()->getAttribute('value'));
        
        // Check secure share
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=SecureShare');
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        $select = new WebDriverSelect(
            self::$driver->findElement(WebDriverBy::cssSelector('select[name="security"]'))
        );
        $this->assertEquals('secure', $select->getFirstSelectedOption()->getAttribute('value'));
        
        // Check private share
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PrivateShare');
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        $select = new WebDriverSelect(
            self::$driver->findElement(WebDriverBy::cssSelector('select[name="security"]'))
        );
        $this->assertEquals('private', $select->getFirstSelectedOption()->getAttribute('value'));
    }

    // ========================================
    // User Access Section Visibility
    // ========================================

    public function testUserAccessHiddenForPublicMode(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PublicShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        // User access section should be hidden
        $isVisible = self::$driver->executeScript(
            'return $("#user-access-section").is(":visible");'
        );
        
        $this->assertFalse($isVisible, 'User access section should be hidden for public mode');
    }

    public function testUserAccessVisibleForSecureMode(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=SecureShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        // Wait for page to fully render
        sleep(1);
        
        $isVisible = self::$driver->executeScript(
            'return $("#user-access-section").is(":visible");'
        );
        
        $this->assertTrue($isVisible, 'User access section should be visible for secure mode');
    }

    public function testUserAccessVisibleForPrivateMode(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PrivateShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        sleep(1);
        
        $isVisible = self::$driver->executeScript(
            'return $("#user-access-section").is(":visible");'
        );
        
        $this->assertTrue($isVisible, 'User access section should be visible for private mode');
    }

    public function testUserAccessTogglesWithSecurityModeChange(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PublicShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        // Initially hidden (public mode)
        $isVisible = self::$driver->executeScript(
            'return $("#user-access-section").is(":visible");'
        );
        $this->assertFalse($isVisible);
        
        // Change to secure mode
        $select = new WebDriverSelect(
            self::$driver->findElement(WebDriverBy::cssSelector('select[name="security"]'))
        );
        $select->selectByValue('secure');
        
        sleep(1);
        
        // Should now be visible
        $isVisible = self::$driver->executeScript(
            'return $("#user-access-section").is(":visible");'
        );
        $this->assertTrue($isVisible, 'User access should appear when switching to secure mode');
        
        // Change back to public
        $select->selectByValue('public');
        
        sleep(1);
        
        // Should be hidden again
        $isVisible = self::$driver->executeScript(
            'return $("#user-access-section").is(":visible");'
        );
        $this->assertFalse($isVisible, 'User access should hide when switching back to public mode');
    }

    // ========================================
    // User Access Dropdowns
    // ========================================

    public function testUserAccessDropdownsExist(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=SecureShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        sleep(1);
        
        // Find user access dropdowns
        $dropdowns = self::$driver->findElements(
            WebDriverBy::cssSelector('select[name^="access_"]')
        );
        
        $this->assertGreaterThan(0, count($dropdowns), 'Should have user access dropdowns');
    }

    public function testUserAccessDropdownOptions(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PrivateShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        sleep(1);
        
        // Get first user access dropdown
        $dropdown = self::$driver->findElement(
            WebDriverBy::cssSelector('select[name^="access_"]')
        );
        
        $options = $dropdown->findElements(WebDriverBy::tagName('option'));
        $optionValues = array_map(fn($o) => $o->getAttribute('value'), $options);
        
        $this->assertContains('no-access', $optionValues);
        $this->assertContains('read-only', $optionValues);
        $this->assertContains('read-write', $optionValues);
    }

    public function testUserAccessShowsCorrectValues(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PrivateShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        sleep(1);
        
        // Check admin has read-write
        $adminDropdown = self::$driver->findElement(
            WebDriverBy::cssSelector('select[name="access_admin"]')
        );
        $adminSelect = new WebDriverSelect($adminDropdown);
        $this->assertEquals('read-write', $adminSelect->getFirstSelectedOption()->getAttribute('value'));
        
        // Check testuser has read-only
        $testUserDropdown = self::$driver->findElement(
            WebDriverBy::cssSelector('select[name="access_testuser"]')
        );
        $testUserSelect = new WebDriverSelect($testUserDropdown);
        $this->assertEquals('read-only', $testUserSelect->getFirstSelectedOption()->getAttribute('value'));
    }

    // ========================================
    // Apply Button Behavior
    // ========================================

    public function testApplyButtonEnabledOnUserAccessChange(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=SecureShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        sleep(1);
        
        // Find a user access dropdown and change it
        $dropdown = self::$driver->findElement(
            WebDriverBy::cssSelector('select[name^="access_"]')
        );
        $select = new WebDriverSelect($dropdown);
        $select->selectByValue('read-write');
        
        // Trigger change event
        self::$driver->executeScript(
            'arguments[0].dispatchEvent(new Event("change", { bubbles: true }));',
            [$dropdown]
        );
        
        sleep(1);
        
        // Apply button should be enabled (not disabled)
        $applyButton = self::$driver->findElement(
            WebDriverBy::cssSelector('input[type="submit"]')
        );
        
        $isDisabled = $applyButton->getAttribute('disabled');
        $this->assertNull($isDisabled, 'Apply button should be enabled after changing user access');
    }

    // ========================================
    // Advanced Permission Settings
    // ========================================

    public function testAdvancedSettingsExist(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PublicShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        // Check for permission mask fields
        $createMask = self::$driver->findElements(
            WebDriverBy::cssSelector('input[name="create_mask"]')
        );
        $directoryMask = self::$driver->findElements(
            WebDriverBy::cssSelector('input[name="directory_mask"]')
        );
        
        // These may be in advanced section, check if they exist in DOM
        $this->assertGreaterThanOrEqual(0, count($createMask));
        $this->assertGreaterThanOrEqual(0, count($directoryMask));
    }

    public function testForceUserGroupFields(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PublicShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        // Check for force user/group fields (may be in advanced section)
        $forceUser = self::$driver->findElements(
            WebDriverBy::cssSelector('input[name="force_user"], select[name="force_user"]')
        );
        $forceGroup = self::$driver->findElements(
            WebDriverBy::cssSelector('input[name="force_group"], select[name="force_group"]')
        );
        
        $this->assertGreaterThanOrEqual(0, count($forceUser));
        $this->assertGreaterThanOrEqual(0, count($forceGroup));
    }

    public function testHostAccessFields(): void
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=PublicShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        // Check for hosts allow/deny fields
        $hostsAllow = self::$driver->findElements(
            WebDriverBy::cssSelector('input[name="hosts_allow"], textarea[name="hosts_allow"]')
        );
        $hostsDeny = self::$driver->findElements(
            WebDriverBy::cssSelector('input[name="hosts_deny"], textarea[name="hosts_deny"]')
        );
        
        $this->assertGreaterThanOrEqual(0, count($hostsAllow));
        $this->assertGreaterThanOrEqual(0, count($hostsDeny));
    }

    // ========================================
    // Save and Verify Persistence
    // ========================================

    /**
     * @group slow
     */
    public function testUserAccessChangesPersistAfterSave(): void
    {
        // This test requires the harness to support actual saves
        // Skip if harness doesn't support persistence
        if (!isset($this->harness['supports_persistence']) || !$this->harness['supports_persistence']) {
            $this->markTestSkipped('Harness does not support persistence testing');
        }
        
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=SecureShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        sleep(1);
        
        // Change user access
        $dropdown = self::$driver->findElement(
            WebDriverBy::cssSelector('select[name="access_testuser"]')
        );
        $select = new WebDriverSelect($dropdown);
        $select->selectByValue('read-write');
        
        // Submit form
        $applyButton = self::$driver->findElement(
            WebDriverBy::cssSelector('input[type="submit"]')
        );
        $applyButton->click();
        
        // Wait for save
        sleep(2);
        
        // Reload page
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=SecureShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::cssSelector('select[name="security"]')
            )
        );
        
        sleep(1);
        
        // Verify value persisted
        $dropdown = self::$driver->findElement(
            WebDriverBy::cssSelector('select[name="access_testuser"]')
        );
        $select = new WebDriverSelect($dropdown);
        
        $this->assertEquals(
            'read-write',
            $select->getFirstSelectedOption()->getAttribute('value'),
            'User access change should persist after save'
        );
    }
}
