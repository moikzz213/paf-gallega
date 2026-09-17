<?php

namespace App\Exports;

use App\Support\MasterDataDefinition;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class MasterDataTemplateExport implements WithMultipleSheets
{
    public function __construct(private readonly string $entity) {}

    public function sheets(): array
    {
        $definition = MasterDataDefinition::find($this->entity);

        return [
            new MasterDataTemplateSheet($definition),
            new MasterDataInstructionsSheet($definition),
        ];
    }
}
