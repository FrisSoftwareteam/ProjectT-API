<?php

declare(strict_types=1);

$archivePath = $argv[1] ?? null;
$manifestPath = $argv[2] ?? null;

if ($archivePath === null || in_array($archivePath, ['-h', '--help'], true)) {
    fwrite(STDOUT, <<<'HELP'
Verify a Project T production application archive and every allowlisted file.

Usage:
  php scripts/verify_production_release.php <release.tar.gz> [release.manifest.json]
HELP.PHP_EOL);
    exit($archivePath === null ? 1 : 0);
}

try {
    $archivePath = absolutePath($archivePath);
    if (! is_file($archivePath)) {
        throw new RuntimeException("Release archive does not exist: {$archivePath}");
    }
    if (! str_ends_with($archivePath, '.tar.gz')) {
        throw new RuntimeException('Release archive must use the .tar.gz extension.');
    }

    $manifestPath = $manifestPath !== null
        ? absolutePath($manifestPath)
        : substr($archivePath, 0, -strlen('.tar.gz')).'.manifest.json';
    if (! is_file($manifestPath)) {
        throw new RuntimeException("External release manifest does not exist: {$manifestPath}");
    }

    $externalManifest = json_decode(
        (string) file_get_contents($manifestPath),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $actualArchiveHash = hash_file('sha256', $archivePath);
    if (! hash_equals((string) ($externalManifest['archive_sha256'] ?? ''), $actualArchiveHash)) {
        throw new RuntimeException('Archive SHA-256 does not match the external manifest.');
    }
    if ((int) ($externalManifest['archive_bytes'] ?? -1) !== filesize($archivePath)) {
        throw new RuntimeException('Archive byte size does not match the external manifest.');
    }

    $temporaryDirectory = sys_get_temp_dir().'/projectt-production-verify-'.bin2hex(random_bytes(8));
    ensureVerifyDirectory($temporaryDirectory);

    try {
        $releaseName = (string) ($externalManifest['release_name'] ?? '');
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/', $releaseName)) {
            throw new RuntimeException('External manifest contains an unsafe release name.');
        }
        extractVerifiedReleaseTar($archivePath, $temporaryDirectory, $releaseName);
        $releaseRoot = $temporaryDirectory.'/'.$releaseName;
        if (! is_dir($releaseRoot)) {
            throw new RuntimeException('Archive does not contain its declared top-level release directory.');
        }

        $internalPath = $releaseRoot.'/.release/manifest.json';
        if (! is_file($internalPath)) {
            throw new RuntimeException('Archive is missing its internal release manifest.');
        }
        $internalManifest = json_decode(
            (string) file_get_contents($internalPath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        foreach (['format', 'release_name', 'git_commit', 'git_dirty', 'production_approved', 'vendor_included'] as $field) {
            if (($internalManifest[$field] ?? null) !== ($externalManifest[$field] ?? null)) {
                throw new RuntimeException("Internal and external manifests differ for {$field}.");
            }
        }

        $declaredFiles = $internalManifest['files'] ?? null;
        if (! is_array($declaredFiles)) {
            throw new RuntimeException('Internal manifest does not contain a file checksum map.');
        }
        ksort($declaredFiles, SORT_STRING);

        $verifiedBytes = 0;
        foreach ($declaredFiles as $relativePath => $expectedHash) {
            ensureVerifyRelativePath((string) $relativePath);
            $absoluteFile = $releaseRoot.'/'.$relativePath;
            if (! is_file($absoluteFile) || is_link($absoluteFile)) {
                throw new RuntimeException("Declared application file is missing or unsafe: {$relativePath}");
            }
            $actualHash = hash_file('sha256', $absoluteFile);
            if (! hash_equals((string) $expectedHash, $actualHash)) {
                throw new RuntimeException("Application file checksum mismatch: {$relativePath}");
            }
            $verifiedBytes += filesize($absoluteFile);
        }

        $actualFiles = collectVerifyFiles($releaseRoot);
        $actualApplicationFiles = array_values(array_filter(
            $actualFiles,
            fn (string $path): bool => ! str_starts_with($path, '.release/')
        ));
        sort($actualApplicationFiles, SORT_STRING);
        $expectedApplicationFiles = array_keys($declaredFiles);
        sort($expectedApplicationFiles, SORT_STRING);
        if ($actualApplicationFiles !== $expectedApplicationFiles) {
            throw new RuntimeException('Archive contains undeclared or missing application files.');
        }

        if ((int) ($internalManifest['application_file_count'] ?? -1) !== count($declaredFiles)) {
            throw new RuntimeException('Declared application file count is incorrect.');
        }
        if ((int) ($internalManifest['application_bytes'] ?? -1) !== $verifiedBytes) {
            throw new RuntimeException('Declared application byte total is incorrect.');
        }

        fwrite(STDOUT, json_encode([
            'verified' => true,
            'release_name' => $releaseName,
            'production_approved' => (bool) $internalManifest['production_approved'],
            'git_dirty' => (bool) $internalManifest['git_dirty'],
            'vendor_included' => (bool) $internalManifest['vendor_included'],
            'application_file_count' => count($declaredFiles),
            'application_bytes' => $verifiedBytes,
            'archive_sha256' => $actualArchiveHash,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    } finally {
        removeVerifyDirectory($temporaryDirectory);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Production release verification failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}

function absolutePath(string $path): string
{
    if (str_starts_with($path, '/')) {
        return $path;
    }

    return getcwd().'/'.$path;
}

function ensureVerifyRelativePath(string $path): void
{
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
        throw new RuntimeException("Unsafe path in release manifest: {$path}");
    }
}

function extractVerifiedReleaseTar(string $archivePath, string $temporaryDirectory, string $releaseName): void
{
    $input = gzopen($archivePath, 'rb');
    if ($input === false) {
        throw new RuntimeException('Could not open the compressed production release archive.');
    }
    $seen = [];
    try {
        while (true) {
            $header = gzReadVerifyExact($input, 512);
            if ($header === null) {
                throw new RuntimeException('Production release TAR archive is truncated.');
            }
            if ($header === str_repeat("\0", 512)) {
                if (gzReadVerifyExact($input, 512) !== str_repeat("\0", 512)) {
                    throw new RuntimeException('Production release TAR end marker is invalid.');
                }
                break;
            }
            $storedChecksum = octdec(trim(substr($header, 148, 8), " \0"));
            $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
            if ($storedChecksum !== array_sum(unpack('C*', $checksumHeader))) {
                throw new RuntimeException('Production release contains an invalid TAR header checksum.');
            }
            $name = rtrim(substr($header, 0, 100), "\0");
            $prefix = rtrim(substr($header, 345, 155), "\0");
            $path = $prefix === '' ? $name : $prefix.'/'.$name;
            $type = substr($header, 156, 1);
            $sizeValue = trim(substr($header, 124, 12), " \0");
            if ($sizeValue !== '' && ! preg_match('/\A[0-7]+\z/', $sizeValue)) {
                throw new RuntimeException('Production release contains an invalid TAR entry size.');
            }
            $size = $sizeValue === '' ? 0 : octdec($sizeValue);
            $relativePath = str_starts_with($path, $releaseName.'/')
                ? substr($path, strlen($releaseName) + 1)
                : '';
            ensureVerifyRelativePath($relativePath);
            if (! in_array($type, ["\0", '0'], true) || isset($seen[$relativePath])) {
                throw new RuntimeException('Production release contains an unsafe, duplicate, or unsupported TAR entry.');
            }
            $destination = $temporaryDirectory.'/'.$releaseName.'/'.$relativePath;
            ensureVerifyDirectory(dirname($destination));
            $output = fopen($destination, 'xb');
            if ($output === false) {
                throw new RuntimeException("Could not extract verified production file: {$relativePath}");
            }
            try {
                $remaining = $size;
                while ($remaining > 0) {
                    $chunk = gzread($input, min(1048576, $remaining));
                    if ($chunk === false || $chunk === '') {
                        throw new RuntimeException("Production release TAR entry is truncated: {$relativePath}");
                    }
                    if (fwrite($output, $chunk) !== strlen($chunk)) {
                        throw new RuntimeException("Could not write complete production file: {$relativePath}");
                    }
                    $remaining -= strlen($chunk);
                }
            } finally {
                fclose($output);
            }
            $padding = (512 - ($size % 512)) % 512;
            if ($padding > 0 && gzReadVerifyExact($input, $padding) === null) {
                throw new RuntimeException('Production release TAR padding is truncated.');
            }
            $seen[$relativePath] = true;
        }
    } finally {
        gzclose($input);
    }
    if ($seen === []) {
        throw new RuntimeException('Production release archive is empty.');
    }
}

/** @param resource $handle */
function gzReadVerifyExact($handle, int $length): ?string
{
    $buffer = '';
    while (strlen($buffer) < $length && ! gzeof($handle)) {
        $chunk = gzread($handle, $length - strlen($buffer));
        if ($chunk === false) {
            throw new RuntimeException('Could not read the compressed production release archive.');
        }
        $buffer .= $chunk;
    }

    return strlen($buffer) === $length ? $buffer : null;
}

/** @return array<int,string> */
function collectVerifyFiles(string $releaseRoot): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($releaseRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $item) {
        if ($item->isLink()) {
            throw new RuntimeException('Symbolic links are not permitted in production releases.');
        }
        if ($item->isFile()) {
            $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($releaseRoot) + 1));
            ensureVerifyRelativePath($relativePath);
            $files[] = $relativePath;
        }
    }

    return $files;
}

function ensureVerifyDirectory(string $directory): void
{
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException("Could not create verification directory: {$directory}");
    }
}

function removeVerifyDirectory(string $directory): void
{
    if (! is_dir($directory) || ! str_starts_with($directory, sys_get_temp_dir().'/projectt-production-verify-')) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}
