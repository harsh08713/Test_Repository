<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Shift;
use App\Modules\Masters\Http\Livewire\SimpleMasterList;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;

#[Title('Shifts')]
class ShiftList extends SimpleMasterList
{
    protected function modelClass(): string
    {
        return Shift::class;
    }

    protected function title(): string
    {
        return 'Shifts';
    }

    protected function description(): string
    {
        return 'Working hours. Punching in after the start plus the grace is late; the time between the first punch in and the last punch out decides present, half day or absent. One shift is the default for people without a shift of their own.';
    }

    protected function columns(): array
    {
        return ['code' => 'Code', 'name' => 'Name', 'start_time' => 'Starts', 'end_time' => 'Ends', 'grace_minutes' => 'Grace (min)', 'full_day_minutes' => 'Full day (min)', 'half_day_minutes' => 'Half day (min)', 'is_default' => 'Default'];
    }

    protected function fields(): array
    {
        $time = ['required', 'date_format:H:i,H:i:s'];

        return [
            ['name' => 'code', 'label' => 'Code', 'type' => 'text', 'uppercase' => true, 'placeholder' => 'e.g. DAY'],
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
            ['name' => 'start_time', 'label' => 'Starts at', 'type' => 'text', 'placeholder' => '09:30', 'default' => '09:30', 'rules' => $time],
            ['name' => 'end_time', 'label' => 'Ends at', 'type' => 'text', 'placeholder' => '18:30', 'default' => '18:30', 'rules' => $time],
            ['name' => 'grace_minutes', 'label' => 'Grace for being late (minutes)', 'type' => 'number', 'default' => '15', 'rules' => ['required', 'integer', 'min:0', 'max:180']],
            ['name' => 'full_day_minutes', 'label' => 'Minutes for a full day', 'type' => 'number', 'default' => '480', 'rules' => ['required', 'integer', 'min:60', 'max:1000']],
            ['name' => 'half_day_minutes', 'label' => 'Minutes for a half day', 'type' => 'number', 'default' => '240', 'rules' => ['required', 'integer', 'min:30', 'max:1000', 'lte:values.full_day_minutes']],
            ['name' => 'is_default', 'label' => 'The company’s default shift', 'type' => 'checkbox', 'default' => false, 'rules' => ['boolean']],
            ['name' => 'remarks', 'label' => 'Remarks', 'type' => 'textarea', 'rules' => ['nullable', 'string', 'max:1000']],
            $this->statusField(),
        ];
    }

    protected function cell(Model $model, string $column): string
    {
        return in_array($column, ['start_time', 'end_time'], true) ? substr((string) $model->getAttribute($column), 0, 5) : parent::cell($model, $column);
    }

    protected function usageBlocker(Model $model): ?string
    {
        return Employee::query()->where('shift_id', $model->getKey())->exists() ? 'People are on this shift, so it cannot be deleted. Make it inactive or move them first.' : null;
    }
}
