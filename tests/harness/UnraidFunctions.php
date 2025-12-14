<?php

declare(strict_types=1);

/**
 * Unraid WebGUI Function Emulation
 *
 * This file replicates Unraid's core helper functions for testing purposes.
 * It allows plugins to be tested outside of the Unraid environment while
 * maintaining compatibility with Unraid's rendering pipeline and utilities.
 *
 * Functions are organized into sections:
 * 1. Core Rendering Pipeline (parse_text, parse_file, Markdown)
 * 2. Translation Functions (_, _var)
 * 3. Display Helpers (my_scale, my_temp, my_number, my_time, my_disk)
 * 4. Configuration Management (parse_plugin_cfg, my_parse_ini_file)
 * 5. Form Helpers (mk_option)
 * 6. File Operations (file_put_contents_atomic)
 * 7. Logging (my_logger)
 * 8. Temperature Conversion (celsius, fahrenheit)
 *
 * @package UnraidTestHarness
 * @license GPL-2.0
 * @see https://github.com/unraid/webgui for original implementations
 */

// ============================================================================
// GLOBAL VARIABLES (Unraid Environment Simulation)
// ============================================================================

/**
 * Global display settings - controls formatting behavior
 * In production Unraid, this is loaded from /var/local/emhttp/display.cfg
 */
if (!isset($GLOBALS['display'])) {
    $GLOBALS['display'] = [
        'scale'    => -1,      // Auto-scale for my_scale()
        'number'   => '.,',    // Decimal point, thousands separator
        'unit'     => 'C',     // Temperature unit: C or F
        'date'     => '%c',    // Date format
        'time'     => '%R',    // Time format
        'critical' => 90,      // Critical usage threshold
        'warning'  => 70,      // Warning usage threshold
        'raw'      => false,   // Show raw disk names
        'wwn'      => false,   // Show WWN in disk IDs
        'text'     => 0,       // Text display mode
    ];
}

/**
 * Global language settings for localization
 */
if (!isset($GLOBALS['language'])) {
    $GLOBALS['language'] = [
        'prefix_SI'  => 'K M G T P E Z Y',      // SI prefixes (1000-based)
        'prefix_IEC' => 'Ki Mi Gi Ti Pi Ei Zi Yi', // IEC prefixes (1024-based)
    ];
}

/**
 * Global var array - system state
 * In production Unraid, this is loaded from /var/local/emhttp/var.ini
 */
if (!isset($GLOBALS['var'])) {
    $GLOBALS['var'] = [
        'csrf_token' => 'test_csrf_token_' . bin2hex(random_bytes(16)),
    ];
}

// ============================================================================
// PARSEDOWN LIBRARY LOADING
// ============================================================================

if (!class_exists('Parsedown')) {
    $parsedownPath = dirname(__DIR__, 2) . '/vendor/erusev/parsedown/Parsedown.php';
    if (file_exists($parsedownPath)) {
        require_once $parsedownPath;
    }
}

// ============================================================================
// SECTION 1: CORE RENDERING PIPELINE
// ============================================================================

/**
 * Process translation markers in text
 *
 * Converts _(text)_ to translated text.
 * Also handles help tags like :setting_help: and :setting_plug:
 *
 * This replicates Unraid's parse_text() from Translations.php
 *
 * @param string $text Input text with translation markers
 * @return string Processed text
 */
if (!function_exists('parse_text')) {
    function parse_text(string $text): string
    {
        // Process _(...)_ translation markers
        $text = preg_replace_callback(
            '/_\((.+?)\)_/m',
            function ($m) {
                return _($m[1]);
            },
            $text
        );

        // Process help tags (simplified - in production these load help content)
        // Note: Using concatenation to avoid parser issues with PHP tags in strings
        $phpOpen = '<' . '?php';
        $phpClose = '?' . '>';
        $text = preg_replace(
            ["/^:(.+_help):$/m", "/^:(.+_plug):$/m", "/^:end$/m"],
            [
                "$phpOpen /* help: $1 */ $phpClose",
                "$phpOpen /* plug: $1 */ if(true): $phpClose",
                "$phpOpen endif; $phpClose"
            ],
            $text
        );

        return $text;
    }
}

/**
 * Process a file through Unraid's rendering pipeline
 *
 * This replicates Unraid's parse_file() from Translations.php:
 * 1. Read file contents
 * 2. Process translation markers via parse_text()
 * 3. Optionally apply Markdown processing
 *
 * @param string $file Path to file
 * @param bool $markdown Whether to apply Markdown processing (default: true)
 * @return string Processed content
 */
