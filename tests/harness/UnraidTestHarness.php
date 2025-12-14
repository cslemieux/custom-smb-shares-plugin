<?php

declare(strict_types=1);

require_once __DIR__ . '/SambaMock.php';
require_once __DIR__ . '/HarnessConfig.php';
require_once __DIR__ . '/ProcessManager.php';
require_once __DIR__ . '/HarnessLogger.php';

/**
 * Unraid Test Harness
 * 
 * Creates a test environment that mimics Unraid's structure with auth bypass
 * for Selenium testing
 */
class UnraidTestHarness
{
    private static ?string $harness_dir = null;
    private static ?int $server_pid = null;
    private static int $port = HarnessConfig::DEFAULT_PORT;
    private static ?string $lock_file = null;
    /** @var resource|null */
    private static $lock_fp = null;
    
    /**
     * Kill any server running on the specified port
     * 
     * @param int $port Port number to check
     * @return void
     */
    private static function killServerOnPort(int $port): void
    {
        ProcessManager::killOnPort($port);
    }
    
    /**
     * Setup test harness
     * 
     * @param int|array $portOrConfig Port number or config array
     * @return array{url: string, harness_dir: string, samba: string} Harness configuration
     * @throws RuntimeException If harness already running or setup fails
     * @throws InvalidArgumentException If port is invalid
     */
    public static function setup($portOrConfig = HarnessConfig::DEFAULT_PORT): array
    {
        // Parse config
        $config = is_array($portOrConfig) ? $portOrConfig : ['port' => $portOrConfig];
        $port = $config['port'] ?? HarnessConfig::DEFAULT_PORT;
        $testDirs = $config['testDirs'] ?? HarnessConfig::DEFAULT_TEST_DIRS;
        $depsConfig = $config['dependencies'] ?? null;
        
        // Validate port
        if (!HarnessConfig::isValidPort($port)) {
            throw new InvalidArgumentException(
                "Port must be between " . HarnessConfig::PORT_MIN . 
                " and " . HarnessConfig::PORT_MAX . ", got: $port"
            );
        }
        
        self::$port = $port;
        
        // Use /tmp directly instead of sys_get_temp_dir() to avoid /var/folders complexity
        $baseDir = HarnessConfig::BASE_TEMP_DIR;
        
        // Kill any existing server on this port
        self::killServerOnPort($port);
        
        // Atomic lock file with flock()
        self::$lock_file = $baseDir . '/' . HarnessConfig::HARNESS_PREFIX . $port . '.lock';
        $lockFp = fopen(self::$lock_file, 'c+');
        if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            // Lock held by another process - kill it
            if ($lockFp) {
                $pid = (int)stream_get_contents($lockFp);
                fclose($lockFp);
                if ($pid > 0 && posix_kill($pid, 0)) {
                    posix_kill($pid, SIGTERM);
                    usleep(100000); // 100ms instead of 1s
                }
            }
            // Retry lock
            $lockFp = fopen(self::$lock_file, 'c+');
            flock($lockFp, LOCK_EX);
        }
        ftruncate($lockFp, 0);
        fwrite($lockFp, (string)getmypid());
        fflush($lockFp);
        self::$lock_fp = $lockFp; // Store handle to maintain lock
        
        self::$harness_dir = $baseDir . '/' . HarnessConfig::HARNESS_PREFIX . uniqid();
        
        // Register shutdown handler for emergency cleanup
        register_shutdown_function([self::class, 'emergencyCleanup']);
        
        // Create directory structure (batch operation)
        $dirs = [
            '/usr/local/emhttp/webGui/include',
            '/usr/local/emhttp/plugins',
            '/var/local/emhttp',
            '/usr/local/boot/config/plugins/custom.smb.shares',
            '/boot/config/plugins/custom.smb.shares',
            '/mnt/user',
            '/logs',
            '/run',
        ];
        
        foreach ($testDirs as $dir) {
            $dirs[] = '/mnt/user/' . $dir;
        }
        
        foreach ($dirs as $dir) {
            if (!mkdir(self::$harness_dir . $dir, 0755, true)) {
                throw new RuntimeException("Failed to create directory: $dir");
            }
        }
        
