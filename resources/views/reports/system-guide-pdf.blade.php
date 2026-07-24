<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Guía del sistema — Radiografía Financiera</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #1e293b; }
@page { margin: 20mm 16mm 22mm 16mm; }

.cover { text-align: center; padding-top: 90px; page-break-after: always; }
.cover .mark { font-size: 26pt; font-weight: bold; color: #106A59; letter-spacing: 1px; }
.cover .sub { font-size: 14pt; color: #334155; margin-top: 10px; text-transform: uppercase; letter-spacing: 2px; }
.cover .desc { font-size: 10pt; color: #64748b; margin-top: 30px; max-width: 380px; margin-left: auto; margin-right: auto; line-height: 1.6; }
.cover .date { margin-top: 60px; font-size: 8.5pt; color: #94a3b8; }

.toc { page-break-after: always; }
.toc h1 { font-size: 15pt; color: #106A59; margin-bottom: 16px; }
.toc ol { margin-left: 18px; font-size: 9.5pt; line-height: 2; color: #334155; }

h1.section-title { font-size: 12.5pt; color: #fff; background: #106A59; padding: 7px 12px; margin-top: 16px; margin-bottom: 9px; border-radius: 4px; }
h2.sub-title { font-size: 10pt; color: #106A59; margin-top: 10px; margin-bottom: 5px; font-weight: bold; }
p { line-height: 1.6; margin-bottom: 6px; }
ul, ol.plain { margin-left: 16px; margin-bottom: 8px; }
li { line-height: 1.6; margin-bottom: 3px; }
ol.steps { margin-left: 0; list-style: none; counter-reset: stepnum; margin-bottom: 8px; }
ol.steps li { counter-increment: stepnum; margin-bottom: 5px; padding-left: 22px; position: relative; line-height: 1.5; }
ol.steps li:before { content: counter(stepnum); position: absolute; left: 0; top: 0; width: 15px; height: 15px; background: #4f46e5; color: #fff; border-radius: 50%; font-size: 7pt; font-weight: bold; text-align: center; line-height: 15px; }

.card { border: 0.5pt solid #e2e8f0; border-radius: 6px; padding: 8px 10px; margin-bottom: 6px; background: #f8fafc; }
.card b { color: #0f172a; }

.mockup { border: 0.75pt solid #cbd5e1; border-radius: 6px; background: #f1f5f9; padding: 10px; margin: 8px 0 10px; }
.mockup .bar { font-size: 7.5pt; color: #94a3b8; font-weight: bold; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.mockup .chip { display: inline-block; background: #4f46e5; color: #fff; border-radius: 4px; padding: 2px 7px; font-size: 7.5pt; font-weight: bold; margin-right: 4px; }
.mockup .chip.alt { background: #fff; color: #64748b; border: 0.5pt solid #cbd5e1; }

table.glossary { width: 100%; border-collapse: collapse; margin-top: 6px; }
table.glossary td { border-bottom: 0.5pt solid #e2e8f0; padding: 5px 6px; vertical-align: top; font-size: 8.5pt; }
table.glossary td.term { font-weight: bold; width: 32%; color: #0f172a; }

table.cmp { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 8pt; }
table.cmp th { background: #1f2937; color: #fff; padding: 5px 6px; text-align: left; }
table.cmp td { padding: 5px 6px; border-bottom: 0.5pt solid #e2e8f0; }
table.cmp .r { text-align: right; }
.pos { color: #15803d; font-weight: bold; }

.faq-q { font-weight: bold; color: #0f172a; margin-top: 8px; }
.faq-a { color: #475569; margin-top: 2px; }

.tip { background: #ecfdf5; border-left: 3px solid #10b981; padding: 6px 10px; margin-bottom: 5px; font-size: 8.5pt; color: #065f46; }
.warn { background: #fffbeb; border-left: 3px solid #f59e0b; padding: 6px 10px; margin-bottom: 5px; font-size: 8.5pt; color: #92400e; }
.section-break { page-break-before: always; }
</style>
</head>
<body>

<!-- PORTADA -->
<div class="cover">
    <div class="mark">MR LANA</div>
    <div class="sub">Guía del Sistema</div>
    <p class="desc">
        Manual paso a paso de la Radiografía Financiera: cómo crear un periodo, cargar archivos,
        generar el reporte y consultarlo — con la explicación de cada botón y cada indicador.
    </p>
    <div class="date">Generado: {{ now('America/Mexico_City')->format('d/m/Y H:i') }}</div>
</div>

<!-- ÍNDICE -->
<div class="toc">
    <h1>Índice</h1>
    <ol>
        <li>Bienvenida</li>
        <li>Flujo general del sistema</li>
        <li>Alta de periodo</li>
        <li>Carga de archivos</li>
        <li>Carga de registros</li>
        <li>Revisión de incidencias</li>
        <li>Configurar reporte</li>
        <li>Generar reporte</li>
        <li>Vista previa</li>
        <li>Exportar Excel y PDF</li>
        <li>Reportes generados</li>
        <li>Reportes mensuales</li>
        <li>Reportes bimestrales y trimestrales</li>
        <li>Comparativos</li>
        <li>Reporte por sucursal</li>
        <li>Reporte por empleado / gestor</li>
        <li>Interpretación de KPIs</li>
        <li>Errores comunes y qué hacer</li>
        <li>Buenas prácticas de cierre mensual</li>
        <li>Glosario de indicadores</li>
        <li>Preguntas frecuentes</li>
    </ol>
</div>

<!-- 1 -->
<h1 class="section-title">1. Bienvenida</h1>
<p>Este sistema arma, mes con mes, el reporte financiero completo del negocio: cuánto se recuperó de
cartera, cuánto se colocó en créditos nuevos, cuánto se gastó, cuánto se pagó de nómina, y cuál fue
la utilidad del negocio — en general y por cada sucursal.</p>
<p>Esta guía acompaña paso a paso: crear el periodo, cargar los archivos, revisar que todo esté
correcto, generar el reporte y consultarlo cuando se necesite.</p>

<!-- 2 -->
<h1 class="section-title">2. Flujo general del sistema</h1>
<div class="card"><b>1. Crear periodo</b> — se da de alta el mes, bimestre o trimestre que se va a trabajar.</div>
<div class="card"><b>2. Cargar archivos</b> — se suben los archivos fuente de ese periodo.</div>
<div class="card"><b>3. Configurar y generar</b> — se elige tipo y alcance, y se genera el reporte.</div>
<div class="card"><b>4. Revisar y descargar</b> — se consulta la vista previa y se descarga Excel/PDF.</div>
<p>Una vez generado, el reporte queda guardado — se puede consultar y descargar cuantas veces se
quiera desde Reportes mensuales, sin volver a generarlo.</p>

<!-- 3 -->
<h1 class="section-title section-break">3. Alta de periodo</h1>
<p>Primero se crea el periodo operativo que se va a trabajar. Este periodo define las fechas y
semanas que tomará en cuenta la radiografía.</p>
<ol class="steps">
    <li>Entrar al módulo Periodos.</li>
    <li>Presionar el botón para crear un nuevo periodo.</li>
    <li>Seleccionar el tipo de periodo: mensual, bimestral o trimestral.</li>
    <li>Elegir el mes (o el bimestre/trimestre correspondiente).</li>
    <li>Revisar las fechas de inicio y fin que se muestran.</li>
    <li>Confirmar que las semanas que abarca sean las correctas.</li>
    <li>Guardar el periodo.</li>
    <li>Verificar que el periodo aparezca en la lista como creado.</li>
</ol>
<div class="mockup">
    <div class="bar">Periodos</div>
    <span class="chip">+ Crear periodo</span>
    <span class="chip alt">Mensual</span>
    <span class="chip alt">Bimestral</span>
    <span class="chip alt">Trimestral</span>
</div>

<!-- 4 -->
<h1 class="section-title">4. Carga de archivos</h1>
<p>Con el periodo mensual creado, se cargan los archivos fuente de ese mes: nómina, cobranza,
colocación, cartera y gastos. El sistema muestra una tarjeta por cada archivo que se necesita para
ese periodo.</p>
<ol class="steps">
    <li>Entrar al periodo mensual que se va a trabajar.</li>
    <li>Revisar la lista de archivos que pide el sistema.</li>
    <li>Seleccionar o arrastrar el archivo correspondiente a cada tarjeta.</li>
    <li>Verificar que el archivo quede marcado como cargado.</li>
    <li>Si se subió el archivo incorrecto, usar Reemplazar archivo.</li>
    <li>Si se subió un archivo por error, usar Eliminar archivo.</li>
</ol>
<table class="glossary">
    <tr><td class="term">Seleccionar archivo</td><td>Abre el explorador de archivos para elegir el documento.</td></tr>
    <tr><td class="term">Arrastrar archivo</td><td>Suelta el archivo directamente sobre la tarjeta.</td></tr>
    <tr><td class="term">Reemplazar archivo</td><td>Sustituye el archivo ya cargado por uno nuevo.</td></tr>
    <tr><td class="term">Eliminar archivo</td><td>Borra el archivo cargado (pide confirmación).</td></tr>
</table>

<!-- 5 -->
<h1 class="section-title section-break">5. Carga de registros</h1>
<p>Después de cargar los archivos, el sistema lee su información y la prepara para el reporte. Se
puede cerrar la ventana mientras procesa; al terminar se muestra si todo salió correcto o si hay
algo que revisar.</p>
<ol class="steps">
    <li>Con los archivos cargados, presionar Cargar registros.</li>
    <li>Esperar a que el estado cambie de Procesando a Completado.</li>
    <li>Si algo falla, usar Reintentar carga.</li>
    <li>Usar Refrescar para confirmar el estado más reciente.</li>
</ol>

<!-- 6 -->
<h1 class="section-title">6. Revisión de incidencias</h1>
<p>Una incidencia es una advertencia: el sistema detectó algo que no pudo resolver solo, por ejemplo
una persona sin sucursal asignada o un gasto sin identificar. No todas las incidencias bloquean el
reporte, pero conviene revisarlas antes de generar.</p>
<ol class="steps">
    <li>Abrir el panel de incidencias del periodo.</li>
    <li>Si hay personas sin sucursal, asignarlas desde el mismo panel.</li>
    <li>Si hay gastos sin asignar, indicar a qué sucursal o colaborador corresponden.</li>
    <li>Usar Ver detalle para entender exactamente qué encontró el sistema.</li>
    <li>Marcar la incidencia como resuelta al corregirla.</li>
    <li>Usar Refrescar para confirmar que ya no aparece.</li>
</ol>

<!-- 7 -->
<h1 class="section-title section-break">7. Configurar reporte</h1>
<h2 class="sub-title">Tipos de reporte</h2>
<ul>
    <li><b>Radiografía simple</b>: para ver un periodo individual.</li>
    <li><b>Comparativo mes vs mes</b>: para comparar un mes contra otro.</li>
    <li><b>Comparativo bimestre vs bimestre</b>: compara dos bimestres.</li>
    <li><b>Comparativo trimestre vs trimestre</b>: compara dos trimestres.</li>
</ul>
<h2 class="sub-title">Alcances</h2>
<ul>
    <li><b>General</b>: todas las sucursales juntas.</li>
    <li><b>Por sucursal</b>: para revisar una sucursal específica.</li>
    <li><b>Por empleado / gestor</b>: para revisar desempeño individual.</li>
</ul>

<!-- 8 -->
<h1 class="section-title">8. Generar reporte</h1>
<ol class="steps">
    <li>Revisar que la configuración elegida sea la correcta.</li>
    <li>Presionar Generar reporte.</li>
    <li>Esperar la confirmación — se puede cerrar la ventana, se avisa por correo.</li>
    <li>Al terminar, se habilitan Vista previa, Excel y PDF.</li>
</ol>
<p>Mientras se genera, el reporte pasa por los estados <b>En cola</b> → <b>Procesando</b> →
<b>Generado</b>. Si algo realmente falla, se muestra <b>Error</b> con el motivo. Si el reporte
terminó bien, siempre se muestra "Generado".</p>

<!-- 9 -->
<h1 class="section-title section-break">9. Vista previa</h1>
<p>La vista previa muestra el reporte completo dentro del sistema, organizado en pestañas: resumen
general, sucursales, ingresos, gastos, nómina, cartera y mora, rotación de personal y EBITDA. Cada
pestaña tiene sus propios filtros para revisar el detalle.</p>

<!-- 10 -->
<h1 class="section-title">10. Exportar Excel y PDF</h1>
<table class="glossary">
    <tr><td class="term">Excel</td><td>Ideal para analizar el detalle, hacer filtros propios o revisar hoja por hoja.</td></tr>
    <tr><td class="term">PDF</td><td>Ideal para compartir o imprimir un resumen ejecutivo ya formateado.</td></tr>
</table>

<!-- 11 -->
<h1 class="section-title">11. Reportes generados</h1>
<p>Lista todos los reportes ya generados, con buscador y filtros por estado. Este módulo es solo
para consultar reportes existentes: Ver (vista previa), Excel y PDF.</p>

<!-- 12 -->
<h1 class="section-title section-break">12. Reportes mensuales</h1>
<p>Un reporte mensual es la radiografía de un solo mes. Es el punto de partida: los bimestres y
trimestres se arman a partir de los meses ya generados.</p>

<!-- 13 -->
<h1 class="section-title">13. Reportes bimestrales y trimestrales</h1>
<p>Un bimestre o trimestre se arma automáticamente con sus meses operativos. Antes de poder
generarlo, deben existir los reportes mensuales de todos los meses que lo componen — si falta
alguno, el sistema indica claramente cuál falta. El reporte resultante muestra el rango completo
del periodo, incluyendo qué meses abarca:</p>
<div class="mockup">
    Trimestre 2 - 2026<br>
    Abril 2026 + Mayo 2026 + Junio 2026<br>
    Rango: 2026-03-30 → 2026-06-21
</div>

<!-- 14 -->
<h1 class="section-title">14. Comparativos</h1>
<p>Un comparativo pone lado a lado dos periodos del mismo tipo (mes vs mes, bimestre vs bimestre o
trimestre vs trimestre) para ver qué tanto creció o bajó cada indicador.</p>
<table class="cmp">
    <tr><th>Métrica</th><th class="r">Mayo 2026</th><th class="r">Junio 2026</th><th class="r">Diferencia</th><th class="r">Variación %</th></tr>
    <tr><td>Recuperación</td><td class="r">$17,697,872</td><td class="r">$17,888,527</td><td class="r pos">+$190,655</td><td class="r pos">+1.08%</td></tr>
</table>
<p>Verde significa que la métrica subió; rojo, que bajó.</p>

<!-- 15 -->
<h1 class="section-title section-break">15. Reporte por sucursal</h1>
<p>Al elegir alcance Por sucursal en Configurar reporte, el reporte trae únicamente la información
de esa sucursal: su recuperación, colocación, cartera, mora, gastos, nómina y utilidad.</p>

<!-- 16 -->
<h1 class="section-title">16. Reporte por empleado / gestor</h1>
<p>Al elegir alcance Por empleado / gestor, el reporte trae únicamente la información de esa
persona: su recuperación, colocación, cartera asignada y utilidad generada.</p>

<!-- 17 -->
<h1 class="section-title">17. Interpretación de KPIs</h1>
<table class="glossary">
    <tr><td class="term">Recuperación</td><td>Dinero real cobrado a los clientes en el periodo.</td></tr>
    <tr><td class="term">Colocación</td><td>Monto total desembolsado en créditos nuevos.</td></tr>
    <tr><td class="term">Valor cartera</td><td>Saldo total de los créditos activos al cierre del periodo.</td></tr>
    <tr><td class="term">Cartera vencida</td><td>Parte de la cartera atrasada en sus pagos.</td></tr>
    <tr><td class="term">Mora %</td><td>Cartera vencida entre valor cartera. Entre más bajo, mejor.</td></tr>
    <tr><td class="term">OPEX</td><td>Gastos operativos del negocio, sin nómina.</td></tr>
    <tr><td class="term">Nómina y Capital Humano</td><td>Todo el gasto relacionado a pago de personal.</td></tr>
    <tr><td class="term">EBITDA</td><td>Utilidad real del negocio en el periodo.</td></tr>
    <tr><td class="term">Margen EBITDA</td><td>Qué porcentaje de los ingresos se convierte en utilidad.</td></tr>
    <tr><td class="term">Préstamo activo</td><td>Número de créditos/contratos vigentes en el periodo.</td></tr>
    <tr><td class="term">Percepciones</td><td>Todo lo que se le pagó al personal antes de descuentos.</td></tr>
    <tr><td class="term">Deducciones</td><td>Descuentos aplicados a la nómina, solo informativos.</td></tr>
    <tr><td class="term">Neto pagado</td><td>Lo que efectivamente recibió el personal.</td></tr>
    <tr><td class="term">Rotación de personal</td><td>Qué tanto entra y sale personal de la empresa en el periodo.</td></tr>
</table>

<!-- 18 -->
<h1 class="section-title section-break">18. Errores comunes y qué hacer</h1>
<div class="warn"><b>"Faltan reportes mensuales"</b> al generar un bimestre/trimestre — genera primero el mes o meses que indica el mensaje.</div>
<div class="warn"><b>Un archivo queda en incidencia</b> — revisa el detalle; casi siempre se resuelve indicando manualmente el dato faltante.</div>
<div class="warn"><b>El reporte tarda en generarse</b> — es normal en una radiografía mensual; se puede cerrar la ventana y se avisa por correo.</div>

<!-- 19 -->
<h1 class="section-title">19. Buenas prácticas de cierre mensual</h1>
<div class="tip">Carga todos los archivos del mes antes de generar el reporte.</div>
<div class="tip">Revisa las incidencias antes de generar.</div>
<div class="tip">Genera primero los reportes mensuales antes de un bimestre o trimestre.</div>
<div class="tip">Abre Ver resultado antes de compartir un reporte, para confirmar que los números se ven correctos.</div>
<div class="tip">Si algo se ve raro, compáralo contra el mes anterior con un comparativo.</div>

<!-- 20 Glosario -->
<h1 class="section-title section-break">20. Glosario de indicadores</h1>
<table class="glossary">
    <tr><td class="term">Recuperación</td><td>Cobros reales recibidos de los clientes.</td></tr>
    <tr><td class="term">Colocación / Otorgamientos</td><td>Nuevos créditos entregados.</td></tr>
    <tr><td class="term">Cartera</td><td>Total de créditos activos (deuda pendiente de los clientes).</td></tr>
    <tr><td class="term">Mora</td><td>Atraso en el pago de un crédito.</td></tr>
    <tr><td class="term">OPEX</td><td>Gastos operativos (no incluye nómina).</td></tr>
    <tr><td class="term">EBITDA</td><td>Utilidad antes de intereses, impuestos, depreciación y amortización.</td></tr>
    <tr><td class="term">Rotación</td><td>Qué tanto entra y sale personal de la empresa.</td></tr>
</table>

<!-- 21 FAQ -->
<h1 class="section-title">21. Preguntas frecuentes</h1>
<p class="faq-q">¿Por qué no puedo cargar archivos en un bimestre o trimestre?</p>
<p class="faq-a">Porque esos periodos se arman automáticamente con los meses que ya se generaron — no reciben archivos propios.</p>
<p class="faq-q">¿Por qué el reporte tarda varios minutos?</p>
<p class="faq-a">Porque procesa una cantidad grande de información. Se puede cerrar la ventana y se avisa por correo cuando termine.</p>
<p class="faq-q">¿Se puede volver a descargar un reporte ya generado?</p>
<p class="faq-a">Sí, desde Reportes mensuales, sin necesidad de generarlo de nuevo.</p>
<p class="faq-q">¿Qué hacer si el EBITDA se ve negativo?</p>
<p class="faq-a">Significa que los gastos superaron el ingreso real del periodo. Revisa el desglose por sucursal para identificar dónde está el mayor impacto.</p>

</body>
</html>
