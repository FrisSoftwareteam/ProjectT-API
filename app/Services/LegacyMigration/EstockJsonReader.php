<?php

namespace App\Services\LegacyMigration;

use Generator;
use JsonException;
use RuntimeException;

/** Streams Estock's CSV-quoted JSON exports without loading the file into memory. */
class EstockJsonReader
{
    /** @return Generator<int, array<string, mixed>> */
    public function rows(string $path): Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The configured Estock source file cannot be opened.');
        }

        $foundArray = false;
        $depth = 0;
        $inString = false;
        $escaped = false;
        $object = '';
        $rowNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
                $line = rtrim($line, "\r\n");

                if (str_starts_with($line, '"') && str_ends_with($line, '"')) {
                    $line = substr($line, 1, -1);
                    $line = str_replace('""', '"', $line);
                }

                $length = strlen($line);
                for ($index = 0; $index < $length; $index++) {
                    $character = $line[$index];
                    if (! $foundArray) {
                        $foundArray = $character === '[';

                        continue;
                    }

                    if ($depth === 0) {
                        if ($character !== '{') {
                            continue;
                        }
                        $depth = 1;
                        $object = '{';
                        $inString = false;
                        $escaped = false;

                        continue;
                    }

                    $object .= $character;
                    if ($escaped) {
                        $escaped = false;

                        continue;
                    }
                    if ($inString && $character === '\\') {
                        $escaped = true;

                        continue;
                    }
                    if ($character === '"') {
                        $inString = ! $inString;

                        continue;
                    }
                    if ($inString) {
                        continue;
                    }
                    if ($character === '{') {
                        $depth++;
                    } elseif ($character === '}') {
                        $depth--;
                    }

                    if ($depth === 0) {
                        try {
                            $decoded = json_decode($object, true, 512, JSON_THROW_ON_ERROR);
                        } catch (JsonException $exception) {
                            throw new RuntimeException('Invalid Estock JSON object near source row '.($rowNumber + 1).'.', 0, $exception);
                        }
                        if (! is_array($decoded)) {
                            throw new RuntimeException('An Estock source row is not a JSON object.');
                        }
                        $rowNumber++;
                        yield $rowNumber => $decoded;
                        $object = '';
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        if (! $foundArray || $depth !== 0) {
            throw new RuntimeException('The Estock source contains an incomplete JSON array or object.');
        }
    }
}
