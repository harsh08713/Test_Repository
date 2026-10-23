<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\LeaveLedgerEntry;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;

#[Title('Leave types')]
class LeaveTypeList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return LeaveType::class;
    }

    protected function title(): string
    {
        return 'Leave types';
    }

    protected function description(): string
    {
        return 'The kinds of leave and their rules: the yearly quota, when it is granted, how much carries forward, and whether half days or a negative balance are allowed (unpaid leave).';
    }

    protected function columns(): array
    {
        return ['code' => 'Code', 'name' => 'Name', 'is_paid' => 'Paid', 'annual_quota' => 'Days a year', 'accrual' => 'Granted', 'carry_forward_max' => 'Carry forward'];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. CL'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'is_paid', 'label' => 'Paid leave', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
            ['name' => 'annual_quota', 'label' => 'Days a year', 'type' => 'number', 'default' => '0', 'rules' => ['required', 'numeric', 'min:0', 'max:365', 'regex:/^\d{1,3}(\.[05])?$/'], 'help' => 'Whole or half days.'],
            ['name' => 'accrual', 'label' => 'When it is granted', 'type' => 'select', 'default' => 'yearly', 'rules' => ['required', 'in:yearly,monthly,none']],
            ['name' => 'carry_forward_max', 'label' => 'Most days carried into next year', 'type' => 'number', 'default' => '0', 'rules' => ['required', 'numeric', 'min:0', 'max:365', 'regex:/^\d{1,3}(\.[05])?$/'], 'help' => 'What is left over beyond this lapses at the end of the year.'],
            ['name' => 'allow_half_day', 'label' => 'Can be taken by the half day', 'type' => 'checkbox', 'default' => true, 'rules' => ['boolean']],
            ['name' => 'allow_negative', 'label' => 'Balance may go below zero (unpaid leave)', 'type' => 'checkbox', 'default' => false, 'rules' => ['boolean']],
            ['name' => 'max_days_per_request', 'label' => 'Longest single request, in days (optional)', 'type' => 'number', 'default' => '', 'rules' => ['nullable', 'integer', 'min:1', 'max:90']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function options(string $field): array
    {
        return $field === 'accrual' ? LeaveType::ACCRUALS : parent::options($field);
    }

    protected function cell(Model $model, string $column): string
    {
        return match ($column) {
            'accrual' => LeaveType::ACCRUALS[$model->getAttribute('accrual')] ?? (string) $model->getAttribute('accrual'),
            'annual_quota', 'carry_forward_max' => rtrim(rtrim((string) $model->getAttribute($column), '0'), '.') ?: '0',
            default => parent::cell($model, $column),
        };
    }

    /** @param  array<string, mixed>  $attributes */
    protected function beforeSave(array $attributes): array
    {
        $attributes['max_days_per_request'] = ($attributes['max_days_per_request'] ?? '') === '' ? null : (int) $attributes['max_days_per_request'];

        return $attributes;
    }

    protected function usageBlocker(Model $model): ?string
    {
        if (LeaveRequest::query()->where('leave_type_id', $model->getKey())->exists() || LeaveLedgerEntry::query()->where('leave_type_id', $model->getKey())->exists()) {
            return 'This kind of leave has been used, so it cannot be deleted. Make it inactive instead.';
        }

        return null;
    }
}
