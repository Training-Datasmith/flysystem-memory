<?php

declare(strict_types=1);

namespace League\Flysystem\InMemory\Tests;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\EmptyExtensionToMimeTypeMap;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use PHPUnit\Framework\TestCase;

class InMemoryFilesystemAdapterTest extends TestCase
{
    private InMemoryFilesystemAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryFilesystemAdapter();
    }

    private function adapter(): InMemoryFilesystemAdapter
    {
        return $this->adapter;
    }

    /**
     * @param array<string, string> $expected
     * @param iterable<StorageAttributes> $listing
     */
    private static function listingPathTypes(iterable $listing): array
    {
        $result = [];
        foreach ($listing as $item) {
            $result[$item->path()] = $item->type();
        }

        return $result;
    }

    public function testWriteReadAndExistsRoundTrip(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'contents', new Config([
            'timestamp' => 100,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertTrue($adapter->fileExists('path.txt'));
        self::assertSame('contents', $adapter->read('path.txt'));
        self::assertSame('path.txt', $adapter->fileSize('path.txt')->path());
        self::assertSame(8, $adapter->fileSize('path.txt')->fileSize());
        self::assertSame(100, $adapter->lastModified('path.txt')->lastModified());
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('path.txt')->visibility());
    }

    public function testLeadingSlashesAddressTheSameFile(): void
    {
        $adapter = $this->adapter();
        $adapter->write('dir/file.txt', 'abc', new Config(['timestamp' => 1]));

        self::assertTrue($adapter->fileExists('/dir/file.txt'));
        self::assertTrue($adapter->fileExists('//dir/file.txt'));
        self::assertSame('abc', $adapter->read('/dir/file.txt'));

        $adapter->delete('/dir/file.txt');
        self::assertFalse($adapter->fileExists('dir/file.txt'));
    }

    public function testEmptyContentsAreARealFile(): void
    {
        $adapter = $this->adapter();
        $adapter->write('empty.txt', '', new Config(['timestamp' => 1]));

        self::assertTrue($adapter->fileExists('empty.txt'));
        self::assertSame('', $adapter->read('empty.txt'));
        self::assertSame(0, $adapter->fileSize('empty.txt')->fileSize());
    }

    public function testBinaryAndMultibyteSize(): void
    {
        $adapter = $this->adapter();
        $adapter->write('bin.dat', "a\0b", new Config(['timestamp' => 1]));
        $adapter->write('utf8.txt', 'é', new Config(['timestamp' => 2]));

        self::assertSame("a\0b", $adapter->read('bin.dat'));
        self::assertSame(3, $adapter->fileSize('bin.dat')->fileSize());
        self::assertSame('é', $adapter->read('utf8.txt'));
        self::assertSame(2, $adapter->fileSize('utf8.txt')->fileSize());
    }

    public function testTimestampZeroIsPreserved(): void
    {
        $adapter = $this->adapter();
        $adapter->write('zero.txt', 'x', new Config(['timestamp' => 0]));

        self::assertSame(0, $adapter->lastModified('zero.txt')->lastModified());
    }

    public function testOverwriteReplacesContentsAndExplicitVisibility(): void
    {
        $adapter = $this->adapter();
        $adapter->write('file.txt', 'old', new Config([
            'timestamp' => 10,
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));
        $adapter->write('file.txt', 'new contents', new Config([
            'timestamp' => 20,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame('new contents', $adapter->read('file.txt'));
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('file.txt')->visibility());
        self::assertSame(20, $adapter->lastModified('file.txt')->lastModified());
    }

    public function testDefaultVisibilityIsPublic(): void
    {
        $adapter = $this->adapter();
        $adapter->write('first.txt', 'x', new Config(['timestamp' => 1]));

        self::assertSame(Visibility::PUBLIC, $adapter->visibility('first.txt')->visibility());
    }

    public function testConstructorDefaultVisibilityIsUsedWhenConfigOmitsIt(): void
    {
        $adapter = new InMemoryFilesystemAdapter(Visibility::PRIVATE);
        $adapter->write('first.txt', 'x', new Config(['timestamp' => 1]));

        self::assertSame(Visibility::PRIVATE, $adapter->visibility('first.txt')->visibility());
    }

    public function testSetVisibilityRoundTripAndMissingFile(): void
    {
        $adapter = $this->adapter();
        $adapter->write('file.txt', 'x', new Config([
            'timestamp' => 1,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        $adapter->setVisibility('file.txt', Visibility::PRIVATE);
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('file.txt')->visibility());

        $adapter->setVisibility('file.txt', Visibility::PUBLIC);
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('file.txt')->visibility());

        try {
            $adapter->setVisibility('missing.txt', Visibility::PUBLIC);
            self::fail('Expected UnableToSetVisibility');
        } catch (UnableToSetVisibility $e) {
        }

        self::assertFalse($adapter->fileExists('missing.txt'));
    }

    /**
     * @return iterable<string, array{0: callable(InMemoryFilesystemAdapter): void}>
     */
    public function missingMetadataProvider(): iterable
    {
        yield 'fileSize' => [static fn (InMemoryFilesystemAdapter $a) => $a->fileSize('missing.txt')];
        yield 'lastModified' => [static fn (InMemoryFilesystemAdapter $a) => $a->lastModified('missing.txt')];
        yield 'visibility' => [static fn (InMemoryFilesystemAdapter $a) => $a->visibility('missing.txt')];
        yield 'mimeType' => [static fn (InMemoryFilesystemAdapter $a) => $a->mimeType('missing.txt')];
    }

    /**
     * @dataProvider missingMetadataProvider
     */
    public function testMissingMetadataThrows(callable $operation): void
    {
        $adapter = $this->adapter();

        try {
            $operation($adapter);
            self::fail('Expected UnableToRetrieveMetadata');
        } catch (UnableToRetrieveMetadata $e) {
        }

        self::assertFalse($adapter->fileExists('missing.txt'));
    }

    public function testInjectedMimeDetectorReceivesPathAndContents(): void
    {
        $detector = new class () implements MimeTypeDetector {
            /** @var list<array{path: string, contents: string}> */
            public array $calls = [];

            public function detectMimeType(string $path, $contents): ?string
            {
                $this->calls[] = ['path' => $path, 'contents' => is_string($contents) ? $contents : ''];

                if ($path === 'notes.txt' && $contents === 'hello') {
                    return 'application/x-test';
                }

                return null;
            }

            public function detectMimeTypeFromBuffer(string $contents): ?string
            {
                return null;
            }

            public function detectMimeTypeFromPath(string $path): ?string
            {
                return null;
            }

            public function detectMimeTypeFromFile(string $path): ?string
            {
                return null;
            }
        };

        $adapter = new InMemoryFilesystemAdapter(Visibility::PUBLIC, $detector);
        $adapter->write('notes.txt', 'hello', new Config(['timestamp' => 1]));

        self::assertSame('application/x-test', $adapter->mimeType('notes.txt')->mimeType());
        self::assertCount(1, $detector->calls);
        self::assertSame('hello', $detector->calls[0]['contents']);
        self::assertSame('notes.txt', basename($detector->calls[0]['path']));
    }

    public function testEmptyExtensionMapThrowsForUnknownMime(): void
    {
        $adapter = new InMemoryFilesystemAdapter(
            Visibility::PUBLIC,
            new ExtensionMimeTypeDetector(new EmptyExtensionToMimeTypeMap())
        );
        $adapter->write('unknown-mime-type.md5', 'abc', new Config(['timestamp' => 1]));

        try {
            $adapter->mimeType('unknown-mime-type.md5');
            self::fail('Expected UnableToRetrieveMetadata');
        } catch (UnableToRetrieveMetadata $e) {
            self::assertSame('mime_type', $e->metadataType());
        }
    }

    public function testWriteStreamAndReadStreamRoundTrip(): void
    {
        $adapter = $this->adapter();
        $writeStream = fopen('php://temp', 'w+b');
        self::assertIsResource($writeStream);
        fwrite($writeStream, "a\0b");
        rewind($writeStream);

        $adapter->writeStream('stream.dat', $writeStream, new Config(['timestamp' => 1]));
        fclose($writeStream);

        self::assertSame("a\0b", $adapter->read('stream.dat'));

        $readStream = $adapter->readStream('stream.dat');
        self::assertIsResource($readStream);
        self::assertSame("a\0b", stream_get_contents($readStream));
        rewind($readStream);
        self::assertSame("a\0b", stream_get_contents($readStream));
        fclose($readStream);

        $readStream2 = $adapter->readStream('stream.dat');
        self::assertIsResource($readStream2);
        fclose($readStream2);
        self::assertSame("a\0b", $adapter->read('stream.dat'));
    }

    public function testReadMissingFileThrows(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->read('missing.txt');
            self::fail('Expected UnableToReadFile');
        } catch (UnableToReadFile $e) {
        }

        self::assertFalse($adapter->fileExists('missing.txt'));
    }

    public function testReadStreamMissingFileThrows(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->readStream('missing.txt');
            self::fail('Expected UnableToReadFile');
        } catch (UnableToReadFile $e) {
        }

        self::assertFalse($adapter->fileExists('missing.txt'));
    }

    public function testDeleteFileAndSecondDeleteLeavesItAbsent(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'x', new Config(['timestamp' => 1]));
        $adapter->delete('path.txt');
        self::assertFalse($adapter->fileExists('path.txt'));

        try {
            $adapter->read('path.txt');
            self::fail('Expected UnableToReadFile');
        } catch (UnableToReadFile $e) {
        }

        $adapter->delete('path.txt');
        self::assertFalse($adapter->fileExists('path.txt'));
    }

    public function testDeleteDirectoryRemovesOnlyThatTree(): void
    {
        $adapter = $this->adapter();
        $adapter->write('a/path.txt', 'A', new Config(['timestamp' => 1]));
        $adapter->write('a/b/path.txt', 'B', new Config(['timestamp' => 2]));
        $adapter->write('a/b/c/path.txt', 'C', new Config(['timestamp' => 3]));
        $adapter->write('ab/path.txt', 'AB', new Config(['timestamp' => 4]));
        $adapter->write('a-other.txt', 'AO', new Config(['timestamp' => 5]));

        $adapter->deleteDirectory('a/b');

        self::assertSame('A', $adapter->read('a/path.txt'));
        self::assertFalse($adapter->fileExists('a/b/path.txt'));
        self::assertFalse($adapter->fileExists('a/b/c/path.txt'));
        self::assertSame('AB', $adapter->read('ab/path.txt'));
        self::assertSame('AO', $adapter->read('a-other.txt'));
        self::assertFalse($adapter->directoryExists('a/b'));
        self::assertTrue($adapter->directoryExists('a'));

        $adapter->deleteDirectory('a');
        self::assertFalse($adapter->fileExists('a/path.txt'));
        self::assertSame('AB', $adapter->read('ab/path.txt'));
        self::assertSame('AO', $adapter->read('a-other.txt'));
    }

    public function testDirectoryExistsTracksChildren(): void
    {
        $adapter = $this->adapter();
        self::assertFalse($adapter->directoryExists('dir'));

        $adapter->write('dir/file.txt', 'x', new Config(['timestamp' => 1]));

        self::assertTrue($adapter->directoryExists('dir'));
        self::assertTrue($adapter->directoryExists('/dir'));
        self::assertFalse($adapter->directoryExists('dir/file.txt'));
        self::assertFalse($adapter->fileExists('dir'));

        $adapter->delete('dir/file.txt');
        self::assertFalse($adapter->directoryExists('dir'));
    }

    public function testCreateDirectoryIsListedAndIdempotent(): void
    {
        $adapter = $this->adapter();
        $dummy = InMemoryFilesystemAdapter::DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST;

        $adapter->createDirectory('something', new Config(['timestamp' => 1]));

        self::assertTrue($adapter->directoryExists('something'));
        self::assertFalse($adapter->fileExists('something'));

        $listing = iterator_to_array($adapter->listContents('', true));
        self::assertCount(1, $listing);
        self::assertInstanceOf(DirectoryAttributes::class, $listing[0]);
        self::assertSame('something', $listing[0]->path());
        foreach ($listing as $item) {
            self::assertFalse(str_ends_with($item->path(), $dummy));
        }

        $adapter->createDirectory('something', new Config(['timestamp' => 2]));
        self::assertCount(1, iterator_to_array($adapter->listContents('', true)));

        $adapter->createDirectory('/something', new Config(['timestamp' => 3]));
        self::assertTrue($adapter->directoryExists('something'));

        $adapter->deleteDirectory('something');
        self::assertFalse($adapter->directoryExists('something'));
        self::assertSame([], iterator_to_array($adapter->listContents('', true)));
    }

    public function testCreateNestedDirectoryAppearsInParentListing(): void
    {
        $adapter = $this->adapter();
        $adapter->createDirectory('creating_a_directory/path', new Config(['timestamp' => 1]));

        $listing = iterator_to_array($adapter->listContents('creating_a_directory', false));
        self::assertCount(1, $listing);
        self::assertInstanceOf(DirectoryAttributes::class, $listing[0]);
        self::assertSame('creating_a_directory/path', $listing[0]->path());
    }

    public function testShallowAndDeepListing(): void
    {
        $adapter = $this->adapter();
        $adapter->write('root.txt', 'root', new Config([
            'timestamp' => 11,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));
        $adapter->write('a/file.txt', 'A', new Config([
            'timestamp' => 22,
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));
        $adapter->write('a/b/nested.txt', 'NEST', new Config([
            'timestamp' => 33,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));
        $adapter->write('0/path.txt', 'zero', new Config(['timestamp' => 44]));

        $shallowRoot = self::listingPathTypes($adapter->listContents('', false));
        self::assertSame([
            'root.txt' => StorageAttributes::TYPE_FILE,
            'a' => StorageAttributes::TYPE_DIRECTORY,
            '0' => StorageAttributes::TYPE_DIRECTORY,
        ], $shallowRoot);

        $deepRoot = self::listingPathTypes($adapter->listContents('', true));
        self::assertSame([
            'root.txt' => StorageAttributes::TYPE_FILE,
            'a' => StorageAttributes::TYPE_DIRECTORY,
            'a/file.txt' => StorageAttributes::TYPE_FILE,
            'a/b' => StorageAttributes::TYPE_DIRECTORY,
            'a/b/nested.txt' => StorageAttributes::TYPE_FILE,
            '0' => StorageAttributes::TYPE_DIRECTORY,
            '0/path.txt' => StorageAttributes::TYPE_FILE,
        ], $deepRoot);

        $shallowA = self::listingPathTypes($adapter->listContents('a', false));
        self::assertSame([
            'a/file.txt' => StorageAttributes::TYPE_FILE,
            'a/b' => StorageAttributes::TYPE_DIRECTORY,
        ], $shallowA);

        $deepA = self::listingPathTypes($adapter->listContents('a', true));
        self::assertSame([
            'a/file.txt' => StorageAttributes::TYPE_FILE,
            'a/b' => StorageAttributes::TYPE_DIRECTORY,
            'a/b/nested.txt' => StorageAttributes::TYPE_FILE,
        ], $deepA);

        self::assertSame(
            ['0/path.txt' => StorageAttributes::TYPE_FILE],
            self::listingPathTypes($adapter->listContents('0', false))
        );
        self::assertSame([], self::listingPathTypes($adapter->listContents('missing', false)));

        foreach ($adapter->listContents('', true) as $item) {
            if ($item->path() === 'root.txt') {
                self::assertSame(4, $item->fileSize());
                self::assertSame(Visibility::PUBLIC, $item->visibility());
                self::assertSame(11, $item->lastModified());
            }
            if ($item->path() === 'a/file.txt') {
                self::assertSame(1, $item->fileSize());
                self::assertSame(Visibility::PRIVATE, $item->visibility());
                self::assertSame(22, $item->lastModified());
            }
            if ($item->path() === 'a/b/nested.txt') {
                self::assertSame(4, $item->fileSize());
                self::assertSame(Visibility::PUBLIC, $item->visibility());
                self::assertSame(33, $item->lastModified());
            }
            if ($item->path() === '0/path.txt') {
                self::assertSame(4, $item->fileSize());
                self::assertSame(44, $item->lastModified());
            }
        }
    }

    public function testListingDoesNotConfuseSiblingPrefixes(): void
    {
        $adapter = $this->adapter();
        $adapter->write('a/file.txt', 'a', new Config(['timestamp' => 1]));
        $adapter->write('ab/file.txt', 'ab', new Config(['timestamp' => 2]));

        self::assertSame(
            ['a/file.txt' => StorageAttributes::TYPE_FILE],
            self::listingPathTypes($adapter->listContents('a', true))
        );
        self::assertTrue($adapter->directoryExists('a'));
        self::assertTrue($adapter->directoryExists('ab'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public function specialPathProvider(): iterable
    {
        yield 'space in path' => ['some dir/file name.txt'];
        yield 'brackets in filename' => ['some/file[0].txt'];
        yield 'braces in dirname' => ['some{name}/file.txt'];
    }

    /**
     * @dataProvider specialPathProvider
     */
    public function testSpecialCharactersInPaths(string $path): void
    {
        $adapter = $this->adapter();
        $adapter->write($path, 'contents', new Config(['timestamp' => 1]));

        self::assertSame('contents', $adapter->read($path));
    }

    public function testCopyPreservesSourceAndClonesBytes(): void
    {
        $adapter = $this->adapter();
        $adapter->write('source.txt', 'contents to be copied', new Config([
            'timestamp' => 10,
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));

        $adapter->copy('source.txt', 'dest.txt', new Config([
            'timestamp' => 50,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertTrue($adapter->fileExists('source.txt'));
        self::assertTrue($adapter->fileExists('dest.txt'));
        self::assertSame('contents to be copied', $adapter->read('dest.txt'));
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('dest.txt')->visibility());
        self::assertSame(50, $adapter->lastModified('dest.txt')->lastModified());
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('source.txt')->visibility());
        self::assertSame(10, $adapter->lastModified('source.txt')->lastModified());

        $adapter->write('source.txt', 'changed', new Config([
            'timestamp' => 60,
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertSame('contents to be copied', $adapter->read('dest.txt'));
        self::assertSame(50, $adapter->lastModified('dest.txt')->lastModified());
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('dest.txt')->visibility());
    }

    public function testCopyTimestampZero(): void
    {
        $adapter = $this->adapter();
        $adapter->write('source.txt', 'x', new Config(['timestamp' => 10]));
        $adapter->copy('source.txt', 'dest.txt', new Config(['timestamp' => 0]));

        self::assertSame(0, $adapter->lastModified('dest.txt')->lastModified());
    }

    public function testCopyMissingThrowsAndWritesNothing(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->copy('missing.txt', 'dest.txt', new Config(['timestamp' => 1]));
            self::fail('Expected UnableToCopyFile');
        } catch (UnableToCopyFile $e) {
        }

        self::assertFalse($adapter->fileExists('dest.txt'));
    }

    public function testCopyOverwritesDestination(): void
    {
        $adapter = $this->adapter();
        $adapter->write('source.txt', 'new contents', new Config(['timestamp' => 1]));
        $adapter->write('dest.txt', 'old', new Config(['timestamp' => 2]));

        $adapter->copy('source.txt', 'dest.txt', new Config(['timestamp' => 3]));

        self::assertSame('new contents', $adapter->read('dest.txt'));
        self::assertSame(3, $adapter->lastModified('dest.txt')->lastModified());
        self::assertTrue($adapter->fileExists('source.txt'));
    }

    public function testCopyOntoItselfKeepsContents(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'new contents', new Config(['timestamp' => 9]));

        $adapter->copy('path.txt', 'path.txt', new Config(['timestamp' => 12]));

        self::assertTrue($adapter->fileExists('path.txt'));
        self::assertSame('new contents', $adapter->read('path.txt'));
    }

    public function testMoveRenamesAndKeepsStoredMetadata(): void
    {
        $adapter = $this->adapter();
        $adapter->write('source.txt', 'payload', new Config([
            'timestamp' => 15,
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));

        $adapter->move('source.txt', 'dest.txt', new Config());

        self::assertFalse($adapter->fileExists('source.txt'));
        self::assertSame('payload', $adapter->read('dest.txt'));
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('dest.txt')->visibility());
        self::assertSame(15, $adapter->lastModified('dest.txt')->lastModified());
    }

    public function testMoveCanSetVisibility(): void
    {
        $adapter = $this->adapter();
        $adapter->write('source.txt', 'payload', new Config([
            'timestamp' => 15,
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));

        $adapter->move('source.txt', 'dest.txt', new Config([
            Config::OPTION_VISIBILITY => Visibility::PUBLIC,
        ]));

        self::assertFalse($adapter->fileExists('source.txt'));
        self::assertSame(Visibility::PUBLIC, $adapter->visibility('dest.txt')->visibility());
        self::assertSame(15, $adapter->lastModified('dest.txt')->lastModified());
    }

    public function testMoveMissingThrows(): void
    {
        $adapter = $this->adapter();

        try {
            $adapter->move('missing.txt', 'dest.txt', new Config());
            self::fail('Expected UnableToMoveFile');
        } catch (UnableToMoveFile $e) {
        }

        self::assertFalse($adapter->fileExists('dest.txt'));
    }

    public function testMoveOverwritesDestination(): void
    {
        $adapter = $this->adapter();
        $adapter->write('source.txt', 'new contents', new Config(['timestamp' => 1]));
        $adapter->write('dest.txt', 'old', new Config(['timestamp' => 2]));

        $adapter->move('source.txt', 'dest.txt', new Config());

        self::assertFalse($adapter->fileExists('source.txt'));
        self::assertSame('new contents', $adapter->read('dest.txt'));
    }

    public function testMoveOntoItselfKeepsTheFile(): void
    {
        $adapter = $this->adapter();
        $adapter->write('path.txt', 'payload', new Config(['timestamp' => 15]));

        $adapter->move('path.txt', 'path.txt', new Config());

        self::assertTrue($adapter->fileExists('path.txt'));
        self::assertSame('payload', $adapter->read('path.txt'));
    }

    public function testDeleteEverythingClearsFiles(): void
    {
        $adapter = $this->adapter();
        $adapter->write('a.txt', 'a', new Config(['timestamp' => 1]));
        $adapter->write('b.txt', 'b', new Config(['timestamp' => 2]));
        $adapter->createDirectory('dir', new Config(['timestamp' => 3]));

        $adapter->deleteEverything();

        self::assertFalse($adapter->fileExists('a.txt'));
        self::assertFalse($adapter->fileExists('b.txt'));
        self::assertFalse($adapter->directoryExists('dir'));
        self::assertSame([], iterator_to_array($adapter->listContents('', true)));
    }

    public function testTrailingSlashOnDirectoryOperations(): void
    {
        $adapter = $this->adapter();
        $adapter->write('a/b/file.txt', 'x', new Config(['timestamp' => 1]));

        self::assertTrue($adapter->directoryExists('a/b/'));

        $adapter->deleteDirectory('a/b/');
        self::assertFalse($adapter->fileExists('a/b/file.txt'));
    }

    public function testFilesystemOperatorWriteAndRead(): void
    {
        $filesystem = new Filesystem(new InMemoryFilesystemAdapter());
        $filesystem->write('path.txt', 'contents', ['timestamp' => 1]);

        self::assertSame('contents', $filesystem->read('path.txt'));
    }
}
