<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';

/**
 * Tests that all form fields are properly saved to JSON and used in config generation.
 * This ensures parity between:
 * 1. ShareForm.php (form fields)
 * 2. add.php/update.php (saved fields)
 * 3. lib.php generateSambaConfig() (config generation)
 */
class FieldParityTest extends TestCase
{
    private static string $configDir;

    public static function setUpBeforeClass(): void
    {
        self::$configDir = \ChrootTestEnvironment::setup();
        \ChrootTestEnvironment::mkdir('user/testshare');
        
        if (!defined('CONFIG_BASE')) {
            define('CONFIG_BASE', self::$configDir);
        }
        
        require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';
    }

    public static function tearDownAfterClass(): void
    {
        \ChrootTestEnvironment::teardown();
    }

    /**
     * Test that all configurable fields are saved and used in config generation
     */
    public function testAllFieldsSavedAndUsedInConfig(): void
    {
        $share = [
            'name' => 'testshare',
            'path' => \ChrootTestEnvironment::getMntPath('user/testshare'),
            'comment' => 'Test comment',
            'export' => 'e',
            'volsizelimit' => '',
            'case_sensitive' => 'auto',
            'security' => 'public',
            'user_access' => '{}',
            'hosts_allow' => '192.168.1.0/24',
            'hosts_deny' => '192.168.1.100',
            'fruit' => 'yes',
            'create_mask' => '0644',
            'directory_mask' => '0755',
            'force_user' => 'testuser',
            'force_group' => 'testgroup',
            'hide_dot_files' => 'no'
        ];

        // Save share
        $shares = [$share];
        $result = saveShares($shares);
        $this->assertNotFalse($result, 'saveShares should succeed');

        // Load and verify all fields persisted
        $loaded = loadShares();
        $this->assertCount(1, $loaded);
        $loadedShare = $loaded[0];

        // Verify all fields are present
        $this->assertEquals('testshare', $loadedShare['name']);
        $this->assertEquals($share['path'], $loadedShare['path']);
        $this->assertEquals('Test comment', $loadedShare['comment']);
        $this->assertEquals('e', $loadedShare['export']);
        $this->assertEquals('auto', $loadedShare['case_sensitive']);
        $this->assertEquals('public', $loadedShare['security']);
        $this->assertEquals('192.168.1.0/24', $loadedShare['hosts_allow']);
        $this->assertEquals('192.168.1.100', $loadedShare['hosts_deny']);
        $this->assertEquals('yes', $loadedShare['fruit']);
        $this->assertEquals('0644', $loadedShare['create_mask']);
        $this->assertEquals('0755', $loadedShare['directory_mask']);
        $this->assertEquals('testuser', $loadedShare['force_user']);
        $this->assertEquals('testgroup', $loadedShare['force_group']);
        $this->assertEquals('no', $loadedShare['hide_dot_files']);

        // Generate config and verify all fields are used
        $config = generateSambaConfig($shares);

        $this->assertStringContainsString('[testshare]', $config);
        $this->assertStringContainsString('comment = Test comment', $config);
        $this->assertStringContainsString('hosts allow = 192.168.1.0/24', $config);
        $this->assertStringContainsString('hosts deny = 192.168.1.100', $config);
        $this->assertStringContainsString('create mask = 0644', $config);
        $this->assertStringContainsString('directory mask = 0755', $config);
        $this->assertStringContainsString('force user = testuser', $config);
        $this->assertStringContainsString('force group = testgroup', $config);
        $this->assertStringContainsString('hide dot files = no', $config);
        $this->assertStringContainsString('vfs objects = catia fruit streams_xattr', $config);
    }

