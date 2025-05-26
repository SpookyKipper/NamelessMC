<?php

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Download and execute update task for NamelessMC
 *
 * @package NamelessMC\Tasks
 * @author Aberdeener
 * @version 2.3.0
 * @license MIT
 */
class Upgrade extends Task
{
    private const DOWNLOAD_TIMEOUT = 30;

    public function run(): string
    {
        // Acquire lock to prevent concurrent upgrades
        if (!$this->acquireLock()) {
            return Task::STATUS_FAILED;
        }

        $updateCheck = $this->validateUpdateAvailable();
        if (!$updateCheck) {
            $this->releaseLock();
            return Task::STATUS_FAILED;
        }

        $upgradeZipPath = $this->downloadUpgradePackage($updateCheck);
        if (!$upgradeZipPath) {
            $this->releaseLock();
            return Task::STATUS_FAILED;
        }

        if (!$this->extractUpgradePackage($upgradeZipPath)) {
            $this->releaseLock();
            return Task::STATUS_FAILED;
        }

        if (!$this->executeMigrations()) {
            $this->releaseLock();
            return Task::STATUS_FAILED;
        }

        $this->releaseLock();

        return Task::STATUS_COMPLETED;
    }

    private function acquireLock(): bool
    {
        $lockFile = ROOT_PATH . '/cache/upgrade.lock';
        if (file_exists($lockFile)) {
            $this->setOutput(['error' => 'Upgrade is already running']);
            return false;
        }

        if (!file_put_contents($lockFile, 'locked')) {
            $this->setOutput(['error' => 'Failed to create lock file']);
            return false;
        }

        return true;
    }

    private function releaseLock(): void
    {
        $lockFile = ROOT_PATH . '/cache/upgrade.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }

    /**
     * Validate that an update is available
     *
     * @return UpdateCheck|null Returns UpdateCheck object if update available, null otherwise
     */
    private function validateUpdateAvailable(): ?UpdateCheck
    {
        $cache = new Cache([
            'name' => 'nameless',
            'extension' => '.cache',
            'path' => ROOT_PATH . '/cache/'
        ]);

        $cache->setCache('update_check');
        $updateCheck = $cache->retrieve('update_check');

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
        $upgradeZipPath = $this->getTempDirectory() . DIRECTORY_SEPARATOR . "namelessmc-upgrade-{$updateCheck->versionTag()}.zip";

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

        if (!$this->verifyChecksum($upgradeZipPath, $updateCheck)) {
            return null;
        }

        return $upgradeZipPath;
    }

    public function extractUpgradePackage(string $upgradeZipPath): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($upgradeZipPath) !== true) {
            $this->setOutput([
                'zip_extract' => "Failed to open upgrade zip: {$upgradeZipPath}"
            ]);
            return false;
        }

        // Extract to the root directory of the NamelessMC installation
        if (!$zip->extractTo(ROOT_PATH)) {
            $this->setOutput([
                'zip_extract' => "Failed to extract upgrade zip: {$upgradeZipPath}"
            ]);
            return false;
        }

        $zip->close();
        $this->setOutput(['zip_extract' => 'Upgrade package extracted successfully']);

        return true;
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

        // Skip verification if no checksum is provided, this should never happen in practice
        if (empty($expectedChecksum)) {
            $this->setOutput([
                'checksum_verify' => 'No checksum provided for verification, skipping checksum check'
            ]);
            return true;
        }

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
        unlink($upgradeZipPath);
        $this->setOutput(['checksum_verify' => 'Removed corrupted download file']);

        return false;
    }

    private function executeMigrations(): bool
    {
        $process = new Process([
            (new PhpExecutableFinder())->find(), // returns '' locally, could be a laravel valet quirk? works if i hardcode full homebrew path
            'vendor/bin/phinx',
            'migrate',
            '-c',
            'core/migrations/phinx.php',
        ]);

        $process->setWorkingDirectory(ROOT_PATH);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            $this->setOutput([
                'migrations' => "Migrations failed: {$e->getMessage()}"
            ]);
            return false;
        }

        $this->setOutput([
            'migrations' => $process->getOutput(),
        ]);

        return $process->isSuccessful();
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
