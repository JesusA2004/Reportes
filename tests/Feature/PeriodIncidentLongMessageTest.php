<?php

use App\Models\Period;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * PROBLEMA 1 (auditoría 27-ago-2026): period_incidents.message se creó como
 * VARCHAR(255) NOT NULL (2026_04_28_100000_create_period_radiography_tables.php).
 * Con la conexión mariadb/mysql en modo strict=true, un mensaje > 255 caracteres
 * lanzaba SQLSTATE[22001] "Data too long for column 'message'" — origen real:
 * NoiNominaImportService arma mensajes de ~200-205 chars + nombre de empleado sin
 * límite. Fix: migration 2026_08_27_000001_expand_period_incidents_message_column
 * cambia message a TEXT.
 *
 * NOTA DE COBERTURA: la suite de tests corre sobre sqlite (phpunit.xml,
 * DB_CONNECTION=sqlite), que no aplica un límite real de longitud por tipo de
 * columna (type affinity, no enforcement) — así que este test pasaría incluso SIN
 * el fix, sobre sqlite. Confirma el comportamiento correcto de la aplicación
 * (Eloquent/fillable/casts no truncan ni rechazan mensajes largos), pero la
 * garantía de que MySQL/MariaDB real ya no lanza 22001 depende de que la migration
 * se haya ejecutado ahí — validado manualmente contra la conexión mysql_testing en
 * tests/Integration (ver RadiographyMysqlPipelineTest.php para el patrón).
 */
it('persists a PeriodIncident with a message over 1000 characters without truncating or throwing', function () {
    $period = Period::query()->create([
        'name' => 'Periodo mensaje largo', 'code' => 'M-2026-09-LONGMSG', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => false,
    ]);
    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(),
    ]);

    // Plantilla real de NoiNominaImportService (~200 chars) + nombre de empleado
    // artificialmente largo, para superar 1000 caracteres totales igual que el caso
    // real que disparó el 1406.
    $longEmployeeName = str_repeat('MARIA DE LOS ANGELES GUADALUPE FERNANDEZ ', 20); // ~840 chars
    $message = "\"{$longEmployeeName}\" tiene pago en NOI pero no se encontró en el directorio Lendus, "
        . 'cartera/colocación/cobranza ni asignaciones históricas. Verificar identidad y sucursal antes de confiar en el reporte.';

    expect(mb_strlen($message))->toBeGreaterThan(1000);

    $incident = PeriodIncident::query()->create([
        'period_summary_id' => $summary->id,
        'type'     => 'noi_identidad.sin_respaldo',
        'severity' => 'warning',
        'message'  => $message,
        'context'  => ['normalized_name' => strtolower($longEmployeeName)],
    ]);

    $incident->refresh();
    expect($incident->message)->toBe($message);
    expect(mb_strlen($incident->message))->toBeGreaterThan(1000);
});

it('persists a PeriodIncident with a large structured context array', function () {
    $period = Period::query()->create([
        'name' => 'Periodo contexto grande', 'code' => 'M-2026-10-BIGCTX', 'type' => 'monthly',
        'year' => 2026, 'month' => 10, 'sequence' => 1,
        'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'is_closed' => false,
    ]);
    $summary = PeriodSummary::query()->create([
        'period_id' => $period->id, 'status' => 'generated', 'generated_at' => now(),
    ]);

    // Igual de forma que posibles_coincidencias_persona guarda hasta 10 "samples" —
    // aquí se fuerza un contexto bastante más grande para probar el límite.
    $samples = collect(range(1, 200))->map(fn ($i) => [
        'a' => "Empleado {$i} A", 'b' => "Empleado {$i} B",
        'a_id' => $i, 'b_id' => $i + 1000, 'score' => 80,
        'reason' => 'Comparten 3 de 4 tokens del nombre (80% similitud).',
    ])->all();

    $incident = PeriodIncident::query()->create([
        'period_summary_id' => $summary->id,
        'type'     => 'posibles_coincidencias_persona',
        'severity' => 'warning',
        'message'  => '200 posible(s) coincidencia(s) de persona detectada(s).',
        'context'  => ['count' => 200, 'samples' => $samples],
    ]);

    $incident->refresh();
    expect($incident->context)->toBeArray();
    expect($incident->context['samples'])->toHaveCount(200);
});