    /**
     * Test default values are applied when fields are empty
     */
    public function testDefaultValuesApplied(): void
    {
        $share = [
            'name' => 'defaultshare',
            'path' => \ChrootTestEnvironment::getMntPath('user/testshare'),
            'export' => 'e',
            'security' => 'public',
            // All other fields omitted - should use defaults
        ];

        $config = generateSambaConfig([$share]);

        // Verify defaults are applied
        $this->assertStringContainsString('force user = nobody', $config);
        $this->assertStringContainsString('force group = users', $config);
        $this->assertStringContainsString('create mask = 0664', $config);
        $this->assertStringContainsString('directory mask = 0775', $config);
        $this->assertStringContainsString('hide dot files = yes', $config);

        // hosts_allow and hosts_deny should NOT appear when empty
        $this->assertStringNotContainsString('hosts allow', $config);
        $this->assertStringNotContainsString('hosts deny', $config);
    }

    /**
     * Test empty force_user/force_group are not written to config
     */
    public function testEmptyForceUserGroupNotWritten(): void
    {
        $share = [
            'name' => 'noforce',
            'path' => \ChrootTestEnvironment::getMntPath('user/testshare'),
            'export' => 'e',
            'security' => 'public',
            'force_user' => '',
            'force_group' => '',
        ];

        $config = generateSambaConfig([$share]);

        // Empty force_user/force_group should not appear
        $this->assertStringNotContainsString('force user', $config);
        $this->assertStringNotContainsString('force group', $config);
    }

    /**
     * Test Time Machine shares get correct settings
     */
    public function testTimeMachineShareSettings(): void
    {
        $share = [
            'name' => 'timemachine',
            'path' => \ChrootTestEnvironment::getMntPath('user/testshare'),
            'export' => 'et',
            'volsizelimit' => '500000',
            'security' => 'public',
        ];

        $config = generateSambaConfig([$share]);

        $this->assertStringContainsString('vfs objects = catia fruit streams_xattr', $config);
        $this->assertStringContainsString('fruit:time machine = yes', $config);
        $this->assertStringContainsString('fruit:time machine max size = 500000M', $config);
    }

    /**
     * Test hidden share settings
     */
    public function testHiddenShareSettings(): void
    {
        $share = [
            'name' => 'hidden',
            'path' => \ChrootTestEnvironment::getMntPath('user/testshare'),
            'export' => 'eh',
            'security' => 'public',
        ];

        $config = generateSambaConfig([$share]);

        $this->assertStringContainsString('browseable = no', $config);
    }

    /**
     * Test case sensitivity settings
     */
    public function testCaseSensitivitySettings(): void
    {
        // Test 'forced' (force lower)
        $share = [
            'name' => 'lowercase',
            'path' => \ChrootTestEnvironment::getMntPath('user/testshare'),
            'export' => 'e',
            'case_sensitive' => 'forced',
            'security' => 'public',
        ];

        $config = generateSambaConfig([$share]);

        $this->assertStringContainsString('case sensitive = yes', $config);
        $this->assertStringContainsString('default case = lower', $config);
        $this->assertStringContainsString('preserve case = no', $config);
    }

    /**
     * Test secure mode with write list
     */
    public function testSecureModeWithWriteList(): void
    {
        $share = [
            'name' => 'secure',
            'path' => \ChrootTestEnvironment::getMntPath('user/testshare'),
            'export' => 'e',
            'security' => 'secure',
            'user_access' => json_encode([
                'alice' => 'read-write',
                'bob' => 'read-only',
            ]),
        ];

        $config = generateSambaConfig([$share]);

        $this->assertStringContainsString('guest ok = yes', $config);
        $this->assertStringContainsString('read only = yes', $config);
        $this->assertStringContainsString('write list = alice', $config);
    }

    /**
     * Test private mode with valid users
     */
    public function testPrivateModeWithValidUsers(): void
    {
        $share = [
            'name' => 'private',
            'path' => \ChrootTestEnvironment::getMntPath('user/testshare'),
            'export' => 'e',
            'security' => 'private',
            'user_access' => json_encode([
                'alice' => 'read-write',
                'bob' => 'read-only',
            ]),
        ];

        $config = generateSambaConfig([$share]);

        $this->assertStringContainsString('guest ok = no', $config);
        // Check valid users contains both users (order may vary)
        $this->assertMatchesRegularExpression('/valid users = (alice bob|bob alice)/', $config);
        $this->assertStringContainsString('write list = alice', $config);
    }
}
