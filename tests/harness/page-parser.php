<?php
/**
 * Simple .page file parser for test harness
 */

error_log("PAGE PARSER CALLED: " . $_SERVER['REQUEST_URI']);

// Include local_prepend.php to set CONFIG_BASE and other globals
$prependFile = $_SERVER['DOCUMENT_ROOT'] . '/webGui/include/local_prepend.php';
if (file_exists($prependFile)) {
    require_once $prependFile;
}

// Get the requested page path
$requestUri = $_SERVER['REQUEST_URI'];
$documentRoot = $_SERVER['DOCUMENT_ROOT'];

error_log("Document root: $documentRoot");
error_log("Request URI: $requestUri");

// Build full path
$pagePath = $documentRoot . $requestUri;
error_log("Page path: $pagePath");

if (!file_exists($pagePath)) {
    error_log("Page not found: $pagePath");
    http_response_code(404);
    echo "<!DOCTYPE html><html><body><h1>404 - Page not found</h1><p>$pagePath</p></body></html>";
    exit;
}

$content = file_get_contents($pagePath);
error_log("Content length: " . strlen($content));

// Split on --- separator
$parts = explode('---', $content, 2);

if (count($parts) < 2) {
    error_log("Invalid .page format - no --- separator found");
    http_response_code(500);
    echo "<!DOCTYPE html><html><body><h1>500 - Invalid .page file format</h1></body></html>";
    exit;
}

// Extract content after ---
$pageContent = trim($parts[1]);
error_log("Page content length: " . strlen($pageContent));

// Simple HTML wrapper
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Custom SMB Shares</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
    var csrf_token = "test-token-123";
    </script>
</head>
<body>
<h1>TEST - Page Parser Working</h1>
<?php
// Execute the PHP content
try {
    eval('?>' . $pageContent);
} catch (Exception $e) {
    error_log("Error executing page content: " . $e->getMessage());
    echo "<pre>Error: " . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
</body>
</html>
