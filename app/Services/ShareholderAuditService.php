<?php

namespace App\Services;

use App\Models\Shareholder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShareholderAuditService
{
    public const CONTEXT = 'shareholder_audit_context';

    private array $columns = [];

    private const SHAREHOLDER_KEYS = ['shareholder_id', 'shareholder', 'from_shareholder_id', 'to_shareholder_id', 'primary_shareholder_id', 'duplicate_shareholder_id', 'deceased_shareholder_id', 'beneficiary_shareholder_id', 'guardian_shareholder_id'];

    // Explicit foreign keys only: an arbitrary record ID is never a shareholder ID.
    private const RECORDS = [
        'shareholder_register_accounts' => ['sra_id', 'sra', 'register_account_id', 'registerAccount'],
        'shareholder_change_requests' => ['change_request_id', 'changeRequest'],
        'shareholder_addresses' => ['address_id', 'address'],
        'shareholder_mandates' => ['mandate_id', 'mandate', 'bank_mandate_id'],
        'shareholder_identities' => ['identity_id', 'identity'],
        'shareholder_cautions' => ['caution_id'],
        'probate_cases' => ['probate_case_id', 'probateCase'],
        'sra_guardians' => ['sraGuardian'],
        'dividend_entitlements' => ['entitlement_id'],
        'dividend_payments' => ['payment_id'],
        'shareholder_change_approvals' => ['change_approval_id'],
        'probate_beneficiaries' => ['beneficiary_id', 'probateBeneficiary'],
        'estate_case_representatives' => ['representative_id'],
    ];

    public function filter($query, int $shareholderId): void
    {
        $query->where(function ($builder) use ($shareholderId) {
            $builder->whereJsonContains('metadata->shareholder_ids', $shareholderId)
                ->orWhereJsonContains('metadata->shareholder_ids', (string) $shareholderId);
            foreach (['', 'route_parameters.', 'payload.', 'data.'] as $prefix) {
                foreach (self::SHAREHOLDER_KEYS as $key) {
                    $column = 'metadata->'.str_replace('.', '->', $prefix.$key);
                    $builder->orWhere($column, $shareholderId)->orWhere($column, (string) $shareholderId);
                }
            }
            foreach (self::RECORDS as $table => $keys) {
                $ids = $this->recordIds($table, $shareholderId);
                if (! $ids) {
                    continue;
                }
                foreach ($keys as $key) {
                    foreach (['metadata->'.$key, 'metadata->route_parameters->'.$key] as $column) {
                        $wrapped = $builder->getQuery()->getGrammar()->wrap($column);
                        $builder->orWhere(function ($legacy) use ($wrapped, $ids) {
                            $legacy->whereNull('metadata->shareholder_ids')
                                ->whereIn(DB::raw("CAST({$wrapped} AS CHAR)"), clone $ids);
                        });
                    }
                }
            }
        });
    }

    private function hasTable(string $table): bool
    {
        if (! array_key_exists($table, $this->columns)) {
            $this->columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : [];
        }

        return $this->columns[$table] !== [];
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->hasTable($table) && in_array($column, $this->columns[$table], true);
    }

    private function recordIds(string $table, int $shareholderId)
    {
        if (! $this->hasTable($table)) {
            return null;
        }
        $query = DB::table($table)->selectRaw('CAST(id AS CHAR)');
        if ($this->hasColumn($table, 'shareholder_id')) {
            return $query->where('shareholder_id', $shareholderId);
        }
        foreach (['sra_id', 'register_account_id'] as $key) {
            if ($this->hasColumn($table, $key) && $this->hasTable('shareholder_register_accounts')) {
                return $query->whereIn($key, DB::table('shareholder_register_accounts')->select('id')->where('shareholder_id', $shareholderId));
            }
        }
        foreach (['entitlement_id' => 'dividend_entitlements', 'change_request_id' => 'shareholder_change_requests', 'probate_case_id' => 'probate_cases', 'caution_id' => 'shareholder_cautions'] as $key => $parent) {
            if ($table !== $parent && $this->hasColumn($table, $key) && ($parentIds = $this->recordIds($parent, $shareholderId))) {
                return $query->whereIn($key, $parentIds);
            }
        }

        return null;
    }

    public function shareholderIds(array $attributes, array $visited = []): array
    {
        $ids = [];
        foreach (self::SHAREHOLDER_KEYS as $key) {
            if (isset($attributes[$key]) && is_scalar($attributes[$key]) && ctype_digit((string) $attributes[$key]) && (int) $attributes[$key] > 0) {
                $ids[] = (int) $attributes[$key];
            }
        }
        foreach (self::RECORDS as $table => $keys) {
            foreach ($keys as $key) {
                if (! isset($attributes[$key]) || ! is_scalar($attributes[$key]) || ! $this->hasTable($table)) {
                    continue;
                }
                $reference = $table.':'.$attributes[$key];
                if (in_array($reference, $visited, true)) {
                    continue;
                }
                $record = DB::table($table)->where('id', $attributes[$key])->first();
                if ($record) {
                    // Follow only known foreign keys, never the record's own ID.
                    $ids = array_merge($ids, $this->shareholderIds((array) $record, [...$visited, $reference]));
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function capture(string $event, Model $model): void
    {
        $request = request();
        if (! $request->attributes->has(self::CONTEXT) || $model->getTable() === 'user_activity_logs') {
            return;
        }
        $before = $event === 'created' ? [] : $model->getRawOriginal();
        $after = $event === 'deleted' ? [] : $model->getAttributes();
        $ids = array_merge($this->shareholderIds($before), $this->shareholderIds($after));
        if ($model instanceof Shareholder) {
            $ids[] = (int) $model->getKey();
        }
        if (! $ids) {
            return;
        }
        $context = $request->attributes->get(self::CONTEXT);
        $context['shareholder_ids'] = array_values(array_unique(array_merge($context['shareholder_ids'], $ids)));
        $before = $this->redact($before, $model->getHidden());
        $after = $this->redact($after, $model->getHidden());
        $changed = array_values(array_filter(array_unique(array_merge(array_keys($before), array_keys($after))), fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null)));
        $context['changes'][] = [
            'entity_type' => $model->getTable(), 'entity_id' => $model->getKey(), 'event' => $event,
            'shareholder_ids' => array_values(array_unique($ids)),
            'before' => array_intersect_key($before, array_flip($changed)),
            'after' => array_intersect_key($after, array_flip($changed)),
        ];
        $request->attributes->set(self::CONTEXT, $context);
    }

    private function redact(array $values, array $hidden = []): array
    {
        foreach ($values as $key => $value) {
            if (in_array($key, $hidden, true) || preg_match('/password|token|secret|authorization|cookie/i', (string) $key)) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            } elseif (is_string($value) && is_array($decoded = json_decode($value, true))) {
                $values[$key] = $this->redact($decoded);
            }
        }

        return $values;
    }
}
