<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class CscsActivityExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(private readonly Collection $events) {}

    public function collection(): Collection
    {
        return $this->events;
    }

    public function headings(): array
    {
        return [
            'Event ID',
            'Date',
            'Event',
            'From Status',
            'To Status',
            'Actor',
            'Actor Email',
            'Comment',
        ];
    }

    public function map($event): array
    {
        return [
            $event->id,
            optional($event->created_at)->toIso8601String(),
            $event->event_type,
            $event->from_status,
            $event->to_status,
            $event->actor?->name ?? $event->actor?->full_name,
            $event->actor?->email,
            $event->comment,
        ];
    }
}
