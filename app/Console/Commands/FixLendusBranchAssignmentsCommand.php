<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Assigns branch_id to NULL-branch PAGO FINANCIAMIENTO MOTO and COMPRA DE CASCOS
 * rows in fact_expenses using the ACUMULADOS ABRIL SUCURSALES employee-branch mapping.
 *
 * Run: php artisan reportes:fix-lendus-branches [--dry-run]
 */
class FixLendusBranchAssignmentsCommand extends Command
{
    protected $signature   = 'reportes:fix-lendus-branches {--dry-run : Solo muestra cambios sin aplicar}';
    protected $description = 'Asigna branch_id a PAGO FINANCIAMIENTO MOTO y COMPRA DE CASCOS en fact_expenses usando ACUMULADOS ABRIL SUCURSALES.';

    // Employees per branch from ACUMULADOS ABRIL SUCURSALES (authoritative mapping)
    private const ACUMULADOS_BRANCHES = [
        'ATLACOMULCO' => [
            'DIEGO MARTINEZ ROMERO','ANGELICA AZUCENA GONZALEZ GRANADOS','ELIOT SERRANO SANCHEZ',
            'LUIS ANGEL CRUZ VELAZQUEZ','ALMMER ARIM CRUZ BALLESTEROS','LUIS JONATHAN DOMINGUEZ JUAREZ',
            'VICTOR HUGO CRUZ BARRIOS','JOSE GUADALUPE CAMACHO ALCANTARA','LUIS CARLOS LOVERA CASIMIRO',
        ],
        'ATLIXCO' => [
            'LUIS ANGEL LOPEZ RAMIREZ','SAUL VALERIO VALENTIN','ROBERTO CHRISTIAN GONZALEZ RAMIREZ',
            'DAVID GAMBOA CANO','PAVEL CASTRO MARTINEZ','RAFAEL PEREZ CHAO','RAMSES LEONIDES ROJAS PEREZ',
            // Variantes tipográficas observadas en observaciones
            'RAMSES LEONIDAS ROJAS PEREZ','RAMSES LEONIDAS ROJAS','PAVEL CASTROS MARTINEZ',
            // Extras: IVAN FUENTES PASTRANA y ROBERTO RAMIREZ GONZALEZ (Cascos), ABIGAIL GARCIA MALDONADO
            'IVAN FUENTES PASTRANA','ROBERTO RAMIREZ GONZALEZ','ABIGAIL GARCIA MALDONADO',
        ],
        'CORDOBA' => [
            'RICARDO ROCHA MORA','ERNESTO LOPEZ LOPEZ','ISAIT DANIEL REYES DE LOS SANTOS',
            'HESIQUIO MARTINEZ ZUNIGA','HEIDY ORTIZ MENDEZ','BERENICE JUAREZ TEMOXTLE',
            'JOSE ALFREDO JIMENEZ FLORES','ANA KAREN BERNABE GAMBOA','JUAN FERNANDO ACEVEDO MORALES',
            'CARLOS ALBERTO MONTALVO SANTIAGO','MIGUEL ANGEL TREJO PERALTA','JESUS ERICK CACHO URIBE',
            'ALEJANDRO REYES PEREA','BERNABEL RIVERA AMBROSIO','JESUS IVAN PORRAS CORONA',
            'MIGUEL ALONSO RAMIREZ CONTRERAS','LORENZO VAZQUEZ AGUILLEN','ANGEL DE JESUS RODRIGUEZ SANCHEZ',
            'JOSE ALBERTO AMECA RODRIGUEZ','JOSE MIGUEL ALONSO MARTINEZ',
            // Variantes tipográficas observadas en observaciones
            'ALEJARO REYES PEREA','ALEJANDRO REYTES PEREA','ANGEL DE JESUS RODIGUEZ SANCH',
        ],
        'CUERNAVACA' => [
            'MARIBEL DIAZ SUAREZ','VICTOR JESUS SANCHEZ NAVA','GUADALUPE MODESTO OCAMPO',
            'MANUEL ANTONIO BERISTAIN MOJICA','SANDRA LIZETH LEGORRETA DE LA ROSA',
            'RODOLFO TONATIUH GUTIERREZ LEON Y VELEZ','ALAN OMAR LOPEZ HERNANDEZ',
        ],
        'HUAMANTLA' => [
            'ROBERTO GALICIA VELAZQUEZ','ROSALINDA MARIBEL CUELLAR FLORES','OMAR MUNOZ SANCHEZ',
            'ISAAC SACRAMENTO HERNANDEZ','JUAN ANGEL GONZALEZ RUIZ','ANOHARD SANCHEZ JIMENEZ',
            'ISIDRO HERNANDEZ MORALES','PHOL RAMIREZ HERNANDEZ',
        ],
        'IXTLAHUACA' => [
            'ANA LAURA SANCHEZ ALDAMA','LUIS GARAY PEREZ','DULCE ARELI HERNANDEZ LOPEZ',
            'GABRIEL JIMENEZ LOPEZ','MARCOS URBANO LUCAS','LUIS ANTONIO PENA JERONIMO',
        ],
        'MIACATLAN' => [
            'LORENZO CAMPOS GUTIERREZ','ISAI BARRERA BERNABE','FELIPE MIGUEL GUTIERREZ SALGADO',
            'MARIA FLOR LOPEZ CORTES','JOSE EDUARDO AVELAR MORALES','MONICA OCAMPO NIETO',
            'DIEGO FERNANDO CHAVARRIA CARRANCO',
        ],
        'ORIZABA' => [
            'ALEJANDRA ROSAS JUAREZ','LIZETTE ALEJANDRA AGUILAR RIVAS','EDER ISAI GABRIEL AMADOR',
            'MARGARITA JAZMIN NOLASCO DOMINGUEZ','EVA CRISTINA SANCHEZ VELASCO',
            'FELIPE ADRIAN PATINO CASTANEDA','MAURICIO VELAZQUEZ CECILIO','GRISELDA MARCELINO PELLICO',
            'ALEJANDRO RIVERA DEL VALLE','IVAN QUEVEDO LOPEZ','LUIS ANTONIO HERRERA ROSAS',
            'ENRIQUE HERNANDEZ CARDENAS','LUIS GERARDO ROMERO ROMERO','ELISEO ACEVEDO MENDEZ',
            'GUIDO HUMBERTO RUIZ ROQUE','JESUS ADRIAN CADENA VELASQUEZ','LUIS ALBERTO CASTANEDA GONZALEZ',
            'JESUS LOZADA GUILLEN','JULIO RODRIGUEZ MARTINEZ','ALBINO REYES VASQUEZ',
        ],
        'SAN LUIS POTOSI' => [
            'RAFAEL JACOBO HERRERA','ALBERTO FLORENTINO BRAVO BRAVO','CARLOS EDUARDO TORRES CASTILLO',
            'LUIS LUGO LOZANO','SANDRA IVETTE CARBAJAL GUTIERREZ','MAYRA ROSARIO AVILA HERNANDEZ',
            'TERESA DE JESUS CAZARES ROBLES','MERCEDES RODRIGUEZ CAMPOS','CARLOS ALEXIS ARAGON PONCE',
        ],
        'TENANGO DEL VALLE' => [
            'VICTOR ANGEL CANO GARCIA','YAEL ISMAEL AVILA MEJIA','ALEXIS JESUS ARIAS LOPEZ',
            'DENISE SARAI DE LA SANCHA ARELLANO','HUGO CESAR CARRIOLA PENA','JUAN CARLOS MIRANDA HERNANDEZ',
            'ROBERTO CARLOS FLORES MORENO','CRISPIN MORA SANCHEZ','EDUARDO GARDUNO CASTRO',
            'ADRIAN DAVID MUNIZ VAZQUEZ',
        ],
        'TLAXCALA' => [
            'JOSE JUAN MUNOZ VAZQUEZ','ANDRES RAYON SAN JUAN','ISAIAS FARIAS BONILLA',
            'EDEN DIAZ VAZQUEZ','ANTONIO SANCHEZ ROJAS','MARICELA FLORES CABRERA',
            'JUAN CARLOS FIBELA BAEZ','CLAUDIA IVETTE ZETINA AKE','TOMAS PEREZ PEREZ',
        ],
        'TULA' => [
            'MONICA ANA KAREN GARCIA CANCINO','DIANA BORGES BRISENO','GERSON MALDONADO MARTINEZ',
            'LIDIA MONTSERRAT JIMENEZ CASTILLO','JOSUE ALEJANDRO LUGARDO JUAREZ','ADRIAN DIAZ MENA',
            'LUIS ALBERTO AGUILAR BARRERA','BLANCA CASANDRA CRUZ CRUZ','JESSICA JUNUEN DIAZ VELAZQUEZ',
            'DEYANIRA LOPEZ QUIJANO','DANIEL ALEJANDRO GUZMAN GARCIA',
        ],
    ];

