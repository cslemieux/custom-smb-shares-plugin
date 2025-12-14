<?php

declare(strict_types=1);

/**
 * Samba Mock for Test Harness
 * 
 * Simulates Samba service interactions for testing
 */
class SambaMock
{
    private static ?string $harnessDir = null;
    private static ?string $configFile = null;
    private static ?string $statusFile = null;
    private static ?string $logFile = null;
    private static bool $scriptsCreated = false;
    
    /**
     * Initialize Samba mock (lazy - only sets paths)
     * 
     * @param string $harnessDir Root directory of test harness
     * @return void
     */
    public static function init(string $harnessDir): void
    {
        self::$harnessDir = $harnessDir;
        self::$configFile = $harnessDir . '/boot/config/plugins/custom.smb.shares/smb-extra.conf';
        self::$statusFile = $harnessDir . '/var/run/samba-status';
        self::$logFile = $harnessDir . '/var/log/samba.log';
        
        // Create directories (suppress warnings if already exist)
        @mkdir(dirname(self::$configFile), 0755, true);
        @mkdir(dirname(self::$statusFile), 0755, true);
        @mkdir(dirname(self::$logFile), 0755, true);
        
        // Scripts created lazily on first use
        self::$scriptsCreated = false;
    }
    
    /**
     * Initialize mock scripts immediately (call this before using reloadSamba from lib.php)
     */
    public static function initScripts(): void
    {
        self::ensureScripts();
    }
    
    /**
     * Ensure mock scripts are created (lazy initialization)
     */
    private static function ensureScripts(): void
    {
        if (self::$scriptsCreated) {
            return;
        }
        
        self::createRcSamba();
        self::createTestparm();
        self::createSmbcontrol();
        self::setStatus('running');
        self::log('Samba mock initialized');
        
        self::$scriptsCreated = true;
    }
    
    /**
     * Create mock /etc/rc.d/rc.samba script
     */
    private static function createRcSamba()
    {
        $statusFile = self::$statusFile;
        $logFile = self::$logFile;
        $configFile = self::$configFile;
        
        $script = <<<'BASH'
#!/bin/bash
# Mock Samba control script for test harness
# Matches real Unraid /etc/rc.d/rc.samba behavior

STATUS_FILE="$statusFile"
LOG_FILE="$logFile"
CONFIG_FILE="$configFile"
DAEMON="Samba server daemon"

log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
}

samba_running() {
    [ -f "$STATUS_FILE" ] && [ "$(cat "$STATUS_FILE")" = "running" ]
}

samba_start() {
    if samba_running; then
        echo "$DAEMON...  Already started."
    else
        echo "running" > "$STATUS_FILE"
        log "Samba started"
        echo "$DAEMON...  Started."
    fi
}

samba_stop() {
    if ! samba_running; then
        echo "$DAEMON...  Already stopped."
    else
        echo "stopped" > "$STATUS_FILE"
        log "Samba stopped"
        echo "$DAEMON...  Stopped."
    fi
}

samba_restart() {
    samba_stop
    samba_start
}

samba_reload() {
    if samba_running; then
        log "Samba configuration reloaded"
        echo "Reloading $DAEMON..."
    else
        log "Samba reload failed - not running"
        echo "$DAEMON is not running."
        exit 1
    fi
}

samba_status() {
    if samba_running; then
        echo "$DAEMON is currently running."
        exit 0
    else
        echo "$DAEMON is not running."
        exit 1
    fi
}

case "$1" in
'start')
    samba_start
    ;;
'stop')
    samba_stop
    ;;
'restart')
    samba_restart
    ;;
'reload')
    samba_reload
    ;;
'status')
    samba_status
    ;;
*)
    samba_start
esac

exit 0
BASH;

        $script = str_replace('$statusFile', $statusFile, $script);
        $script = str_replace('$logFile', $logFile, $script);
        $script = str_replace('$configFile', $configFile, $script);
        
        $rcSamba = self::$harnessDir . '/etc/rc.d/rc.samba';
        @mkdir(dirname($rcSamba), 0755, true);
        file_put_contents($rcSamba, $script);
        chmod($rcSamba, 0755);
        
        self::log('Created rc.samba mock at ' . $rcSamba);
    }
    
    /**
     * Create mock testparm script
     */
    private static function createTestparm()
    {
        $script = <<<'BASH'
#!/bin/bash
# Mock testparm for config validation

# Parse options
SUPPRESS=0
CONFIG_FILE=""

while getopts "s" opt; do
    case $opt in
        s) SUPPRESS=1 ;;
    esac
done

# Shift past options to get config file argument
shift $((OPTIND-1))
CONFIG_FILE="${1:-/etc/samba/smb.conf}"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "Can't load $CONFIG_FILE - run testparm on this file to debug it"
    exit 1
fi

# Empty config is valid (no custom shares)
if [ ! -s "$CONFIG_FILE" ]; then
    if [ $SUPPRESS -eq 0 ]; then
        echo "Load smb config files from $CONFIG_FILE"
        echo "Loaded services file OK."
        echo ""
    fi
    exit 0
fi

# Check for syntax errors (unmatched brackets, etc)
if grep -q "^\[.*\[" "$CONFIG_FILE"; then
    echo "ERROR: Syntax error in $CONFIG_FILE"
    exit 1
fi

# Check for unclosed brackets (line starting with [ but no closing ])
if grep -q "^\[[^]]*$" "$CONFIG_FILE"; then
    echo "ERROR: Unclosed section bracket in $CONFIG_FILE"
    exit 1
fi

if [ $SUPPRESS -eq 0 ]; then
    echo "Load smb config files from $CONFIG_FILE"
    echo "Loaded services file OK."
    echo ""
fi

