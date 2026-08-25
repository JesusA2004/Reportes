<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;

/**
 * Para tests que validan el pipeline REAL contra datos históricos existentes
 * (Margarita/José/roster automático/sucursales) — SOLO LECTURA, JAMÁS
 * RefreshDatabase, JAMÁS un write. Cambia la conexión por defecto a 'mariadb' (la
 * misma base de datos de desarrollo que usa la app fuera de tests — ver
 * .env.testing.mysql: DB_HOST/DB_DATABASE/etc.), y se salta el test (no lo falla) si
 * esa conexión no está configurada o no es alcanzable — así el testsuite completo
 * sigue siendo ejecutable sin credenciales (ver PARTE 17 del pendiente: "si el MySQL
 * de testing te bloquea, continúa con todo lo demás").
 */
trait UsesRealDevDatabase
{
    protected function useRealDevDatabaseOrSkip(): void
    {
        $database = config('database.connections.mariadb.database');

        if (empty($database)) {
            $this->markTestSkipped(
                'DB_DATABASE no está configurado en .env.testing.mysql — no hay conexión de solo lectura al dev real. '
                . 'Copia .env.testing.mysql.example y define DB_HOST/DB_DATABASE/DB_USERNAME/DB_PASSWORD.'
            );
        }

        config(['database.default' => 'mariadb']);

        try {
            DB::connection('mariadb')->getPdo();
        } catch (\Throwable $e) {
            $this->markTestSkipped('No se pudo conectar a la BD de dev real (mariadb): ' . $e->getMessage());
        }
    }
}
