<?php

namespace App\Enums;

enum DataSourceCode: string {

    case NoiNomina = 'noi_nomina';
    case LendusMinistraciones = 'lendus_ministraciones';
    case LendusIngresosCobranza = 'lendus_ingresos_cobranza';
    case LendusSaldosCliente = 'lendus_saldos_cliente';
    case Gastos = 'gastos';
    case MacroAnalisis = 'macro_analisis';

}
