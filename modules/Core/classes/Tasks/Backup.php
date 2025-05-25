<?php

use Druidfi\Mysqldump\Mysqldump;

class Backup extends Task
{
    public const DAILY_BACKUP = 'Daily Backup';
    public const MANUAL_BACKUP = 'Manual Backup';

    private const EXCLUDED_DIRS = [
        'backups',
        '.git'
    ];

    /**
     * Creates a backup of the database and files.
     * Will write a dump of the database and all nameless files to a temporary folder,
     * then create a zip archive of that folder.
     * The zip archive will be saved in the backups folder.
     * The temporary folder will be deleted after the zip archive is created.
     * The zip archive will be named 'nameless_backup_<date>.zip'.
     */
    public function run(): string
    {
        $backupsFolder = ROOT_PATH . '/backups/';

        if (!$this->backupsFolderWritable($backupsFolder)) {
            return Task::STATUS_ERROR;
        }

        if (!$this->ensureHasDiskSpace()) {
            return Task::STATUS_ERROR;
        }

        $tempBackupFolder = $backupsFolder . date('Y-m-d_H-i-s') . '/';
        if (!is_dir($tempBackupFolder)) {
            mkdir($tempBackupFolder, 0755, true);
        }

        if (!$this->backupDatabase($tempBackupFolder)) {
            return Task::STATUS_ERROR;
        }

        if (!$this->backupFiles($tempBackupFolder)) {
            return Task::STATUS_ERROR;
        }

        $maxRetention = (int) Settings::get('backup_max_retention', '5');
        if ($maxRetention > 0) {
            $this->cleanupOldBackups($backupsFolder, $maxRetention);
        }

        if ($this->getName() === self::DAILY_BACKUP && Settings::get('backup_daily_scheduling', '0')) {
            self::scheduleNextDailyBackup();

            $this->setOutput([
                'schedule' => 'Next daily backup scheduled successfully',
            ]);
        }

        return Task::STATUS_COMPLETED;
    }

    private function backupsFolderWritable(string $backupsFolder): bool
    {
        if (!is_writable($backupsFolder)) {
            $this->setOutput([
                'error' => $backupsFolder . ' is not writable. Please check permissions.',
            ]);
            return false;
        }

        return true;
    }

    private function ensureHasDiskSpace(): bool
    {
        $dbConfig = Config::get('mysql');
        $dbName = $dbConfig['db'];

        // Get the size of the database
        $sizeQuery = DB::getInstance()->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size FROM information_schema.TABLES WHERE table_schema = ?", [$dbName]);
        $databaseSizeEstimate = $sizeQuery->first()->size ?? 0;
        $databaseSizeEstimate = $databaseSizeEstimate * 1024 * 1024;

        // ~50 MB for files, mostly due to the possibility of many image uploads
        $fileSizeEstimate = 50 * 1024 * 1024;

        $totalEstimatedSize = $databaseSizeEstimate + $fileSizeEstimate;
        $freeSpace = disk_free_space(ROOT_PATH);

        if ($totalEstimatedSize > $freeSpace) {
            $this->setOutput([
                'error' => 'Not enough disk space for backup. Estimated size: ' . Util::formatBytes($totalEstimatedSize) . ', free space: ' . Util::formatBytes($freeSpace),
            ]);
            return false;
        }

        return true;
    }

    private function backupDatabase(string $tempBackupFolder): bool
    {
        $dbConfig = Config::get('mysql');
        $dbHost = $dbConfig['host'];
        $dbPort = $dbConfig['port'];
        $dbName = $dbConfig['db'];
        $dbUsername = $dbConfig['username'];
        $dbPassword = $dbConfig['password'];

        // Dump the database to the temporary folder
        try {
            $dump = new Mysqldump("mysql:host={$dbHost};port={$dbPort};dbname={$dbName}", $dbUsername, $dbPassword);
            $dump->start($tempBackupFolder . 'database.sql');
        } catch (Exception $e) {
            $this->setOutput([
                'error' => 'Database backup failed: ' . $e->getMessage(),
            ]);
            return false;
        }

        return true;
    }

