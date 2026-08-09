<?php

namespace Database\Seeders;

use App\Models\AplicacionExterna;
use Illuminate\Database\Seeder;

class AplicacionesSeeder extends Seeder
{
    public function run(): void
    {
        $kpisSso = AplicacionExterna::updateOrCreate(
            ['codigo' => 'kpis-sso'],
            ['nombre' => 'KPI de Accidentes', 'url_base' => 'https://kpis-sso.lglabproyect.com', 'activo' => true]
        );

        foreach ([
            ['codigo' => 'metricas', 'nombre' => 'Métricas'],
            ['codigo' => 'historial', 'nombre' => 'Historial'],
            ['codigo' => 'cargar', 'nombre' => 'Cargar datos'],
        ] as $seccion) {
            $kpisSso->secciones()->updateOrCreate(['codigo' => $seccion['codigo']], $seccion);
        }

        AplicacionExterna::updateOrCreate(
            ['codigo' => 'tarjetas-verdes'],
            ['nombre' => 'Tarjetas Verdes', 'url_base' => '', 'activo' => false]
        );
        AplicacionExterna::updateOrCreate(
            ['codigo' => 'vcc'],
            ['nombre' => 'VCC', 'url_base' => '', 'activo' => false]
        );
        AplicacionExterna::updateOrCreate(
            ['codigo' => 'higiene-seguridad'],
            ['nombre' => 'Control de Higiene y Seguridad', 'url_base' => '', 'activo' => false]
        );
    }
}
