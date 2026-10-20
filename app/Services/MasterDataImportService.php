<?php

namespace App\Services;

use App\Imports\MasterDataSpreadsheetImport;
use App\Models\Department;
use App\Support\MasterDataDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class MasterDataImportService
{
    private const MAX_ROWS = 1000;

    /** The departments template's optional Department Heads column. */
    private const HEAD_EMAILS = 'department_head_emails';

    public function __construct(private readonly DepartmentHeadService $headService) {}

    public function import(string $entity, UploadedFile $file): int
    {
        $definition = MasterDataDefinition::find($entity);
        $import = new MasterDataSpreadsheetImport;
        try {
            Excel::import($import, $file);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'file' => ['The file could not be read as an Excel workbook. Download a fresh template and try again.'],
            ]);
        }

        $rows = $import->rows();
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'file' => ['The workbook does not contain any data rows.'],
            ]);
        }
        if ($rows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => ['A maximum of '.self::MAX_ROWS.' rows can be imported at once.'],
            ]);
        }

        // An optional column may be missing from a template downloaded before it was added.
        $expectedHeadings = collect($definition['columns'])
            ->reject(fn (array $column) => $column['optional'])
            ->map(fn (array $column) => $column['key'])
            ->values();
        $actualHeadings = collect($rows->first()->keys())
            ->map(fn (string $heading) => rtrim($heading, '_'))
            ->values();
        $missingHeadings = $expectedHeadings->diff($actualHeadings);
        if ($missingHeadings->isNotEmpty()) {
            throw ValidationException::withMessages([
                'file' => ['Missing template columns: '.$missingHeadings->implode(', ').'. Download a fresh template and keep its headers unchanged.'],
            ]);
        }

        $preparedRows = [];
        $errors = [];
        $seenNames = [];
        $seenCodes = [];

        foreach ($rows as $index => $row) {
            $excelRow = $index + 2;
            $data = $this->normalizeRow($row->toArray(), $definition);
            $validator = Validator::make($data, $this->validationRules($definition));

            foreach ($validator->errors()->all() as $message) {
                $errors[] = "Row {$excelRow}: {$message}";
            }

            // Department Heads are nominated by an admin only, and must be active users. See
            // DepartmentHeadService.
            if (array_key_exists(self::HEAD_EMAILS, $data)) {
                $heads = DepartmentHeadService::resolveEmails($data[self::HEAD_EMAILS]);

                if (($heads['ids'] || $heads['unknown']) && ! auth()->user()?->isAdmin()) {
                    $errors[] = "Row {$excelRow}: Only an administrator can nominate Department Heads. Leave the Department Head Emails column blank.";
                } elseif ($heads['unknown']) {
                    $errors[] = "Row {$excelRow}: Not an active PAF user: ".implode(', ', $heads['unknown']).'. Department Heads must have an active user account.';
                }

                $data[self::HEAD_EMAILS] = $heads['ids'];
            }

            if (! $definition['code_key']) {
                $normalizedName = mb_strtolower((string) ($data['name'] ?? ''));
                if ($normalizedName !== '' && isset($seenNames[$normalizedName])) {
                    $errors[] = "Row {$excelRow}: The name is duplicated in the workbook (first used on row {$seenNames[$normalizedName]}).";
                } elseif ($normalizedName !== '') {
                    $seenNames[$normalizedName] = $excelRow;
                }
            }

            $codeKey = $definition['code_key'];
            $normalizedCode = $codeKey ? mb_strtolower((string) ($data[$codeKey] ?? '')) : '';
            if ($normalizedCode !== '' && isset($seenCodes[$normalizedCode])) {
                $errors[] = "Row {$excelRow}: The code is duplicated in the workbook (first used on row {$seenCodes[$normalizedCode]}).";
            } elseif ($normalizedCode !== '') {
                $seenCodes[$normalizedCode] = $excelRow;
            }

            $preparedRows[] = $data;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_values(array_unique($errors))]);
        }

        return DB::transaction(function () use ($definition, $entity, $preparedRows): int {
            $model = $definition['model'];
            foreach ($preparedRows as $data) {
                $item = $model::create(Arr::except($data, self::HEAD_EMAILS));
                AuditLogger::log('master_data_imported', ucfirst($entity)." \"{$item->name}\" imported");

                if ($item instanceof Department && ! empty($data[self::HEAD_EMAILS])) {
                    $this->headService->sync($item, $data[self::HEAD_EMAILS]);
                }
            }

            return count($preparedRows);
        });
    }

    private function normalizeRow(array $row, array $definition): array
    {
        $data = [];
        foreach ($definition['columns'] as $column) {
            $key = $column['key'];
            $sourceKey = $key.'_';
            $value = $row[$key] ?? $row[$sourceKey] ?? null;
            $data[$key] = is_string($value) ? trim($value) : $value;
        }

        if (array_key_exists('credit_limit', $data) && ($data['credit_limit'] === '' || $data['credit_limit'] === null)) {
            $data['credit_limit'] = 0;
        }
        if (array_key_exists('credit_days', $data) && ($data['credit_days'] === '' || $data['credit_days'] === null)) {
            $data['credit_days'] = 0;
        }
        // An unrated currency is allowed in (the base currency needs none, and a rate can be filled
        // in later) — it is sending one for approval that is refused, not recording it.
        if (array_key_exists('exchange_rate', $data) && ($data['exchange_rate'] === '' || $data['exchange_rate'] === null)) {
            $data['exchange_rate'] = null;
        }
        if ($definition['code_key'] && $data[$definition['code_key']] === '') {
            $data[$definition['code_key']] = null;
        }
        $data['is_active'] = true;

        return $data;
    }

    private function validationRules(array $definition): array
    {
        $rules = [
            'name' => array_merge(['required'], MasterDataDefinition::nameRules($definition)),
            'is_active' => ['required', 'boolean'],
        ];

        if (! $definition['code_key']) {
            $rules['name'][] = Rule::unique($definition['table'], 'name');
        }

        if ($definition['code_key']) {
            $codeKey = $definition['code_key'];
            $rules[$codeKey] = [
                'nullable',
                'string',
                'max:50',
                Rule::unique($definition['table'], $codeKey)->whereNotNull($codeKey),
            ];
            $rules['credit_limit'] = ['required', 'numeric', 'min:0', 'max:999999999999'];
            $rules['credit_days'] = ['required', 'integer', 'min:0', 'max:365'];
        }

        if (collect($definition['columns'])->contains(fn ($column) => $column['key'] === self::HEAD_EMAILS)) {
            $rules[self::HEAD_EMAILS] = ['nullable', 'string', 'max:2000'];
        }

        if (collect($definition['columns'])->contains(fn ($column) => $column['key'] === 'exchange_rate')) {
            $rules['exchange_rate'] = ['nullable', 'numeric', 'gt:0', 'max:999999999'];
        }

        return $rules;
    }
}
