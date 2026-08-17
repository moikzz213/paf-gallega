<?php

namespace App\Services;

use App\Imports\MasterDataSpreadsheetImport;
use App\Support\MasterDataDefinition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class MasterDataImportService
{
    private const MAX_ROWS = 1000;

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

        $expectedHeadings = collect($definition['columns'])
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
                $item = $model::create($data);
                AuditLogger::log('master_data_imported', ucfirst($entity)." \"{$item->name}\" imported");
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

        return $rules;
    }
}
