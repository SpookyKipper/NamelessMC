<?php

class Upgrade extends Task
{

    public function run(): string {
        // 1. Put site in maintenance mode
        Settings::set('maintenance', '1');

        // 2. Attempt to download the upgrade zip
        $cache = new Cache(['name' => 'nameless', 'extension' => '.cache', 'path' => ROOT_PATH . '/cache/']);

        $cache->setCache('update_check');
        $updateCheck = $cache->retrieve('update_check');
        if (!$updateCheck) {
            $this->setOutput(['update_check' => 'No update available']);
            return Task::STATUS_FAILED;
        } else {
            $this->setOutput(['update_check' => "Found update: {$updateCheck->versionTag()}"]);
        }

        if (!ini_get('upload_tmp_dir')) {
            $tmp_dir = sys_get_temp_dir();
        } else {
            $tmp_dir = ini_get('upload_tmp_dir');
        }

        $upgradeZipPath = $tmp_dir . "namelessmc-upgrade-{$updateCheck->versionTag()}.zip";
        $downloadResponse = HttpClient::get($updateCheck->upgradeZipLink(), [
            'sink' => $upgradeZipPath,
            'timeout' => 30,
        ]);
        if ($downloadResponse->hasError()) {
            $this->setOutput(['zip_download' => "Error downloading upgrade zip: {$downloadResponse->getError()}"]);
            return Task::STATUS_FAILED;
        } else {
            $this->setOutput(['zip_download' => "Downloaded upgrade zip"]);
        }

        // 3. Unzip the upgrade zip
        $zip = new ZipArchive();
        $unzip = $zip->open($upgradeZipPath, ZipArchive::CREATE);
        if ($unzip !== true) {
            $this->setOutput(['unzip' => "Error unzipping upgrade zip: {$zip->getStatusString()}"]);
            return Task::STATUS_FAILED;
        } else {
            $this->setOutput(['unzip' => "Extracting upgrade zip: {$zip->getStatusString()}"]);
        }
        $zip->extractTo(ROOT_PATH . '/upgrade-test');
        $zip->close();

        // 4. Delete the upgrade zip
        if (file_exists($upgradeZipPath)) {
            unlink($upgradeZipPath);
            $this->setOutput(['zip_delete' => "Deleted upgrade zip: {$upgradeZipPath}"]);
        } else {
            $this->setOutput(['zip_delete' => "Error deleting upgrade zip: {$upgradeZipPath}"]);
        }

        // 5. Run the upgrade script
        $this->setOutput(['upgrade_script' => 'Running upgrade script...']);
        $upgradeScript = UpgradeScript::get(Settings::get('nameless_version'));
        if ($upgradeScript instanceof UpgradeScript) {
            $upgradeScript->run();
        } else {
            $this->setOutput(['upgrade_script' => 'No upgrade script found']);
        }
        $this->setOutput(['upgrade_script' => 'Upgrade script completed.']);

        // 6. Success
        Settings::set('maintenance', '0');

        $cache->setCache('update_check');
        if ($cache->isCached('update_check')) {
            $cache->erase('update_check');
        }

        $this->setOutput(['result' => "Upgrade to version {$updateCheck->versionTag()} completed successfully."]);

        return Task::STATUS_COMPLETED;
    }
}
