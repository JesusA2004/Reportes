<?php

namespace App\Services;

use App\Models\Period;
use Illuminate\Support\Facades\DB;

/**
 * Deriva Índice de Rotación de Personal desde el roster canónico (NOI normal +
 * fiscal), sin archivo manual. Altas/bajas/plantilla se calculan SOLO sobre
 * las 13 sucursales operativas — igual que Cartera/Colocación/OPEX.
 * Índice de rotación = bajas / plantilla actual * 100 (misma fórmula que el
 * reporte manual anterior).
 */
class RotacionDerivedFromNoiService
{
    public function __construct(
        private readonly PeriodEmployeeRosterService $rosterService,
    ) {}

    /**
     * @return array{altas:int,bajas:int,plantilla:int,indice:float,branches:int,roster:array}
     */
    public function deriveForPeriod(Period $period): array
    {
        $rosterStats = $this->rosterService->buildForPeriod($period);

        $rows = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('is_branch_operativa', true)
            ->whereNotNull('branch_id')
            ->get(['branch_id', 'branch_name', 'is_active_for_period', 'movement_type']);

        $byBranch = [];
        foreach ($rows as $row) {
            $branchId = (int) $row->branch_id;
            $byBranch[$branchId] ??= ['branch_name' => $row->branch_name, 'altas' => 0, 'bajas' => 0, 'plantilla' => 0];

            if ($row->is_active_for_period) {
                $byBranch[$branchId]['plantilla']++;
                if ($row->movement_type === 'alta') {
                    $byBranch[$branchId]['altas']++;
                }
            } elseif ($row->movement_type === 'baja') {
                $byBranch[$branchId]['bajas']++;
            }
        }

        // Solo tocar las filas que este servicio mismo escribió (source='derived_noi').
        // Si el periodo tiene un archivo manual legacy (source='file', reprocesado por
        // ReportAnalysisService en el paso previo del pipeline), esas filas NUNCA se
        // tocan aquí — son la fuente autoritativa mientras existan (ver
        // BranchRadiographyCalculator/RadiographySnapshotBuilder, que las prefieren).
        DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')->delete();

        $now       = now();
        $mesLabel  = strtoupper($period->label);
        $insert    = [];
        $totalAltas = 0;
        $totalBajas = 0;
        $totalPlantilla = 0;

        foreach ($byBranch as $branchId => $d) {
            $indice = $d['plantilla'] > 0 ? round($d['bajas'] / $d['plantilla'] * 100, 2) : 0.0;
            $insert[] = [
                'period_id'         => $period->id,
                'report_upload_id'  => null,
                'sucursal_nombre'   => $d['branch_name'],
                'branch_id'         => $branchId,
                'mes'               => $mesLabel,
                'bajas'             => $d['bajas'],
                'altas'             => $d['altas'],
                'promedio_personal' => $d['plantilla'],
                'plantilla_maxima'  => null,
                'indice_rotacion'   => $indice,
                'hoja_fuente'       => null,
                'raw_payload'       => null,
                'source'            => 'derived_noi',
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
            $totalAltas     += $d['altas'];
            $totalBajas     += $d['bajas'];
            $totalPlantilla += $d['plantilla'];
        }

        if (!empty($insert)) {
            DB::table('fact_rotacion')->insert($insert);
        }

        $indiceGlobal = $totalPlantilla > 0 ? round($totalBajas / $totalPlantilla * 100, 2) : 0.0;

        $legacy = DB::table('fact_rotacion')
            ->where('period_id', $period->id)
            ->where('source', 'file')
            ->selectRaw('SUM(altas) as altas, SUM(bajas) as bajas, SUM(promedio_personal) as plantilla')
            ->first();

        $legacyExists = $legacy && (int) $legacy->plantilla > 0;
        $matchesLegacy = $legacyExists
            ? ((int) $legacy->altas === $totalAltas && (int) $legacy->bajas === $totalBajas && (int) $legacy->plantilla === $totalPlantilla)
            : null; // null = no hay legacy contra qué comparar (periodo nuevo)

        return [
            'altas'          => $totalAltas,
            'bajas'          => $totalBajas,
            'plantilla'      => $totalPlantilla,
            'indice'         => $indiceGlobal,
            'branches'       => count($insert),
            'roster'         => $rosterStats,
            'legacy_exists'  => $legacyExists,
            'matches_legacy' => $matchesLegacy,
            'legacy'         => $legacyExists ? [
                'altas' => (int) $legacy->altas, 'bajas' => (int) $legacy->bajas, 'plantilla' => (int) $legacy->plantilla,
            ] : null,
        ];
    }
}
