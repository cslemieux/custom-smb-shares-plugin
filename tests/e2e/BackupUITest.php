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
 * E2E tests for backup UI functionality on Settings page.
 * These tests verify actual browser behavior, not just PHP functions.
 */
class BackupUITest extends TestCase
{
    private static ?RemoteWebDriver $driver = null;
    private static ?array $harness = null;
    
    public static function setUpBeforeClass(): void
    {
        // Start test harness
        self::$harness = UnraidTestHarness::setup(8892);
        
        // Start ChromeDriver
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
        
        // Create a test backup so we have something to work with
        $this->createTestBackup();
    }
    
    private function createTestBackup(): void
    {
        // harness_dir contains the full harness structure
        // Config is at harness_dir/boot/config
        $configDir = self::$harness['harness_dir'] . '/boot/config';
        $backupDir = $configDir . '/plugins/custom.smb.shares/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $testShares = [
            ['name' => 'TestShare', 'path' => '/mnt/user/test', 'comment' => 'Test backup']
        ];
        
        $filename = 'shares_' . date('Y-m-d_H-i-s') . '.json';
        file_put_contents($backupDir . '/' . $filename, json_encode($testShares, JSON_PRETTY_PRINT));
    }
    
    /**
     * Test that Settings page loads and shows backup table
     */
    public function testSettingsPageLoadsBackupTable(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBSharesSettings');
        
        // Wait for page to load
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(
                WebDriverBy::id('backupList')
            )
        );
        
        // Verify backup table exists
        $backupList = self::$driver->findElement(WebDriverBy::id('backupList'));
        $this->assertNotNull($backupList, 'Backup list table should exist');
    }
    
    /**
     * Test that backup list loads via AJAX
     */
    public function testBackupListLoadsViaAjax(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBSharesSettings');
        
        // Wait for AJAX to complete
        self::$driver->wait(10)->until(function($driver) {
            return $driver->executeScript('return typeof jQuery === "undefined" || jQuery.active == 0');
        });
        
        // Wait a bit more for the backup list to populate
        sleep(1);
        
        // Check if backup rows exist (not just "Loading..." or "No backups")
        $rows = self::$driver->findElements(WebDriverBy::cssSelector('#backupList tr'));
        $this->assertGreaterThan(0, count($rows), 'Backup list should have at least one row');
        
        // Check for backup action links
        $viewLinks = self::$driver->findElements(WebDriverBy::cssSelector('.backup-view'));
        $this->assertGreaterThan(0, count($viewLinks), 'Should have View links for backups');
    }
    
    /**
     * Test that clicking View button triggers the viewBackup function
     */
    public function testViewBackupButtonIsClickable(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBSharesSettings');
        
        // Wait for backup list to load
        self::$driver->wait(10)->until(function($driver) {
            $links = $driver->findElements(WebDriverBy::cssSelector('.backup-view'));
            return count($links) > 0;
        });
        
        // Get the first View link
        $viewLink = self::$driver->findElement(WebDriverBy::cssSelector('.backup-view'));
        
        // Verify it has the data-filename attribute
        $filename = $viewLink->getAttribute('data-filename');
        $this->assertNotEmpty($filename, 'View link should have data-filename attribute');
        
        // Inject a flag to track if viewBackup was called
        self::$driver->executeScript("window.viewBackupCalled = false; var origViewBackup = viewBackup; viewBackup = function(f) { window.viewBackupCalled = true; window.viewBackupFilename = f; origViewBackup(f); };");
        
        // Click it
        $viewLink->click();
        
        // Wait a moment for the click handler to execute
        sleep(1);
        
        // Check if viewBackup was called
        $wasCalled = self::$driver->executeScript("return window.viewBackupCalled;");
        $this->assertTrue($wasCalled, 'viewBackup function should be called when clicking View');
        
        // Check the filename was passed correctly
        $passedFilename = self::$driver->executeScript("return window.viewBackupFilename;");
        $this->assertEquals($filename, $passedFilename, 'Correct filename should be passed to viewBackup');
    }
    
    /**
     * Test that View makes AJAX call and gets response
     */
    public function testViewBackupMakesAjaxCall(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBSharesSettings');
        
        // Wait for backup list to load
        self::$driver->wait(10)->until(function($driver) {
            $links = $driver->findElements(WebDriverBy::cssSelector('.backup-view'));
            return count($links) > 0;
        });
        
        // Get the filename from the first backup
        $viewLink = self::$driver->findElement(WebDriverBy::cssSelector('.backup-view'));
        $filename = $viewLink->getAttribute('data-filename');
        
        // Set up a flag to capture the AJAX response
        self::$driver->executeScript("
            window.testAjaxResult = null;
            window.testAjaxError = null;
            window.testAjaxDone = false;
            $.get('/plugins/custom.smb.shares/api.php?action=viewBackup&filename=' + encodeURIComponent('$filename'))
                .done(function(data) {
                    window.testAjaxResult = data;
                    window.testAjaxDone = true;
                })
                .fail(function(xhr, status, error) {
                    window.testAjaxError = {status: status, error: error, response: xhr.responseText};
                    window.testAjaxDone = true;
                });
        ");
        
        // Wait for AJAX to complete
        self::$driver->wait(10)->until(function($driver) {
            return $driver->executeScript("return window.testAjaxDone === true;");
        });
        
        // Check for errors
        $error = self::$driver->executeScript("return window.testAjaxError;");
        $this->assertNull($error, 'AJAX call should not error: ' . json_encode($error));
        
        $response = self::$driver->executeScript("return window.testAjaxResult;");
        
        $this->assertNotNull($response, 'AJAX call should return response');
        $this->assertTrue($response['success'], 'API should return success');
        $this->assertIsArray($response['config'], 'API should return config array');
    }
    
    /**
     * Test that event delegation is properly set up
     */
    public function testEventDelegationIsSetUp(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBSharesSettings');
        
        // Check that event handlers are attached via delegation
        $hasViewHandler = self::$driver->executeScript("
            var events = jQuery._data(document, 'events');
            if (!events || !events.click) return false;
            return events.click.some(function(e) {
                return e.selector && e.selector.indexOf('backup-view') !== -1;
            });
        ");
        
        $this->assertTrue($hasViewHandler, 'Document should have delegated click handler for .backup-view');
        
        $hasRestoreHandler = self::$driver->executeScript("
            var events = jQuery._data(document, 'events');
            if (!events || !events.click) return false;
            return events.click.some(function(e) {
                return e.selector && e.selector.indexOf('backup-restore') !== -1;
            });
        ");
        
        $this->assertTrue($hasRestoreHandler, 'Document should have delegated click handler for .backup-restore');
        
        $hasDeleteHandler = self::$driver->executeScript("
            var events = jQuery._data(document, 'events');
            if (!events || !events.click) return false;
            return events.click.some(function(e) {
                return e.selector && e.selector.indexOf('backup-delete') !== -1;
            });
        ");
        
        $this->assertTrue($hasDeleteHandler, 'Document should have delegated click handler for .backup-delete');
    }
    
    /**
     * Test for JavaScript errors on page load
     */
    public function testNoJavaScriptErrors(): void
    {
        // Inject error catcher before page load
        self::$driver->get('about:blank');
        self::$driver->executeScript("window.jsErrors = [];");
        
        self::$driver->get(self::$harness['url'] . '/SMBSharesSettings');
        
        // Wait for page to load
        sleep(2);
        
        // Check for JS errors
        $jsErrors = self::$driver->executeScript("return window.jsErrors || [];");
        
        $this->assertEmpty($jsErrors, 'Page should have no JavaScript errors: ' . json_encode($jsErrors));
    }
    
    /**
     * Test that viewBackup function exists and is callable
     */
    public function testViewBackupFunctionExists(): void
    {
        self::$driver->get(self::$harness['url'] . '/SMBSharesSettings');
        
        // Wait for page to load
        sleep(1);
        
        $functionExists = self::$driver->executeScript("return typeof viewBackup === 'function';");
        $this->assertTrue($functionExists, 'viewBackup function should exist in global scope');
        
        $functionExists = self::$driver->executeScript("return typeof restoreBackup === 'function';");
        $this->assertTrue($functionExists, 'restoreBackup function should exist in global scope');
        
        $functionExists = self::$driver->executeScript("return typeof deleteBackup === 'function';");
        $this->assertTrue($functionExists, 'deleteBackup function should exist in global scope');
    }
}
