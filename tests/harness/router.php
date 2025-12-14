<?php

declare(strict_types=1);

/**
 * Router for PHP built-in server
 * Handles .page files and auth bypass
 */

// Load comprehensive Unraid function emulation
require_once __DIR__ . '/UnraidFunctions.php';
require_once __DIR__ . '/UnraidJavaScript.php';

// Set CONFIG_BASE globally for all requests
// DOCUMENT_ROOT is harness_dir/usr/local/emhttp
// CONFIG_BASE should be harness_dir/boot/config
// So we need to go up 3 levels from DOCUMENT_ROOT
if (!defined('CONFIG_BASE')) {
    // Go from usr/local/emhttp up to harness root, then into boot/config
    $harnessRoot = dirname($_SERVER['DOCUMENT_ROOT'], 3);
    $configPath = $harnessRoot . '/boot/config';
    define('CONFIG_BASE', $configPath);
    error_log("Router: CONFIG_BASE set to: $configPath");
}

// Add harness bin directories to PATH for Samba mock scripts
$harnessRoot = $_SERVER['DOCUMENT_ROOT'] . '/..';
$existingPath = $_SERVER['PATH'] ?? getenv('PATH') ?? '/usr/bin:/bin';
$_SERVER['PATH'] = $harnessRoot . '/usr/bin:' . $harnessRoot . '/etc/rc.d:' . $existingPath;
putenv('PATH=' . $_SERVER['PATH']);

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

error_log("Router: Incoming request: $uri");
error_log("Router: Parsed path: $path");

// Serve test assets (fileTree, etc.)
if (preg_match('#^/test-assets/(.+)$#', $path, $matches)) {
    $assetFile = __DIR__ . '/assets/' . $matches[1];
    if (file_exists($assetFile)) {
        $ext = pathinfo($assetFile, PATHINFO_EXTENSION);
        $contentType = $ext === 'js' ? 'application/javascript' : 'text/css';
        header('Content-Type: ' . $contentType);
        readfile($assetFile);
        return true;
    }
    http_response_code(404);
    return false;
}

// Serve plugin static files (JS, CSS, images) from source directory
if (preg_match('#^/plugins/custom\.smb\.shares/(js|css|images)/(.+)$#', $path, $matches)) {
    $subdir = $matches[1];
    $filename = $matches[2];
    // Go up from harness to project root, then into source
    $staticFile = dirname(__DIR__, 2) . '/source/usr/local/emhttp/plugins/custom.smb.shares/' . $subdir . '/' . $filename;
    
    if (file_exists($staticFile)) {
        $ext = pathinfo($staticFile, PATHINFO_EXTENSION);
        $contentTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
        ];
        $contentType = $contentTypes[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $contentType);
        readfile($staticFile);
        return true;
    }
    error_log("Router: Static file not found: $staticFile");
    http_response_code(404);
    return false;
}

// Auto-discover and map all .page files from the plugin
// This mimics Unraid's behavior where /PageName maps to /plugins/*/PageName.page
$pageRoutes = discoverPageRoutes($_SERVER['DOCUMENT_ROOT']);
$cleanPath = rtrim($path, '/');

if (isset($pageRoutes[$cleanPath])) {
    $path = $pageRoutes[$cleanPath];
    error_log("Router: Auto-mapped $cleanPath to: $path");
}

/**
 * Discover all .page files and create route mappings
 * Caches results for performance
 */
function discoverPageRoutes(string $docRoot): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    
    $routes = [];
    $pluginDir = $docRoot . '/plugins/custom.smb.shares';
    
    if (!is_dir($pluginDir)) {
        error_log("Router: Plugin directory not found: $pluginDir");
        return $routes;
    }
    
    $pageFiles = glob($pluginDir . '/*.page');
    foreach ($pageFiles as $pageFile) {
        $basename = basename($pageFile, '.page');
        
        // Create multiple route patterns for each page:
        // /PageName -> /plugins/custom.smb.shares/PageName.page
        // /Settings/PageName -> /plugins/custom.smb.shares/PageName.page
        // /PageName.page -> /plugins/custom.smb.shares/PageName.page
        
        $pagePath = '/plugins/custom.smb.shares/' . basename($pageFile);
        
        $routes['/' . $basename] = $pagePath;
        $routes['/Settings/' . $basename] = $pagePath;
        $routes[$pagePath] = $pagePath;
        
        error_log("Router: Registered routes for $basename");
    }
    
    $cache = $routes;
    return $routes;
}

// AJAX tracing log with file locking
$ajaxLogFile = sys_get_temp_dir() . '/ajax-trace.log';

/**
 * Thread-safe log writing
 */