    private function backupFiles(string $tempBackupFolder): bool
    {
        $source = ROOT_PATH . '/';
        $destination = $tempBackupFolder . 'nameless/';

        mkdir($destination, 0755, true);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $subPath = str_replace($source, '', $file->getPathname());

            // Skip excluded directories
            $shouldSkip = false;
            foreach (self::EXCLUDED_DIRS as $excludedDir) {
                if (str_starts_with($subPath, $excludedDir . '/') || $subPath === $excludedDir) {
                    $shouldSkip = true;
                    break;
                }
            }

            if ($shouldSkip) {
                continue;
            }

            $destPath = $destination . $subPath;

            if ($file->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                copy($file->getPathname(), $destPath);
            }
        }

        // Create a zip archive of the backup folder
        $zip = new ZipArchive();
        $zipFileLocation = ROOT_PATH . '/backups/nameless_backup_' . date('Y-m-d_H-i-s') . '.zip';
        if ($zip->open($zipFileLocation, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->setOutput([
                'error' => 'Failed to create zip archive',
            ]);
            $this->deleteFolder($tempBackupFolder);
            return false;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempBackupFolder, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $subPath = str_replace($tempBackupFolder, '', $file->getPathname());
            if ($file->isDir()) {
                $zip->addEmptyDir($subPath);
            } else {
                $zip->addFile($file->getPathname(), $subPath);
            }
        }
        $zip->close();

        // Clean up the temporary backup folder
        $this->deleteFolder($tempBackupFolder);

        $this->setOutput([
            'result' => 'Backup created successfully',
            'file' => $zipFileLocation,
        ]);

        return true;
    }

    private function deleteFolder(string $folder): void
    {
        if (!is_dir($folder)) {
            return;
        }

        $files = array_diff(scandir($folder), ['.', '..']);
        foreach ($files as $file) {
            is_dir("$folder/$file") ? $this->deleteFolder("$folder/$file") : unlink("$folder/$file");
        }

        rmdir($folder);
    }

    /**
     * Clean up old backups based on the max retention setting
     */
    private function cleanupOldBackups(string $backupsFolder, int $maxRetention): void
    {
        $backupFiles = glob($backupsFolder . 'nameless_backup_*.zip');

        if (count($backupFiles) <= $maxRetention) {
            return;
        }

        usort($backupFiles, function ($a, $b) {
            return filemtime($b) - filemtime($a);
        });

        $filesToDelete = array_slice($backupFiles, $maxRetention);
        foreach ($filesToDelete as $file) {
            unlink($file);
        }

        $this->setOutput([
            'cleanup' => count($filesToDelete) . ' backups cleaned up successfully',
        ]);
    }

    /**
     * Schedule the next daily backup if daily scheduling is enabled
     */
    public static function scheduleNextDailyBackup(): void
    {
        // Cancel any existing scheduled daily backups to avoid duplicates
        self::unscheduleNextDailyBackup();

        // Schedule new daily backup for tomorrow
        $task = (new Backup())->fromNew(
            Module::getIdFromName('Core'),
            self::DAILY_BACKUP,
            null,
            Date::next()->getTimestamp(),
        );
        Queue::schedule($task);
    }

    /**
     * Unschedule the next daily backup
     */
    public static function unscheduleNextDailyBackup(): void
    {
        $existingTasks = DB::getInstance()->query(
            'SELECT id FROM nl2_queue WHERE `task` = ? AND `name` = ? AND `status` = ?',
            [Backup::class, self::DAILY_BACKUP, 'ready']
        )->results();

        foreach ($existingTasks as $task) {
            DB::getInstance()->delete('queue', ['id', $task->id]);
        }
    }
}
