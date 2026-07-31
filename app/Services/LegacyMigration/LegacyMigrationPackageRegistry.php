<?php

namespace App\Services\LegacyMigration;

use Illuminate\Validation\ValidationException;

class LegacyMigrationPackageRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return collect(config('legacy_migrations.packages', []))
            ->map(function (array $package, string $key) {
                return [
                    'key' => $key,
                    'name' => $package['name'],
                    'version' => $package['version'],
                    'source_filename' => $package['source_filename'],
                    'source_sha256' => $package['source_sha256'],
                    'expected_rows' => $package['expected_rows'],
                    'expected_quantity' => $package['expected_quantity'],
                    'holding_mode' => $package['holding_mode'],
                ];
            })->values()->all();
    }

    /** @return array<string, mixed> */
    public function get(string $key): array
    {
        $package = config("legacy_migrations.packages.{$key}");
        if (! is_array($package)) {
            throw ValidationException::withMessages(['package_key' => ['The migration package is not registered.']]);
        }

        return $package;
    }

    /** @return array<string, mixed> */
    public function snapshot(string $key): array
    {
        $package = $this->get($key);
        unset($package['source_path']);

        return $package;
    }
}
