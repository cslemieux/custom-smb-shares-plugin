<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Tests that JavaScript API calls match PHP API action handlers.
 * This catches mismatches like JS calling 'export' when PHP expects 'exportConfig'.
 */
class APIActionParityTest extends TestCase
{
    private static string $pluginDir;
    
    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares';
    }
    
    /**
     * Extract all action handlers from api.php
     */
    private function getApiActions(): array
    {
        $apiFile = self::$pluginDir . '/api.php';
        $content = file_get_contents($apiFile);
        
        // Match patterns like: if ($action === 'actionName')
        preg_match_all('/\$action\s*===\s*[\'"]([^\'"]+)[\'"]/', $content, $matches);
        
        return array_unique($matches[1]);
    }
    
    /**
     * Extract all API calls from JavaScript in .page files
     */
    private function getJsApiCalls(): array
    {
        $calls = [];
        
        // Check all .page files
        $pageFiles = glob(self::$pluginDir . '/*.page');
        foreach ($pageFiles as $file) {
            $content = file_get_contents($file);
            
            // Match patterns like: api.php?action=actionName (GET)
            preg_match_all('/api\.php\?action=([a-zA-Z]+)/', $content, $matches);
            foreach ($matches[1] as $action) {
                $calls[$action] = basename($file);
            }
            
            // Match patterns like: { action: 'actionName' } (POST)
            preg_match_all('/action:\s*[\'"]([a-zA-Z]+)[\'"]/', $content, $matches);
            foreach ($matches[1] as $action) {
                $calls[$action] = basename($file);
            }
        }
        
        return $calls;
    }
    
    /**
     * Test that all JavaScript API calls have matching PHP handlers
     */
    public function testAllJsApiCallsHaveMatchingPhpHandlers(): void
    {
        $apiActions = $this->getApiActions();
        $jsCalls = $this->getJsApiCalls();
        
        $mismatches = [];
        foreach ($jsCalls as $action => $sourceFile) {
            if (!in_array($action, $apiActions)) {
                $mismatches[] = "JS calls 'action=$action' in $sourceFile but api.php has no handler for it";
            }
        }
        
        $this->assertEmpty(
            $mismatches,
            "API action mismatches found:\n" . implode("\n", $mismatches) . 
            "\n\nAvailable API actions: " . implode(', ', $apiActions)
        );
    }
    
    /**
     * Test export action specifically
     */
    public function testExportConfigActionExists(): void
    {
        $apiActions = $this->getApiActions();
        $jsCalls = $this->getJsApiCalls();
        
        // Verify exportConfig is a valid API action
        $this->assertContains('exportConfig', $apiActions, "api.php should have 'exportConfig' handler");
        
        // If JS calls exportConfig, verify it matches
        if (isset($jsCalls['exportConfig'])) {
            $this->assertContains('exportConfig', $apiActions);
        }
    }
    
    /**
     * Test import action specifically - JS must call importConfig (not import)
     */
    public function testImportConfigActionExists(): void
    {
        $apiActions = $this->getApiActions();
        $jsCalls = $this->getJsApiCalls();
        
        // Verify importConfig is a valid API action
        $this->assertContains('importConfig', $apiActions, "api.php should have 'importConfig' handler");
        
        // JS must call importConfig, not import
        $this->assertArrayHasKey('importConfig', $jsCalls, "JS should call 'importConfig' action");
        $this->assertArrayNotHasKey('import', $jsCalls, "JS should NOT call 'import' action (use 'importConfig')");
    }
    
    /**
     * Test that import creates backup before overwriting
     */
    public function testImportCreatesBackup(): void
    {
        $apiFile = self::$pluginDir . '/api.php';
        $content = file_get_contents($apiFile);
        
        // Import handler should call backupShares before saveShares
        $this->assertMatchesRegularExpression(
            '/importConfig.*backupShares.*saveShares/s',
            $content,
            "Import should backup before saving"
        );
    }
    
    /**
     * Test that exportConfig returns the expected response format for JavaScript
     */
    public function testExportConfigResponseFormat(): void
    {
        $apiFile = self::$pluginDir . '/api.php';
        $content = file_get_contents($apiFile);
        
        // Find the exportConfig handler
        $this->assertStringContainsString(
            "action === 'exportConfig'",
            $content,
            "api.php should have exportConfig handler"
        );
        
        // Verify it returns success and config keys (what JS expects)
        // The JS expects: response.success and response.config
        $this->assertMatchesRegularExpression(
            "/exportConfig.*json_encode.*'success'.*'config'/s",
            $content,
            "exportConfig should return JSON with 'success' and 'config' keys"
        );
    }
    
    /**
     * Test that export UI has download button with proper event binding
     * 
     * Bug caught: onclick='functionName()' in dynamically inserted HTML (like SweetAlert)
     * can't find functions due to scope issues. Must use jQuery event binding after render.
     */
    public function testExportDownloadButtonHasProperEventBinding(): void
    {
        $pageFile = self::$pluginDir . '/SMBShares.page';
        $content = file_get_contents($pageFile);
        
        // Download button must exist
        $this->assertStringContainsString(
            'downloadConfigBtn',
            $content,
            "Export should have download button with id"
        );
        
        // Must NOT use inline onclick for dynamically created buttons (scope issues with SweetAlert)
        $this->assertDoesNotMatchRegularExpression(
            '/onclick=["\']downloadConfig\(\)["\']/',
            $content,
            "Download button must NOT use inline onclick (scope issues in SweetAlert modal)"
        );
        
        // Must use jQuery event binding after modal renders
        $this->assertMatchesRegularExpression(
            '/\$\([\'"]#downloadConfigBtn[\'"]\)\.on\([\'"]click[\'"]/',
            $content,
            "Download button must use jQuery .on('click') binding after modal renders"
        );
    }
    
    /**
     * Test that import UI has file upload option
     */
    public function testImportHasFileUpload(): void
    {
        $pageFile = self::$pluginDir . '/SMBShares.page';
        $content = file_get_contents($pageFile);
        
        // Import should have file input or FileReader
        $this->assertMatchesRegularExpression(
            '/type=["\']file["\']|FileReader|file.*input/i',
            $content,
            "Import should have file upload functionality"
        );
    }
    
    /**
     * Test that dynamically generated HTML does NOT use inline onclick
     * 
     * Bug: onclick='func()' in dynamically inserted HTML can't find functions.
     * Fix: Use event delegation with $(document).on('click', '.class', ...)
     */
    public function testNoDynamicInlineOnclick(): void
    {
        $pageFiles = glob(self::$pluginDir . '/*.page');
        
        foreach ($pageFiles as $file) {
            $content = file_get_contents($file);
            $basename = basename($file);
            
            // Find patterns where HTML is built with += and contains onclick
            // This catches: html += '<a onclick="func()">'
            if (preg_match('/html\s*\+?=.*onclick\s*=/', $content)) {
                $this->fail(
                    "$basename: Found inline onclick in dynamically generated HTML. " .
                    "Use event delegation instead: \$(document).on('click', '.class', handler)"
                );
            }
        }
        
        $this->assertTrue(true, "No inline onclick in dynamic HTML");
    }
}
