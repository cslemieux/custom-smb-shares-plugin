<?php
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';
require_once __DIR__ . '/E2ETestBase.php';

/**
 * E2E Tests for Permission Checkbox Interface
 */
class PermissionsUITest extends E2ETestBase
{
    protected static RemoteWebDriver $driver;
    protected array $harness;
    protected string $baseUrl;
    
    public static function setUpBeforeClass(): void
    {
        $configPath = __DIR__ . '/../configs/PermissionsUITest.json';
        $config = json_decode(file_get_contents($configPath), true);
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
    
    public function testPermissionCheckboxesRender()
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBShares');
        
        // Click Add Share
        $addButton = self::$driver->findElement(WebDriverBy::linkText('Add Share'));
        $addButton->click();
        
        // Wait for modal
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('shareForm'))
        );
        
        // Verify file permission checkboxes exist
        $this->assertNotNull(self::$driver->findElement(WebDriverBy::name('file_owner_read')));
        $this->assertNotNull(self::$driver->findElement(WebDriverBy::name('file_owner_write')));
        $this->assertNotNull(self::$driver->findElement(WebDriverBy::name('file_group_read')));
        
        // Verify directory permission checkboxes exist
        $this->assertNotNull(self::$driver->findElement(WebDriverBy::name('directory_owner_read')));
        $this->assertNotNull(self::$driver->findElement(WebDriverBy::name('directory_owner_write')));
        
        // Verify octal previews exist
        $this->assertNotNull(self::$driver->findElement(WebDriverBy::id('file-octal-preview')));
        $this->assertNotNull(self::$driver->findElement(WebDriverBy::id('directory-octal-preview')));
        
        // Verify preset dropdowns exist
        $presets = self::$driver->findElements(WebDriverBy::cssSelector('.permission-preset'));
        $this->assertCount(2, $presets);
    }
    
    public function testCheckboxClickUpdatesOctalPreview()
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBShares');
        self::$driver->findElement(WebDriverBy::linkText('Add Share'))->click();
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('shareForm'))
        );
        
        // Get initial octal value
        $preview = self::$driver->findElement(WebDriverBy::id('file-octal-preview'));
        $initialOctal = $preview->getText();
        
        // Click a checkbox
        $checkbox = self::$driver->findElement(WebDriverBy::name('file_owner_execute'));
        $checkbox->click();
        
        // Wait for update
        usleep(100000); // 100ms
        
        // Verify octal changed
        $newOctal = $preview->getText();
        $this->assertNotEquals($initialOctal, $newOctal);
    }
    
    public function testPresetSelectionUpdatesCheckboxes()
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBShares');
        self::$driver->findElement(WebDriverBy::linkText('Add Share'))->click();
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('shareForm'))
        );
        
        // Select 0600 preset (owner only)
        $preset = self::$driver->findElement(WebDriverBy::cssSelector('.permission-preset[data-prefix="file"]'));
        $preset->findElement(WebDriverBy::cssSelector('option[value="0600"]'))->click();
        
        usleep(100000);
        
        // Verify owner has read/write, others don't
        $this->assertTrue(self::$driver->findElement(WebDriverBy::name('file_owner_read'))->isSelected());
        $this->assertTrue(self::$driver->findElement(WebDriverBy::name('file_owner_write'))->isSelected());
        $this->assertFalse(self::$driver->findElement(WebDriverBy::name('file_group_read'))->isSelected());
        $this->assertFalse(self::$driver->findElement(WebDriverBy::name('file_others_read'))->isSelected());
        
        // Verify octal preview
        $preview = self::$driver->findElement(WebDriverBy::id('file-octal-preview'));
        $this->assertEquals('0600', $preview->getText());
    }
    
    public function testFormSubmissionIncludesOctalValues()
    {
        self::$driver->get($this->baseUrl . '/Settings/SMBShares');
        self::$driver->findElement(WebDriverBy::linkText('Add Share'))->click();
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('shareForm'))
        );
        
        // Fill required fields
        self::$driver->findElement(WebDriverBy::id('shareName'))->sendKeys('PermTest');
        self::$driver->findElement(WebDriverBy::id('sharePath'))->sendKeys('/mnt/user/permtest');
        
        // Set specific permissions via checkboxes
        self::$driver->findElement(WebDriverBy::name('file_owner_read'))->click();
        self::$driver->findElement(WebDriverBy::name('file_owner_write'))->click();
        
        usleep(100000);
        
        // Verify hidden field has correct value
        $hiddenField = self::$driver->findElement(WebDriverBy::name('create_mask'));
        $this->assertMatchesRegularExpression('/^0[0-7]{3}$/', $hiddenField->getAttribute('value'));
    }
    
    public function testEditModeLoadsExistingPermissions()
    {
        // First add a share with specific permissions
        self::$driver->get($this->baseUrl . '/Settings/SMBShares');
        self::$driver->findElement(WebDriverBy::linkText('Add Share'))->click();
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('shareForm'))
        );
        
        self::$driver->findElement(WebDriverBy::id('shareName'))->sendKeys('EditTest');
        self::$driver->findElement(WebDriverBy::id('sharePath'))->sendKeys('/mnt/user/edittest');
        
        // Set 0644 via preset
        $preset = self::$driver->findElement(WebDriverBy::cssSelector('.permission-preset[data-prefix="file"]'));
        $preset->findElement(WebDriverBy::cssSelector('option[value="0644"]'))->click();
        
        usleep(100000);
        
        self::$driver->findElement(WebDriverBy::cssSelector('input[type="submit"]'))->click();
        
        // Wait for success
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('.notification-success'))
        );
        
        // Reload page
        self::$driver->get($this->baseUrl . '/Settings/SMBShares');
        
        // Click edit on the share
        $editLink = self::$driver->findElement(WebDriverBy::linkText('Edit'));
        $editLink->click();
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('shareForm'))
        );
        
        usleep(200000); // Wait for initialization
        
        // Verify checkboxes match 0644
        $this->assertTrue(self::$driver->findElement(WebDriverBy::name('file_owner_read'))->isSelected());
        $this->assertTrue(self::$driver->findElement(WebDriverBy::name('file_owner_write'))->isSelected());
        $this->assertFalse(self::$driver->findElement(WebDriverBy::name('file_owner_execute'))->isSelected());
        
        $this->assertTrue(self::$driver->findElement(WebDriverBy::name('file_group_read'))->isSelected());
        $this->assertFalse(self::$driver->findElement(WebDriverBy::name('file_group_write'))->isSelected());
        
        // Verify octal preview
        $preview = self::$driver->findElement(WebDriverBy::id('file-octal-preview'));
        $this->assertEquals('0644', $preview->getText());
    }
    
    public function testAllPresetsWork()
    {
        $presets = [
            'file' => ['0644', '0664', '0666', '0600'],
            'directory' => ['0755', '0775', '0777', '0700']
        ];
        
        self::$driver->get($this->baseUrl . '/Settings/SMBShares');
        self::$driver->findElement(WebDriverBy::linkText('Add Share'))->click();
        
        self::$driver->wait(10)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('shareForm'))
        );
        
        foreach ($presets as $prefix => $values) {
            foreach ($values as $octal) {
                $preset = self::$driver->findElement(WebDriverBy::cssSelector(".permission-preset[data-prefix=\"$prefix\"]"));
                $preset->findElement(WebDriverBy::cssSelector("option[value=\"$octal\"]"))->click();
                
                usleep(100000);
                
                $preview = self::$driver->findElement(WebDriverBy::id("$prefix-octal-preview"));
                $this->assertEquals($octal, $preview->getText(), "Preset $octal failed for $prefix");
            }
        }
    }
}
