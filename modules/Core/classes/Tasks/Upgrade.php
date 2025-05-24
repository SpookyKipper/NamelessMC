<?php

use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Download and execute update task for NamelessMC
 *
 * This simplified task:
 * 1. Validates update availability
 * 2. Downloads the upgrade package
 * 3. Executes the isolated CLI upgrade script synchronously
 *
 * @package NamelessMC\Tasks
 * @author Aberdeener
 * @version 2.3.0
 * @license MIT
 */
class Upgrade extends Task
{
    private const DOWNLOAD_TIMEOUT = 30;
    private const UPGRADE_SCRIPT_PATH = '/upgrade_cli.php';

    private Cache $cache;
    private string $tmpDir;

    public function run(): string
    {
        try {
            $updateCheck = $this->validateUpdateAvailable();
            if (!$updateCheck) {
                return Task::STATUS_FAILED;
            }

            $upgradeZipPath = $this->downloadUpgradePackage($updateCheck);
            if (!$upgradeZipPath) {
                return Task::STATUS_FAILED;
            }

            $success = $this->executeUpgrade($updateCheck, $upgradeZipPath);
            return $success ? Task::STATUS_COMPLETED : Task::STATUS_FAILED;

        } catch (Exception $e) {
            $this->setOutput([
                'error' => "Upgrade failed: {$e->getMessage()}",
                'exception' => $e->getMessage(),
                'failed_at' => date('Y-m-d H:i:s')
            ]);

            return Task::STATUS_FAILED;
        }
    }

    /**
     * Initialize cache and validate that an update is available
     *
     * @return UpdateCheck|null Returns UpdateCheck object if update available, null otherwise
     */
    private function validateUpdateAvailable(): ?UpdateCheck
    {
        $this->cache = new Cache([
            'name' => 'nameless',
            'extension' => '.cache',
            'path' => ROOT_PATH . '/cache/'
        ]);

        $this->cache->setCache('update_check');
        $updateCheck = $this->cache->retrieve('update_check');

        if (!$updateCheck) {
            $this->setOutput(['update_check' => 'No update available']);
            return null;
        }

        $this->setOutput(['update_check' => "Found update: {$updateCheck->versionTag()}"]);
        return $updateCheck;
    }

    /**
     * Download the upgrade package to temporary directory
     *
     * @param UpdateCheck $updateCheck The update information
     * @return string|null Returns path to downloaded file, or null on failure
     */
    private function downloadUpgradePackage(UpdateCheck $updateCheck): ?string
    {
        $this->tmpDir = $this->getTempDirectory();
        $upgradeZipPath = $this->tmpDir . DIRECTORY_SEPARATOR . "namelessmc-upgrade-{$updateCheck->versionTag()}.zip";

        $downloadResponse = HttpClient::get($updateCheck->upgradeZipLink(), [
            'sink' => $upgradeZipPath,
            'timeout' => self::DOWNLOAD_TIMEOUT,
        ]);

        if ($downloadResponse->hasError()) {
            $this->setOutput([
                'zip_download' => "Error downloading upgrade zip: {$downloadResponse->getError()}"
            ]);
            return null;
        }

        $this->setOutput([
            'zip_download' => "Downloaded upgrade zip to: {$upgradeZipPath}"
        ]);

        // Verify checksum of downloaded file
        if (!$this->verifyChecksum($upgradeZipPath, $updateCheck)) {
            return null;
        }

        return $upgradeZipPath;
    }

