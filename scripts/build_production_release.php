<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$options = getopt('', ['name:', 'output:', 'allow-dirty', 'with-vendor', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, <<<'HELP'
Build an allowlisted Project T production application archive.

Usage:
  php scripts/build_production_release.php [--name=release-name] [--output=directory]
      [--with-vendor] [--allow-dirty]

The normal production build refuses a dirty Git worktree. --allow-dirty is for
local verification packages only and is recorded in the release manifest.
HELP.PHP_EOL);
    exit(0);
}

try {
    $config = json_decode(
        (string) file_get_contents($root.'/deploy/production-files.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $releaseName = $options['name'] ?? 'projectt-api-'.gmdate('Ymd-His');
    if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/', $releaseName)) {
        throw new RuntimeException('Release name may contain only letters, numbers, dot, underscore and hyphen.');
    }

    $outputDirectory = $options['output'] ?? $root.'/build/production';
    if (! str_starts_with($outputDirectory, '/')) {
        $outputDirectory = $root.'/'.$outputDirectory;
    }
    ensureDirectory($outputDirectory);

    [$commit, $dirty] = gitState($root);
    if ($dirty && ! isset($options['allow-dirty'])) {
        throw new RuntimeException('Refusing a production build from a dirty Git worktree. Commit/review the changes first, or use --allow-dirty for a local verification package.');
    }

    $temporaryRoot = sys_get_temp_dir().'/projectt-production-release-'.bin2hex(random_bytes(8));
    $stagingDirectory = $temporaryRoot.'/'.$releaseName;
    ensureDirectory($stagingDirectory);

    try {
        $includedFiles = [];
        foreach ($config['include'] as $relativePath) {
            copyAllowedPath($root, $stagingDirectory, $relativePath, $config, $includedFiles);
        }

        foreach ($config['runtime_directories'] as $directory) {
            ensureSafeRelativePath($directory);
            ensureDirectory($stagingDirectory.'/'.$directory);
        }

        if (isset($options['with-vendor'])) {
            installProductionDependencies($stagingDirectory);
            collectDirectoryFiles($stagingDirectory, 'vendor', $config, $includedFiles);
        }

        $includedFiles = array_values(array_unique($includedFiles));
        sort($includedFiles, SORT_STRING);
        assertNoForbiddenPaths($includedFiles, $config);

        $checksums = [];
        $applicationBytes = 0;
        foreach ($includedFiles as $relativePath) {
            $absolutePath = $stagingDirectory.'/'.$relativePath;
            $checksums[$relativePath] = hash_file('sha256', $absolutePath);
            $applicationBytes += filesize($absolutePath);
        }

        $internalManifest = [
            'format' => 'projectt-production-application-release-v1',
            'release_name' => $releaseName,
            'created_at_utc' => gmdate(DATE_ATOM),
            'git_commit' => $commit,
            'git_dirty' => $dirty,
            'production_approved' => ! $dirty,
            'vendor_included' => isset($options['with-vendor']),
            'application_file_count' => count($includedFiles),
            'application_bytes' => $applicationBytes,
            'runtime_directories' => $config['runtime_directories'],
            'excluded_workstation_domains' => $config['forbidden_prefixes'],
            'notes' => [
                'Production environment secrets are supplied separately and are never included.',
                'Company migration data bundles are transferred separately and are never included.',
                'If vendor_included is false, run Composer install on the deployment host.',
            ],
            'files' => $checksums,
        ];

        ensureDirectory($stagingDirectory.'/.release');
        file_put_contents(
            $stagingDirectory.'/.release/manifest.json',
            json_encode($internalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
        );
        file_put_contents(
            $stagingDirectory.'/.release/files.sha256',
            renderChecksums($checksums)
        );

        $tarPath = $outputDirectory.'/'.$releaseName.'.tar';
        $gzipPath = $tarPath.'.gz';
        $manifestPath = $outputDirectory.'/'.$releaseName.'.manifest.json';
        $checksumPath = $outputDirectory.'/'.$releaseName.'.sha256';
        foreach ([$tarPath, $gzipPath, $manifestPath, $checksumPath] as $target) {
            if (file_exists($target)) {
                throw new RuntimeException("Refusing to overwrite existing release output: {$target}");
            }
        }

        $archive = new PharData($tarPath);
        $archive->buildFromDirectory($temporaryRoot);
        unset($archive);
        gzipReleaseFile($tarPath, $gzipPath);
        unlink($tarPath);

        $archiveHash = hash_file('sha256', $gzipPath);
        $externalManifest = $internalManifest + [
            'archive_filename' => basename($gzipPath),
            'archive_bytes' => filesize($gzipPath),
            'archive_sha256' => $archiveHash,
        ];
        file_put_contents(
            $manifestPath,
            json_encode($externalManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL
        );
        file_put_contents($checksumPath, $archiveHash.'  '.basename($gzipPath).PHP_EOL);

        fwrite(STDOUT, json_encode([
            'release_name' => $releaseName,
            'archive' => $gzipPath,
            'manifest' => $manifestPath,
            'checksum_file' => $checksumPath,
            'archive_sha256' => $archiveHash,
            'archive_bytes' => filesize($gzipPath),
            'application_file_count' => count($includedFiles),
            'git_commit' => $commit,
            'git_dirty' => $dirty,
            'production_approved' => ! $dirty,
            'vendor_included' => isset($options['with-vendor']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
    } finally {
        removeTemporaryDirectory($temporaryRoot);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Production release build failed: '.$exception->getMessage().PHP_EOL);
    exit(1);
}

/** @return array{0:string|null,1:bool} */
function gitState(string $root): array
{
    $commitOutput = [];
    $commitCode = 0;
    exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null', $commitOutput, $commitCode);
    $commit = $commitCode === 0 ? trim(implode("\n", $commitOutput)) : null;

    $statusOutput = [];
    $statusCode = 0;
    exec('git -C '.escapeshellarg($root).' status --porcelain --untracked-files=normal 2>/dev/null', $statusOutput, $statusCode);

    return [$commit, $statusCode !== 0 || $statusOutput !== []];
}

/** @param array<string,mixed> $config @param array<int,string> $includedFiles */
function copyAllowedPath(string $root, string $stagingDirectory, string $relativePath, array $config, array &$includedFiles): void
{
    ensureSafeRelativePath($relativePath);
    $source = $root.'/'.$relativePath;
    if (! file_exists($source) && ! is_link($source)) {
        throw new RuntimeException("Required production path is missing: {$relativePath}");
    }

    if (is_link($source)) {
        throw new RuntimeException("Production allowlist entries cannot be symbolic links: {$relativePath}");
    }

    if (is_file($source)) {
        copyAllowedFile($source, $stagingDirectory, $relativePath, $config, $includedFiles);

        return;
    }

    collectDirectoryFiles($root, $relativePath, $config, $includedFiles, $stagingDirectory);
}

/** @param array<string,mixed> $config @param array<int,string> $includedFiles */
function collectDirectoryFiles(string $root, string $relativeDirectory, array $config, array &$includedFiles, ?string $stagingDirectory = null): void
{
    $base = $root.'/'.$relativeDirectory;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            continue;
        }
        if (! $item->isFile()) {
            continue;
        }
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
        if (isExcluded($relativePath, $config)) {
            continue;
        }
        if ($stagingDirectory !== null) {
            copyAllowedFile($item->getPathname(), $stagingDirectory, $relativePath, $config, $includedFiles);
        } else {
            $includedFiles[] = $relativePath;
        }
    }
}

/** @param array<string,mixed> $config @param array<int,string> $includedFiles */
function copyAllowedFile(string $source, string $stagingDirectory, string $relativePath, array $config, array &$includedFiles): void
{
    ensureSafeRelativePath($relativePath);
    if (isExcluded($relativePath, $config)) {
        return;
    }
    $target = $stagingDirectory.'/'.$relativePath;
    ensureDirectory(dirname($target));
    if (! copy($source, $target)) {
        throw new RuntimeException("Could not copy production file: {$relativePath}");
    }
    chmod($target, fileperms($source) & 0777);
    $includedFiles[] = $relativePath;
}

/** @param array<string,mixed> $config */
function isExcluded(string $relativePath, array $config): bool
{
    foreach ($config['exclude_prefixes'] as $prefix) {
        if ($relativePath === $prefix || str_starts_with($relativePath, $prefix.'/')) {
            return true;
        }
    }
    foreach ($config['exclude_patterns'] as $pattern) {
        if (fnmatch($pattern, basename($relativePath)) || fnmatch($pattern, $relativePath)) {
            return true;
        }
    }

    return false;
}

/** @param array<int,string> $files @param array<string,mixed> $config */
function assertNoForbiddenPaths(array $files, array $config): void
{
    foreach ($files as $relativePath) {
        foreach ($config['forbidden_prefixes'] as $prefix) {
            if ($relativePath === $prefix || str_starts_with($relativePath, $prefix.'/')) {
                throw new RuntimeException("Forbidden workstation path entered the production release: {$relativePath}");
            }
        }
        if (isExcluded($relativePath, $config)) {
            throw new RuntimeException("Excluded file entered the production release: {$relativePath}");
        }
    }
}

function ensureSafeRelativePath(string $path): void
{
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..') || str_contains($path, "\0")) {
        throw new RuntimeException("Unsafe relative release path: {$path}");
    }
}

function ensureDirectory(string $directory): void
{
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Could not create directory: {$directory}");
    }
}

function installProductionDependencies(string $stagingDirectory): void
{
    $command = 'composer install --working-dir='.escapeshellarg($stagingDirectory)
        .' --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --no-scripts';
    passthru($command, $exitCode);
    if ($exitCode !== 0 || ! is_dir($stagingDirectory.'/vendor')) {
        throw new RuntimeException('Composer could not install production dependencies into the release.');
    }
}

function gzipReleaseFile(string $source, string $destination): void
{
    $input = fopen($source, 'rb');
    $output = gzopen($destination, 'wb9');
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            gzclose($output);
        }
        throw new RuntimeException('Could not create the compressed production release archive.');
    }
    try {
        while (! feof($input)) {
            $chunk = fread($input, 1048576);
            if ($chunk === false) {
                throw new RuntimeException('Could not read the production release TAR archive.');
            }
            if ($chunk !== '' && gzwrite($output, $chunk) !== strlen($chunk)) {
                throw new RuntimeException('Could not write the complete compressed production release archive.');
            }
        }
    } finally {
        fclose($input);
        gzclose($output);
    }
}

/** @param array<string,string> $checksums */
function renderChecksums(array $checksums): string
{
    $lines = [];
    foreach ($checksums as $relativePath => $checksum) {
        $lines[] = $checksum.'  '.$relativePath;
    }

    return implode(PHP_EOL, $lines).PHP_EOL;
}

function removeTemporaryDirectory(string $directory): void
{
    if (! is_dir($directory) || ! str_starts_with($directory, sys_get_temp_dir().'/projectt-production-release-')) {
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
