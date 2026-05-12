<?php

namespace App\Support;

/**
 * Maps an expense concept string to a normalized category name.
 * Ordered most-specific → least-specific; first match wins.
 */
class ExpenseCategoryMapper
{
    private const MAP = [
        // Intersucursal / Fondeo — must come first (most specific)
        'PRESTAMO INTERSUCURSAL'  => 'Préstamos Intersucursales',
        'PRESTAMO SUCURSAL'       => 'Préstamos Intersucursales',
        'INTERSUCURSAL'           => 'Préstamos Intersucursales',
        'FONDEO'                  => 'Préstamos Intersucursales',
        'FONDEA'                  => 'Préstamos Intersucursales',

        // IMSS
        'IMSS'                    => 'IMSS',

        // Financiamiento — specific phrases before generic "MOTO"/"CELULAR"
        'FINANCIAMIENTO MOTO'     => 'Financiamiento de Motos',
        'FIN MOTO'                => 'Financiamiento de Motos',
        'FINANCIAMIENTO DE MOTO'  => 'Financiamiento de Motos',
        'PAGO MOTO'               => 'Financiamiento de Motos',
        'FINANCIAMIENTO CELULAR'  => 'Financiamiento Celular',
        'PAGO CELULAR'            => 'Financiamiento Celular',
        'CELULAR'                 => 'Financiamiento Celular',

        // Recargas telefónicas
        'RECARGA'                 => 'Recargas Telefónicas',

        // Teléfono e Internet
        'INTERNET'                => 'Teléfono e Internet',
        'TELEFONO'                => 'Teléfono e Internet',
        'TELEFON'                 => 'Teléfono e Internet',
        'DATOS MOVILES'           => 'Teléfono e Internet',

        // Software / Licencias
        'SOFTWARE'                => 'Software Póliza Anual',
        'LICENCIA'                => 'Software Póliza Anual',

        // Pólizas / Seguros
        'POLIZA'                  => 'Pólizas',
        'SEGURO'                  => 'Pólizas',

        // Cascos
        'CASCO'                   => 'Cascos',

        // Papelería / Insumos
        'PAPELERIA'               => 'Insumos de Papelería',
        'UTILES DE OFICINA'       => 'Insumos de Papelería',
        'INSUMOS DE PAPELERIA'    => 'Insumos de Papelería',
        'CAFETERIA'               => 'Insumos de Cafetería',
        'INSUMOS DE CAFETERIA'    => 'Insumos de Cafetería',

        // Limpieza (genérico → Servicios de Limpieza)
        'INSUMOS DE LIMPIEZA'     => 'Insumos de Limpieza',
        'LIMPIEZA'                => 'Servicios de Limpieza',

        // Mantenimiento / Fumigación
        'FUMIGACION'              => 'Mantenimiento',
        'MANTENIMIENTO'           => 'Mantenimiento',
        'MTTO'                    => 'Mantenimiento',

        // Renta
        'RENTA BODEGA'            => 'Renta de Bodegas',
        'RENTA OFICINA'           => 'Renta Oficina',
        'ARRENDAMIENTO'           => 'Renta Oficina',
        'RENTA'                   => 'Renta Oficina',

        // Servicios básicos
        'ELECTRICIDAD'            => 'Luz',
        'CFE'                     => 'Luz',
        'LUZ'                     => 'Luz',
        'AGUA'                    => 'Agua',

        // Mobiliario y equipo
        'MOBILIARIO'              => 'Mobiliario y Equipo',
        'EQUIPO DE COMPUTO'       => 'Mobiliario y Equipo',
        'COMPUTADORA'             => 'Mobiliario y Equipo',
        'LAPTOP'                  => 'Mobiliario y Equipo',
        'PROYECTOR'               => 'Mobiliario y Equipo',

        // Gasolina (categoría propia, más específico que TRANSPORTE)
        'GASOLINA'                => 'Gasolina',
        'COMBUSTIBLE'             => 'Gasolina',

        // Transportes
        'TAXI'                    => 'Transportes',
        'TRANSPORTE'              => 'Transportes',
        'VIATICO'                 => 'Viáticos',
        'FLETE'                   => 'Fletes',

        // Publicidad / Mercadeo
        'PUBLICIDAD'              => 'Publicidad',
        'MARKETING'               => 'Publicidad',
        'MERCADEO'                => 'Publicidad',
        'PEGOTE'                  => 'Pegotes',

        // Motocicletas / Mecánicos
        'MOTOCICLETA'             => 'Servicios de Motocicletas',
        'SERVICIO DE MOTO'        => 'Servicios de Motocicletas',
        'MOTO'                    => 'Servicios de Motocicletas',
        'MECANICO'                => 'Mecánicos',
        'MECANICA'                => 'Mecánicos',

        // Trámites / Permisos
        'PERMISO VEHICULAR'       => 'Permisos Vehiculares',
        'TRAMITE'                 => 'Trámites Gubernamentales',
        'GOBIERNO'                => 'Trámites Gubernamentales',
        'ACTA'                    => 'Trámites Gubernamentales',

        // Multas
        'MULTA'                   => 'Multas e Infracciones',
        'INFRACCION'              => 'Multas e Infracciones',

        // Paquetería / Envíos
        'PAQUETERIA'              => 'Paquetería',
        'ENVIO'                   => 'Paquetería',

        // Eventos
        'EVENTO'                  => 'Eventos',

        // Señora limpieza
        'SENORA LIMPIEZA'         => 'Señora Limpieza',
        'SENORA'                  => 'Señora Limpieza',

        // Oxxo
        'OXXO'                    => 'Comisiones Oxxo',

        // Gastos médicos
        'GASTOS MEDICOS'          => 'Gastos médicos',
        'MEDICO'                  => 'Gastos médicos',

        // Finiquito
        'FINIQUITO'               => 'Finiquito',

        // Formatería
        'FORMATER'                => 'Formatería',

        // Legal
        'GASTOS LEGALES'          => 'Gastos legales',
        'LEGAL'                   => 'Gastos legales',
        'NOTARIO'                 => 'Gastos legales',
        'HONORARIO'               => 'Gastos legales',
    ];

    /**
     * Map a single concept string to a category.
     */
    public static function fromConcept(?string $concept): string
    {
        return self::fromFields($concept);
    }

    /**
     * Map combined text fields (concept, category, observations…) to a category.
     * First match wins. Falls back to 'Emergentes'.
     */
    public static function fromFields(?string ...$parts): string
    {
        $text = implode(' ', array_filter(array_map(fn($p) => $p !== null ? trim($p) : null, $parts)));

        if ($text === '') {
            return 'Emergentes';
        }

        $upper = strtr(mb_strtoupper($text), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        foreach (self::MAP as $keyword => $category) {
            if (str_contains($upper, $keyword)) {
                return $category;
            }
        }

        return 'Emergentes';
    }
}
