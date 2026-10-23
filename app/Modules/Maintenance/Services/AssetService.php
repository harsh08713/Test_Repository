<?php

namespace App\Modules\Maintenance\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Finance\Models\CostCenter;
use App\Modules\Maintenance\Models\Asset;
use App\Modules\Maintenance\Models\MaintenanceOrder;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assets — the one door for their data. An asset belongs to one branch (one the person works in), has a typed code that is unique in the company, and is never deleted: it is made
 * inactive (not while it has open work orders).
 *
 * Input: ['code', 'name', 'location_id', 'category', 'make_model', 'serial_no', 'installed_on', 'criticality', 'cost_center_id', 'remarks']
 */
final class AssetService
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): Asset
    {
        $data = $this->validated($input, $actor, null);

        return DB::transaction(function () use ($data, $actor) {
            $asset = new Asset;
            $asset->forceFill(['company_id' => (int) $this->tenant->activeCompanyId(), 'location_id' => $data['location_id'], 'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey()] + $data['header'])->save();
            activity('assets')->causedBy($actor)->performedOn($asset)->event('created')->log('created');

            return $asset->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(Asset $asset, array $input, User $actor): Asset
    {
        $data = $this->validated($input, $actor, $asset);

        return DB::transaction(function () use ($asset, $data, $actor) {
            $fresh = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());
            $fresh->forceFill(['location_id' => $data['location_id'], 'updated_by' => $actor->getKey()] + $data['header'])->save();
            activity('assets')->causedBy($actor)->performedOn($fresh)->event('updated')->log('updated');

            return $fresh->refresh();
        });
    }

    /** @throws FieldRuleException */
    public function setStatus(Asset $asset, bool $active, User $actor): Asset
    {
        return DB::transaction(function () use ($asset, $active, $actor) {
            $fresh = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());

            if (! $active && MaintenanceOrder::query()->where('asset_id', $fresh->getKey())->whereIn('state', ['open', 'in_progress'])->lockForUpdate()->value('id') !== null) {
                throw new FieldRuleException('This asset has open work orders: finish or cancel them before making it inactive.');
            }

            $fresh->forceFill(['status' => $active ? 'active' : 'inactive', 'updated_by' => $actor->getKey()])->save();
            activity('assets')->causedBy($actor)->performedOn($fresh)->event($active ? 'activated' : 'deactivated')->log($active ? 'activated' : 'deactivated');

            return $fresh->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{location_id: int, header: array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function validated(array $input, User $actor, ?Asset $editing): array
    {
        $errors = [];
        $companyId = (int) $this->tenant->activeCompanyId();

        $code = strtoupper(trim((string) ($input['code'] ?? '')));

        if (preg_match('/^[A-Z0-9][A-Z0-9\-_\/]{1,29}$/', $code) !== 1) {
            $errors['code'] = 'Give the asset a code of 2 to 30 letters, digits, dashes or slashes (for example PRESS-01).';
        } elseif (Asset::query()->where('code', $code)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing?->getKey()))->exists()) {
            $errors['code'] = 'There is already an asset with that code.';
        }

        $name = trim((string) ($input['name'] ?? ''));

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['name'] = 'Name the asset (2 to 150 characters).';
        }

        $locationId = is_numeric($input['location_id'] ?? null) ? (int) $input['location_id'] : 0;
        $reach = $this->tenant->accessibleLocationIdsFor($actor, $companyId);

        if (Location::query()->whereKey($locationId)->where('status', 'active')->doesntExist() || ($reach !== null && ! in_array($locationId, $reach, true))) {
            $errors['location_id'] = 'Choose a branch you work in.';
        }

        $category = (string) ($input['category'] ?? '');

        if (! array_key_exists($category, Asset::CATEGORIES)) {
            $errors['category'] = 'Choose what kind of asset it is.';
        }

        $criticality = (string) ($input['criticality'] ?? 'medium');

        if (! array_key_exists($criticality, Asset::CRITICALITY)) {
            $errors['criticality'] = 'Choose how critical it is.';
        }

        $makeModel = trim((string) ($input['make_model'] ?? ''));
        $serial = trim((string) ($input['serial_no'] ?? ''));

        if (mb_strlen($makeModel) > 120 || mb_strlen($serial) > 60) {
            $errors['make_model'] = 'The make and model can be at most 120 characters and the serial number 60.';
        }

        $installed = trim((string) ($input['installed_on'] ?? ''));

        if ($installed !== '' && ((($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $installed)) === false) || $d->format('Y-m-d') !== $installed || $installed > now()->toDateString())) {
            $errors['installed_on'] = 'Enter the date it was installed (not in the future), or leave it empty.';
        }

        $centreId = is_numeric($input['cost_center_id'] ?? null) ? (int) $input['cost_center_id'] : null;

        if ($centreId !== null && CostCenter::query()->whereKey($centreId)->doesntExist()) {
            $errors['cost_center_id'] = 'That cost centre was not found.';
        }

        $remarks = trim((string) ($input['remarks'] ?? ''));

        if (mb_strlen($remarks) > 1000) {
            $errors['remarks'] = 'Remarks can be at most 1000 characters.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'location_id' => $locationId,
            'header' => [
                'code' => $code, 'name' => $name, 'category' => $category, 'criticality' => $criticality, 'make_model' => $makeModel === '' ? null : $makeModel, 'serial_no' => $serial === '' ? null : $serial,
                'installed_on' => $installed === '' ? null : $installed, 'cost_center_id' => $centreId, 'remarks' => $remarks === '' ? null : $remarks,
            ],
        ];
    }
}
