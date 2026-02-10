<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the File Browser link functionality in SMBShares.page
 * 
 * Feature request from Frank1940 (forum comment #1603494):
 * Add a link to Unraid's built-in File Browser for each share.
 */
class BrowseLinkTest extends TestCase
{
    private string $pageFile;
    private string $pageContent;

    protected function setUp(): void
    {
        $this->pageFile = dirname(__DIR__, 2) . '/source/usr/local/emhttp/plugins/custom.smb.shares/SMBShares.page';
        $this->pageContent = file_get_contents($this->pageFile);
    }

    /**
     * Test that the browse link uses the correct Unraid icon class
     */
    public function testBrowseLinkUsesCorrectIcon(): void
    {
        $this->assertStringContainsString(
            'icon-u-tab',
            $this->pageContent,
            'Browse link should use Unraid\'s icon-u-tab icon class'
        );
    }

    /**
     * Test that the browse link uses the correct CSS class
     */
    public function testBrowseLinkUsesViewClass(): void
    {
        $this->assertStringContainsString(
            'class="view"',
            $this->pageContent,
            'Browse link should use the "view" CSS class like other Unraid browse links'
        );
    }

    /**
     * Test that the browse link URL follows Unraid's pattern
     */
    public function testBrowseLinkUrlPattern(): void
    {
        // The URL should be /SMBShares/Browse?dir={path}
        $this->assertStringContainsString(
            '/SMBShares/Browse?dir=',
            $this->pageContent,
            'Browse link should use /SMBShares/Browse?dir= URL pattern'
        );
    }

    /**
     * Test that the browse link has a title attribute for accessibility
     */
    public function testBrowseLinkHasTitleAttribute(): void
    {
        // Should have title="Browse {path}" pattern
        $this->assertMatchesRegularExpression(
            '/title="[^"]*Browse[^"]*"/',
            $this->pageContent,
            'Browse link icon should have a title attribute for tooltip'
        );
    }

    /**
     * Test that the browse link properly escapes the path
     */
    public function testBrowseLinkEscapesPath(): void
    {
        // The path should be escaped with htmlspecialchars
        $this->assertStringContainsString(
            'htmlspecialchars($share[\'path\'])',
            $this->pageContent,
            'Browse link should escape the path with htmlspecialchars'
        );
    }

    /**
     * Test that the browse link is in the Path column (td element)
     */
    public function testBrowseLinkIsInPathColumn(): void
    {
        // The browse link should be inside a <td> element along with the path display
        $pattern = '/<td>\s*<a class="view"[^>]*Browse\?dir=[^>]*>.*?<\/a>\s*<\?=\s*htmlspecialchars\(\$share\[\'path\'\]\)/s';
        $this->assertMatchesRegularExpression(
            $pattern,
            $this->pageContent,
            'Browse link should be in the same td as the path display'
        );
    }

    /**
     * Test that the browse link doesn't use deprecated routes
     */
    public function testBrowseLinkDoesNotUseDeprecatedRoutes(): void
    {
        $deprecatedRoutes = [
            '/Settings/SMBShares/Browse',
            '/Settings/CustomSMBShares/Browse',
            '/CustomSMBShares/Browse',
        ];

        foreach ($deprecatedRoutes as $route) {
            $this->assertStringNotContainsString(
                $route,
                $this->pageContent,
                "Browse link should not use deprecated route: $route"
            );
        }
    }
}
