<?php
use PHPUnit\Framework\TestCase;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;

require_once __DIR__ . '/../harness/UnraidTestHarness.php';

class AddPropModalTest extends TestCase
{
    private static $driver;
    private static $harness;
    private static $baseUrl;
    private static $screenshotDir;
    
    public static function setUpBeforeClass(): void
    {
        self::$harness = UnraidTestHarness::setup(8889);
        self::$baseUrl = self::$harness['url'];
        
        self::$screenshotDir = __DIR__ . '/../../screenshots/add-prop-modal';
        if (!is_dir(self::$screenshotDir)) {
            mkdir(self::$screenshotDir, 0755, true);
        }
        
        $options = new ChromeOptions();
        $options->addArguments(['--headless=new', '--window-size=1920,1080', '--disable-gpu']);
        
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);
        
        self::$driver = RemoteWebDriver::create('http://localhost:9515', $capabilities);
    }
    
    public static function tearDownClass(): void
    {
        if (self::$driver) {
            self::$driver->quit();
        }
        UnraidTestHarness::teardown();
    }
    
    private function screenshot($name)
    {
        $filename = self::$screenshotDir . '/' . sprintf('%02d', ++self::$testCounter) . '-' . $name . '.png';
        self::$driver->takeScreenshot($filename);
        echo "Screenshot: $filename\n";
    }
    
    private static $testCounter = 0;
    
    public function testAddPropButtonExists()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        $this->screenshot('01-page-loaded');
        
        $addPropButton = self::$driver->findElement(WebDriverBy::xpath("//input[@value='Add Prop']"));
        $this->assertNotNull($addPropButton);
        $this->assertTrue($addPropButton->isDisplayed());
    }
    
    public function testModalOpensWhenButtonClicked()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Click Add Prop button
        $addPropButton = self::$driver->findElement(WebDriverBy::xpath("//input[@value='Add Prop']"));
        $addPropButton->click();
        $this->screenshot('02-button-clicked');
        
        // Wait for modal to appear
        self::$driver->wait(5)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('.ui-dialog'))
        );
        $this->screenshot('03-modal-opened');
        
        // Verify modal is visible
        $modal = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog'));
        $this->assertTrue($modal->isDisplayed());
    }
    
    public function testModalHasRequiredFields()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Open modal
        $addPropButton = self::$driver->findElement(WebDriverBy::xpath("//input[@value='Add Prop']"));
        $addPropButton->click();
        
        self::$driver->wait(5)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('.ui-dialog'))
        );
        
        // Check for required fields
        $typeSelect = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog select[name="Type"]'));
        $this->assertNotNull($typeSelect);
        
        $nameInput = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog input[name="Name"]'));
        $this->assertNotNull($nameInput);
        
        $targetInput = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog input[name="Target"]'));
        $this->assertNotNull($targetInput);
        
        $valueInput = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog input[name="Value"]'));
        $this->assertNotNull($valueInput);
        
        $this->screenshot('04-fields-verified');
    }
    
    public function testConfigTypeChangesFields()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Open modal
        $addPropButton = self::$driver->findElement(WebDriverBy::xpath("//input[@value='Add Prop']"));
        $addPropButton->click();
        
        self::$driver->wait(5)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('.ui-dialog'))
        );
        
        // Default is Path (index 0)
        $this->screenshot('05-type-path');
        
        // Change to Port (index 1)
        $typeSelect = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog select[name="Type"]'));
        $typeSelect->findElement(WebDriverBy::cssSelector('option[value="Port"]'))->click();
        sleep(1);
        $this->screenshot('06-type-port');
        
        // Change to Variable (index 2)
        $typeSelect->findElement(WebDriverBy::cssSelector('option[value="Variable"]'))->click();
        sleep(1);
        $this->screenshot('07-type-variable');
        
        // Verify Mode field appears for Path/Port
        $typeSelect->findElement(WebDriverBy::cssSelector('option[value="Path"]'))->click();
        sleep(1);
        $modeSelect = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog select[name="Mode"]'));
        $this->assertNotNull($modeSelect);
        $this->screenshot('08-mode-field-present');
    }
    
    public function testModalHasAddAndCancelButtons()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Open modal
        $addPropButton = self::$driver->findElement(WebDriverBy::xpath("//input[@value='Add Prop']"));
        $addPropButton->click();
        
        self::$driver->wait(5)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('.ui-dialog'))
        );
        
        // Check for buttons
        $buttons = self::$driver->findElements(WebDriverBy::cssSelector('.ui-dialog-buttonpane button'));
        $this->assertCount(2, $buttons);
        
        $this->screenshot('09-buttons-present');
    }
    
    public function testCancelButtonClosesModal()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Open modal
        $addPropButton = self::$driver->findElement(WebDriverBy::xpath("//input[@value='Add Prop']"));
        $addPropButton->click();
        
        self::$driver->wait(5)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('.ui-dialog'))
        );
        
        // Click Cancel button (second button)
        $buttons = self::$driver->findElements(WebDriverBy::cssSelector('.ui-dialog-buttonpane button'));
        $cancelButton = $buttons[1];
        $cancelButton->click();
        
        sleep(1);
        $this->screenshot('10-modal-closed');
        
        // Verify modal is gone
        $modals = self::$driver->findElements(WebDriverBy::cssSelector('.ui-dialog:visible'));
        $this->assertCount(0, $modals);
    }
    
    public function testAddButtonTriggersAlert()
    {
        self::$driver->get(self::$baseUrl . '/Settings/SMBShares');
        
        // Open modal
        $addPropButton = self::$driver->findElement(WebDriverBy::xpath("//input[@value='Add Prop']"));
        $addPropButton->click();
        
        self::$driver->wait(5)->until(
            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('.ui-dialog'))
        );
        
        // Fill in some data
        $nameInput = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog input[name="Name"]'));
        $nameInput->sendKeys('TestConfig');
        
        $targetInput = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog input[name="Target"]'));
        $targetInput->sendKeys('/container/path');
        
        $valueInput = self::$driver->findElement(WebDriverBy::cssSelector('.ui-dialog input[name="Value"]'));
        $valueInput->sendKeys('/host/path');
        
        $this->screenshot('11-form-filled');
        
        // Click Add button (first button)
        $buttons = self::$driver->findElements(WebDriverBy::cssSelector('.ui-dialog-buttonpane button'));
        $addButton = $buttons[0];
        $addButton->click();
        
        // Wait for alert
        self::$driver->wait(5)->until(WebDriverExpectedCondition::alertIsPresent());
        $this->screenshot('12-alert-shown');
        
        // Get alert text
        $alert = self::$driver->switchTo()->alert();
        $alertText = $alert->getText();
        $this->assertStringContainsString('Config added', $alertText);
        $this->assertStringContainsString('TestConfig', $alertText);
        
        // Accept alert
        $alert->accept();
        $this->screenshot('13-alert-accepted');
    }
}
