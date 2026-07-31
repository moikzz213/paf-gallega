<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MasterDataInstructionsSheet implements FromArray, WithColumnWidths, WithStyles, WithTitle
{
    public function __construct(private readonly array $definition) {}

    public function array(): array
    {
        $rows = [
            [$this->definition['label'].' Import Template', null, null],
            ['How to use', null, null],
            ['1. Enter records on the Import Data sheet. Do not change the header names.', null, null],
            ['2. Required columns are marked with an asterisk (*).', null, null],
            ['3. Remove fully blank rows, then upload the workbook from Master Data.', null, null],
            ['4. Every imported record is active by default (is_active = 1). Imports are create-only and atomic: any invalid or duplicate row cancels the entire import.', null, null],
            [null, null, null],
            ['Column', 'Required', 'Description'],
        ];

        foreach ($this->definition['columns'] as $column) {
            $rows[] = [
                $column['heading'],
                $column['required'] ? 'Yes' : 'No',
                $column['description'],
            ];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Instructions';
    }

    public function columnWidths(): array
    {
        return ['A' => 32, 'B' => 14, 'C' => 88];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->mergeCells('A1:C1');
        $sheet->mergeCells('A2:C2');
        foreach (range(3, 6) as $row) {
            $sheet->mergeCells("A{$row}:C{$row}");
        }
        $sheet->freezePane('A9');
        $sheet->getStyle('A1:C1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1F4E78']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getStyle('A2:C2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['rgb' => '1F4E78']],
        ]);
        $sheet->getStyle('A8:C8')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '5B9BD5']],
        ]);
        $sheet->getStyle('A1:C'.(8 + count($this->definition['columns'])))
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_TOP)
            ->setWrapText(true);
        $sheet->getRowDimension(1)->setRowHeight(28);

        return [];
    }
}