    /**
     * Execute the upgrade using the isolated CLI script with full output capture
     *
     * @param UpdateCheck $updateCheck The update information
     * @param string $upgradeZipPath Path to the downloaded upgrade package
     * @return bool True on success, false on failure
     */
    private function executeUpgrade(UpdateCheck $updateCheck, string $upgradeZipPath): bool
    {
        $this->setOutput(['upgrade' => 'Starting upgrade process...']);

        try {
            $upgradeDataPath = $this->createUpgradeDataFile($updateCheck, $upgradeZipPath);

            // Find PHP executable
            $phpFinder = new PhpExecutableFinder();
            $phpBinary = $phpFinder->find();
            if (!$phpBinary) {
                $this->setOutput(['error' => 'PHP executable not found']);
                return false;
            }

            // Execute CLI script synchronously
            $upgradeScript = ROOT_PATH . self::UPGRADE_SCRIPT_PATH;
            if (!is_file($upgradeScript)) {
                $this->setOutput(['error' => "Upgrade script not found: {$upgradeScript}"]);
                return false;
            }

            $process = new Process([$phpBinary, $upgradeScript, $upgradeDataPath]);
            $process->setWorkingDirectory(ROOT_PATH);
            $process->setTimeout(300); // 5 minutes

            // Run the process and capture all output
            $process->run();

            // Capture both stdout and stderr
            $output = $process->getOutput();
            $errorOutput = $process->getErrorOutput();
            $combinedOutput = trim($output . ($errorOutput ? "\n--- ERRORS ---\n" . $errorOutput : ''));

            // Store the complete output regardless of success/failure
            $outputData = [
                'upgrade_log' => $combinedOutput,
                'exit_code' => $process->getExitCode(),
                'started_at' => date('Y-m-d H:i:s'),
                'version_target' => $updateCheck->versionTag()
            ];

            if ($process->isSuccessful()) {
                $outputData['upgrade'] = 'Upgrade completed successfully';
                $outputData['result'] = "Upgrade completed successfully to version {$updateCheck->versionTag()}.";
                $this->setOutput($outputData);
                return true;
            } else {
                $outputData['error'] = 'Upgrade process failed with exit code: ' . $process->getExitCode();
                $this->setOutput($outputData);
                return false;
            }

        } catch (Exception $e) {
            $this->setOutput([
                'error' => "Upgrade failed: {$e->getMessage()}",
                'exception' => $e->getMessage(),
                'started_at' => date('Y-m-d H:i:s'),
                'version_target' => $updateCheck->versionTag()
            ]);
            return false;
        }
    }

    /**
     * Create a JSON data file with upgrade parameters for the CLI script
     *
     * @param UpdateCheck $updateCheck The update information
     * @param string $upgradeZipPath Path to the downloaded upgrade package
     * @return string Path to the created data file
     * @throws Exception If file creation fails
     */
    private function createUpgradeDataFile(UpdateCheck $updateCheck, string $upgradeZipPath): string
    {
        $upgradeData = [
            'zip_path' => $upgradeZipPath,
            'version_tag' => $updateCheck->versionTag(),
            'root_path' => ROOT_PATH
        ];

        $upgradeDataPath = $this->tmpDir . DIRECTORY_SEPARATOR . 'namelessmc_upgrade_data.json';
        file_put_contents($upgradeDataPath, json_encode($upgradeData, JSON_PRETTY_PRINT));

        return $upgradeDataPath;
    }

    /**
     * Verify the checksum of the downloaded upgrade package
     *
     * @param string $upgradeZipPath Path to the downloaded upgrade package
     * @param UpdateCheck $updateCheck The update information containing expected checksum
     * @return bool True if checksum matches, false otherwise
     */
    private function verifyChecksum(string $upgradeZipPath, UpdateCheck $updateCheck): bool
    {
        $expectedChecksum = $updateCheck->checksum();

        // Skip verification if no checksum is provided
        if (empty($expectedChecksum)) {
            $this->setOutput([
                'checksum_verify' => 'No checksum provided for verification, skipping checksum check'
            ]);
            return true;
        }

        $this->setOutput(['checksum_verify' => 'Verifying download integrity...']);

        $actualChecksum = hash_file('sha256', $upgradeZipPath);
        if ($actualChecksum === false) {
            $this->setOutput([
                'checksum_verify' => "Failed to calculate checksum for downloaded file: {$upgradeZipPath}"
            ]);
            return false;
        }

        if (hash_equals($expectedChecksum, $actualChecksum)) {
            $this->setOutput([
                'checksum_verify' => "Checksum verification passed (SHA256: {$actualChecksum})"
            ]);
            return true;
        }

        $this->setOutput([
            'checksum_verify' => "Checksum verification failed! Expected: {$expectedChecksum}, Got: {$actualChecksum}"
        ]);

        // Remove the corrupted file
        if (file_exists($upgradeZipPath)) {
            unlink($upgradeZipPath);
            $this->setOutput(['checksum_verify' => 'Removed corrupted download file']);
        }

        return false;
    }

    /**
     * Get the appropriate temporary directory
     *
     * @return string Path to temporary directory
     */
    private function getTempDirectory(): string
    {
        $tmpDir = ini_get('upload_tmp_dir');
        return $tmpDir ?: sys_get_temp_dir();
    }
}
