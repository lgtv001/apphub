<?php

namespace App\Exports;

use App\Models\Area;
use App\Models\Subarea;
use App\Models\Sistema;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class QuiebreTemplateExport implements FromArray, WithHeadings, WithColumnWidths, WithTitle, WithEvents
{
    private array $areasCodigos;
    private array $subareasCodigos;
    private array $sistemasCodigos;

    public function __construct(private int $proyectoId)
    {
        $this->areasCodigos    = Area::where('proyecto_id', $proyectoId)->orderBy('codigo')->pluck('codigo')->toArray();
        $this->subareasCodigos = Subarea::where('proyecto_id', $proyectoId)->orderBy('codigo')->pluck('codigo')->toArray();
        $this->sistemasCodigos = Sistema::where('proyecto_id', $proyectoId)->orderBy('codigo')->pluck('codigo')->toArray();
    }

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
            ['3600',    'Estructura',        'area',       ''],
            ['3610',    'Fundaciones',       'subarea',    '3600'],
            ['3610B',   'Pilotes',           'sistema',    '3610'],
            ['3610B-1', 'Pilotes hormigón',  'subsistema', '3610B'],
        ];
    }

    public function columnWidths(): array
    {
        return ['A' => 15, 'B' => 45, 'C' => 15, 'D' => 25];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet       = $event->sheet->getDelegate();
                $spreadsheet = $sheet->getParent();

                // ── Hoja oculta con los valores válidos ──────────────────
                $listsSheet = $spreadsheet->createSheet();
                $listsSheet->setTitle('_Listas');
                $listsSheet->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

                // Columna A: niveles
                foreach (['area', 'subarea', 'sistema', 'subsistema'] as $i => $v) {
                    $listsSheet->setCellValue('A' . ($i + 1), $v);
                }

                // Columna B: áreas
                foreach ($this->areasCodigos as $i => $v) {
                    $listsSheet->setCellValue('B' . ($i + 1), $v);
                }

                // Columna C: subáreas
                foreach ($this->subareasCodigos as $i => $v) {
                    $listsSheet->setCellValue('C' . ($i + 1), $v);
                }

                // Columna D: sistemas
                foreach ($this->sistemasCodigos as $i => $v) {
                    $listsSheet->setCellValue('D' . ($i + 1), $v);
                }

                // ── Validación: columna C (nivel), filas 2-500 ───────────
                $nivelValidation = new DataValidation();
                $nivelValidation->setType(DataValidation::TYPE_LIST)
                    ->setErrorStyle(DataValidation::STYLE_STOP)
                    ->setAllowBlank(true)
                    ->setShowDropDown(false)
                    ->setShowErrorMessage(true)
                    ->setErrorTitle('Nivel inválido')
                    ->setError('Usa: area, subarea, sistema o subsistema')
                    ->setFormula1('_Listas!$A$1:$A$4')
                    ->setSqref('C2:C500');
                $sheet->setDataValidation('C2:C500', $nivelValidation);

                // ── Validación: columna D (codigo_padre), filas 2-500 ────
                $allPadres = array_merge(
                    $this->areasCodigos,
                    $this->subareasCodigos,
                    $this->sistemasCodigos
                );

                if (!empty($allPadres)) {
                    $lastRow = count($allPadres);

                    // Escribir en columna E de _Listas (concatenación de todas)
                    foreach ($allPadres as $i => $v) {
                        $listsSheet->setCellValue('E' . ($i + 1), $v);
                    }

                    $padreValidation = new DataValidation();
                    $padreValidation->setType(DataValidation::TYPE_LIST)
                        ->setErrorStyle(DataValidation::STYLE_INFORMATION)
                        ->setAllowBlank(true)
                        ->setShowDropDown(false)
                        ->setShowErrorMessage(true)
                        ->setErrorTitle('Código padre no encontrado')
                        ->setError('Selecciona un código existente en el proyecto')
                        ->setFormula1('_Listas!$E$1:$E$' . $lastRow)
                        ->setSqref('D2:D500');
                    $sheet->setDataValidation('D2:D500', $padreValidation);
                }

                // Volver a la hoja principal
                $spreadsheet->setActiveSheetIndex(0);
            },
        ];
    }
}