if (!function_exists('parse_file')) {
    function parse_file(string $file, bool $markdown = true): string
    {
        if (!file_exists($file)) {
            error_log("parse_file: File not found: $file");
            return '';
        }

        $content = file_get_contents($file);
        if ($content === false) {
            error_log("parse_file: Failed to read file: $file");
            return '';
        }

        // Process translation markers
        $content = parse_text($content);

        // Apply Markdown if requested
        if ($markdown) {
            $content = Markdown($content);
        }

        return $content;
    }
}

/**
 * Convert Markdown to HTML
 *
 * Uses Unraid's actual MarkdownExtra implementation which properly handles
 * HTML/PHP passthrough via hashHTMLBlocks.
 *
 * @param string $text Markdown text
 * @return string HTML output
 */
if (!function_exists('Markdown')) {
    // Load Unraid's Markdown implementation
    require_once __DIR__ . '/markdown/MarkdownInterface.php';
    require_once __DIR__ . '/markdown/Markdown.php';
    require_once __DIR__ . '/markdown/MarkdownExtra.php';
    require_once __DIR__ . '/markdown/MarkdownExtra.inc.php';
    // The Markdown() function is now defined by MarkdownExtra.inc.php
}

// ============================================================================
// SECTION 2: TRANSLATION FUNCTIONS
// ============================================================================

/**
 * Translation function - returns text as-is in test environment
 *
 * In production Unraid, this looks up translations from language files.
 *
 * @param string $text Text to translate
 * @return string Translated text (unchanged in test environment)
 */
if (!function_exists('_')) {
    function _(string $text): string
    {
        return $text;
    }
}

/**
 * Safe variable access with default fallback
 *
 * This is one of the most commonly used Unraid helper functions.
 * It safely accesses array elements or variables without triggering notices.
 *
 * @param mixed $name Variable or array to access
 * @param string|null $key Array key (null for direct variable access)
 * @param mixed $default Default value if not found
 * @return mixed Value or default
 */
if (!function_exists('_var')) {
    function _var(&$name, $key = null, $default = '')
    {
        return is_null($key) ? ($name ?? $default) : ($name[$key] ?? $default);
    }
}

// ============================================================================
// SECTION 3: DISPLAY HELPERS
// ============================================================================

/**
 * Scale a byte value to appropriate unit
 *
 * Converts bytes to KB, MB, GB, etc. with proper formatting.
 * Supports both SI (1000-based) and IEC (1024-based) prefixes.
 *
 * @param float $value Value in bytes
 * @param string &$unit Output unit (e.g., "KB", "MB")
 * @param int|null $decimals Number of decimal places (null for auto)
 * @param int|null $scale Maximum scale level (null for auto)
 * @param int $kilo Base for scaling (1000 for SI, 1024 for IEC)
 * @return string Formatted number
 */
if (!function_exists('my_scale')) {
    function my_scale($value, &$unit, $decimals = null, $scale = null, $kilo = 1000)
    {
        global $display, $language;

        $scale = $scale ?? _var($display, 'scale', -1);
        $number = _var($display, 'number', '.,');
        $units = explode(' ', ' ' . ($kilo == 1000
            ? (_var($language, 'prefix_SI', 'K M G T P E Z Y'))
            : (_var($language, 'prefix_IEC', 'Ki Mi Gi Ti Pi Ei Zi Yi'))));
        $size = count($units);

        if ($scale == 0 && ($decimals === null || $decimals < 0)) {
            $decimals = 0;
            $unit = '';
        } else {
            $base = $value ? intval(floor(log($value, $kilo))) : 0;
            if ($scale > 0 && $base > $scale) {
                $base = $scale;
            }
            if ($base > $size) {
                $base = $size - 1;
            }
            $value /= pow($kilo, $base);

            if ($decimals === null) {
                $decimals = $value >= 100 ? 0 : ($value >= 10 ? 1 : (round($value * 100) % 100 === 0 ? 0 : 2));
            } elseif ($decimals < 0) {
                $decimals = $value >= 100 || round($value * 10) % 10 === 0 ? 0 : abs($decimals);
            }

            if ($scale < 0 && round($value, -1) == 1000) {
                $value = 1;
                $base++;
            }
            $unit = $units[$base] . _('B');
        }

        return number_format($value, $decimals, $number[0], $value > 9999 ? $number[1] : '');
    }
}

/**
 * Format a number with locale-aware separators
 *
 * @param float|int $value Number to format
 * @return string Formatted number
 */
if (!function_exists('my_number')) {
    function my_number($value)
    {
        global $display;
        $number = _var($display, 'number', '.,');
        return number_format($value, 0, $number[0], ($value >= 10000 ? $number[1] : ''));
    }
}

