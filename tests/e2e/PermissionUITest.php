<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

require_once 'tests/harness/UnraidTestHarness.php';
require_once 'tests/e2e/E2ETestBase.php';

class PermissionUITest extends E2ETestBase
{
    public function testPermissionTableRendersInModal()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares/Edit');
        
        $this->waitForModal();
        
        // Check permission manager container exists
        $permManager = self::$driver->findElement(WebDriverBy::id('permissionManager'));
        $this->assertNotNull($permManager);
        
        // Check search input exists
        $searchInput = $permManager->findElement(WebDriverBy::id('userSearch'));
        $this->assertNotNull($searchInput);
        
        // Check permission table exists
        $table = $permManager->findElement(WebDriverBy::cssSelector('.permission-table'));
        $this->assertNotNull($table);
    }

    public function testUserSearchTriggersAutocomplete()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares/Edit');
        $this->waitForModal();
        
        $searchInput = self::$driver->findElement(WebDriverBy::id('userSearch'));
        $searchInput->sendKeys('root');
        
        // Wait for autocomplete
        usleep(500000); // 500ms debounce + processing
        
        $results = self::$driver->findElement(WebDriverBy::id('searchResults'));
        $this->assertTrue($results->isDisplayed());
    }

    public function testAddUserToPermissionTable()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares/Edit');
        $this->waitForModal();
        
        // Search for user
        $searchInput = self::$driver->findElement(WebDriverBy::id('userSearch'));
        $searchInput->sendKeys('root');
        usleep(500000);
        
        // Click first result
        $firstResult = self::$driver->findElement(
            WebDriverBy::cssSelector('#searchResults .search-result-item')
        );
        $firstResult->click();
        
        // Verify added to table
        $tableRows = self::$driver->findElements(
            WebDriverBy::cssSelector('#permissionList tr')
        );
        $this->assertGreaterThan(0, count($tableRows));
    }

    public function testPermissionDropdownChanges()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares/Edit');
        $this->waitForModal();
        
        // Add user first
        $searchInput = self::$driver->findElement(WebDriverBy::id('userSearch'));
        $searchInput->sendKeys('root');
        usleep(500000);
        
        $firstResult = self::$driver->findElement(
            WebDriverBy::cssSelector('#searchResults .search-result-item')
        );
        $firstResult->click();
        
        // Change permission level
        $dropdown = self::$driver->findElement(
            WebDriverBy::cssSelector('#permissionList select')
        );
        $dropdown->sendKeys('Read Only');
        
        // Verify dropdown value changed
        $this->assertEquals('read', $dropdown->getAttribute('value'));
    }

    public function testRemoveUserFromTable()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares/Edit');
        $this->waitForModal();
        
        // Add user
        $searchInput = self::$driver->findElement(WebDriverBy::id('userSearch'));
        $searchInput->sendKeys('root');
        usleep(500000);
        
        $firstResult = self::$driver->findElement(
            WebDriverBy::cssSelector('#searchResults .search-result-item')
        );
        $firstResult->click();
        
        // Remove user
        $removeBtn = self::$driver->findElement(
            WebDriverBy::cssSelector('#permissionList .remove-user')
        );
        $removeBtn->click();
        
        // Verify removed
        $tableRows = self::$driver->findElements(
            WebDriverBy::cssSelector('#permissionList tr')
        );
        $this->assertCount(0, $tableRows);
    }

    public function testFormSubmissionWithPermissions()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares/Edit');
        $this->waitForModal();
        
        // Fill basic fields
        $nameField = self::$driver->findElement(WebDriverBy::name('name'));
        $nameField->sendKeys('PermTest');
        
        $pathField = self::$driver->findElement(WebDriverBy::name('path'));
        $pathField->sendKeys('/mnt/user/permtest');
        
        // Add user with read-only
        $searchInput = self::$driver->findElement(WebDriverBy::id('userSearch'));
        $searchInput->sendKeys('root');
        usleep(500000);
        
        $firstResult = self::$driver->findElement(
            WebDriverBy::cssSelector('#searchResults .search-result-item')
        );
        $firstResult->click();
        
        // Submit form
        $this->clickElement(WebDriverBy::xpath("//button[text()='Add']"));
        $this->waitForAjaxComplete();
        
        // Verify share created with permissions
        $shares = $this->loadSharesFromConfig();
        $share = array_filter($shares, fn($s) => $s['name'] === 'PermTest');
        $this->assertNotEmpty($share);
    }
}
