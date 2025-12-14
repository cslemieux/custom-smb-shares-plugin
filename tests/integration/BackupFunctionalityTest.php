<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';

/**
 * Functional tests for backup system.
 * Tests actual behavior, not just code patterns.
 */
class BackupFunctionalityTest extends TestCase
{
    private static string $configBase;
    
    public static function setUpBeforeClass(): void
    {
        self::$configBase = \ChrootTestEnvironment::setup();
        
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';
    }
    
    public static function tearDownAfterClass(): void
    {
        \ChrootTestEnvironment::teardown();
    }
    
    protected function setUp(): void
    {
        // Create test shares file
        $sharesDir = self::$configBase . '/plugins/custom.smb.shares';
        if (!is_dir($sharesDir)) {
            mkdir($sharesDir, 0755, true);
        }
        
        $testShares = [
            ['name' => 'TestShare1', 'path' => '/mnt/user/test1', 'comment' => 'Test 1'],
            ['name' => 'TestShare2', 'path' => '/mnt/user/test2', 'comment' => 'Test 2'],
        ];
        file_put_contents($sharesDir . '/shares.json', json_encode($testShares, JSON_PRETTY_PRINT));
        
        // Clean up any existing backups
        $backupDir = $sharesDir . '/backups';
        if (is_dir($backupDir)) {
            array_map('unlink', glob($backupDir . '/*.json'));
        }
    }
    
    /**
     * Test backupShares() creates a timestamped backup file
     */
    public function testBackupSharesCreatesFile(): void
    {
        $result = backupShares(self::$configBase);
        
        $this->assertNotFalse($result, "backupShares should return path on success");
        $this->assertFileExists($result, "Backup file should exist");
        $this->assertMatchesRegularExpression('/shares_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.json$/', $result);
    }
    
    /**
     * Test backupShares() copies the actual content
     */
    public function testBackupSharesPreservesContent(): void
    {
        $originalFile = self::$configBase . '/plugins/custom.smb.shares/shares.json';
        $originalContent = file_get_contents($originalFile);
        
        $backupPath = backupShares(self::$configBase);
        $backupContent = file_get_contents($backupPath);
        
        $this->assertEquals($originalContent, $backupContent, "Backup should have identical content");
    }
    
    /**
     * Test listBackups() returns correct metadata
     */
    public function testListBackupsReturnsMetadata(): void
    {
        // Create a backup first
        backupShares(self::$configBase);
        
        $backups = listBackups(self::$configBase);
        
        $this->assertCount(1, $backups);
        $this->assertArrayHasKey('filename', $backups[0]);
        $this->assertArrayHasKey('date', $backups[0]);
        $this->assertArrayHasKey('size', $backups[0]);
        $this->assertArrayHasKey('shares', $backups[0]);
        $this->assertEquals(2, $backups[0]['shares'], "Should report 2 shares");
    }
    
    /**
     * Test viewBackup() returns the backup content
     */
    public function testViewBackupReturnsContent(): void
    {
        $backupPath = backupShares(self::$configBase);
        $filename = basename($backupPath);
        
        $content = viewBackup($filename, self::$configBase);
        
        $this->assertIsArray($content, "viewBackup should return array");
        $this->assertCount(2, $content, "Should have 2 shares");
        $this->assertEquals('TestShare1', $content[0]['name']);
        $this->assertEquals('TestShare2', $content[1]['name']);
    }
    
    /**
     * Test viewBackup() returns false for non-existent file
     */
    public function testViewBackupReturnsFalseForMissing(): void
    {
        $result = viewBackup('nonexistent.json', self::$configBase);
        $this->assertFalse($result);
    }
    
    /**
     * Test restoreBackup() replaces current shares
     */
    public function testRestoreBackupReplacesShares(): void
    {
        // Create backup of original
        $backupPath = backupShares(self::$configBase);
        $filename = basename($backupPath);
        
        // Modify current shares
        $sharesFile = self::$configBase . '/plugins/custom.smb.shares/shares.json';
        $modifiedShares = [['name' => 'Modified', 'path' => '/mnt/user/modified']];
        file_put_contents($sharesFile, json_encode($modifiedShares));
        
        // Verify modification
        $current = json_decode(file_get_contents($sharesFile), true);
        $this->assertEquals('Modified', $current[0]['name']);
        
        // Restore from backup
        $result = restoreBackup($filename, self::$configBase);
        $this->assertTrue($result, "restoreBackup should succeed");
        
        // Verify restoration
        $restored = json_decode(file_get_contents($sharesFile), true);
        $this->assertCount(2, $restored, "Should have 2 shares after restore");
        $this->assertEquals('TestShare1', $restored[0]['name']);
    }
    
    /**
     * Test deleteBackup() removes the file
     */
    public function testDeleteBackupRemovesFile(): void
    {
        $backupPath = backupShares(self::$configBase);
        $filename = basename($backupPath);
        
        $this->assertFileExists($backupPath);
        
        $result = deleteBackup($filename, self::$configBase);
        $this->assertTrue($result);
        $this->assertFileDoesNotExist($backupPath);
    }
    
    /**
     * Test deleteBackup() returns false for non-existent file
     */
    public function testDeleteBackupReturnsFalseForMissing(): void
    {
        $result = deleteBackup('nonexistent.json', self::$configBase);
        $this->assertFalse($result);
    }
    
    /**
     * Test pruneBackups() keeps only configured number
     */
    public function testPruneBackupsKeepsConfiguredCount(): void
    {
        $backupDir = self::$configBase . '/plugins/custom.smb.shares/backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        // Create 5 backup files directly (bypassing backupShares auto-prune)
        $testContent = json_encode([['name' => 'Test', 'path' => '/mnt/test']]);
        for ($i = 1; $i <= 5; $i++) {
            $filename = sprintf('shares_2024-01-%02d_12-00-00.json', $i);
            file_put_contents($backupDir . '/' . $filename, $testContent);
            // Set different mtimes
            touch($backupDir . '/' . $filename, strtotime("2024-01-0{$i}"));
        }
        
        $before = glob($backupDir . '/shares_*.json');
        $this->assertCount(5, $before, "Should have 5 backups before prune");
        
        // Prune to keep only 3
        pruneBackups($backupDir, 3);
        
        $remaining = glob($backupDir . '/shares_*.json');
        $this->assertCount(3, $remaining, "Should keep only 3 backups after prune");
    }
    
    /**
     * Test listBackups() returns newest first
     */
    public function testListBackupsNewestFirst(): void
    {
        // Create multiple backups
        backupShares(self::$configBase);
        usleep(1100000); // 1.1 second delay to ensure different timestamps
        backupShares(self::$configBase);
        
        $backups = listBackups(self::$configBase);
        
        $this->assertCount(2, $backups);
        // Newest should be first
        $this->assertGreaterThan(
            strtotime($backups[1]['date']),
            strtotime($backups[0]['date']),
            "First backup should be newer than second"
        );
    }
}
