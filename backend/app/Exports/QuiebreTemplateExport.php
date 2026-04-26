<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class QuiebreTemplateExport implements FromArray, WithHeadings, WithColumnWidths, WithTitle
{
    public function title(): string
    {
        return 'Quiebre del Contrato';
    }

    public function headings(): array
    {
        return ['codigo', 'nombre', 'nivel', 'codigo_padre_de_quiebre'];
    }

    public function array(): array
    {
        return [
            ['3600',   'Estructura',       'area',        ''],
            ['3610',   'Fundaciones',      'subarea',     '3600'],
            ['3610B',  'Pilotes',          'sistema',     '3610'],
            ['3610B-1','Pilotes hormigón', 'subsistema',  '3610B'],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 15, 'B' => 45, 'C' => 15, 'D' => 25];
    }
}
