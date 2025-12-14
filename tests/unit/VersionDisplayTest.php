<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for version file handling and display
 */
class VersionDisplayTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/version-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempDir)) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
    }

    public function testVersionFileExists(): void
    {
        $versionFile = dirname(__DIR__, 2) . '/source/usr/local/emhttp/plugins/custom.smb.shares/VERSION';
        $this->assertFileExists($versionFile, 'VERSION file should exist in source');
    }

    public function testVersionFileFormat(): void
    {
        $versionFile = dirname(__DIR__, 2) . '/source/usr/local/emhttp/plugins/custom.smb.shares/VERSION';
        $version = trim(file_get_contents($versionFile));
        
        // Version should match YYYY.MM.DD or YYYY.MM.DDx format
        $this->assertMatchesRegularExpression(
            '/^\d{4}\.\d{2}\.\d{2}[a-z]?$/',
            $version,
            'Version should be in YYYY.MM.DD or YYYY.MM.DDx format'
        );
    }

    public function testVersionReadingLogic(): void
    {
        // Create a mock VERSION file
        $versionFile = $this->tempDir . '/VERSION';
        file_put_contents($versionFile, "2025.12.14b\n");
        
        // Simulate the version reading logic from SMBShares.page
        $version = file_exists($versionFile)
            ? trim(file_get_contents($versionFile))
            : 'unknown';
        
        $this->assertEquals('2025.12.14b', $version);
    }

    public function testVersionFallbackWhenMissing(): void
    {
        $versionFile = $this->tempDir . '/nonexistent/VERSION';
        
        // Simulate the version reading logic
        $version = file_exists($versionFile)
            ? trim(file_get_contents($versionFile))
            : 'unknown';
        
        $this->assertEquals('unknown', $version);
    }

    public function testVersionWithWhitespace(): void
    {
        $versionFile = $this->tempDir . '/VERSION';
        file_put_contents($versionFile, "  2025.12.14c  \n\n");
        
        $version = trim(file_get_contents($versionFile));
        
        $this->assertEquals('2025.12.14c', $version);
    }
}
