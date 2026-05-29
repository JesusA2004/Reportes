<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * Single source of truth for authorized column definitions per data source.
 *
 * Each field definition:
 *   aliases     - normalized column names to match, in priority order
 *   required    - missing column creates a critical incident
 *   metric      - true: feeds calculations/reports; false: traceability/identity only
 *   internal    - true: used for branch resolution only, never shown as metric
 *   description - human-readable purpose
 */
class ColumnMapService
{
    /**
     * Returns field definitions for a given source code.
     *
     * @return array<string, array{aliases: string[], required: bool, metric: bool, internal?: bool, description: string}>
     */
    public function getFieldDefinitions(string $sourceCode): array
    {
        return match ($sourceCode) {
            'lendus_ministraciones'    => $this->ministracionesMap(),
            'lendus_ingresos_cobranza' => $this->ingresosCobranzaMap(),
            'lendus_saldos_cliente'    => $this->saldosClienteMap(),
            default                    => [],
        };
    }

    /**
     * Resolve a raw header row against the authorized map for a source.
     *
     * Returns:
     *   map               [field => colIndex]   — matched fields (metric + internal)
     *   ignored           [normalizedName => colIndex] — columns in file but not in map
     *   missing_required  [fieldName]           — required fields not found
     *   missing_optional  [fieldName]           — optional fields not found
     */
    public function resolveHeader(string $sourceCode, array $headerRow): array
    {
        $defs = $this->getFieldDefinitions($sourceCode);

        if (empty($defs)) {
            return ['map' => [], 'ignored' => [], 'missing_required' => [], 'missing_optional' => []];
        }

        $normalizedHeaders = [];
        foreach ($headerRow as $index => $value) {
            $normalized = $this->normalizeHeader((string) $value);
            if ($normalized !== '') {
                $normalizedHeaders[$index] = $normalized;
            }
        }

        $map            = [];
        $matchedIndices = [];

        foreach ($defs as $field => $def) {
            foreach ($def['aliases'] as $alias) {
                foreach ($normalizedHeaders as $index => $normalizedHeader) {
                    if ($normalizedHeader === $alias && !in_array($index, $matchedIndices, true)) {
                        $map[$field]      = $index;
                        $matchedIndices[] = $index;
                        break 2;
                    }
                }
            }
        }

        $ignored = [];
        foreach ($normalizedHeaders as $index => $normalizedHeader) {
            if (!in_array($index, $matchedIndices, true)) {
                $ignored[$normalizedHeader] = $index;
            }
        }

        $missingRequired = [];
        $missingOptional = [];
        foreach ($defs as $field => $def) {
            if (!array_key_exists($field, $map)) {
                if ($def['required'] ?? false) {
                    $missingRequired[] = $field;
                } else {
                    $missingOptional[] = $field;
                }
            }
        }

        return compact('map', 'ignored', 'missingRequired', 'missingOptional');
    }

    // ── A) COLOCACIÓN / MINISTRACIONES ──────────────────────────────────────

