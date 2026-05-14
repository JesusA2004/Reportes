<?php

namespace App\Enums;

enum DataSourceCode: string {

    case NoiNomina = 'noi_nomina';
    case NoiNominaFiscal = 'noi_nomina_fiscal';
    case LendusMinistraciones = 'lendus_ministraciones';
    case LendusIngresosCobranza = 'lendus_ingresos_cobranza';
    case LendusSaldosCliente = 'lendus_saldos_cliente';
    case Gastos = 'gastos';
    case GastosLendus = 'gastos_lendus';
    case GastosErp = 'gastos_erp';
    case GastosLendusExcel = 'gastos_lendus_excel';
    case MacroAnalisis = 'macro_analisis';

}
