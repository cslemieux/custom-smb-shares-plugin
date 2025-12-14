<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Facebook\WebDriver\Exception\UnexpectedAlertOpenException;
use Facebook\WebDriver\Exception\NoSuchElementException;

require_once __DIR__ . '/../harness/HarnessConfig.php';

/**
 * Base class for E2E tests with robust timeout and cleanup handling
 */
abstract class E2ETestBase extends TestCase
{
    protected RemoteWebDriver $driver;
    protected array $harness;
    protected string $baseUrl;
    
    /**
     * Wait for AJAX to complete
     */
    protected function waitForAjaxComplete(int $timeout = 10): void
    {
        $this->driver->wait($timeout)->until(function($driver) {
            return $driver->executeScript('return typeof jQuery === "undefined" || jQuery.active == 0');
        });
    }
    
    /**
     * Wait for modal to open
     */
    protected function waitForModal(int $timeout = 10): void
    {
        try {
            $this->driver->wait($timeout)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('.ui-dialog')
                )
            );
        } catch (\Exception $e) {
            // Check for JS errors before failing
            $jsErrors = $this->driver->executeScript(
                'return window.jsErrors || [];'
            );
            if (!empty($jsErrors)) {
                throw new \Exception("Modal failed to open. JS Errors: " . json_encode($jsErrors));
            }
            
            // Check if button exists
            $buttonExists = $this->driver->executeScript(
                'return $("input[value=\'Add Share\']").length > 0;'
            );
            if (!$buttonExists) {
                throw new \Exception("Modal failed to open. Add Share button not found");
            }
            
            throw $e;
        }
        
        // Wait for modal animation and jQuery to finish
        usleep(HarnessConfig::getModalAnimationDelay());
    }
    
    /**
     * Wait for page to be fully loaded and ready
     */
    protected function waitForPageReady(int $timeout = 10): void
    {
        // Wait for jQuery to be loaded
        $this->driver->wait($timeout)->until(
            function ($driver) {
                return $driver->executeScript('return typeof jQuery !== "undefined";');
            }
        );
        
        // Wait for document ready
        $this->driver->wait($timeout)->until(
            function ($driver) {
                return $driver->executeScript('return document.readyState === "complete";');
            }
        );
        
        // Wait for jQuery ready
        $this->driver->wait($timeout)->until(
            function ($driver) {
                return $driver->executeScript('return jQuery.isReady;');
            }
        );
        
        // Wait for any pending AJAX
        $this->driver->wait($timeout)->until(
            function ($driver) {
                return $driver->executeScript('return jQuery.active === 0;');
            }
        );
        
        usleep(HarnessConfig::getPageReadyBuffer());
    }
    
    /**
     * Close all modals and overlays
     */
    protected function closeAllModals(): void
    {
        try {
            // Close jQuery UI dialogs (only if jQuery is loaded)
            $this->driver->executeScript('
                if (typeof $ !== "undefined" && $(".ui-dialog").length > 0) {
                    $(".ui-dialog").dialog("close");
                    $(".ui-widget-overlay").remove();
                }
            ');
        } catch (\Exception $e) {
            // Ignore if no dialogs open or jQuery not loaded
        }
    }
    
    /**
     * Dismiss any alerts
     */
    protected function dismissAlerts(): void
    {
        try {
            $alert = $this->driver->switchTo()->alert();
            $alert->dismiss();
        } catch (UnexpectedAlertOpenException $e) {
            // Try again
            try {
                $alert = $this->driver->switchTo()->alert();
                $alert->dismiss();
            } catch (\Exception $e2) {
                // Ignore
            }
        } catch (\Exception $e) {
            // No alert present
        }
    }
    
    /**
     * Click element with retry and overlay handling
     */
    protected function clickElement(WebDriverBy $by, int $maxRetries = HarnessConfig::MAX_CLICK_RETRIES): void
    {
        $attempt = 0;
        while ($attempt < $maxRetries) {
            try {
                // Remove overlays first
                $this->driver->executeScript('$(".ui-widget-overlay").remove();');
                
                // Wait for element
                $element = $this->driver->wait(10)->until(
                    WebDriverExpectedCondition::elementToBeClickable($by)
                );
                
                // Scroll into view
                $this->driver->executeScript('arguments[0].scrollIntoView(true);', [$element]);
                usleep(100000); // 100ms
                
                // Click
                $element->click();
                return;
            } catch (\Exception $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                usleep(HarnessConfig::CLICK_RETRY_DELAY_MS * 1000);
            }
        }
    }
    
    /**
     * Wait for element with timeout
     */
    protected function waitForElement(WebDriverBy $by, int $timeout = 10)
    {
        return $this->driver->wait($timeout)->until(
            WebDriverExpectedCondition::presenceOfElementLocated($by)
        );
    }
    
    /**
     * Check if element exists without throwing
     */
    protected function elementExists(WebDriverBy $by): bool
    {
        try {
            $this->driver->findElement($by);
            return true;
        } catch (NoSuchElementException $e) {
            return false;
        }
    }
    
    /**
     * Safe screenshot that handles alerts
     */
    protected function safeScreenshot(string $filename): void
    {
        try {
            $this->dismissAlerts();
            $this->driver->takeScreenshot($filename);
        } catch (\Exception $e) {
            // Screenshot failed, continue
        }
    }
    
    /**
     * Verify share exists in backend (shares.json)
     * @param string $shareName Name of share to verify
     * @return bool True if share exists in backend
     */
    protected function verifyShareInBackend(string $shareName): bool
    {
        $configFile = self::$harness['harness_dir'] . '/usr/local/boot/config/plugins/custom.smb.shares/shares.json';
        if (!file_exists($configFile)) {
            return false;
        }
        
        $content = file_get_contents($configFile);
        if ($content === false) {
            return false;
        }
        
        $shares = json_decode($content, true);
        if (!is_array($shares)) {
            return false;
        }
        
        foreach ($shares as $share) {
            if (isset($share['name']) && $share['name'] === $shareName) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get share data from backend
     * @param string $shareName Name of share to get
     * @return array|null Share data or null if not found
     */
    protected function getShareFromBackend(string $shareName): ?array
    {
        $configFile = self::$harness['harness_dir'] . '/usr/local/boot/config/plugins/custom.smb.shares/shares.json';
        if (!file_exists($configFile)) {
            return null;
        }
        
        $content = file_get_contents($configFile);
        if ($content === false) {
            return null;
        }
        
        $shares = json_decode($content, true);
        if (!is_array($shares)) {
            return null;
        }
        
        foreach ($shares as $share) {
            if (isset($share['name']) && $share['name'] === $shareName) {
                return $share;
            }
        }
        
        return null;
    }
    
    /**
     * Get all shares from backend
     * @return array Array of shares
     */
    protected function getAllSharesFromBackend(): array
    {
        $configFile = self::$harness['harness_dir'] . '/usr/local/boot/config/plugins/custom.smb.shares/shares.json';
        if (!file_exists($configFile)) {
            return [];
        }
        
        $content = file_get_contents($configFile);
        if ($content === false) {
            return [];
        }
        
        $shares = json_decode($content, true);
        return is_array($shares) ? $shares : [];
    }
    
    /**
     * Cleanup after each test
     */
    protected function tearDown(): void
    {
        if (isset($this->driver)) {
            // Dismiss any alerts first
            $this->dismissAlerts();
            
            // Close all modals
            $this->closeAllModals();
            
            // Verify modals are actually closed
            $maxAttempts = HarnessConfig::MAX_MODAL_CLOSE_ATTEMPTS;
            for ($i = 0; $i < $maxAttempts; $i++) {
                $modalVisible = $this->driver->executeScript('return $(".ui-dialog:visible").length;');
                if ($modalVisible == 0) {
                    break;
                }
                usleep(HarnessConfig::MODAL_CLOSE_RETRY_MS * 1000);
                $this->closeAllModals();
            }
            
            // Clear any test data
            try {
                $configFile = self::$harness['harness_dir'] . '/usr/local/boot/config/plugins/custom.smb.shares/shares.json';
                if (file_exists($configFile)) {
                    file_put_contents($configFile, '[]');
                }
            } catch (\Exception $e) {
                // Ignore cleanup errors
            }
        }
        
        parent::tearDown();
    }
}
