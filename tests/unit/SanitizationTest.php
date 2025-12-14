<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

/**
 * Test sanitization functions
 */
class SanitizationTest extends TestCase
{
    public function testTrimsWhitespace()
    {
        $input = [
            'name' => '  TestShare  ',
            'path' => '  /mnt/user/test  ',
            'comment' => '  Test comment  '
        ];

        $result = sanitizeShareData($input);

        $this->assertEquals('TestShare', $result['name']);
        $this->assertEquals('/mnt/user/test', $result['path']);
        $this->assertEquals('Test comment', $result['comment']);
    }

    public function testFiltersEmptyStrings()
    {
        $input = [
            'name' => 'TestShare',
            'path' => '/mnt/user/test',
            'comment' => '',
            'empty_field' => '   '
        ];

        $result = sanitizeShareData($input);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('path', $result);
        $this->assertArrayNotHasKey('comment', $result);
        $this->assertArrayNotHasKey('empty_field', $result);
    }

    public function testPreservesValidData()
    {
        $input = [
            'name' => 'ValidShare',
            'path' => '/mnt/user/valid',
            'browseable' => 'yes',
            'read_only' => 'no'
        ];

        $result = sanitizeShareData($input);

        $this->assertEquals($input, $result);
    }

    // Tests for sanitizeForSambaConfig() - config injection prevention

    public function testSanitizeForSambaConfigStripsNewlines()
    {
        $this->assertEquals('test', sanitizeForSambaConfig("test\n"));
        $this->assertEquals('test', sanitizeForSambaConfig("test\r"));
        $this->assertEquals('test', sanitizeForSambaConfig("test\r\n"));
        $this->assertEquals('testvalue', sanitizeForSambaConfig("test\nvalue"));
        $this->assertEquals('testvalue', sanitizeForSambaConfig("test\rvalue"));
        $this->assertEquals('testvalue', sanitizeForSambaConfig("test\r\nvalue"));
    }

    public function testSanitizeForSambaConfigPreservesNormalStrings()
    {
        $this->assertEquals('normal string', sanitizeForSambaConfig('normal string'));
        $this->assertEquals('with-dashes_and_underscores', sanitizeForSambaConfig('with-dashes_and_underscores'));
        $this->assertEquals('/mnt/user/share', sanitizeForSambaConfig('/mnt/user/share'));
        $this->assertEquals('192.168.1.0/24', sanitizeForSambaConfig('192.168.1.0/24'));
    }

    public function testSanitizeForSambaConfigHandlesEmptyString()
    {
        $this->assertEquals('', sanitizeForSambaConfig(''));
    }

    public function testSanitizeForSambaConfigHandlesMultipleNewlines()
    {
        $this->assertEquals('abc', sanitizeForSambaConfig("a\nb\nc"));
        $this->assertEquals('abc', sanitizeForSambaConfig("a\r\nb\r\nc"));
    }

    // Tests for generateSambaConfig() injection prevention

    public function testGenerateSambaConfigPreventsCommentInjection()
    {
        $shares = [[
            'name' => 'TestShare',
            'path' => '/mnt/user/test',
            'comment' => "Innocent comment\npath = /etc\nread only = no",
            'enabled' => true
        ]];

        $config = generateSambaConfig($shares);

        // The injected path should NOT appear as a separate directive (at start of line)
        $this->assertStringNotContainsString("\n    path = /etc", $config);
        // The comment should be on a single line with newlines stripped
        $this->assertStringContainsString('comment = Innocent commentpath = /etcread only = no', $config);
        // There should only be ONE path directive at the start of a line
        $this->assertEquals(1, preg_match_all('/^\s+path = /m', $config));
    }

    public function testGenerateSambaConfigPreventsNameInjection()
    {
        $shares = [[
            'name' => "TestShare]\n[EvilShare",
            'path' => '/mnt/user/test',
            'enabled' => true
        ]];

        $config = generateSambaConfig($shares);

        // Should NOT create a second share section (no newline before [)
        $this->assertEquals(1, preg_match_all('/^\[/m', $config));
        $this->assertStringContainsString('[TestShare][EvilShare]', $config);
    }

    public function testGenerateSambaConfigPreventsPathInjection()
    {
        $shares = [[
            'name' => 'TestShare',
            'path' => "/mnt/user/test\nread only = no\nguest ok = yes",
            'enabled' => true
        ]];

        $config = generateSambaConfig($shares);

        // The injected directives should NOT appear as separate lines
        $this->assertStringNotContainsString("\n    read only = no\n    guest ok = yes", $config);
        // Path should be sanitized (all on one line)
        $this->assertStringContainsString('path = /mnt/user/testread only = noguest ok = yes', $config);
    }

    public function testGenerateSambaConfigPreventsHostsAllowInjection()
    {
        $shares = [[
            'name' => 'TestShare',
            'path' => '/mnt/user/test',
            'hosts_allow' => "192.168.1.0/24\npath = /etc",
            'enabled' => true
        ]];

        $config = generateSambaConfig($shares);

        // Should NOT have injected path as a separate directive
        $this->assertEquals(1, preg_match_all('/^\s+path = /m', $config));
        $this->assertStringContainsString('hosts allow = 192.168.1.0/24path = /etc', $config);
    }

    public function testGenerateSambaConfigPreventsForceUserInjection()
    {
        $shares = [[
            'name' => 'TestShare',
            'path' => '/mnt/user/test',
            'force_user' => "nobody\nforce group = root",
            'enabled' => true
        ]];

        $config = generateSambaConfig($shares);

        // Should NOT have injected force group as separate directive
        $this->assertStringContainsString('force user = nobodyforce group = root', $config);
        // The legitimate force group should still be there
        $this->assertStringContainsString("\n    force group = users\n", $config);
    }

    public function testGenerateSambaConfigPreventsUserAccessInjection()
    {
        $shares = [[
            'name' => 'TestShare',
            'path' => '/mnt/user/test',
            'security' => 'private',
            'user_access' => [
                "admin\npath = /etc" => 'read-write'
            ],
            'enabled' => true
        ]];

        $config = generateSambaConfig($shares);

        // Should NOT have injected path as separate directive
        $this->assertEquals(1, preg_match_all('/^\s+path = /m', $config));
        // Username should be sanitized
        $this->assertStringContainsString('valid users = adminpath = /etc', $config);
    }
}
