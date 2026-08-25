# RadiographyIntegration testsuite

Pruebas que necesitan MySQL/MariaDB real (no SQLite) porque
`RadiographySnapshotBuilder` / `BranchRadiographyCalculator` usan SQL propio de
MySQL (`REGEXP`, `JSON_EXTRACT`, `JSON_UNQUOTE`) que SQLite rechaza incluso sobre
tablas vacías.

## Configuración

1. Copia `.env.testing.mysql.example` a `.env.testing.mysql`.
2. Crea una base de datos MySQL/MariaDB vacía y DEDICADA para pruebas (nunca la de
   desarrollo/producción), por ejemplo:
   ```sql
   CREATE DATABASE reportes_test;
   ```
3. Ajusta `DB_TEST_HOST` / `DB_TEST_PORT` / `DB_TEST_DATABASE` / `DB_TEST_USERNAME` /
   `DB_TEST_PASSWORD` en tu `.env.testing.mysql`.
4. Migra esa base (una sola vez, o cuando cambien las migraciones):
   ```
   php artisan migrate --database=mysql_testing --path=database/migrations --force
   ```
5. Corre la suite:
   ```
   php artisan test --configuration=phpunit.integration.xml --testsuite=RadiographyIntegration
   ```

Si `.env.testing.mysql` no existe o la conexión no es alcanzable, los tests que la
necesitan se marcan `skipped` — no fallan la suite ni bloquean `php artisan test`
normal (que sigue usando SQLite sin cambios).

## Dos tipos de test en esta carpeta

- **Fixture sintética + BD aislada** (ej. `RadiographyMysqlPipelineTest`): usa
  `RefreshDatabase` sobre la conexión `mysql_testing` (`reportes_test`). Seguro:
  esa base está dedicada a esto y se puede recrear en cualquier momento.
- **Datos reales de desarrollo, solo lectura** (`ScopedDataRealRosterTest`,
  `WebExcelPdfDatasetConsistencyTest`): usan el trait `UsesRealDevDatabase`, que
  cambia la conexión activa a `mariadb` (la misma BD que usa la app fuera de
  tests — ver `DB_HOST`/`DB_DATABASE` en `.env.testing.mysql`). Estos archivos
  **jamás** usan `RefreshDatabase` ni escriben nada — solo hacen peticiones HTTP
  `GET` reales contra el roster/periodos que ya existen. Se saltan automáticamente
  si `DB_DATABASE` no está configurado.

## Por qué esto importa

`php artisan test` (SQLite) no puede ejercitar el pipeline financiero real
(`RadiographySnapshotBuilder::build()`, `applyEmployeeScope()`, etc.) — cualquier
test que lo intente revienta por sintaxis SQL antes de llegar a la lógica de
negocio. Esta suite es la única forma de probar el endpoint HTTP real
(`/reportes-mensuales/{period}/scoped-data`) de punta a punta.
