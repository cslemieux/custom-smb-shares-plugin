<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';

/**
 * Test concurrent modification scenarios
 */
class ConcurrentModificationTest extends TestCase
{
    private static $configDir;

    public static function setUpBeforeClass(): void
    {
        self::$configDir = ChrootTestEnvironment::setup();
        if (!defined('CONFIG_BASE')) {
            define('CONFIG_BASE', self::$configDir);
        }
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';
    }

    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        ChrootTestEnvironment::mkdir('user/testshare');
    }

    /**
     * Test that rapid sequential saves don't corrupt data
     */
    public function testRapidSequentialSaves()
    {
        $shares = [];
        
        // Rapidly add 10 shares
        for ($i = 0; $i < 10; $i++) {
            ChrootTestEnvironment::mkdir("user/share{$i}");
            $shares[] = [
                'name' => "Share{$i}",
                'path' => ChrootTestEnvironment::getMntPath("user/share{$i}"),
                'enabled' => true
            ];
            saveShares($shares);
        }
        
        // Verify all shares were saved
        $loaded = loadShares();
        $this->assertCount(10, $loaded);
        
        for ($i = 0; $i < 10; $i++) {
            $this->assertEquals("Share{$i}", $loaded[$i]['name']);
        }
    }

    /**
     * Test that save-load-save cycle preserves data integrity
     */
    public function testSaveLoadSaveCycle()
    {
        ChrootTestEnvironment::mkdir('user/original');
        
        // Initial save
        $original = [
            [
                'name' => 'Original',
                'path' => ChrootTestEnvironment::getMntPath('user/original'),
                'comment' => 'Original comment',
                'enabled' => true
            ]
        ];
        saveShares($original);
        
        // Load, modify, save
        $loaded = loadShares();
        $loaded[0]['comment'] = 'Modified comment';
        saveShares($loaded);
        
        // Load again and verify
        $final = loadShares();
        $this->assertCount(1, $final);
        $this->assertEquals('Modified comment', $final[0]['comment']);
        $this->assertEquals('Original', $final[0]['name']);
    }

    /**
     * Test that concurrent reads don't interfere with each other
     */
    public function testConcurrentReads()
    {
        ChrootTestEnvironment::mkdir('user/testshare');
        
        $shares = [
            [
                'name' => 'TestShare',
                'path' => ChrootTestEnvironment::getMntPath('user/testshare'),
                'enabled' => true
            ]
        ];
        saveShares($shares);
        
        // Simulate multiple concurrent reads
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = loadShares();
        }
        
        // All reads should return identical data
        foreach ($results as $result) {
            $this->assertCount(1, $result);
            $this->assertEquals('TestShare', $result[0]['name']);
        }
    }

    /**
     * Test that findShareIndex works correctly after modifications
     */
    public function testFindShareIndexAfterModification()
    {
        ChrootTestEnvironment::mkdir('user/share1');
        ChrootTestEnvironment::mkdir('user/share2');
        ChrootTestEnvironment::mkdir('user/share3');
        
        $shares = [
            ['name' => 'Alpha', 'path' => ChrootTestEnvironment::getMntPath('user/share1'), 'enabled' => true],
            ['name' => 'Beta', 'path' => ChrootTestEnvironment::getMntPath('user/share2'), 'enabled' => true],
            ['name' => 'Gamma', 'path' => ChrootTestEnvironment::getMntPath('user/share3'), 'enabled' => true]
        ];
        saveShares($shares);
        
        // Find middle share
        $loaded = loadShares();
        $index = findShareIndex($loaded, 'Beta');
        $this->assertEquals(1, $index);
        
        // Remove first share
        array_shift($loaded);
        saveShares($loaded);
        
        // Beta should now be at index 0
        $reloaded = loadShares();
        $newIndex = findShareIndex($reloaded, 'Beta');
        $this->assertEquals(0, $newIndex);
    }

    /**
     * Test that empty shares array is handled correctly
     */
    public function testEmptySharesHandling()
    {
        // Save empty array
        saveShares([]);
        
        // Load should return empty array
        $loaded = loadShares();
        $this->assertIsArray($loaded);
        $this->assertEmpty($loaded);
        
        // findShareIndex should return -1
        $index = findShareIndex($loaded, 'NonExistent');
        $this->assertEquals(-1, $index);
    }

    /**
     * Test that special characters in share data are preserved
     */
    public function testSpecialCharactersPreserved()
    {
        ChrootTestEnvironment::mkdir('user/special');
        
        $shares = [
            [
                'name' => 'SpecialShare',
                'path' => ChrootTestEnvironment::getMntPath('user/special'),
                'comment' => 'Comment with "quotes" and \'apostrophes\' and <brackets>',
                'hosts_allow' => '192.168.1.0/24 10.0.0.0/8',
                'enabled' => true
            ]
        ];
        saveShares($shares);
        
        $loaded = loadShares();
        $this->assertEquals($shares[0]['comment'], $loaded[0]['comment']);
        $this->assertEquals($shares[0]['hosts_allow'], $loaded[0]['hosts_allow']);
    }
}
