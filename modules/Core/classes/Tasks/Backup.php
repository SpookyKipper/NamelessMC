<?php

use Druidfi\Mysqldump\Mysqldump;

class Backup extends Task
{
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
        $tempBackupFolder = ROOT_PATH . '/backups/' . date('Y-m-d_H-i-s') . '/';
        if (!is_dir($tempBackupFolder)) {
            mkdir($tempBackupFolder, 0755, true);
        }

        if (!$this->backupDatabase($tempBackupFolder)) {
            return Task::STATUS_ERROR;
        }

        if (!$this->backupFiles($tempBackupFolder)) {
            return Task::STATUS_ERROR;
        }

        return Task::STATUS_COMPLETED;
    }

    private function backupDatabase($tempBackupFolder): bool
    {
        $dbConfig = Config::get('mysql');
        $dbHost = $dbConfig['host'];
        $dbPort = $dbConfig['port'];
        $dbName = $dbConfig['db'];
        $dbUsername = $dbConfig['username'];
        $dbPassword = $dbConfig['password'];

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

    private function backupFiles($tempBackupFolder): bool
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
            $this->deleteDirectory($tempBackupFolder);
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

        $this->deleteDirectory($tempBackupFolder);

        $this->setOutput([
            'result' => 'Backup created successfully',
            'file' => $zipFileLocation,
        ]);

        return true;
    }

    private function deleteDirectory($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->deleteDirectory("$dir/$file") : unlink("$dir/$file");
        }

        return rmdir($dir);
    }
}