/**
 * Format a timestamp with locale-aware date/time
 *
 * @param int $time Unix timestamp
 * @param string|null $fmt Custom format (null for default)
 * @return string Formatted date/time
 */
if (!function_exists('my_time')) {
    function my_time($time, $fmt = null)
    {
        global $display;
        if (!$fmt) {
            $fmt = _var($display, 'date', '%c') .
                   (_var($display, 'date', '%c') != '%c' ? ", " . _var($display, 'time', '%R') : "");
        }
        return $time ? strftime($fmt, $time) : _('unknown');
    }
}

/**
 * Format a temperature value with unit conversion
 *
 * @param mixed $value Temperature in Celsius
 * @return string Formatted temperature with unit
 */
if (!function_exists('my_temp')) {
    function my_temp($value)
    {
        global $display;
        $unit = _var($display, 'unit', 'C');
        $number = _var($display, 'number', '.,');

        if (!is_numeric($value)) {
            return $value;
        }

        $temp = ($unit == 'F') ? fahrenheit($value) : str_replace('.', $number[0], (string)$value);
        return $temp . '&#8201;&#176;' . $unit;
    }
}

/**
 * Format a disk name for display
 *
 * Converts "disk1" to "Disk 1" unless raw mode is enabled.
 *
 * @param string $name Disk name
 * @param bool $raw Force raw output
 * @return string Formatted disk name
 */
if (!function_exists('my_disk')) {
    function my_disk($name, $raw = false)
    {
        global $display;
        return _var($display, 'raw') || $raw ? $name : ucfirst(preg_replace('/(\d+)$/', ' $1', $name));
    }
}

// ============================================================================
// SECTION 4: CONFIGURATION MANAGEMENT
// ============================================================================

/**
 * Parse a plugin configuration file
 *
 * Loads configuration from both RAM (default.cfg) and ROM (plugin.cfg),
 * merging them with ROM taking precedence.
 *
 * @param string $plugin Plugin name
 * @param bool $sections Parse as sections
 * @param int $scanner INI scanner mode
 * @return array Configuration array
 */
if (!function_exists('parse_plugin_cfg')) {
    function parse_plugin_cfg($plugin, $sections = false, $scanner = INI_SCANNER_NORMAL)
    {
        global $docroot;
        $docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp');

        $ram = "$docroot/plugins/$plugin/default.cfg";
        $rom = "/boot/config/plugins/$plugin/$plugin.cfg";

        $cfg_ram = [];
        if (file_exists($ram)) {
            $cfg_ram = my_parse_ini_file($ram, $sections, $scanner);
            if ($cfg_ram === false) {
                my_logger("Failed to parse config file: $ram", 'webgui');
                $cfg_ram = [];
            }
        }

        $cfg_rom = [];
        if (file_exists($rom)) {
            $cfg_rom = my_parse_ini_file($rom, $sections, $scanner);
            if ($cfg_rom === false) {
                my_logger("Failed to parse config file: $rom", 'webgui');
                $cfg_rom = [];
            }
        }

        return !empty($cfg_rom) ? array_replace_recursive($cfg_ram, $cfg_rom) : $cfg_ram;
    }
}

/**
 * Parse an INI string with Unraid-specific handling
 *
 * Strips HTML/PHP tags and handles # comment lines.
 *
 * @param string $text INI content
 * @param bool $sections Parse as sections
 * @param int $scanner INI scanner mode
 * @return array|false Parsed array or false on failure
 */
if (!function_exists('my_parse_ini_string')) {
    function my_parse_ini_string($text, $sections = false, $scanner = INI_SCANNER_NORMAL)
    {
        // Strip HTML/PHP tags, decode entities, remove # comments
        $cleaned = strip_tags(html_entity_decode(preg_replace('/^#.*$/m', '', $text)));
        return parse_ini_string($cleaned, $sections, $scanner);
    }
}

/**
 * Parse an INI file with Unraid-specific handling
 *
 * @param string $file Path to INI file
 * @param bool $sections Parse as sections
 * @param int $scanner INI scanner mode
 * @return array|false Parsed array or false on failure
 */
if (!function_exists('my_parse_ini_file')) {
    function my_parse_ini_file($file, $sections = false, $scanner = INI_SCANNER_NORMAL)
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }
        return my_parse_ini_string($content, $sections, $scanner);
    }
}

// ============================================================================
// SECTION 5: FORM HELPERS
// ============================================================================

/**
 * Generate an HTML <option> element for select dropdowns
 *
 * @param mixed $select Currently selected value
 * @param mixed $value Option value
 * @param string $text Option display text
 * @param string $extra Extra attributes
 * @return string HTML option element
 */