    // VACANTE entries in COMPRA DE CASCOS → always CUERNAVACA
    private const VACANTE_BRANCH = 'CUERNAVACA';

    public function handle(): int
    {
        $dry = $this->option('dry-run');
        if ($dry) $this->info('── MODO DRY-RUN: no se aplicarán cambios ──');

        // Build normalized name → branch_name mapping
        $nameMap = [];
        foreach (self::ACUMULADOS_BRANCHES as $branch => $employees) {
            foreach ($employees as $emp) {
                $nameMap[$this->normalize($emp)] = $branch;
            }
        }

        // Load branch_id lookup
        $branchIds = DB::table('branches')->pluck('id', 'name')->toArray();
        // Normalize branch names for lookup
        $branchIdByNorm = [];
        foreach ($branchIds as $name => $id) {
            $branchIdByNorm[strtoupper(trim($name))] = $id;
        }

        // ──────────────────────────────────────────────
        // 1. PAGO FINANCIAMIENTO MOTO
        // ──────────────────────────────────────────────
        $this->info('');
        $this->info('═══ PAGO FINANCIAMIENTO MOTO ═══');

        $filas = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', [5, 6, 7, 8, 14])
            ->where('ds.code', 'gastos_lendus_excel')
            ->where('e.concept', 'PAGO FINANCIAMIENTO MOTO')
            ->whereNull('e.branch_id')
            ->select('e.id', 'e.observations', DB::raw('COALESCE(NULLIF(e.paid_amount,0),e.amount) as total'))
            ->get();

        $this->line("  Filas NULL branch encontradas: {$filas->count()}");

        $fixed = 0; $unmatched = [];
        foreach ($filas as $row) {
            $empName = $this->parseNameFromObs($row->observations);
            $branch  = $this->findBranch($empName, $nameMap);

            if ($branch) {
                $branchId = $branchIdByNorm[strtoupper($branch)] ?? null;
                $this->line("  ✓ {$empName} → {$branch} (\${$row->total})");
                if (!$dry && $branchId) {
                    DB::table('fact_expenses')->where('id', $row->id)->update(['branch_id' => $branchId]);
                }
                $fixed++;
            } else {
                $unmatched[] = $empName . ' ($' . number_format($row->total, 2) . ')';
            }
        }

        if ($unmatched) {
            $this->warn('  Sin match:');
            foreach ($unmatched as $u) $this->warn("    - {$u}");
        }
        $this->info("  Fijados: {$fixed} / {$filas->count()}");

        // ──────────────────────────────────────────────
        // 2. COMPRA DE CASCOS
        // ──────────────────────────────────────────────
        $this->info('');
        $this->info('═══ COMPRA DE CASCOS ═══');

        $cascos = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', [5, 6, 7, 8, 14])
            ->where('ds.code', 'gastos_lendus_excel')
            ->where('e.concept', 'COMPRA DE CASCOS')
            ->whereNull('e.branch_id')
            ->select('e.id', 'e.observations', DB::raw('COALESCE(NULLIF(e.paid_amount,0),e.amount) as total'))
            ->get();

        $this->line("  Filas NULL branch encontradas: {$cascos->count()}");

        $fixedC = 0; $unmatchedC = [];
        foreach ($cascos as $row) {
            $obs     = $row->observations;
            $empName = $this->parseNameFromObs($obs);

            // VACANTE → siempre CUERNAVACA
            if (stripos($empName, 'VACANTE') !== false) {
                $branch  = self::VACANTE_BRANCH;
            } else {
                $branch = $this->findBranch($empName, $nameMap);
            }

            if ($branch) {
                $branchId = $branchIdByNorm[strtoupper($branch)] ?? null;
                $this->line("  ✓ {$empName} → {$branch} (\${$row->total})");
                if (!$dry && $branchId) {
                    DB::table('fact_expenses')->where('id', $row->id)->update(['branch_id' => $branchId]);
                }
                $fixedC++;
            } else {
                $unmatchedC[] = $empName . ' ($' . number_format($row->total, 2) . ')';
            }
        }

        if ($unmatchedC) {
            $this->warn('  Sin match:');
            foreach ($unmatchedC as $u) $this->warn("    - {$u}");
        }
        $this->info("  Fijados: {$fixedC} / {$cascos->count()}");

        // ──────────────────────────────────────────────
        // 3. Gasolina CORPORATIVO → CUERNAVACA (REQ-3G0YM)
        // ──────────────────────────────────────────────
        $this->info('');
        $this->info('═══ GASOLINA CORPORATIVO → CUERNAVACA ═══');

        $gasolinaCorp = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->join('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', [5, 6, 7, 8, 14])
            ->where('ds.code', 'gastos_erp')
            ->where('e.category', 'Gasolina')
            ->where('e.concept', 'GASOLINA OPERATIVA SUCURSALES')
            ->where('b.name', 'LIKE', '%CORPORATIVO%')
            ->select('e.id', 'e.observations', DB::raw('COALESCE(NULLIF(e.paid_amount,0),e.amount) as total'))
            ->get();

        foreach ($gasolinaCorp as $row) {
            // Check if this is the misassigned CUERNAVACA folio (contains 3G0YM or 3GOYM)
            $isCuernavacaFolio = preg_match('/REQ-3G[0O]YM/i', $row->observations ?? '');
            if ($isCuernavacaFolio) {
                $branchId = $branchIdByNorm['CUERNAVACA'] ?? null;
                $this->line("  ✓ REQ-3G0YM (\${$row->total}) → CUERNAVACA");
                if (!$dry && $branchId) {
                    DB::table('fact_expenses')->where('id', $row->id)->update(['branch_id' => $branchId]);
                }
            } else {
                $this->line("  (Mantenido en CORPORATIVO): {$row->observations}");
            }
        }

        if ($gasolinaCorp->isEmpty()) {
            $this->line('  No se encontraron entradas de gasolina CORPORATIVO.');
        }

        // ──────────────────────────────────────────────
        // Summary
        // ──────────────────────────────────────────────
        $this->info('');
        $this->info('═══ RESUMEN ═══');
        $this->line("  PAGO FINANCIAMIENTO MOTO fijados: {$fixed}/{$filas->count()}");
        $this->line("  COMPRA DE CASCOS fijados: {$fixedC}/{$cascos->count()}");
        if ($dry) {
            $this->warn('  (Dry-run: no se aplicaron cambios)');
        } else {
            $this->info('  ✓ Cambios aplicados. Regenera la radiografía con --force-reset.');
        }

        return self::SUCCESS;
    }

