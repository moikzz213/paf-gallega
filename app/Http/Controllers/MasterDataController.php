<?php

namespace App\Http\Controllers;

use App\Exports\MasterDataTemplateExport;
use App\Services\AuditLogger;
use App\Services\MasterDataImportService;
use App\Support\MasterDataDefinition;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Unique;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MasterDataController extends Controller
{
    public function __construct(private readonly MasterDataImportService $importService) {}

    public function index(Request $request, string $entity)
    {
        $model = $this->resolveModel($entity);
        $definition = $this->resolveDefinition($entity);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = $model::query();

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $query->where(function ($builder) use ($definition, $search) {
                $builder->where('name', 'like', "%{$search}%");

                if ($definition['code_key']) {
                    $builder->orWhere($definition['code_key'], 'like', "%{$search}%");
                }
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 15);

        return $query->orderBy('name')->orderBy('id')->paginate($perPage);
    }

    public function store(Request $request, string $entity)
    {
        $model = $this->resolveModel($entity);

        $this->normalizeRequestCode($request, $entity);
        $data = $request->validate($this->validationRules($entity));
        $this->normalizeCode($data, $entity);

        $item = $model::create($data);

        AuditLogger::log('master_data_created', ucfirst($entity)." \"{$item->name}\" created");

        return response()->json($item, 201);
    }

    public function template(string $entity): BinaryFileResponse
    {
        $this->resolveDefinition($entity);
        $filename = $entity.'-import-template.xlsx';

        return Excel::download(new MasterDataTemplateExport($entity), $filename);
    }

    public function import(Request $request, string $entity)
    {
        $definition = $this->resolveDefinition($entity);
        $data = $request->validate([
            'file' => ['required', File::types(['xlsx', 'xls'])->max(5 * 1024)],
        ]);

        $count = $this->importService->import($entity, $data['file']);

        return response()->json([
            'message' => "{$definition['label']} import completed: {$count} record".($count === 1 ? '' : 's').' created.',
            'imported_count' => $count,
        ]);
    }

    public function update(Request $request, string $entity, int $id)
    {
        $model = $this->resolveModel($entity);
        $item = $model::findOrFail($id);

        $this->normalizeRequestCode($request, $entity);
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
                'name' => ['required', 'string', 'max:255'],
                'vendor_code' => ['nullable', 'string', 'max:50', Rule::unique('vendors', 'vendor_code')->whereNotNull('vendor_code')->when($ignoreId, fn ($r) => $r->ignore($ignoreId))],
            ], $creditRules, $common),

            'customers' => array_merge([
                'name' => ['required', 'string', 'max:255'],
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

    private function normalizeRequestCode(Request $request, string $entity): void
    {
        $codeKey = $entity === 'vendors' ? 'vendor_code' : ($entity === 'customers' ? 'customer_code' : null);
        if (! $codeKey || ! $request->exists($codeKey)) {
            return;
        }

        $value = $request->input($codeKey);
        if (is_string($value)) {
            $value = trim($value);
        }

        $request->merge([$codeKey => $value === '' ? null : $value]);
    }

    private function resolveModel(string $entity): string
    {
        return $this->resolveDefinition($entity)['model'];
    }

    private function resolveDefinition(string $entity): array
    {
        $definition = MasterDataDefinition::find($entity);

        abort_unless($definition, 404, 'Unknown master data entity.');

        return $definition;
    }
}
