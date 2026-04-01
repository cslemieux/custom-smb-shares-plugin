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
     * Wait for form page to be ready (replaces modal wait)
     * Waits for form fields to be present on the page
     */
    protected function waitForModal(int $timeout = 10): void
    {
        try {
            // Wait for form fields to be present on the page (page-based navigation, not modal)
            $this->driver->wait($timeout)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(
                    WebDriverBy::cssSelector('input[name="name"], input[name="path"], form')
                )
            );
        } catch (\Exception $e) {
            // Check for JS errors before failing
            $jsErrors = $this->driver->executeScript(
                'return window.jsErrors || [];'
            );
            if (!empty($jsErrors)) {
                throw new \Exception("Page form failed to load. JS Errors: " . json_encode($jsErrors));
            }
            
            throw $e;
        }
        
        // Wait for page to be fully ready
        usleep(HarnessConfig::getModalAnimationDelay());
    }
    
    /**
     * Wait for page to be fully loaded and ready
     * 
     * Uses a resilient strategy: first waits for document.readyState (always fast),
     * then optionally waits for jQuery (CDN may be slow — uses longer timeout and
     * falls through gracefully if jQuery never loads).
     */
    protected function waitForPageReady(int $timeout = 15): void
    {
        // Step 1: Wait for document ready state — this works even without CDN scripts
        $this->driver->wait($timeout)->until(
            function ($driver) {
                return $driver->executeScript('return document.readyState === "complete";');
            }
        );
        
        // Step 2: Try to wait for jQuery — CDN scripts can be slow, use a generous
        // per-poll approach rather than a hard wait() that throws on timeout
        $jqueryMaxWaitMs = $timeout * 1000;
        $jqueryPollMs = 200;
        $elapsed = 0;
        while ($elapsed < $jqueryMaxWaitMs) {
            $jqueryLoaded = $this->driver->executeScript(
                'return typeof jQuery !== "undefined" && jQuery.isReady;'
            );
            if ($jqueryLoaded) {
                break;
            }
            usleep($jqueryPollMs * 1000);
            $elapsed += $jqueryPollMs;
        }
        
        // Step 3: Wait for any pending AJAX (gracefully if jQuery not available)
        $this->driver->wait(5)->until(
            function ($driver) {
                return $driver->executeScript(
                    'try { return typeof jQuery === "undefined" || jQuery.active === 0; } catch(e) { return true; }'
                );
            }
        );
        
        usleep(HarnessConfig::getPageReadyBuffer());
    }
    
    /**
     * Close all modals and overlays (no-op for page-based navigation)
     */
    protected function closeAllModals(): void
    {
        // Plugin uses page-based navigation, not jQuery UI dialogs
        // This method is kept for backward compatibility but is a no-op
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
     * Click element with retry
     */
    protected function clickElement(WebDriverBy $by, int $maxRetries = HarnessConfig::MAX_CLICK_RETRIES): void
    {
        $attempt = 0;
        while ($attempt < $maxRetries) {
            try {
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