function writeAjaxLog(string $message): void {
    global $ajaxLogFile;
    $fp = fopen($ajaxLogFile, 'a');
    if ($fp && flock($fp, LOCK_EX)) {
        fwrite($fp, $message);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

// Handle regular .php files (endpoints like add.php, update.php, delete.php)
if (preg_match('/\.php$/', $path) && !preg_match('/\.page$/', $path)) {
    // Log AJAX request
    $logEntry = sprintf(
        "[%s] %s %s | POST: %s | Headers: %s\n",
        date('Y-m-d H:i:s'),
        $_SERVER['REQUEST_METHOD'],
        $path,
        json_encode($_POST),
        json_encode(getallheaders())
    );
    writeAjaxLog($logEntry);
    
    $file = $_SERVER['DOCUMENT_ROOT'] . $path;
    
    // Validate path doesn't escape document root
    $realFile = realpath($file);
    $realDocRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($realFile === false || $realDocRoot === false || strpos($realFile, $realDocRoot) !== 0) {
        error_log("Path traversal attempt: $path");
        http_response_code(403);
        return false;
    }
    
    if (file_exists($file)) {
        // Validate PHP syntax before executing
        $result = 0;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $result);
        if ($result !== 0) {
            error_log("PHP SYNTAX ERROR in $file: " . implode("\n", $output));
            http_response_code(500);
            echo "<!-- PHP SYNTAX ERROR:\n" . implode("\n", $output) . "\n-->\n";
            echo "500 - PHP Syntax Error";
            return false;
        }
        
        // Set include path and change to plugin directory
        $pluginDir = $_SERVER['DOCUMENT_ROOT'] . '/plugins/custom.smb.shares';
        set_include_path(get_include_path() . PATH_SEPARATOR . $pluginDir);
        chdir($pluginDir);
        
        // Capture output and log response
        ob_start();
        require $file;
        $response = ob_get_contents();
        ob_end_flush();
        
        // Log response
        $logEntry = sprintf(
            "[%s] RESPONSE %s | Status: %d | Body: %s\n",
            date('Y-m-d H:i:s'),
            $path,
            http_response_code(),
            $response // Full response
        );
        writeAjaxLog($logEntry);
        
        return true;
    }
    
    http_response_code(404);
    return false;
}

// Handle .page files
if (preg_match('/\.page$/', $path)) {
    $file = $_SERVER['DOCUMENT_ROOT'] . $path;
    error_log("Router: Looking for .page file: $file");
    
    // Validate path doesn't escape document root
    $realFile = realpath($file);
    $realDocRoot = realpath($_SERVER['DOCUMENT_ROOT']);
    if ($realFile === false || $realDocRoot === false || strpos($realFile, $realDocRoot) !== 0) {
        error_log("Path traversal attempt: $path");
        http_response_code(403);
        return false;
    }
    
    if (!file_exists($file)) {
        error_log("Router: File not found: $file");
        http_response_code(404);
        echo "404 - Page not found";
        return false;
    }
    
    error_log("Router: Loading .page file: $file");
    $content = file_get_contents($file);
    error_log("Router: Content length: " . strlen($content));
    
    $parts = explode('---', $content, 2);
    error_log("Router: Split into " . count($parts) . " parts");
    
    if (count($parts) < 2) {
        error_log("Router: Invalid .page format");
        http_response_code(500);
        echo "500 - Invalid .page format";
        return false;
    }
    
    // Validate PHP syntax in content section
    $pageContent = $parts[1];
    if (preg_match('/<\?php/i', $pageContent)) {
        $tempFile = tempnam(sys_get_temp_dir(), 'validate-page-') . '.php';
        
        try {
            file_put_contents($tempFile, $pageContent);
            $result = 0;
            exec('php -l ' . escapeshellarg($tempFile) . ' 2>&1', $output, $result);
            
            if ($result !== 0) {
                error_log("PHP SYNTAX ERROR in $file: " . implode("\n", $output));
                http_response_code(500);
                echo "<!-- PHP SYNTAX ERROR in .page file:\n" . implode("\n", $output) . "\n-->\n";
                echo "500 - PHP Syntax Error in .page file";
                return false;
            }
        } finally {
            if (file_exists($tempFile) && !unlink($tempFile)) {
                error_log("Failed to delete temp validation file: $tempFile");
            }
        }
    }
    
    // Parse metadata
    $meta = [];
    foreach (explode("\n", trim($parts[0])) as $line) {
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $meta[trim($key)] = trim($value, '"');
        }
    }
    
    // Output HTML with page content
    
    // Start output buffering to capture EVERYTHING for validation
    ob_start();
    
    // Load dependencies from config
    $depsFile = __DIR__ . '/dependencies.json';
    $dependencies = file_exists($depsFile) ? json_decode(file_get_contents($depsFile), true) : null;
    
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($meta['Title'] ?? 'Unraid') ?></title>
    <?php
    // Inject CSS dependencies
    if ($dependencies && isset($dependencies['tags']['css'])) {
        foreach ($dependencies['tags']['css'] as $tag) {
            echo $tag . "\n    ";
        }
    }
    ?>
    <style>
    /* Simple modal for testing */
    #sb-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 9998; display: none; }
    #sb-player { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; z-index: 9999; display: none; max-width: 90%; max-height: 90%; overflow: auto; }
    </style>
    <?php
    // Inject JS dependencies
    if ($dependencies && isset($dependencies['tags']['js'])) {
        foreach ($dependencies['tags']['js'] as $tag) {
            echo $tag . "\n    ";
        }
    }
    ?>
    <?php
    // Read CSRF token from var.ini
    // Harness structure: $harness_dir/var/local/emhttp/var.ini
    // DOCUMENT_ROOT is: $harness_dir/usr/local/emhttp
    // So we need to go up 3 levels: ../../.. to get to $harness_dir
    $varIniPath = $_SERVER['DOCUMENT_ROOT'] . '/../../../var/local/emhttp/var.ini';
    $csrfToken = 'test-token-123'; // fallback
    if (file_exists($varIniPath)) {
        $varIni = parse_ini_file($varIniPath);
        if (isset($varIni['csrf_token'])) {
            $csrfToken = $varIni['csrf_token'];
        }
    }
    
    // Inject comprehensive Unraid JavaScript emulation
    // This includes: CSRF handling, dialogStyle(), form enhancement, utilities, swal polyfill
    echo UnraidJavaScript::getInlineScript($csrfToken);
    ?>
    <script>
    // Simple Shadowbox replacement for testing
    var Shadowbox = {
        init: function() {},
        open: function(opts) {
            $('#sb-player').html(opts.content).show();
            $('#sb-overlay').show();
            // Call onFinish callback if provided
            if (opts.options && opts.options.onFinish) {
                opts.options.onFinish();
            }
        },
        close: function() {
            $('#sb-player').hide();
            $('#sb-overlay').hide();
        }
    };
    </script>
</head>
<body>
<div id="sb-overlay" onclick="Shadowbox.close()"></div>
<div id="sb-player"></div>
<?php
    // CONFIG_BASE is already defined at the top of the router
    // No need to redefine here
    
    // Set up $docroot like Unraid does - this is critical for includes
    // Unraid sets $docroot = $_SERVER['DOCUMENT_ROOT'] in local_prepend.php
    $docroot = $_SERVER['DOCUMENT_ROOT'];
    
    // Set include path to find plugin files
    $pluginDir = $docroot . '/plugins/custom.smb.shares';
    set_include_path(get_include_path() . PATH_SEPARATOR . $pluginDir);
    
    // Get page content
    $pageContent = trim($parts[1]);
    error_log("Router: Page content length: " . strlen($pageContent));
    
    // Replace absolute paths with $docroot-relative paths (like Unraid expects)
    $pageContent = str_replace(
        "require_once '/usr/local/emhttp/plugins/custom.smb.shares/",
        "require_once \"\$docroot/plugins/custom.smb.shares/",
        $pageContent
    );
    
    // Check if Markdown processing is enabled (default: true, like Unraid)
    $useMarkdown = !isset($meta['Markdown']) || strtolower($meta['Markdown'] ?? 'true') !== 'false';
    
    // Process page content using Unraid's pattern from MainContent.php:
    // 1. parse_text() - handles _(...)_ translation markers
    // 2. Markdown() - converts Markdown to HTML (if enabled)
    // 3. eval('?' . '>'.content) - executes PHP in the content
    //
    // This replicates: eval('?' . '>'.generateContent($page));
    // where generateContent() does: Markdown(parse_text($page['text']))
    
    error_log("Router: Processing with parse_text" . ($useMarkdown ? " + Markdown" : ""));
    
    // Process translations
    $processedContent = parse_text($pageContent);
    
    // Apply Markdown if enabled
    if ($useMarkdown) {
        $processedContent = Markdown($processedContent);
    }
    
    error_log("Router: Executing via eval (Unraid pattern)");
    
    // Execute using eval('?' . '>'.content) - exactly like Unraid does
    // This is why __DIR__ doesn't work in page content (resolves to cwd, not file dir)
    // and why we must use $docroot for includes
    try {
        // Use concatenation to avoid parser issues with close tag in string literal
        $phpCloseTag = '?' . '>';
        eval($phpCloseTag . $processedContent);
        error_log("Router: eval completed successfully");
    } catch (Throwable $e) {
        error_log("Router: eval error: " . $e->getMessage());
        echo "<pre>Error: " . htmlspecialchars($e->getMessage()) . "\n";
        echo htmlspecialchars($e->getTraceAsString()) . "</pre>";
    }
    
    // Close body and html tags after page content
    echo "\n</body>\n</html>";
    
    // Get buffered output and validate it (logs to side channel)
    $output = ob_get_clean();
    
    // Load validation function
    require_once __DIR__ . '/validate-append.php';
    validateOutput($output);  // Logs warnings to file, doesn't modify output
    
    // Output the content unchanged
    echo $output;
    
    return true;
}

// Default: serve file as-is
return false;
