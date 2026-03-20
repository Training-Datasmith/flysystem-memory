# Architecture: flysystem-memory

## Purpose

An in-memory Flysystem adapter for testing and temporary storage. Files are held in a PHP array keyed by path, making it ideal for unit tests that need a real `FilesystemAdapter` without disk I/O.

## Directory Structure

```
InMemoryFile.php                        — Value object: holds file content, visibility, and last-modified timestamp
InMemoryFilesystemAdapter.php           — FilesystemAdapter implementation backed by a PHP array
StaticInMemoryAdapterRegistry.php       — Shared registry allowing multiple code paths to access the same instance
InMemoryFilesystemAdapterTest.php       — Adapter contract tests
StaticInMemoryAdapterRegistryTest.php   — Registry unit tests
```

## Key Design Decisions

- **Array-backed storage** — `InMemoryFile[]` indexed by normalised path; no temp files, no cleanup required.
- **Mime detection** — uses `League\MimeTypeDetection` (FinfoMimeTypeDetector by default) to populate metadata, keeping the adapter consistent with real adapters.
- **Static registry** — `StaticInMemoryAdapterRegistry` enables test helpers and service providers to share a single in-memory filesystem by name without dependency injection.
- **Dummy listing file** — a constant `DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST` ensures directory listing tests pass even on empty filesystems.

## Extension Points

- Pass a custom `MimeTypeDetector` to the constructor to control mime-type resolution.
- Use `StaticInMemoryAdapterRegistry` to share named adapter instances across test fixtures.

## Dependency Flow

```
InMemoryFilesystemAdapter
  ├── InMemoryFile (per stored path)
  └── MimeTypeDetector (mime-type resolution for metadata)

StaticInMemoryAdapterRegistry
  └── InMemoryFilesystemAdapter (stores named instances)
```
