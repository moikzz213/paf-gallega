<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class MasterDataSpreadsheetImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    private bool $capturedFirstSheet = false;

    private Collection $rows;

    public function __construct()
    {
        $this->rows = collect();
    }

    public function collection(Collection $rows): void
    {
        if ($this->capturedFirstSheet) {
            return;
        }

        $this->rows = $rows;
        $this->capturedFirstSheet = true;
    }

    public function rows(): Collection
    {
        return $this->rows;
    }
}
