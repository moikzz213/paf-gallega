<?php

namespace App\Http\Controllers;

use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\Department;
use App\Models\Location;
use App\Models\Vendor;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class MasterDataController extends Controller
{
    private const ENTITIES = [
        'vendors' => Vendor::class,
        'customers' => Customer::class,
        'business-units' => BusinessUnit::class,
        'departments' => Department::class,
        'locations' => Location::class,
    ];

    public function index(string $entity)
    {
        $model = $this->resolveModel($entity);

        return $model::query()->orderBy('name')->get();
    }

    public function store(Request $request, string $entity)
    {
        $model = $this->resolveModel($entity);

        $data = $request->validate($this->validationRules($entity));
        $this->normalizeCode($data, $entity);

        $item = $model::create($data);

        AuditLogger::log('master_data_created', ucfirst($entity)." \"{$item->name}\" created");

        return response()->json($item, 201);
    }

    public function update(Request $request, string $entity, int $id)
    {
        $model = $this->resolveModel($entity);
        $item = $model::findOrFail($id);

        $data = $request->validate($this->validationRules($entity, $item->id));
        $this->normalizeCode($data, $entity);

        $item->update($data);

        AuditLogger::log('master_data_updated', ucfirst($entity)." \"{$item->name}\" updated");

        return response()->json($item);
    }

    public function destroy(string $entity, int $id)
    {
        $model = $this->resolveModel($entity);
        $item = $model::findOrFail($id);

        AuditLogger::log('master_data_deleted', ucfirst($entity)." \"{$item->name}\" deleted");
        $item->delete();

        return response()->json(['message' => 'Deleted']);
    }

    private function validationRules(string $entity, ?int $ignoreId = null): array
    {
        $uniqueName = function (string $table) use ($ignoreId): Unique {
            $rule = Rule::unique($table, 'name');
            if ($ignoreId) {
                $rule->ignore($ignoreId);
            }

            return $rule;
        };

        $creditRules = [
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'credit_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];

        $common = ['is_active' => ['boolean']];

        return match ($entity) {
            'vendors' => array_merge([
                'name' => ['required', 'string', 'max:255', $uniqueName('vendors')],
                'vendor_code' => ['nullable', 'string', 'max:50', Rule::unique('vendors', 'vendor_code')->whereNotNull('vendor_code')->when($ignoreId, fn ($r) => $r->ignore($ignoreId))],
            ], $creditRules, $common),

            'customers' => array_merge([
                'name' => ['required', 'string', 'max:255', $uniqueName('customers')],
                'customer_code' => ['nullable', 'string', 'max:50', Rule::unique('customers', 'customer_code')->whereNotNull('customer_code')->when($ignoreId, fn ($r) => $r->ignore($ignoreId))],
            ], $creditRules, $common),

            default => array_merge([
                'name' => ['required', 'string', 'max:255', $uniqueName((new ($this->resolveModel($entity)))->getTable())],
            ], $common),
        };
    }

    private function normalizeCode(array &$data, string $entity): void
    {
        $codeKey = $entity === 'vendors' ? 'vendor_code' : ($entity === 'customers' ? 'customer_code' : null);
        if ($codeKey && isset($data[$codeKey]) && $data[$codeKey] === '') {
            $data[$codeKey] = null;
        }
    }

    private function resolveModel(string $entity): string
    {
        $class = self::ENTITIES[$entity] ?? null;

        abort_unless($class, 404, 'Unknown master data entity.');

        return $class;
    }
}
