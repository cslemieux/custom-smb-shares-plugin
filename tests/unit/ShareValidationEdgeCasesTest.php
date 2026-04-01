<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../helpers/ChrootTestEnvironment.php';
require_once __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares/include/lib.php';

class ShareValidationEdgeCasesTest extends TestCase {
    private static $configDir;
    
    public static function setUpBeforeClass(): void
    {
        self::$configDir = ChrootTestEnvironment::setup();
    }
    
    protected function setUp(): void
    {
        ChrootTestEnvironment::reset();
        ChrootTestEnvironment::mkdir('user/data');
        ChrootTestEnvironment::mkdir('user/appdata');
        ChrootTestEnvironment::mkdir('disk1/data');
        ChrootTestEnvironment::mkdir('cache/downloads');
    }
    
    public static function tearDownAfterClass(): void
    {
        ChrootTestEnvironment::teardown();
    }
    
    private function validPath(): string
    {
        return ChrootTestEnvironment::getMntPath('user/data');
    }
    
    // --- Share name validation ---
    
    public function testEmptyShareName() {
        $share = ['name' => '', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
        $this->assertStringContainsString('share name', strtolower($result[0]));
    }
    
    public function testShareNameWithSpacesIsValid() {
        // SMB allows spaces in share names (just not leading/trailing)
        $share = ['name' => 'my share', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    public function testShareNameWithLeadingSpaceIsInvalid() {
        $share = ['name' => ' leadingspace', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithTrailingSpaceIsInvalid() {
        $share = ['name' => 'trailingspace ', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithBracketsIsInvalid() {
        $share = ['name' => 'share[1]', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithQuotesIsInvalid() {
        $share = ['name' => 'share"name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithColonIsInvalid() {
        $share = ['name' => 'share:name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithAsteriskIsInvalid() {
        $share = ['name' => 'share*name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithEqualsIsInvalid() {
        $share = ['name' => 'share=name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithSlashIsInvalid() {
        $share = ['name' => 'share/name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithBackslashIsInvalid() {
        $share = ['name' => 'share\\name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithPipeIsInvalid() {
        $share = ['name' => 'share|name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithAngleBracketsIsInvalid() {
        $share = ['name' => 'share<name>', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithQuestionMarkIsInvalid() {
        $share = ['name' => 'share?name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithSemicolonIsInvalid() {
        $share = ['name' => 'share;name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithCommaIsInvalid() {
        $share = ['name' => 'share,name', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testShareNameWithAllowedSpecialChars() {
        // @, #, $, !, (, ), etc. are all valid in SMB
        $share = ['name' => 'share@home', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    public function testShareNameWithDotsIsValid() {
        $share = ['name' => 'my.share', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    public function testValidShareNameWithUnderscore() {
        $share = ['name' => 'my_share', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    public function testValidShareNameWithHyphen() {
        $share = ['name' => 'my-share', 'path' => $this->validPath()];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    // --- Path validation ---
    
    public function testEmptyPath() {
        $share = ['name' => 'test', 'path' => ''];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testRelativePath() {
        $share = ['name' => 'test', 'path' => 'relative/path'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testPathWithoutMnt() {
        $share = ['name' => 'test', 'path' => '/home/user/data'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testValidMntUserPath() {
        $share = ['name' => 'test', 'path' => ChrootTestEnvironment::getMntPath('user/appdata')];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    public function testValidMntDiskPath() {
        $share = ['name' => 'test', 'path' => ChrootTestEnvironment::getMntPath('disk1/data')];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    public function testValidMntCachePath() {
        $share = ['name' => 'test', 'path' => ChrootTestEnvironment::getMntPath('cache/downloads')];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    // --- Mask validation ---
    
    public function testMaskWithLetters() {
        $share = ['name' => 'test', 'path' => $this->validPath(), 'create_mask' => '066a'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testMaskTooShort() {
        $share = ['name' => 'test', 'path' => $this->validPath(), 'create_mask' => '066'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testMaskTooLong() {
        $share = ['name' => 'test', 'path' => $this->validPath(), 'create_mask' => '06644'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testMaskWithInvalidOctal() {
        $share = ['name' => 'test', 'path' => $this->validPath(), 'create_mask' => '0888'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    public function testValidDirectoryMask() {
        $share = ['name' => 'test', 'path' => $this->validPath(), 'directory_mask' => '0775'];
        $result = validateShare($share);
        
        $this->assertEmpty($result);
    }
    
    public function testInvalidDirectoryMask() {
        $share = ['name' => 'test', 'path' => $this->validPath(), 'directory_mask' => '999'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
    }
    
    // --- Multiple errors ---
    
    public function testMultipleErrors() {
        $share = ['name' => 'bad[name]', 'path' => '/home/data', 'create_mask' => '999'];
        $result = validateShare($share);
        
        $this->assertNotEmpty($result);
        $this->assertGreaterThanOrEqual(2, count($result));
    }
}