        // Create empty shares.json in both locations
        file_put_contents(
            self::$harness_dir . '/usr/local/boot/config/plugins/custom.smb.shares/shares.json',
            '[]'
        );
        file_put_contents(
            self::$harness_dir . '/boot/config/plugins/custom.smb.shares/shares.json',
            '[]'
        );
        
        // Create auth bypass
        self::createAuthBypass();
        
        // Create minimal var.ini with CSRF token
        self::createVarIni();
        
        // Copy plugin files
        self::copyPluginFiles();
        
        // Initialize Samba mock
        SambaMock::init(self::$harness_dir);
        // Trigger script creation (ensureScripts is called by reload)
        SambaMock::reload();
        
        // Start services
        self::startServices();
        
        return [
            'url' => 'http://localhost:' . self::$port,
            'harness_dir' => self::$harness_dir,
            'samba' => 'SambaMock'
        ];
    }
    
    /**
     * Create test share directory
     * 
     * @param string $path Path relative to harness root
     * @return string Full path to created directory
     * @throws RuntimeException If directory creation fails
     */
    public static function createShareDir(string $path): string
    {
        $fullPath = self::$harness_dir . $path;
        if (!is_dir($fullPath)) {
            if (!mkdir($fullPath, 0755, true)) {
                throw new RuntimeException("Failed to create share directory: $path");
            }
        }
        return $fullPath;
    }
    
    /**
     * Teardown test harness
     * 
     * @return void
     */
    /**
     * Setup harness from JSON config file
     * 
     * @param string $configPath Path to test config JSON file
     * @return array Harness configuration
     */
    public static function setupFromConfig(string $configPath): array
    {
        if (!file_exists($configPath)) {
            throw new InvalidArgumentException("Config file not found: $configPath");
        }
        
        $config = json_decode(file_get_contents($configPath), true);
        if (!$config) {
            throw new RuntimeException("Invalid JSON in config file: $configPath");
        }
        
        // Run dependency scanner if configured
        if ($config['dependencies']['scan'] ?? false) {
            require_once __DIR__ . '/DependencyScanner.php';
            
            $scanPaths = $config['dependencies']['scanPaths'] ?? [];
            $depsOutput = __DIR__ . '/dependencies.json';
            
            DependencyScanner::scanAndGenerate($scanPaths, $depsOutput);
        }
        
        // Build harness config
        $harnessConfig = [
            'port' => $config['harness']['port'] ?? 8888,
            'testDirs' => $config['harness']['createTestDirs'] ?? []
        ];
        
        return self::setup($harnessConfig);
    }
    
    public static function teardown(): void
    {
        self::stopServices();
        
        if (self::$harness_dir && is_dir(self::$harness_dir)) {
            exec('rm -rf ' . escapeshellarg(self::$harness_dir), $output, $result);
            
            // Verify cleanup succeeded
            if (is_dir(self::$harness_dir)) {
                HarnessLogger::warning("Failed to remove harness directory: " . self::$harness_dir);
            }
        }
        
        // Release lock and remove lock file
        if (self::$lock_fp) {
            flock(self::$lock_fp, LOCK_UN);
            fclose(self::$lock_fp);
            self::$lock_fp = null;
        }
        if (self::$lock_file && file_exists(self::$lock_file)) {
            unlink(self::$lock_file);
        }
        
        // Reset static state
        self::$harness_dir = null;
        self::$server_pid = null;
    }
    
    /**
     * Emergency cleanup on shutdown (handles fatal errors)
     * 
     * @return void
     */
    public static function emergencyCleanup(): void
    {
        // Only run if harness is still active
        if (self::$server_pid || self::$harness_dir) {
            HarnessLogger::error("Emergency cleanup triggered");
            self::teardown();
        }
    }
    
    /**
     * Create auth bypass - always return 200
     * 
     * @return void
     * @throws RuntimeException If file creation fails
     */
    private static function createAuthBypass(): void
    {
        $authBypass = <<<'PHP'
<?php
// Test harness auth bypass - always authorized
session_start();
$_SESSION['unraid_login'] = time();
session_write_close();
http_response_code(200);
exit;
PHP;
        
        file_put_contents(
            self::$harness_dir . '/usr/local/emhttp/auth-request.php',
            $authBypass
        );
        
        if (!file_exists(self::$harness_dir . '/usr/local/emhttp/auth-request.php')) {
            throw new RuntimeException('Failed to create auth bypass file');
        }
        
        // Create minimal local_prepend.php with CSRF validation
        $harnessDir = self::$harness_dir;
        $prepend = <<<PHP
<?php
// Define CONFIG_BASE for test harness
define('CONFIG_BASE', '{$harnessDir}/boot/config');

function csrf_terminate(\$reason) {
    error_log("CSRF error: \$reason");
    http_response_code(302);
    header('Location: /');
    exit;
}

// Add mock script paths to PATH
putenv('PATH={$harnessDir}/usr/bin:{$harnessDir}/etc/rc.d:/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin');
chdir('/usr/local/emhttp');
session_start();

// CSRF validation for POST requests
if (\$_SERVER['SCRIPT_NAME'] != '/login.php' && 
    \$_SERVER['SCRIPT_NAME'] != '/auth-request.php' && 
    isset(\$_SERVER['REQUEST_METHOD']) && 
    \$_SERVER['REQUEST_METHOD'] === 'POST') {
    
    \$var = parse_ini_file('{$harnessDir}/var/local/emhttp/var.ini');
    if (!isset(\$var['csrf_token'])) csrf_terminate("uninitialized");
    if (!isset(\$_POST['csrf_token'])) csrf_terminate("missing");
    if (\$var['csrf_token'] != \$_POST['csrf_token']) csrf_terminate("wrong");
    unset(\$_POST['csrf_token']);
}
PHP;
        
        file_put_contents(
            self::$harness_dir . '/usr/local/emhttp/webGui/include/local_prepend.php',
            $prepend
        );
        
        if (!file_exists(self::$harness_dir . '/usr/local/emhttp/webGui/include/local_prepend.php')) {
            throw new RuntimeException('Failed to create local_prepend.php');
        }
    }
    
    /**
     * Create var.ini with CSRF token
     * 
     * @return void
     * @throws RuntimeException If file creation fails
     */
    private static function createVarIni(): void
    {
        $token = bin2hex(random_bytes(16));
        $varIni = "csrf_token=\"$token\"\n";
        
        file_put_contents(
            self::$harness_dir . '/var/local/emhttp/var.ini',
            $varIni
        );
        
        if (!file_exists(self::$harness_dir . '/var/local/emhttp/var.ini')) {
            throw new RuntimeException('Failed to create var.ini');
        }
    }
    
    /**
     * Copy plugin files to harness
     * 
     * @return void
     * @throws RuntimeException If copy fails
     */
    private static function copyPluginFiles(): void
    {
        $pluginSrc = __DIR__ . '/../../source/usr/local/emhttp/plugins/custom.smb.shares';
        $pluginDst = self::$harness_dir . '/usr/local/emhttp/plugins/custom.smb.shares';
        
        $result = 0;
        exec('cp -r ' . escapeshellarg($pluginSrc) . ' ' . escapeshellarg($pluginDst) . ' 2>&1', $output, $result);
        
        if ($result !== 0 || !is_dir($pluginDst)) {
            throw new RuntimeException('Failed to copy plugin files: ' . implode("\n", $output));
        }
    }
    
    /**
     * Create nginx configuration
     */
    private static function createNginxConfig()
    {
        $harnessDir = self::$harness_dir;
        $port = self::$port;
        
        // Find nginx config files (Homebrew on macOS or standard Linux)
        $nginxEtc = file_exists('/opt/homebrew/etc/nginx') 
            ? '/opt/homebrew/etc/nginx'
            : '/etc/nginx';
        
        $config = <<<NGINX
worker_processes 1;
error_log {$harnessDir}/logs/nginx-error.log;
pid {$harnessDir}/run/nginx.pid;

events {
    worker_connections 1024;
}

http {
    include {$nginxEtc}/mime.types;
    default_type application/octet-stream;
    
    access_log {$harnessDir}/logs/nginx-access.log;
    
    server {
        listen {$port};
        server_name localhost;
        root {$harnessDir}/usr/local/emhttp;
        
        # Auth bypass for testing
        auth_request /auth-request.php;
        
        location / {
            index index.php index.html;
            try_files \$uri \$uri/ /index.php?\$args;
        }
        
        location ~ \.page$ {
            fastcgi_pass unix:{$harnessDir}/run/php-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME {$harnessDir}/../../tests/harness/page-parser.php;
            include {$nginxEtc}/fastcgi_params;
        }
        
        location ~ \.php$ {
            fastcgi_pass unix:{$harnessDir}/run/php-fpm.sock;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            include {$nginxEtc}/fastcgi_params;
        }
        
        location /plugins/ {
            alias {$harnessDir}/usr/local/emhttp/plugins/;
        }
    }
}
NGINX;
        
        self::$nginx_conf = self::$harness_dir . '/nginx.conf';
        file_put_contents(self::$nginx_conf, $config);
    }
    
    /**
     * Create PHP-FPM configuration
     */
    private static function createPhpFpmConfig()
    {
        $harnessDir = self::$harness_dir;
        $config = <<<INI
[global]
error_log = {$harnessDir}/logs/php-fpm.log
pid = {$harnessDir}/run/php-fpm.pid

[www]
listen = {$harnessDir}/run/php-fpm.sock
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[auto_prepend_file] = {$harnessDir}/usr/local/emhttp/webGui/include/local_prepend.php
INI;
        
        self::$php_ini = self::$harness_dir . '/php-fpm.conf';
        file_put_contents(self::$php_ini, $config);
    }
    
    /**
     * Start PHP built-in server
     * 
     * @return void
     * @throws RuntimeException If server fails to start
     */
    private static function startServices(): void
    {
        $docroot = self::$harness_dir . '/usr/local/emhttp';
        $router = __DIR__ . '/router.php';
        $port = self::$port;
        
        // Start PHP built-in server in background
        $logFile = self::$harness_dir . '/php-server.log';
        $cmd = sprintf(
            "php -S localhost:%d -t %s %s > %s 2>&1 & echo $!",
            (int)$port,
            escapeshellarg($docroot),
            escapeshellarg($router),
            escapeshellarg($logFile)
        );
        $pid = trim((string)shell_exec($cmd));
        self::$server_pid = (int)$pid;
        
        if (self::$server_pid <= 0) {
            throw new RuntimeException('Failed to start PHP server');
        }
        
        // Wait for server to start with fast polling
        if (!self::waitForServerReady($port, 1000)) {
            posix_kill(self::$server_pid, SIGTERM);
            throw new RuntimeException('PHP server started but not responding after 1s');
        }
        
        
        echo "Test harness started on http://localhost:" . self::$port . "\n";
    }
    
    /**
     * Stop services
     * 
     * @return void
     */
    private static function stopServices(): void
    {
        // Stop PHP built-in server
        if (self::$server_pid) {
            exec("kill " . (int)self::$server_pid . " 2>/dev/null");
        }
    }
    
    /**
     * Wait for server to become ready
     * 
     * @param int $port Port to check
     * @param int $timeoutMs Maximum wait time in milliseconds
     * @return bool True if server is ready, false if timeout
     */
    private static function waitForServerReady(int $port, int $timeoutMs): bool
    {
        $attempts = (int)($timeoutMs / 50); // 50ms per attempt
        
        for ($i = 0; $i < $attempts; $i++) {
            // Check if process is still alive
            if (self::$server_pid && !ProcessManager::isRunning(self::$server_pid)) {
                throw new RuntimeException('PHP server died immediately after start');
            }
            
            // Fast HTTP check
            $ch = curl_init("http://localhost:$port/");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 100);
            curl_setopt($ch, CURLOPT_NOBODY, true);
            
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode > 0) {
                return true;
            }
            
            usleep(50000);
        }
        
        return false;
    }
    
    /**
     * Get harness URL
     * 
     * @return string Base URL of test harness
     */
    public static function getUrl(): string
    {
        return 'http://localhost:' . self::$port;
    }
}
