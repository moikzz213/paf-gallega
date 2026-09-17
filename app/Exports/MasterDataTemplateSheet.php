<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MasterDataTemplateSheet implements FromArray, WithColumnWidths, WithEvents, WithHeadings, WithStyles, WithTitle
{
    public function __construct(private readonly array $definition) {}

    public function array(): array
    {
        return [array_fill(0, count($this->definition['columns']), null)];
    }

    public function headings(): array
    {
        return array_map(
            fn (array $column) => $column['heading'].($column['required'] ? ' *' : ''),
            $this->definition['columns'],
        );
    }

    public function title(): string
    {
        return 'Import Data';
    }

    public function columnWidths(): array
    {
        $widths = [];
        foreach ($this->definition['columns'] as $index => $column) {
            $widths[$this->columnLetter($index + 1)] = $column['width'];
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = $this->columnLetter(count($this->definition['columns']));

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1F4E78']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $sheet->getStyle("A2:{$lastColumn}2")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $lastColumn = $this->columnLetter(count($this->definition['columns']));
                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastColumn}1");
                $sheet->getRowDimension(1)->setRowHeight(24);

                foreach ($this->definition['columns'] as $index => $column) {
                    $letter = $this->columnLetter($index + 1);
                    if ($column['key'] === 'credit_limit') {
                        $sheet->getStyle("{$letter}2")->getNumberFormat()->setFormatCode('#,##0.00');
                    }
                    if ($column['key'] === 'credit_days') {
                        $sheet->getStyle("{$letter}2")->getNumberFormat()->setFormatCode('0');
                    }
                }
            },
        ];
    }

    private function columnLetter(int $number): string
    {
        return chr(64 + $number);
    }
}
