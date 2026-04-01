<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';
require_once __DIR__ . '/../helpers/WebDriverManager.php';

class UserGroupBrowserTest extends TestCase
{
    private static $driver;
    private static $harness;
    
    public static function setUpBeforeClass(): void
    {
        self::$harness = UnraidTestHarness::setup(8888);
        self::$driver = WebDriverManager::createDriver();
    }
    
    public static function tearDownAfterClass(): void
    {
        if (self::$driver) {
            self::$driver->quit();
        }
        if (self::$harness) {
            UnraidTestHarness::teardown(self::$harness);
        }
    }
    
    public function testUserBrowserRendersOnEditPage(): void
    {
        self::$driver->get(self::$harness['url'] . '/Settings/SMBShares/Edit');
        
        // Wait for permission manager to load
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::className('permission-manager')
            )
        );
        
        $permManager = self::$driver->findElement(WebDriverBy::className('permission-manager'));
        $this->assertNotNull($permManager);
        
        // Check for read and write sections
        $sections = $permManager->findElements(WebDriverBy::className('permission-section'));
        $this->assertCount(2, $sections);
    }
    
    public function testUserBrowserHasSearchBox(): void
    {
        self::$driver->get(self::$harness['url'] . '/Settings/SMBShares/Edit');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::className('user-browser-search')
            )
        );
        
        $searchBoxes = self::$driver->findElements(WebDriverBy::cssSelector('.user-browser-search input'));
        $this->assertGreaterThanOrEqual(2, count($searchBoxes)); // Read and write browsers
    }
    
    public function testUserBrowserDisplaysUsers(): void
    {
        self::$driver->get(self::$harness['url'] . '/Settings/SMBShares/Edit');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::className('user-item')
            )
        );
        
        $userItems = self::$driver->findElements(WebDriverBy::className('user-item'));
        $this->assertGreaterThan(0, count($userItems));
    }
    
    public function testUserSelectionUpdatesHiddenField(): void
    {
        self::$driver->get(self::$harness['url'] . '/Settings/SMBShares/Edit');
        
        // Wait for user browser to load
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::className('user-item')
            )
        );
        
        // Click first user checkbox in read browser
        $firstCheckbox = self::$driver->findElement(
            WebDriverBy::cssSelector('#readUserBrowser .user-item input[type="checkbox"]')
        );
        $firstCheckbox->click();
        
        // Wait a moment for onChange to fire
        usleep(500000);
        
        // Check hidden field was updated
        $hiddenField = self::$driver->findElement(WebDriverBy::id('permissionsData'));
        $value = $hiddenField->getAttribute('value');
        
        $this->assertNotEmpty($value);
        $data = json_decode($value, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('read', $data);
        $this->assertArrayHasKey('write', $data);
    }
    
    public function testSearchFilterWorks(): void
    {
        self::$driver->get(self::$harness['url'] . '/Settings/SMBShares/Edit');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::className('user-item')
            )
        );
        
        // Get initial count
        $initialCount = count(self::$driver->findElements(
            WebDriverBy::cssSelector('#readUserBrowser .user-item:not([style*="display: none"])')
        ));
        
        // Type in search box
        $searchBox = self::$driver->findElement(
            WebDriverBy::cssSelector('#readUserBrowser .user-search')
        );
        $searchBox->sendKeys('root');
        
        // Wait for filter to apply
        usleep(500000);
        
        // Get filtered count
        $filteredCount = count(self::$driver->findElements(
            WebDriverBy::cssSelector('#readUserBrowser .user-item:not([style*="display: none"])')
        ));
        
        $this->assertLessThanOrEqual($initialCount, $filteredCount);
    }
    
    public function testGroupsHaveAtPrefix(): void
    {
        self::$driver->get(self::$harness['url'] . '/Settings/SMBShares/Edit');
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::className('user-item')
            )
        );
        
        // Find group items (checkboxes with @ in value)
        $groupCheckboxes = self::$driver->findElements(
            WebDriverBy::cssSelector('.user-item input[value^="@"]')
        );
        
        $this->assertGreaterThan(0, count($groupCheckboxes));
        
        // Verify @ prefix in displayed text
        foreach ($groupCheckboxes as $checkbox) {
            $label = $checkbox->findElement(WebDriverBy::xpath('..'));
            $text = $label->getText();
            $this->assertStringStartsWith('@', $text);
        }
    }
}
