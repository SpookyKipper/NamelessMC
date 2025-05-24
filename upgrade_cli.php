<?php
/**
 * Isolated CLI upgrade script for NamelessMC
 *
 * This script is completely isolated from NamelessMC and only performs file operations.
 */

// Ensure this script is only run from CLI
if (PHP_SAPI !== 'cli') {
    die('This script must be run from the command line.');
}

function logMessage($message, $level = 'INFO') {
    echo "[" . date('Y-m-d H:i:s') . "] [{$level}] {$message}\n";
}

function logError($message, $exitCode = 1) {
    logMessage($message, 'ERROR');
    exit($exitCode);
}

// Validate arguments
if ($argc < 2) {
    logError("Usage: php upgrade_cli.php /path/to/upgrade_data.json");
}

$upgradeDataFile = $argv[1];
if (!file_exists($upgradeDataFile)) {
    logError("Upgrade data file not found: {$upgradeDataFile}");
}

// Load upgrade data
$upgradeData = json_decode(file_get_contents($upgradeDataFile), true);
if (!$upgradeData) {
    logError("Invalid upgrade data file");
}

$zipPath = $upgradeData['zip_path'];
$versionTag = $upgradeData['version_tag'];
$rootPath = $upgradeData['root_path'];

logMessage("Starting NamelessMC upgrade to version {$versionTag}");

// Validate ZIP file
if (!file_exists($zipPath)) {
    logError("Upgrade ZIP file not found: {$zipPath}");
}

// Extract ZIP
logMessage("Extracting upgrade files...");
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    logError("Failed to open ZIP file: {$zipPath}");
}

// Create upgrade directory if it doesn't exist
$upgradeDir = $rootPath . '/upgrade_temp';
if (!is_dir($upgradeDir)) {
    mkdir($upgradeDir, 0755, true);
}

if (!$zip->extractTo($upgradeDir)) {
    $zip->close();
    logError("Failed to extract ZIP file");
}
$zip->close();
logMessage("Files extracted successfully");

// Copy files from upgrade directory to root (overwrites existing files)
logMessage("Installing upgrade files...");
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($upgradeDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

$fileCount = 0;
foreach ($iterator as $item) {
    $subPath = str_replace($upgradeDir, '', $item->getPathname());
    $destPath = $rootPath . $subPath;

    if ($item->isDir()) {
        if (!is_dir($destPath)) {
            if (!mkdir($destPath, 0755, true)) {
                logError("Failed to create directory: {$destPath}");
            }
        }
    } else {
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                logError("Failed to create parent directory: {$destDir}");
            }
        }
        if (!copy($item->getPathname(), $destPath)) {
            logError("Failed to copy file: {$subPath}");
        }
        $fileCount++;
    }
}
logMessage("Installed {$fileCount} files successfully");

// Update version in database directly
logMessage("Updating version in database...");
try {
    // Load config to get database connection
    $config = require $rootPath . '/core/config.php';

    $pdo = new PDO(
        "mysql:host={$config['mysql']['host']};port={$config['mysql']['port']};dbname={$config['mysql']['db']};charset={$config['mysql']['charset']}",
        $config['mysql']['username'],
        $config['mysql']['password']
    );

    // Update version
    $stmt = $pdo->prepare("INSERT INTO `nl2_settings` (`name`, `value`) VALUES ('nameless_version', ?) ON DUPLICATE KEY UPDATE `value` = ?");
    $stmt->execute([$versionTag, $versionTag]);

    // Clear version update flag
    $stmt = $pdo->prepare("DELETE FROM `nl2_settings` WHERE `name` = 'version_update'");
    $stmt->execute();

    logMessage("Database updated successfully");
} catch (Exception $e) {
    logError("Failed to update database: " . $e->getMessage());
}

// Cleanup
logMessage("Cleaning up...");
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($upgradeDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);

foreach ($files as $file) {
    if ($file->isDir()) {
        rmdir($file->getRealPath());
    } else {
        unlink($file->getRealPath());
    }
}
rmdir($upgradeDir);

if (file_exists($zipPath)) {
    unlink($zipPath);
}
if (file_exists($upgradeDataFile)) {
    unlink($upgradeDataFile);
}

logMessage("Upgrade completed successfully!");
exit(0);
