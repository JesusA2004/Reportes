<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PROBLEMA 1 (auditoría 27-ago-2026): period_incidents.message se creó como
 * VARCHAR(255) NOT NULL (ver 2026_04_28_100000_create_period_radiography_tables.php).
 * Con la conexión `mariadb`/`mysql` en modo strict=true (config/database.php), un
 * mensaje que exceda 255 caracteres NO se trunca silenciosamente — lanza
 * SQLSTATE[22001] "Data too long for column 'message'" y revienta el INSERT.
 *
 * Origen real detectado: app/Services/Imports/NoiNominaImportService.php arma
 * mensajes de plantilla fija (~200-205 chars) + nombre crudo de empleado sin
 * límite, y como PeriodRadiographyService::generate() corre dentro de un único
 * DB::transaction(), ese overflow revierte TODA la generación (summary, resúmenes
 * por sucursal/corporativo, incidencias), no solo el incidente individual.
 *
 * doctrine/dbal NO está instalado en este proyecto (ver composer.lock), así que
 * Schema::table(...)->change() no está disponible — se usa DB::statement() con SQL
 * compatible con MySQL/MariaDB (driver real de la conexión `mariadb`, ver
 * config/database.php:80 y .env DB_CONNECTION).
 *
 * `context` ya es JSON nullable desde su creación — no tiene el mismo riesgo
 * (JSON no tiene límite de 255) y no se modifica aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        // sqlite (usado por la suite de tests, ver phpunit.xml DB_CONNECTION=sqlite)
        // no tiene un límite real de longitud por tipo de columna (type affinity, no
        // enforcement) — el ALTER TABLE...MODIFY de MySQL tampoco es sintaxis válida
        // ahí. En VPS/producción la conexión real es mariadb/mysql (ver
        // config/database.php), donde SÍ aplica y es necesario.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE `period_incidents` MODIFY `message` TEXT NOT NULL');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Restaurar a VARCHAR(255) solo es seguro si ya no hay filas con message > 255
        // caracteres (si las hay, MySQL truncará silenciosamente en modo no estricto o
        // fallará en modo estricto). No se trunca automáticamente aquí: revisar datos
        // antes de hacer rollback en un entorno con incidencias reales.
        DB::statement('ALTER TABLE `period_incidents` MODIFY `message` VARCHAR(255) NOT NULL');
    }
};