    private function normalize(string $name): string
    {
        $name = mb_strtoupper(trim($name));
        $from = ['Á','É','Í','Ó','Ú','Ñ','Ü','á','é','í','ó','ú','ñ','ü'];
        $to   = ['A','E','I','O','U','N','U','A','E','I','O','U','N','U'];
        $name = str_replace($from, $to, $name);
        return preg_replace('/\s+/', ' ', $name);
    }

    private function parseNameFromObs(string $obs): string
    {
        // Remove 'PAGO DE FINANCIAMIENTO MOTO' prefix
        $name = preg_replace('/^PAGO\s+DE\s+FINANCIAMIENTO\s+MOTO\s*/i', '', $obs);
        // Split on | and take first part (for "VACANTE | JOSE ALBERTO...")
        $parts = explode('|', $name);
        $name  = trim($parts[0]);
        // Remove trailing dollar amounts like "$1,644.50"
        $name = preg_replace('/\s*\$[\d,\.]+\s*$/', '', $name);
        return $this->normalize($name);
    }

    private function findBranch(string $normalizedName, array $nameMap): ?string
    {
        // Exact match
        if (isset($nameMap[$normalizedName])) {
            return $nameMap[$normalizedName];
        }

        // Partial match: check if the parsed name is contained in any key, or vice versa
        foreach ($nameMap as $key => $branch) {
            // Key contains parsed name (obs name is a truncation)
            if (str_contains($key, $normalizedName) && strlen($normalizedName) >= 8) {
                return $branch;
            }
            // Parsed name contains key (obs has extra words)
            if (str_contains($normalizedName, $key) && strlen($key) >= 8) {
                return $branch;
            }
        }

        // Fuzzy: first two words match
        $words = explode(' ', $normalizedName);
        if (count($words) >= 2) {
            $prefix = implode(' ', array_slice($words, 0, 2));
            foreach ($nameMap as $key => $branch) {
                if (str_starts_with($key, $prefix)) return $branch;
            }
        }

        return null;
    }
}
