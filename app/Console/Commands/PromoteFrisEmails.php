<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PromoteFrisEmails extends Command
{
    protected $signature = 'fris:promote-emails
        {--batch=* : Limit promotion to one or more published batch IDs}
        {--apply : Update eligible shareholder email addresses}
        {--chunk-size=1000 : Profiles processed per chunk}';

    protected $description = 'Audit and promote valid unique FRIS emails into published shareholder records';

    public function handle(): int
    {
        $batchIds = collect($this->option('batch'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        if ($batchIds->isNotEmpty()) {
            $unpublished = DB::table('fris_migration_batches')
                ->whereIn('id', $batchIds)
                ->whereNull('published_at')
                ->pluck('id');

            if ($unpublished->isNotEmpty()) {
                $this->error('All selected batches must be published. Unpublished batch IDs: '.$unpublished->implode(', '));

                return self::FAILURE;
            }
        }

        $profiles = $this->publishedProfiles($batchIds->all());
        $total = (clone $profiles)->count();
        if ($total === 0) {
            $this->warn('No published FRIS profiles matched the selection.');

            return self::SUCCESS;
        }

        // Duplicate detection is global so a batch-limited run cannot claim an
        // address that belongs to a shareholder in another published batch.
        $duplicateEmails = $this->duplicateSourceEmails();
        $apply = (bool) $this->option('apply');
        $chunkSize = max(1, (int) $this->option('chunk-size'));
        $counts = [
            'published_profiles' => $total,
            'missing_email' => 0,
            'invalid_email' => 0,
            'duplicate_source_email' => 0,
            'existing_email_conflict' => 0,
            'existing_real_email_preserved' => 0,
            'already_promoted' => 0,
            'eligible' => 0,
            'promoted' => 0,
        ];

        $this->info(($apply ? 'Promoting' : 'Auditing').' FRIS shareholder emails');

        $profiles->orderBy('p.id')->chunkById($chunkSize, function ($rows) use (&$counts, $duplicateEmails, $apply) {
            $normalizedByProfile = [];
            foreach ($rows as $row) {
                $email = $this->normalizeEmail($row->normalized_email);
                if ($email !== null) {
                    $normalizedByProfile[$row->id] = $email;
                }
            }

            $existingByEmail = DB::table('shareholders')
                ->whereIn('email', array_values(array_unique($normalizedByProfile)))
                ->get(['id', 'email'])
                ->groupBy(fn ($shareholder) => strtolower(trim((string) $shareholder->email)));

            $updates = [];
            foreach ($rows as $row) {
                $rawEmail = trim((string) $row->normalized_email);
                if ($rawEmail === '') {
                    $counts['missing_email']++;

                    continue;
                }

                $email = $normalizedByProfile[$row->id] ?? null;
                if ($email === null) {
                    $counts['invalid_email']++;

                    continue;
                }

                if (isset($duplicateEmails[$email])) {
                    $counts['duplicate_source_email']++;

                    continue;
                }

                $currentEmail = strtolower(trim((string) $row->current_email));
                if ($currentEmail === $email) {
                    $counts['already_promoted']++;

                    continue;
                }

                $hasConflict = ($existingByEmail->get($email) ?? collect())
                    ->contains(fn ($shareholder) => (int) $shareholder->id !== (int) $row->shareholder_id);
                if ($hasConflict) {
                    $counts['existing_email_conflict']++;

                    continue;
                }

                if (! $this->isFrisPlaceholder($currentEmail)) {
                    $counts['existing_real_email_preserved']++;

                    continue;
                }

                $counts['eligible']++;
                if ($apply) {
                    $updates[] = [
                        'id' => (int) $row->shareholder_id,
                        'email' => $email,
                    ];
                }
            }

            if ($apply && $updates !== []) {
                $counts['promoted'] += $this->bulkPromote($updates);
            }
        }, 'p.id', 'id');

        $this->table(['Result', 'Count'], collect($counts)->map(fn ($count, $label) => [$label, $count])->values());
        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply to promote eligible emails.');
        }

        return self::SUCCESS;
    }

    /** @param array<int> $batchIds */
    private function publishedProfiles(array $batchIds): Builder
    {
        return DB::table('fris_migration_profiles as p')
            ->join('fris_migration_batches as b', 'b.id', '=', 'p.batch_id')
            ->join('shareholders as s', 's.id', '=', 'p.shareholder_id')
            ->whereNotNull('b.published_at')
            ->whereNotNull('p.published_at')
            ->when($batchIds !== [], fn (Builder $query) => $query->whereIn('p.batch_id', $batchIds))
            ->select([
                'p.id',
                'p.batch_id',
                'p.shareholder_id',
                'p.normalized_email',
                's.email as current_email',
            ]);
    }

    /** @return array<string, true> */
    private function duplicateSourceEmails(): array
    {
        return DB::table('fris_migration_profiles as p')
            ->join('fris_migration_batches as b', 'b.id', '=', 'p.batch_id')
            ->whereNotNull('b.published_at')
            ->whereNotNull('p.published_at')
            ->whereNotNull('p.normalized_email')
            ->whereRaw("TRIM(p.normalized_email) <> ''")
            ->selectRaw('LOWER(TRIM(p.normalized_email)) as normalized_email')
            ->groupByRaw('LOWER(TRIM(p.normalized_email))')
            ->havingRaw('COUNT(DISTINCT p.shareholder_id) > 1')
            ->pluck('normalized_email')
            ->mapWithKeys(fn ($email) => [(string) $email => true])
            ->all();
    }

    private function normalizeEmail(mixed $value): ?string
    {
        $email = strtolower(trim((string) $value));

        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }

    private function isFrisPlaceholder(string $email): bool
    {
        return str_starts_with($email, 'fris-') && str_ends_with($email, '@invalid.projectt.local');
    }

    /** @param array<int, array{id:int,email:string}> $updates */
    private function bulkPromote(array $updates): int
    {
        $emailCases = [];
        $bindings = [];
        $ids = [];

        foreach ($updates as $update) {
            $emailCases[] = 'WHEN ? THEN ?';
            $bindings[] = $update['id'];
            $bindings[] = $update['email'];
            $ids[] = $update['id'];
        }

        $idPlaceholders = implode(', ', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE shareholders '
            .'SET email = CASE id '.implode(' ', $emailCases).' ELSE email END, '
            .'email_is_verified = 0, updated_at = ? '
            ."WHERE id IN ({$idPlaceholders}) AND email LIKE ?";

        $bindings[] = now();
        array_push($bindings, ...$ids);
        $bindings[] = 'fris-%@invalid.projectt.local';

        return DB::update($sql, $bindings);
    }
}
