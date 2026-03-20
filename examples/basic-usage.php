<?php

declare(strict_types=1);

/**
 * Example: Using InMemoryFilesystemAdapter in unit tests and application code.
 *
 * The in-memory adapter holds files in a PHP array — no disk I/O, no cleanup.
 * It is a drop-in replacement for any FilesystemAdapter, making it ideal for tests.
 */

use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\Visibility;

require_once __DIR__ . '/../vendor/autoload.php';

$adapter = new InMemoryFilesystemAdapter(defaultVisibility: Visibility::PRIVATE);
$fs = new Filesystem($adapter);

// Write files
$fs->write('uploads/avatar.png', 'fake-png-content');
$fs->write('reports/2026-01.csv', "date,amount\n2026-01-01,100.00");

// Read
echo $fs->read('reports/2026-01.csv') . PHP_EOL;

// Check existence
var_dump($fs->fileExists('uploads/avatar.png')); // bool(true)
var_dump($fs->fileExists('missing.txt'));          // bool(false)

// List
foreach ($fs->listContents('/', recursive: true) as $item) {
    echo $item->path() . PHP_EOL;
}

// Delete
$fs->delete('uploads/avatar.png');
var_dump($fs->fileExists('uploads/avatar.png')); // bool(false)
