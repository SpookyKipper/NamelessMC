<?php
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
        try {
            // Acquire lock to prevent concurrent upgrades
            $this->acquireLock();

            $updateCheck = $this->validateUpdateAvailable();

            $upgradeZipPath = $this->downloadUpgradePackage($updateCheck);

            $this->extractUpgradePackage($upgradeZipPath);

            $this->executeMigrations();

            Settings::set('nameless_version', $updateCheck->versionTag());
            Settings::set('version_update', null);

            $this->releaseLock();

        } catch (Exception $e) {
            $this->setOutput(['error' => $e->getMessage()]);
            $this->releaseLock();
            return Task::STATUS_FAILED;
        }

        return Task::STATUS_COMPLETED;
    }

    private function acquireLock(): void
    {
        $lockFile = ROOT_PATH . '/cache/upgrade.lock';
        if (file_exists($lockFile)) {
            throw new Exception('Upgrade is already running');
        }

        if (!file_put_contents($lockFile, 'locked')) {
            throw new Exception('Failed to create lock file');
        }
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
     * @return UpdateCheck Returns UpdateCheck object
     */
    private function validateUpdateAvailable(): UpdateCheck
    {
        $cache = new Cache([
            'name' => 'nameless',
            'extension' => '.cache',
            'path' => ROOT_PATH . '/cache/'
        ]);

        $cache->setCache('update_check');
        $updateCheck = $cache->retrieve('update_check');

        if (!$updateCheck) {
            throw new Exception('No update found');
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
    private function downloadUpgradePackage(UpdateCheck $updateCheck): string
    {
        $upgradeZipPath = $this->getTempDirectory() . DIRECTORY_SEPARATOR . "namelessmc-upgrade-{$updateCheck->versionTag()}.zip";

        $downloadResponse = HttpClient::get($updateCheck->upgradeZipLink(), [
            'sink' => $upgradeZipPath,
            'timeout' => self::DOWNLOAD_TIMEOUT,
        ]);

        if ($downloadResponse->hasError()) {
            throw new Exception("Error downloading upgrade zip: {$downloadResponse->getError()}");
        }

        $this->setOutput([
            'zip_download' => "Downloaded upgrade zip to: {$upgradeZipPath}"
        ]);

        $this->verifyChecksum($upgradeZipPath, $updateCheck);

        return $upgradeZipPath;
    }

    public function extractUpgradePackage(string $upgradeZipPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($upgradeZipPath) !== true) {
            throw new Exception("Failed to open upgrade zip: {$upgradeZipPath}");
        }

        // Extract to the root directory of the NamelessMC installation
        if (!$zip->extractTo(ROOT_PATH)) {
            throw new Exception("Failed to extract upgrade zip: {$upgradeZipPath}");
        }

        $zip->close();
        $this->setOutput(['zip_extract' => 'Upgrade package extracted successfully']);

        return;
    }

    /**
     * Verify the checksum of the downloaded upgrade package
     *
     * @param string $upgradeZipPath Path to the downloaded upgrade package
     * @param UpdateCheck $updateCheck The update information containing expected checksum
     */
    private function verifyChecksum(string $upgradeZipPath, UpdateCheck $updateCheck): void
    {
        $expectedChecksum = $updateCheck->checksum();

        // Skip verification if no checksum is provided, this should never happen in practice
        if (empty($expectedChecksum)) {
            $this->setOutput([
                'checksum_verify' => 'No checksum provided for verification, skipping checksum check'
            ]);
            return;
        }

        $actualChecksum = hash_file('sha256', $upgradeZipPath);
        if ($actualChecksum === false) {
            $this->setOutput([
                'checksum_verify' => "Failed to calculate checksum for downloaded file: {$upgradeZipPath}"
            ]);
            return;
        }

        if (hash_equals($expectedChecksum, $actualChecksum)) {
            $this->setOutput([
                'checksum_verify' => "Checksum verification passed (SHA256: {$actualChecksum})"
            ]);
            return;
        }

        // Remove the corrupted file
        unlink($upgradeZipPath);

        throw new Exception("Checksum verification failed: expected {$expectedChecksum}, got {$actualChecksum}");
    }

    private function executeMigrations(): void
    {
        $output = (new Phinx\Wrapper\TextWrapper(
            new Phinx\Console\PhinxApplication(),
            [
            'configuration' => __DIR__ . '/../../../migrations/phinx.php',
            ]
        ))->getMigrate();

       if (!str_contains($output, 'All Done')) {
            throw new Exception("Migration failed: {$output}");
        }

        $this->setOutput([
            'migrations' => $output,
        ]);
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
