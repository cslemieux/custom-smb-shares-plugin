<?php
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';
require_once __DIR__ . '/E2ETestBase.php';

/**
 * E2E Test for User Access Apply Button Bug
 * 
 * Bug: When editing permissions (SMB User Access), modifying any access 
 * for the users will not enable the "Apply" Button
 */
class UserAccessApplyButtonTest extends E2ETestBase
{
    protected static RemoteWebDriver $driver;
    protected static array $sharedHarness;
    protected array $harness;
    protected string $baseUrl;
    
    public static function setUpBeforeClass(): void
    {
        // Create config with a share that has secure mode
        $config = [
            'shares' => [
                [
                    'name' => 'SecureShare',
                    'path' => '/mnt/user/secure',
                    'enabled' => true,
                    'security' => 'secure',
                    'user_access' => json_encode(['testuser' => 'read-only'])
                ]
            ],
            'users' => [
                ['name' => 'testuser'],
                ['name' => 'admin']
            ]
        ];
        
        $configPath = __DIR__ . '/../configs/UserAccessApplyButtonTest.json';
        file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT));
        
        self::$sharedHarness = UnraidTestHarness::setup($config);
        
        $options = new \Facebook\WebDriver\Chrome\ChromeOptions();
        $options->addArguments(['--headless=new', '--window-size=1920,1080']);
        
        self::$driver = RemoteWebDriver::create(
            'http://localhost:9515',
            \Facebook\WebDriver\Remote\DesiredCapabilities::chrome()
                ->setCapability(\Facebook\WebDriver\Chrome\ChromeOptions::CAPABILITY, $options)
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
    
    /**
     * Test that changing user access enables the Apply button
     */
    public function testUserAccessChangeEnablesApplyButton()
    {
        // Navigate to edit page for SecureShare
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=SecureShare');
        
        // Wait for page to load
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('form'))
        );
        
        // Wait for user access section to load (it's loaded via AJAX)
        sleep(2);
        
        // Debug: Check if Apply button exists and its state
        $applyButton = self::$driver->findElement(WebDriverBy::cssSelector('input[type="submit"]'));
        $isDisabled = $applyButton->getAttribute('disabled');
        
        echo "\n=== DEBUG INFO ===\n";
        echo "Apply button disabled attribute: " . var_export($isDisabled, true) . "\n";
        echo "Apply button value: " . $applyButton->getAttribute('value') . "\n";
        
        // Check if user access section is visible
        $userAccessVisible = self::$driver->executeScript(
            'return $("#user-access-section").is(":visible");'
        );
        echo "User access section visible: " . var_export($userAccessVisible, true) . "\n";
        
        // Check if user access dropdowns exist
        $userDropdowns = self::$driver->executeScript(
            'return $("select[name^=\'access_\']").length;'
        );
        echo "User access dropdowns count: " . $userDropdowns . "\n";
        
        // Get the HTML of user access section
        $userAccessHtml = self::$driver->executeScript(
            'return $("#userAccessList").html();'
        );
        echo "User access HTML: " . substr($userAccessHtml, 0, 500) . "\n";
        
        // Check form's onchange attribute
        $formOnchange = self::$driver->executeScript(
            'return $("form").attr("onchange");'
        );
        echo "Form onchange: " . var_export($formOnchange, true) . "\n";
        
        // Verify Apply button starts disabled (in edit mode)
        $this->assertEquals('true', $isDisabled, 'Apply button should start disabled in edit mode');
        
        // If there are user dropdowns, change one
        if ($userDropdowns > 0) {
            // Change the first user's access
            self::$driver->executeScript(
                'var $select = $("select[name^=\'access_\']").first();
                 $select.val("read-write").trigger("change");'
            );
            
            sleep(1);
            
            // Check if Apply button is now enabled
            $isDisabledAfter = self::$driver->executeScript(
                'return $("input[type=submit]").prop("disabled");'
            );
            echo "Apply button disabled after change: " . var_export($isDisabledAfter, true) . "\n";
            
            $this->assertFalse($isDisabledAfter, 'Apply button should be enabled after changing user access');
        } else {
            $this->fail('No user access dropdowns found');
        }
    }
    
    /**
     * Test that the form change event fires correctly
     */
    public function testFormChangeEventFires()
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBSharesUpdate?name=SecureShare');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('form'))
        );
        
        sleep(2);
        
        // Add a listener to track form change events
        self::$driver->executeScript(
            'window.formChangeCount = 0;
             $("form").on("change", function() { window.formChangeCount++; });'
        );
        
        // Change a standard field (Export dropdown) - this should work
        self::$driver->executeScript(
            '$("select[name=\'export\']").val("eh").trigger("change");'
        );
        
        sleep(1);
        
        $changeCountAfterExport = self::$driver->executeScript('return window.formChangeCount;');
        echo "\nForm change count after Export change: " . $changeCountAfterExport . "\n";
        
        // Reset counter
        self::$driver->executeScript('window.formChangeCount = 0;');
        
        // Now change user access
        self::$driver->executeScript(
            'var $select = $("select[name^=\'access_\']").first();
             if ($select.length) {
                 $select.val("read-write").trigger("change");
             }'
        );
        
        sleep(1);
        
        $changeCountAfterUserAccess = self::$driver->executeScript('return window.formChangeCount;');
        echo "Form change count after User Access change: " . $changeCountAfterUserAccess . "\n";
        
        // The form change should have fired
        $this->assertGreaterThan(0, $changeCountAfterUserAccess, 
            'Form change event should fire when user access changes');
    }
}
