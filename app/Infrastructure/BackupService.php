<?php

/**
 * Author: ramanpal singh
 * URL: https://kwebby.com
 */

namespace App\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class BackupService
{
    public function create(string $password): string
    {
        $this->requireSqlAndPassword($password);
        if (! app()->isDownForMaintenance()) {
            throw new \RuntimeException('Enter maintenance mode before a consistent backup.');
        }
        $root = rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR);
        $this->assertNoSymlinks($root);
        $directory = $root.'/backups';
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/'.now()->format('Ymd-His').'-'.Str::random(8).'.zip';
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new \RuntimeException('Cannot create backup archive.');
        }
        $zip->setPassword($password);
        try {
            $snapshot = DB::transaction(fn () => ['format' => 1, 'created_at' => now()->toISOString(), 'key_fingerprint' => hash('sha256', config('app.key')), 'records' => DB::table('cms_records')->get()->map(fn ($row) => (array) $row)->all(), 'sequences' => DB::table('cms_locks')->get()->map(fn ($row) => (array) $row)->all()]);
            $files = [];
            foreach (Storage::disk('local')->allFiles() as $relative) {
                if (str_starts_with($relative, 'backups/') || str_starts_with($relative, 'restore-') || str_starts_with($relative, '.')) {
                    continue;
                }
                $absolute = $root.'/'.$relative;
                if (is_link($absolute)) {
                    throw new \RuntimeException('Symlinks are not permitted in protected storage.');
                }
                $files[$relative] = hash_file('sha256', $absolute);
                $zip->addFile($absolute, 'files/'.$relative);
                if (! $zip->setEncryptionName('files/'.$relative, ZipArchive::EM_AES_256)) {
                    throw new \RuntimeException('Unable to encrypt a backup file.');
                }
            }
            $snapshot['files'] = $files;
            $zip->addFromString('snapshot.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
            if (! $zip->setEncryptionName('snapshot.json', ZipArchive::EM_AES_256)) {
                throw new \RuntimeException('Unable to encrypt the database snapshot.');
            }
        } catch (\Throwable $error) {
            $zip->close();
            @unlink($path);
            throw $error;
        }
        if (! $zip->close()) {
            @unlink($path);
            throw new \RuntimeException('Backup could not be finalized.');
        }
        chmod($path, 0600);

        return $path;
    }

    public function restore(string $path, string $password): void
    {
        $this->requireSqlAndPassword($password);
        if (! app()->isDownForMaintenance()) {
            throw new \RuntimeException('Restoration requires maintenance mode.');
        }
        if (DB::table('cms_records')->exists() || DB::table('cms_locks')->exists()) {
            throw new \RuntimeException('Restore only to an empty, migrated database.');
        }
        if (! is_file($path) || filesize($path) > 1024 * 1024 * 1024) {
            throw new \RuntimeException('Archive is missing or exceeds the 1 GiB restore limit.');
        }
        $root = rtrim(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR);
        $this->assertNoSymlinks($root);
        foreach (Storage::disk('local')->allFiles() as $existing) {
            if (! str_starts_with($existing, 'backups/') && $existing !== '.gitignore') {
                throw new \RuntimeException('Restore target storage must be empty.');
            }
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Cannot open backup.');
        }
        $zip->setPassword($password);
        $stage = $root.'/restore-'.Str::uuid();
        $created = [];
        try {
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (($stat['encryption_method'] ?? 0) !== ZipArchive::EM_AES_256) {
                    throw new \RuntimeException('Backup entries must be encrypted with AES-256.');
                }
                $name = $stat['name'];
                $total += $stat['size'];
                if ($zip->numFiles > 50000 || $total > 2 * 1024 * 1024 * 1024 || $stat['size'] > 128 * 1024 * 1024 || isset($names[$name]) || ! preg_match('~^(snapshot\.json|files/[A-Za-z0-9_./-]+)$~D', $name) || str_contains($name, '..') || str_contains($name, '//')) {
                    throw new \RuntimeException('Invalid or oversized archive.');
                }
                $names[$name] = true;
            }
            $json = $zip->getFromName('snapshot.json');
            if ($json === false) {
                throw new \RuntimeException('Cannot decrypt backup.');
            }
            $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (($snapshot['format'] ?? 0) !== 1 || ! hash_equals($snapshot['key_fingerprint'] ?? '', hash('sha256', config('app.key')))) {
                throw new \RuntimeException('Restore requires the original APP_KEY and a supported backup format.');
            }
            mkdir($stage, 0700, true);
            foreach ($snapshot['files'] as $relative => $hash) {
                if (! preg_match('~^[A-Za-z0-9_./-]+$~D', $relative) || str_contains($relative, '..') || str_starts_with($relative, '/') || str_starts_with($relative, 'backups/')) {
                    throw new \RuntimeException('Invalid file manifest.');
                }
                $bytes = $zip->getFromName('files/'.$relative);
                if ($bytes === false || ! hash_equals($hash, hash('sha256', $bytes))) {
                    throw new \RuntimeException('File integrity check failed.');
                }
                if (Storage::disk('local')->exists($relative)) {
                    throw new \RuntimeException('Restore target storage must be empty.');
                }
                $dest = $stage.'/'.$relative;
                if (! is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0700, true);
                }
                file_put_contents($dest, $bytes);
                chmod($dest, 0600);
            }
            DB::transaction(function () use ($snapshot, $stage, $root, &$created) {
                foreach ($snapshot['records'] as $row) {
                    if (array_diff(array_keys($row), ['collection', 'id', 'data', 'version', 'created_at', 'updated_at', 'lookup_email', 'lookup_customer_id', 'lookup_user_id', 'lookup_invoice_id', 'lookup_conversation_id', 'lookup_status', 'lookup_type', 'lookup_employee_user_id'])) {
                        throw new \RuntimeException('Unexpected record fields.');
                    }
                    DB::table('cms_records')->insert($row);
                }
                foreach ($snapshot['sequences'] as $row) {
                    DB::table('cms_locks')->insert(['key' => $row['key'], 'value' => $row['value']]);
                }
                foreach (array_keys($snapshot['files']) as $relative) {
                    $target = $root.'/'.$relative;
                    if (! is_dir(dirname($target))) {
                        mkdir(dirname($target), 0700, true);
                    }
                    if (! rename($stage.'/'.$relative, $target)) {
                        throw new \RuntimeException('Cannot restore file.');
                    }
                    $created[] = $relative;
                }
            });
        } catch (\Throwable $error) {
            foreach ($created as $file) {
                Storage::disk('local')->delete($file);
            }
            throw $error;
        } finally {
            $zip->close();
            File::deleteDirectory($stage);
        }
        $this->invalidateFileSessions();
    }

    private function invalidateFileSessions(): void
    {
        if (config('session.driver') !== 'file') {
            return;
        }
        $directory = config('session.files');
        if (! is_string($directory) || ! is_dir($directory)) {
            return;
        }
        if (is_link($directory)) {
            throw new \RuntimeException('Restoration succeeded; clear sessions manually because the configured session directory is a symlink. Keep maintenance mode enabled.');
        }
        foreach (new \DirectoryIterator($directory) as $file) {
            if ($file->isFile() && ! $file->isLink() && preg_match('/^[A-Za-z0-9]{40}$/D', $file->getFilename())) {
                if (! unlink($file->getPathname())) {
                    throw new \RuntimeException('Restoration succeeded, but existing file sessions could not be invalidated. Keep maintenance mode enabled.');
                }
            }
        }
    }

    private function assertNoSymlinks(string $root): void
    {
        if (is_link($root)) {
            throw new \RuntimeException('Protected storage cannot be a symlink.');
        }
        if (! is_dir($root)) {
            mkdir($root, 0700, true);
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new \RuntimeException('Symlinks are not permitted in protected storage.');
            }
        }
    }

    private function requireSqlAndPassword(string $password): void
    {
        if (config('cms.data_provider') === 'firestore') {
            throw new \RuntimeException('Firestore backups are not certified yet. Use a managed export until the portable adapter is certified.');
        }
        if (! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256, true)) {
            throw new \RuntimeException('This ZIP runtime does not support AES-256 encryption.');
        }
        if (strlen($password) < 16) {
            throw new \RuntimeException('Set CMS_BACKUP_PASSWORD to at least 16 characters.');
        }
    }
}