cat "$CONFIG_FILE"
exit 0
BASH;
        
        $scriptPath = self::$harnessDir . '/usr/bin/testparm';
        @mkdir(dirname($scriptPath), 0755, true);
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);
    }
    
    /**
     * Create mock smbcontrol script
     */
    private static function createSmbcontrol()
    {
        $statusFile = self::$statusFile;
        $logFile = self::$logFile;
        
        $script = <<<BASH
#!/bin/bash
# Mock smbcontrol for reload operations

STATUS_FILE="$statusFile"
LOG_FILE="$logFile"

log() {
    echo "\$(date '+%Y-%m-%d %H:%M:%S') - \$1" >> "\$LOG_FILE"
}

if [ "\$1" = "all" ] && [ "\$2" = "reload-config" ]; then
    if [ -f "\$STATUS_FILE" ] && [ "\$(cat "\$STATUS_FILE")" = "running" ]; then
        log "Configuration reloaded via smbcontrol"
        echo "reload-config: OK"
        exit 0
    else
        log "smbcontrol reload failed - Samba not running"
        echo "Samba is not running"
        exit 1
    fi
fi

echo "Usage: smbcontrol all reload-config"
exit 1
BASH;
        
        $scriptPath = self::$harnessDir . '/usr/bin/smbcontrol';
        $dir = dirname($scriptPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);
    }
    
    /**
     * Set Samba status
     */
    public static function setStatus($status)
    {
        file_put_contents(self::$statusFile, $status);
        self::log("Status changed to: $status");
    }
    
    /**
     * Get Samba status
     */
    public static function getStatus()
    {
        if (file_exists(self::$statusFile)) {
            return trim(file_get_contents(self::$statusFile));
        }
        return 'stopped';
    }
    
    /**
     * Write to log
     */
    public static function log($message)
    {
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents(self::$logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
    
    /**
     * Get log contents
     */
    public static function getLog()
    {
        if (file_exists(self::$logFile)) {
            return file_get_contents(self::$logFile);
        }
        return '';
    }
    
    /**
     * Clear log
     */
    public static function clearLog()
    {
        if (file_exists(self::$logFile)) {
            unlink(self::$logFile);
        }
    }
    
    /**
     * Get config file path
     */
    public static function getConfigFile()
    {
        return self::$configFile;
    }
    
    /**
     * Write config
     */
    public static function writeConfig($content)
    {
        file_put_contents(self::$configFile, $content);
        self::log("Config written (" . strlen($content) . " bytes)");
    }
    
    /**
     * Read config
     */
    public static function readConfig()
    {
        if (file_exists(self::$configFile)) {
            return file_get_contents(self::$configFile);
        }
        return '';
    }
    
    /**
     * Validate config using testparm
     * Uses real testparm if available, falls back to mock
     */
    public static function validateConfig()
    {
        self::ensureScripts();
        
        // Try real testparm first (more realistic validation)
        $realTestparm = self::findRealTestparm();
        $configFile = self::$configFile;
        
        if ($realTestparm !== null) {
            // Use real testparm for accurate Samba config validation
            exec(escapeshellarg($realTestparm) . " -s " . escapeshellarg($configFile) . " 2>&1", $output, $ret);
            self::log("Config validation (real testparm): " . ($ret === 0 ? 'PASS' : 'FAIL'));
        } else {
            // Fall back to mock testparm
            $testparm = self::$harnessDir . '/usr/bin/testparm';
            exec(escapeshellarg($testparm) . " -s " . escapeshellarg($configFile) . " 2>&1", $output, $ret);
            self::log("Config validation (mock testparm): " . ($ret === 0 ? 'PASS' : 'FAIL'));
        }
        
        return [
            'valid' => $ret === 0,
            'output' => implode("\n", $output),
            'exit_code' => $ret,
            'real_testparm' => $realTestparm !== null
        ];
    }
    
    /**
     * Find real testparm binary if available
     */
    private static function findRealTestparm(): ?string
    {
        // Common locations for testparm
        $locations = [
            '/opt/homebrew/bin/testparm',  // macOS Homebrew ARM
            '/usr/local/bin/testparm',      // macOS Homebrew Intel
            '/usr/bin/testparm',            // Linux
            '/usr/sbin/testparm',           // Some Linux distros
        ];
        
        foreach ($locations as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        // Try PATH
        $which = trim(shell_exec('which testparm 2>/dev/null') ?? '');
        if (!empty($which) && file_exists($which)) {
            return $which;
        }
        
        return null;
    }
    
    /**
     * Reload Samba config
     */
    public static function reload()
    {
        self::ensureScripts();
        
        $rcSamba = self::$harnessDir . '/etc/rc.d/rc.samba';
        exec(escapeshellarg($rcSamba) . " reload 2>&1", $output, $ret);
        
        return [
            'success' => $ret === 0,
            'output' => implode("\n", $output),
            'exit_code' => $ret
        ];
    }
    
    /**
     * Get shares from config
     */
    public static function getShares()
    {
        $config = self::readConfig();
        $shares = [];
        
        if (preg_match_all('/^\[([^\]]+)\]/m', $config, $matches)) {
            foreach ($matches[1] as $share) {
                if ($share !== 'global') {
                    $shares[] = $share;
                }
            }
        }
        
        return $shares;
    }
    
    /**
     * Check if share exists in config
     */
    public static function hasShare($name)
    {
        return in_array($name, self::getShares());
    }
    
    /**
     * Get share config section
     */
    public static function getShareConfig($name)
    {
        $config = self::readConfig();
        
        // Extract share section
        $pattern = '/\[' . preg_quote($name, '/') . '\](.*?)(?=\n\[|$)/s';
        if (preg_match($pattern, $config, $matches)) {
            return trim($matches[1]);
        }
        
        return null;
    }
}
