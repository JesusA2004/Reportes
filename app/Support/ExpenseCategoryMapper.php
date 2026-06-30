<?php

namespace App\Support;

/**
 * Maps an expense concept string to a normalized category name.
 * Ordered most-specific → least-specific; first match wins.
 */
class ExpenseCategoryMapper
{
    private const MAP = [
        // Excedente enviado a corporativo — must come before Emergentes fallback
        'EXCEDENTE'               => 'Envío de utilidad a corporativo',

        // Intersucursal / Fondeo — must come first (most specific)
        'PRESTAMO INTERSUCURSAL'  => 'Préstamos Intersucursales',
        'PRESTAMO SUCURSAL'       => 'Préstamos Intersucursales',
        'INTERSUCURSAL'           => 'Préstamos Intersucursales',
        'FONDEO'                  => 'Préstamos Intersucursales',
        'FONDEA'                  => 'Préstamos Intersucursales',

        // Nómina y Capital Humano — before more generic terms
        'NOMINA'                  => 'Nómina y Capital Humano',
        'DEDUCCION'               => 'Nómina y Capital Humano',
        'IMSS'                    => 'Nómina y Capital Humano',

        // Financiamiento — specific phrases before generic "MOTO"/"CELULAR"
        'FINANCIAMIENTO MOTO'     => 'Nómina y Capital Humano',
        'FIN MOTO'                => 'Nómina y Capital Humano',
        'FINANCIAMIENTO DE MOTO'  => 'Nómina y Capital Humano',
        'PAGO MOTO'               => 'Nómina y Capital Humano',
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
        'CHAT GPT'                => 'Software Póliza Anual',
        'CHATGPT'                 => 'Software Póliza Anual',
        'ASPEL'                   => 'Software Póliza Anual',
        'SOFTWARE'                => 'Software Póliza Anual',
        'LICENCIA'                => 'Software Póliza Anual',

        // Pólizas / Seguros / Coberturas (incluye Savehearts, CRECE, Crédito Grupal)
        'POLIZA'                  => 'Pólizas',
        'SEGURO'                  => 'Pólizas',
        'COBERTURA'               => 'Pólizas',
        'SAVEHEARTS'              => 'Pólizas',

        // Cascos
        'CASCO'                   => 'Nómina y Capital Humano',

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
        'GARRAFON'                => 'Agua',
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

        // Transportes / Paquetería
        'DHL'                     => 'Paquetería',
        'FEDEX'                   => 'Paquetería',
        'GUIAS'                   => 'Paquetería',
        'TAXI'                    => 'Transportes',
        'TRANSPORTE'              => 'Transportes',
        'DILIGENCIAS'             => 'Viáticos',
        'VIATICO'                 => 'Viáticos',
        'FLETE'                   => 'Fletes',

        // Publicidad / Mercadeo
        'PUBLICIDAD'              => 'Publicidad',
        'MARKETING'               => 'Publicidad',
        'MERCADEO'                => 'Publicidad',
        'PEGOTE'                  => 'Pegotes',

        // Motocicletas / Mecánicos
        'TALLER REPARACION MOTO'  => 'Servicios de Motocicletas',
        'TALLER REPARACION DE MOTO' => 'Servicios de Motocicletas',
        'MOTOCICLETA'             => 'Servicios de Motocicletas',
        'SERVICIO DE MOTO'        => 'Servicios de Motocicletas',
        'MANO DE OBRA'            => 'Servicios de Motocicletas',
        'SPROCKET'                => 'Servicios de Motocicletas',
        'SERVICE MOTO'            => 'Servicios de Motocicletas',
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
        'MENSAJERIA'              => 'Paquetería',
        'ENVIO'                   => 'Paquetería',

        // Eventos
        'EVENTO'                  => 'Eventos',

        // Señora limpieza
        'SENORA LIMPIEZA'         => 'Señora Limpieza',
        'SENORA'                  => 'Señora Limpieza',

        // Oxxo
        'OXXO'                    => 'Comisiones Oxxo',

        // Gastos médicos — específicos de nómina → Nómina y Capital Humano
        'GASTOS MEDICOS'          => 'Nómina y Capital Humano',
        'MEDICO'                  => 'Gastos médicos',

        // Finiquito
        'FINIQUITO'               => 'Nómina y Capital Humano',

        // Préstamo Z / Zéptimo — anticipo de nómina, no es gasto operativo
        'PRESTAMO Z'              => 'Nómina y Capital Humano',
        'PRÉSTAMO Z'              => 'Nómina y Capital Humano',
        'PAGO PRESTAMO Z'         => 'Nómina y Capital Humano',

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
     * First match wins. Falls back to 'Gastos Operativos'.
     */
    public static function fromFields(?string ...$parts): string
    {
        $text = implode(' ', array_filter(array_map(fn($p) => $p !== null ? trim($p) : null, $parts)));

        if ($text === '') {
            return 'Gastos Operativos';
        }

        $upper = strtr(mb_strtoupper($text), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        foreach (self::MAP as $keyword => $category) {
            if (str_contains($upper, $keyword)) {
                return $category;
            }
        }

        return 'Gastos Operativos';
    }
}