    private function ministracionesMap(): array
    {
        return [
            'promoter_name' => [
                'aliases'     => ['nombre_promotor', 'nombre_cobrador', 'nombre_gestor', 'nombre_asesor', 'nombre_empleado'],
                'required'    => true,
                'metric'      => true,
                'description' => 'Nombre del gestor/promotor operativo.',
            ],
            'product_name' => [
                // ONLY "Producto de crédito" — NEVER "Línea de crédito" ni "Financiamiento"
                'aliases'     => ['producto_de_credito', 'producto', 'tipo_credito', 'tipo_de_credito', 'producto_credito'],
                'required'    => true,
                'metric'      => true,
                'description' => 'Producto de crédito (CRECE, I30, S12, etc.). Col "Producto de crédito" únicamente.',
            ],
            'amount' => [
                // Col 53 "Monto desembolsado" — total del crédito (usado para DESEMBOLSO)
                'aliases'     => ['monto_desembolsado', 'monto_desembolso', 'monto_de_desembolso', 'importe_desembolso', 'monto_ministrado', 'capital_otorgado', 'capital_por_producto'],
                'required'    => true,
                'metric'      => true,
                'description' => 'Monto total del crédito (col "Monto desembolsado"). Para DESEMBOLSO es colocación real.',
            ],
            'amount_disbursed' => [
                // Col 54 "$ Desembolsado" — efectivo entregado al cliente (usado para REFINANCIAMIENTO)
                'aliases'     => ['desembolsado', 'importe_desembolsado', 'monto_dispersado', 'monto_entregado'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Efectivo realmente entregado al cliente (col "$ Desembolsado"). Colocación real en REFINANCIAMIENTO.',
            ],
            'credit_origin' => [
                // Col 31 "Origen crédito" — DESEMBOLSO, REFINANCIAMIENTO, REESTRUCTURA, etc.
                'aliases'     => ['origen_credito', 'origen_de_credito', 'tipo_origen_credito', 'tipo_credito_origen'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Origen del crédito: DESEMBOLSO, REFINANCIAMIENTO, REESTRUCTURACION, UNIFICACION. Determina qué monto usar.',
            ],
            'client_name' => [
                'aliases'     => ['cliente', 'nombre_del_cliente', 'nombre_cliente', 'acreditado', 'nombre_acreditado', 'socio'],
                'required'    => false,
                'metric'      => false,
                'description' => 'Nombre/identificador del cliente para trazabilidad.',
            ],
            // internal: used for branch resolution only
            'promoter_code' => [
                'aliases'     => ['promotor', 'cobrador', 'gestor', 'asesor', 'clave_promotor', 'codigo_promotor', 'promotor_cobrador', 'promotor_gestor'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Código interno del promotor. Solo para resolver sucursal.',
            ],
            'operation_date' => [
                'aliases'     => ['fecha_desembolso', 'fecha_de_desembolso', 'fecha', 'fecha_operacion', 'fecha_ministracion', 'fecha_apertura', 'fecha_creacion'],
                'required'    => false,
                'metric'      => false,
                'description' => 'Fecha del desembolso. Metadato para trazabilidad temporal.',
            ],
            // ── Needed for product normalization (CREDITO DIARIO → i40, SEMANAL → s12) ──
            'num_payments' => [
                'aliases'     => ['numero_de_pagos', 'no_pagos', 'num_pagos', 'numero_pagos', 'pagos', 'plazo_pagos', 'num_de_pagos'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Número de pagos del crédito. Permite normalizar CREDITO DIARIO → i20/i30/i40/i60.',
            ],
            'periodicity' => [
                'aliases'     => ['periodicidad', 'periodo_pago', 'tipo_periodicidad', 'frecuencia_pago', 'frecuencia'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Periodicidad del crédito (DIARIO, SEMANAL, MENSUAL). Usada junto a num_payments.',
            ],
            'term' => [
                'aliases'     => ['plazo', 'plazo_credito', 'duracion', 'duración'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Plazo/duración del crédito en pagos o días. Alternativa a num_payments.',
            ],
        ];
    }

    // ── E) INGRESOS COBRANZA / RECUPERACIÓN ─────────────────────────────────

    private function ingresosCobranzaMap(): array
    {
        return [
            'promoter_name' => [
                'aliases'     => ['promotor', 'nombre_promotor', 'asesor', 'ejecutivo', 'cobrador'],
                'required'    => true,
                'metric'      => true,
                'description' => 'Nombre/código del gestor o promotor.',
            ],
            'contract' => [
                'aliases'     => ['contrato', 'no_contrato', 'num_contrato', 'numero_contrato', 'folio', 'credito', 'no_credito', 'numero_de_contrato'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Número de crédito/contrato. Permite trazabilidad y resolución de sucursal por prefijo.',
            ],
            'product_name' => [
                'aliases'     => ['producto_de_credito', 'producto', 'tipo_credito', 'linea_de_credito', 'tipo_de_credito'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Producto de crédito. Requerido para regla Savehearts CRECE.',
            ],
            'operation' => [
                'aliases'     => ['operacion', 'tipo_operacion', 'tipo_de_operacion', 'op'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Tipo de operación (PAGO A CONTRATO, SEGUROS, etc.). Clave para detectar Savehearts.',
            ],
            'concept' => [
                'aliases'     => ['concepto', 'descripcion', 'detalle'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Concepto del movimiento (PRINCIPAL, MORATORIOS, COBERTURA SAVEHEARTS, etc.).',
            ],
            'transaction' => [
                'aliases'     => ['transaccion', 'tipo_transaccion', 'clase_transaccion'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Tipo de transacción (PAGO, DESCUENTO, etc.).',
            ],
            'capital' => [
                'aliases'     => ['capital', 'capital_pagado', 'abono_capital'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Capital cobrado en el movimiento.',
            ],
            'interest' => [
                'aliases'     => ['interes', 'intereses', 'interes_pagado'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Interés cobrado.',
            ],
            'tax' => [
                'aliases'     => ['impuesto', 'iva', 'tax'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Impuesto cobrado.',
            ],
            'charges' => [
                'aliases'     => ['cargos_calendario', 'cargos_de_calendario', 'cargo_calendario'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Cargos calendario cobrados.',
            ],
            'charges_due' => [
                'aliases'     => ['cargos_vencimiento', 'cargos_por_vencimiento', 'cargo_vencimiento', 'cargos_de_vencimiento'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Cargos por vencimiento cobrados.',
            ],
            'excedente' => [
                'aliases'     => ['excedente', 'excedentes', 'saldo_a_favor'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Excedente del pago.',
            ],
            'total' => [
                'aliases'     => ['total', 'importe', 'monto', 'importe_total', 'monto_total', 'ingreso', 'recuperacion'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Total del movimiento. No se usa a ciegas si hay desglose.',
            ],
            'days_past_due' => [
                'aliases'     => ['dias_vencidos_pago', 'dias_vencido_pago', 'dias_vencido'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Días vencidos del pago. Identifica ingresos de créditos vencidos.',
            ],
            // internal: used for branch resolution only
            'branch_name_raw' => [
                'aliases'     => ['oficina', 'ruta', 'sucursal', 'oficina_ruta', 'zona'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Oficina/ruta del archivo. Solo para resolver sucursal; no es métrica.',
            ],
            // metadata
            'client_name' => [
                'aliases'     => ['nombre_del_cliente', 'cliente', 'nombre_cliente', 'acreditado', 'nombre_acreditado'],
                'required'    => false,
                'metric'      => false,
                'description' => 'Nombre del cliente para trazabilidad.',
            ],
            'payment_date' => [
                'aliases'     => ['fecha_transaccion', 'fecha_pago', 'fecha_cuota', 'fecha'],
                'required'    => false,
                'metric'      => false,
                'description' => 'Fecha del movimiento. Metadato temporal.',
            ],
        ];
    }

    // ── B+C+D) LENDUS SALDOS (Moras + Préstamo activo + Valor cartera) ───────

    private function saldosClienteMap(): array
    {
        return [
            // identifiers
            'contract' => [
                'aliases'     => ['contrato', 'no_contrato', 'num_contrato', 'numero_contrato', 'numero_de_contrato', 'clave'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Número de contrato/crédito.',
            ],
            'promoter_name' => [
                'aliases'     => ['nombre_promotor', 'nombre_promotor2', 'nombre_cobrador', 'nombre_gestor', 'nombre_gestor_de_cobranza'],
                'required'    => true,
                'metric'      => true,
                'description' => 'Nombre del gestor/promotor.',
            ],
            'product_name' => [
                'aliases'     => ['producto_de_credito', 'producto', 'tipo_credito', 'nombre_producto'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Producto de crédito.',
            ],
            'days_past_due' => [
                'aliases'     => ['dias_vencido', 'dias_vencidos'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Días Vencido. Columna oficial para buckets de mora y cartera vigente/vencida. NO usar días mora ni días atraso.',
            ],
            // D) valor cartera
            'balance' => [
                'aliases'     => ['saldo_actual', 'saldo_insoluto', 'saldo_capital', 'saldo'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Saldo actual. Representa valor cartera total por contrato.',
            ],
            // C) préstamo activo — DISTINCT from balance/saldo_actual
            'capital_activo' => [
                'aliases'     => ['capital'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Capital del préstamo activo. Distinto de Saldo actual/valor cartera.',
            ],
            // B) mora components
            'capital_due' => [
                'aliases'     => ['capital_atrasado', 'capital_mora'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Capital atrasado. Componente de mora.',
            ],
            'interes_atrasado' => [
                'aliases'     => ['interes_atrasado', 'interes_vencido', 'intereses_atrasados', 'interes_mora'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Interés atrasado. Componente de mora.',
            ],
            'impuesto_atrasado' => [
                'aliases'     => ['impuesto_atrasado', 'iva_atrasado', 'impuesto_vencido', 'impuesto_mora'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Impuesto atrasado. Componente de mora.',
            ],
            'saldo_interes_moratorio' => [
                'aliases'     => ['saldo_interes_moratorio', 'interes_moratorio', 'saldo_moratorio_interes'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Saldo interés moratorio. Componente de mora.',
            ],
            'saldo_impuesto_interes_moratorio' => [
                'aliases'     => ['saldo_impuesto_interes_moratorio', 'impuesto_interes_moratorio', 'saldo_impuesto_moratorio'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Saldo impuesto interés moratorio. Componente de mora.',
            ],
            'cargos_calendario_atrasado' => [
                'aliases'     => ['cargos_calendario_atrasado', 'cargos_atrasados', 'cargo_calendario_atrasado', 'cargos_cal_atrasados'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Cargos calendario atrasados. Componente de mora.',
            ],
            'impuesto_cargos_calendario_atrasado' => [
                'aliases'     => ['impuesto_cargos_calendario_atrasado', 'iva_cargos_atrasados', 'impuesto_cargo_atrasado'],
                'required'    => false,
                'metric'      => true,
                'description' => 'Impuesto sobre cargos calendario atrasados. Componente de mora.',
            ],
            // internal
            'promoter_code' => [
                'aliases'     => ['promotor', 'gestor_de_cobranza', 'cobrador', 'clave_promotor', 'codigo_promotor'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Código de promotor. Solo para resolver sucursal.',
            ],
            'route_name' => [
                'aliases'     => ['oficina', 'ruta', 'ruta_u_oficina', 'sucursal', 'nombre_sucursal', 'zona', 'oficina_zona'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Ruta u oficina. Solo para resolver sucursal; no es métrica.',
            ],
            // metadata
            'client_name' => [
                'aliases'     => ['nombre_del_cliente', 'nombre_cliente', 'acreditado', 'nombre_acreditado', 'socio', 'nombre_completo'],
                'required'    => false,
                'metric'      => false,
                'description' => 'Nombre del cliente para trazabilidad.',
            ],
            // legacy "Cliente" column in some Lendus exports = contract code
            'client_code' => [
                'aliases'     => ['cliente', 'clave_cliente', 'codigo_cliente', 'cliente_id', 'id_cliente'],
                'required'    => false,
                'metric'      => false,
                'internal'    => true,
                'description' => 'Código de cliente en exportes legacy Lendus (equivale a contrato).',
            ],
            'portfolio_date' => [
                'aliases'     => ['fecha_corte', 'fecha', 'fecha_saldo', 'fecha_de_corte', 'fecha_desembolso', 'fecha_apertura'],
                'required'    => false,
                'metric'      => false,
                'description' => 'Fecha de corte del saldo.',
            ],
        ];
    }

    public function normalizeHeader(string $value): string
    {
        return (string) Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_');
    }
}
