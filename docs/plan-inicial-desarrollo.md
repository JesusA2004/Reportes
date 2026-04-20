# Plan inicial de desarrollo (backend + frontend)

## Decisión: ¿Con qué empezamos?

Para arrancar **ya**, la mejor secuencia es:

1. **Backend primero (núcleo de periodos + procesos)**
2. **Frontend en paralelo desde la semana 2**, usando endpoints/contratos ya definidos

Razón: todo el flujo depende de `periods`, `report_uploads`, `process_runs`, `employee_branch_assignments` y tablas fact. Si eso no está sólido, el front solo maquilla estados incompletos.

---

## Fase 1 (prioridad máxima): backend base operativa

### 1) Motor de periodos (semanal y otros tipos)

Objetivo: dejar de depender de `month` como unidad central.

Entregables:

- Servicio para generar periodos `weekly` con regla:
  - Semana 1: día 1 → primer domingo
  - Siguientes: lunes → domingo
  - Última: tramo restante del mes
- Soporte de tipos: `weekly`, `bimonthly`, `quarterly`, `semiannual`, `annual`
- Convención de `name`, `code`, `type`, `year`, `month`, `sequence`, `start_date`, `end_date`, `is_closed`
- Validaciones de unicidad por `type + year + month + sequence`

### 2) Carga y procesamiento por fuente

Objetivo: que subir archivo sí dispare proceso real y trazabilidad.

Entregables:

- Pipeline por fuente (NOI, Gastos, Lendus Ingresos, Ministraciones, Saldos)
- Registro de corrida en `process_runs` con:
  - estado
  - filas leídas/insertadas/fallidas
  - errores relevantes
- Normalización mínima común (nombres, fechas, montos)

### 3) Generador de asignación empleado ↔ sucursal

Objetivo: poblar `employee_branch_assignments` automáticamente.

Entregables:

- Proceso de matching con `match_type`, `confidence`, `source_type`, `source_reference`
- Detección de no asignados
- Marca de revisión manual (`was_manual_reviewed`)

### 4) Capa de validaciones derivadas (sin tabla propia)

Objetivo: exponer incidencias reales sin crear aún `validations`.

Entregables:

- Incidencias por:
  - fuentes faltantes por periodo
  - uploads fallidos
  - asignaciones pendientes
  - inconsistencias de cruce

### 5) Consolidador de reporte final por periodo

Objetivo: salida única por periodo.

Entregables:

- Consolidación por empleado/sucursal
- Endpoint de consulta para UI
- Base para exportación (CSV/XLSX en fase posterior)

---

## Fase 2: frontend operativo (sobre backend estable)

### Pantallas a cerrar en este orden

1. **Histórico general** (carga, estados, eliminar, completar periodo)
2. **Periodos** (crear, listar, cerrar/reabrir, progreso)
3. **Asignación sucursal** (pendientes + edición manual)
4. **Validaciones** (vista derivada)
5. **Reporte final por periodo**

### Criterios UX ya acordados

- Flujo visual por tarjetas/paneles
- Acciones claras por módulo
- Estados y confirmaciones con SweetAlert (loading/success/error)
- Poco ruido y foco en operación

---

## Primer sprint sugerido (inmediato, 5-7 días)

### Backend

- Implementar generador semanal completo
- Ajustar creación de periodos para `type` + `sequence`
- Exponer endpoint para listar periodos por tipo/año/mes

### Frontend

- Adaptar módulo de Periodos para seleccionar `type`
- Mostrar bloques semanales reales (4/5/6 según calendario)

### Definición de listo (DoD)

- Se puede generar abril 2026 semanal correctamente
- Cada bloque semanal queda persistido con fechas exactas
- UI lista los bloques y permite seleccionar uno para carga

---

## Riesgos actuales a atacar temprano

- Duplicidad de migraciones (dejar una sola por tabla)
- Controladores asumiendo campos inexistentes (`match_status`)
- Dependencias a lógica mensual antigua
- Procesos de import sin bitácora completa

---

## Recomendación final

**Empezamos por backend**, específicamente por el **generador de periodos semanales**.

Es la base de todo: carga, validaciones, asignación y reporte final dependen de que el periodo esté bien definido.