if (!function_exists('mk_option')) {
    function mk_option($select, $value, $text, $extra = ""): string
    {
        // Match Unraid's exact format with single quotes
        return "<option value='$value'" . ($value == $select ? " selected" : "") .
               (strlen($extra) ? " $extra" : "") . ">$text</option>";
    }
}

// ============================================================================
// SECTION 6: FILE OPERATIONS
// ============================================================================

/**
 * Atomic file write operation
 *
 * Writes to a temporary file first, then renames to prevent corruption.
 *
 * @param string $filename Target file path
 * @param string $data Content to write
 * @return int|false Bytes written or false on failure
 */
if (!function_exists('file_put_contents_atomic')) {
    function file_put_contents_atomic($filename, $data)
    {
        // Generate unique suffix
        do {
            $suffix = rand();
        } while (is_file("$filename$suffix"));

        $renResult = false;
        $writeResult = @file_put_contents("$filename$suffix", $data) === strlen($data);

        if ($writeResult) {
            $renResult = @rename("$filename$suffix", $filename);
        }

        if (!$writeResult || !$renResult) {
            my_logger("file_put_contents_atomic failed to write/rename $filename");
            @unlink("$filename$suffix");
            return false;
        }

        return strlen($data);
    }
}

// ============================================================================
// SECTION 7: LOGGING
// ============================================================================

/**
 * Log a message to syslog
 *
 * In test environment, logs to error_log instead.
 *
 * @param string $message Message to log
 * @param string $tag Log tag (default: 'webgui')
 */
if (!function_exists('my_logger')) {
    function my_logger($message, $tag = 'webgui')
    {
        // In test environment, use error_log
        // In production, this would use: exec("logger -t $tag -- " . escapeshellarg($message))
        error_log("[$tag] $message");
    }
}

// ============================================================================
// SECTION 8: TEMPERATURE CONVERSION
// ============================================================================

/**
 * Convert Fahrenheit to Celsius
 *
 * @param float $temp Temperature in Fahrenheit
 * @return int Temperature in Celsius (rounded)
 */
if (!function_exists('celsius')) {
    function celsius($temp)
    {
        return (int)round(($temp - 32) * 5 / 9);
    }
}

/**
 * Convert Celsius to Fahrenheit
 *
 * @param float $temp Temperature in Celsius
 * @return int Temperature in Fahrenheit (rounded)
 */
if (!function_exists('fahrenheit')) {
    function fahrenheit($temp)
    {
        return (int)round(9 / 5 * $temp) + 32;
    }
}

// ============================================================================
// SECTION 9: UTILITY FUNCTIONS
// ============================================================================

/**
 * Generate cache-busting URL for static files
 *
 * Appends file modification time as version parameter.
 *
 * @param string $file Path to file (relative to docroot)
 * @return string URL with version parameter
 */
if (!function_exists('autov')) {
    function autov($file)
    {
        global $docroot;
        $docroot = $docroot ?? ($_SERVER['DOCUMENT_ROOT'] ?? '/usr/local/emhttp');

        $path = "$docroot/$file";
        if (file_exists($path)) {
            $time = filemtime($path);
            return "$file?v=$time";
        }
        return $file;
    }
}

/**
 * Compress/decompress data
 *
 * @param string $data Data to process
 * @param bool $decompress True to decompress, false to compress
 * @return string Processed data
 */
if (!function_exists('compress')) {
    function compress($data, $decompress = false)
    {
        if ($decompress) {
            return @gzuncompress(base64_decode($data)) ?: $data;
        }
        return base64_encode(gzcompress($data, 9));
    }
}

/**
 * Explode string with limit and default values
 *
 * @param string $delimiter Delimiter
 * @param string $string String to explode
 * @param int $limit Maximum elements
 * @param mixed $default Default value for missing elements
 * @return array Exploded array
 */
if (!function_exists('my_explode')) {
    function my_explode($delimiter, $string, $limit = PHP_INT_MAX, $default = '')
    {
        $parts = explode($delimiter, $string, $limit);
        // Pad array to limit with default values
        while (count($parts) < $limit && $limit < PHP_INT_MAX) {
            $parts[] = $default;
        }
        return $parts;
    }
}

/**
 * Split string by regex with limit
 *
 * @param string $pattern Regex pattern
 * @param string $string String to split
 * @param int $limit Maximum elements
 * @return array Split array
 */
if (!function_exists('my_preg_split')) {
    function my_preg_split($pattern, $string, $limit = -1)
    {
        return preg_split($pattern, $string, $limit, PREG_SPLIT_NO_EMPTY);
    }
}
