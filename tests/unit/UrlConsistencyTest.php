<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests to ensure URL consistency across the plugin.
 * Catches issues like redirecting to old/wrong URLs.
 */
class UrlConsistencyTest extends TestCase
{
    private string $sourceDir;
    
    /** @var array<string, string> Valid plugin routes */
    private array $validRoutes = [
        '/SMBShares' => 'Main plugin page',
        '/SMBSharesAdd' => 'Add share page',
        '/SMBSharesUpdate' => 'Edit share page',
        '/SMBSharesSettings' => 'Settings page',
    ];
    
    /** @var array<string> Deprecated/invalid routes that should not be used */
    private array $invalidRoutes = [
        '/Settings/CustomSMBShares',
        '/CustomSMBShares',
        '/CustomSMBSharesAdd',
        '/CustomSMBSharesUpdate',
    ];

    protected function setUp(): void
    {
        $this->sourceDir = dirname(__DIR__, 2) . '/source/usr/local/emhttp/plugins/custom.smb.shares';
    }

    public function testNoInvalidRoutesInPhpFiles(): void
    {
        $phpFiles = glob($this->sourceDir . '/*.php') ?: [];
        $phpFiles = array_merge($phpFiles, glob($this->sourceDir . '/include/*.php') ?: []);
        
        $errors = [];
        
        foreach ($phpFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            foreach ($this->invalidRoutes as $invalidRoute) {
                if (strpos($content, $invalidRoute) !== false) {
                    $errors[] = basename($file) . " contains invalid route: $invalidRoute";
                }
            }
        }
        
        $this->assertEmpty(
            $errors,
            "Found invalid routes in PHP files:\n" . implode("\n", $errors)
        );
    }

    public function testNoInvalidRoutesInPageFiles(): void
    {
        $pageFiles = glob($this->sourceDir . '/*.page') ?: [];
        
        $errors = [];
        
        foreach ($pageFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            foreach ($this->invalidRoutes as $invalidRoute) {
                if (strpos($content, $invalidRoute) !== false) {
                    $errors[] = basename($file) . " contains invalid route: $invalidRoute";
                }
            }
        }
        
        $this->assertEmpty(
            $errors,
            "Found invalid routes in page files:\n" . implode("\n", $errors)
        );
    }

    public function testNoInvalidRoutesInJsFiles(): void
    {
        $jsFiles = glob($this->sourceDir . '/js/*.js') ?: [];
        
        $errors = [];
        
        foreach ($jsFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            foreach ($this->invalidRoutes as $invalidRoute) {
                if (strpos($content, $invalidRoute) !== false) {
                    $errors[] = basename($file) . " contains invalid route: $invalidRoute";
                }
            }
        }
        
        $this->assertEmpty(
            $errors,
            "Found invalid routes in JS files:\n" . implode("\n", $errors)
        );
    }

    public function testAllRedirectsUseValidRoutes(): void
    {
        $allFiles = array_merge(
            glob($this->sourceDir . '/*.php') ?: [],
            glob($this->sourceDir . '/include/*.php') ?: [],
            glob($this->sourceDir . '/*.page') ?: [],
            glob($this->sourceDir . '/js/*.js') ?: []
        );
        
        $errors = [];
        $validRoutePatterns = array_keys($this->validRoutes);
        
        foreach ($allFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            // Find location.href assignments
            if (preg_match_all("/location\.href\s*=\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                foreach ($matches[1] as $url) {
                    // Skip external URLs and anchors
                    if (strpos($url, 'http') === 0 || strpos($url, '#') === 0) {
                        continue;
                    }
                    
                    // Check if it's a valid plugin route or a known Unraid route
                    $isValid = false;
                    foreach ($validRoutePatterns as $pattern) {
                        if (strpos($url, $pattern) === 0) {
                            $isValid = true;
                            break;
                        }
                    }
                    
                    // Also allow /plugins/ paths for AJAX endpoints
                    if (strpos($url, '/plugins/') === 0) {
                        $isValid = true;
                    }
                    
                    if (!$isValid) {
                        $errors[] = basename($file) . ": redirect to unknown route '$url'";
                    }
                }
            }
        }
        
        $this->assertEmpty(
            $errors,
            "Found redirects to unknown routes:\n" . implode("\n", $errors)
        );
    }

    public function testFormActionsUseValidEndpoints(): void
    {
        $allFiles = array_merge(
            glob($this->sourceDir . '/*.php') ?: [],
            glob($this->sourceDir . '/include/*.php') ?: [],
            glob($this->sourceDir . '/*.page') ?: []
        );
        
        $errors = [];
        
        foreach ($allFiles as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }
            
            // Find form action attributes
            if (preg_match_all('/action\s*=\s*["\']([^"\']+)["\']/', $content, $matches)) {
                foreach ($matches[1] as $action) {
                    // Form actions should point to our plugin endpoints
                    if (strpos($action, '/plugins/custom.smb.shares/') !== 0 
                        && strpos($action, '/update.htm') !== 0) {
                        $errors[] = basename($file) . ": form action '$action' doesn't use plugin endpoint";
                    }
                }
            }
        }
        
        $this->assertEmpty(
            $errors,
            "Found forms with invalid actions:\n" . implode("\n", $errors)
        );
    }
}
