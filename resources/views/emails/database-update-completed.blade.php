<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Registros cargados — {{ $period->label }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,.08);">

      <!-- Header -->
      <tr>
        <td style="background:#0f172a;padding:28px 36px;">
          <p style="margin:0 0 6px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;">Sistema Reportes</p>
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:900;line-height:1.3;">Registros cargados correctamente</h1>
        </td>
      </tr>

      <!-- Status banner -->
      <tr>
        <td style="background:#dcfce7;padding:14px 36px;border-bottom:1px solid #bbf7d0;">
          <p style="margin:0;color:#15803d;font-size:13px;font-weight:700;">✓ &nbsp;Carga de registros completada</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:36px 36px 28px;">
          <p style="margin:0 0 20px;color:#334155;font-size:15px;line-height:1.7;">
            Hola{{ $user?->name ? ', ' . $user->name : '' }},
          </p>
          <p style="margin:0 0 28px;color:#334155;font-size:15px;line-height:1.7;">
            Los registros base del periodo <strong style="color:#0f172a;">{{ $period->label }}</strong> fueron cargados correctamente.
            Revisa las incidencias detectadas antes de generar el reporte.
          </p>

          <!-- Info del proceso -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:24px;">
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;width:45%;">Periodo</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;font-weight:700;">{{ $period->label }}</td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">En cola</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ ($run->queued_at ?? $run->created_at)?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Inicio</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $run->started_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
            @php
                $duration = ($run->started_at && $run->finished_at)
                    ? (int) $run->started_at->diffInSeconds($run->finished_at)
                    : null;
                $durationStr = $duration !== null
                    ? (($duration >= 60) ? floor($duration / 60) . ' min ' . ($duration % 60) . ' seg' : $duration . ' seg')
                    : null;
            @endphp
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Duración</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $durationStr ?? '—' }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Finalización</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $run->finished_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
          </table>

          @if(!empty($stats))
          <!-- Stats de carga -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;overflow:hidden;margin-bottom:24px;">

            @if(isset($stats['persons_detected']) || isset($stats['employees_detected']))
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;width:60%;">Personas detectadas</td>
              <td style="padding:12px 18px;font-size:13px;color:#1e40af;font-weight:700;">{{ $stats['persons_detected'] ?? $stats['employees_detected'] }}</td>
            </tr>
            @endif

            @if(isset($stats['records_loaded']))
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;">Registros cargados</td>
              <td style="padding:12px 18px;font-size:13px;color:#1e40af;font-weight:700;">{{ number_format($stats['records_loaded']) }}</td>
            </tr>
            @endif

            @if(isset($stats['records_excluded']))
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;">Registros excluidos por reglas</td>
              <td style="padding:12px 18px;font-size:13px;color:#1e40af;font-weight:700;">{{ number_format($stats['records_excluded']) }}</td>
            </tr>
            @endif

            @if(isset($stats['branches_included']))
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;">Sucursales incluidas en reporte</td>
              <td style="padding:12px 18px;font-size:13px;color:#15803d;font-weight:700;">{{ $stats['branches_included'] }}</td>
            </tr>
            @endif

            @if(isset($stats['branches_excluded']))
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;">Sucursales excluidas</td>
              <td style="padding:12px 18px;font-size:13px;color:#b91c1c;font-weight:700;">{{ $stats['branches_excluded'] }}</td>
            </tr>
            @endif

            @if(isset($stats['pending_locations']) && $stats['pending_locations'] > 0)
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#b91c1c;font-weight:700;">⚠ Ubicaciones pendientes</td>
              <td style="padding:12px 18px;font-size:13px;color:#b91c1c;font-weight:700;">{{ $stats['pending_locations'] }}</td>
            </tr>
            @endif

            @if(isset($stats['critical_incidents']) && $stats['critical_incidents'] > 0)
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#b45309;font-weight:700;">⚠ Incidencias críticas</td>
              <td style="padding:12px 18px;font-size:13px;color:#b45309;font-weight:700;">{{ $stats['critical_incidents'] }}</td>
            </tr>
            @endif

            @if(isset($stats['warnings']) && $stats['warnings'] > 0)
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;">Advertencias</td>
              <td style="padding:12px 18px;font-size:13px;color:#1e40af;font-weight:700;">{{ $stats['warnings'] }}</td>
            </tr>
            @endif

          </table>
          @endif

          @if(!empty($stats['pending_locations']) && $stats['pending_locations'] > 0)
          <!-- Alerta ubicaciones pendientes -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;overflow:hidden;margin-bottom:24px;">
            <tr>
              <td style="padding:16px 18px;">
                <p style="margin:0 0 6px;font-size:13px;font-weight:700;color:#b91c1c;">⚠ Se detectaron ubicaciones no reconocidas</p>
                <p style="margin:0;font-size:12px;color:#7f1d1d;line-height:1.6;">
                  Hay {{ $stats['pending_locations'] }} ubicación(es) que no se pudieron clasificar como sucursales operativas ni exclusiones conocidas.
                  Revisa las incidencias críticas en el sistema antes de generar el reporte.
                </p>
              </td>
            </tr>
          </table>
          @endif

          <!-- Sucursales incluidas -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;overflow:hidden;margin-bottom:24px;">
            <tr>
              <td style="padding:16px 18px;">
                <p style="margin:0 0 8px;font-size:12px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.05em;">Sucursales incluidas en el reporte general</p>
                <p style="margin:0;font-size:12px;color:#166534;line-height:1.8;">
                  ATLACOMULCO · ATLIXCO · CORDOBA · CUERNAVACA · HUAMANTLA · IXTLAHUACA ·
                  MIACATLAN · ORIZABA · SAN LUIS POTOSI · TENANGO DEL VALLE · TLAXCALA · TULA
                </p>
                <p style="margin:8px 0 0;font-size:11px;color:#4ade80;">
                  Excluidas: SAN JUAN DEL RÍO (sucursal cerrada) · CORPORATIVO (no operativo)
                </p>
              </td>
            </tr>
          </table>

          <!-- CTA -->
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
            <tr>
              <td style="background:#4f46e5;border-radius:12px;padding:14px 28px;">
                <a href="{{ route('historico-general.index') }}" style="color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;">
                  Revisar incidencias y continuar →
                </a>
              </td>
            </tr>
          </table>
        </td>
      </tr>

      <!-- Footer -->
      <tr>
        <td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:20px 36px;text-align:center;">
          <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.6;">Este correo fue generado automáticamente por el Sistema Reportes.<br>No respondas a este mensaje.</p>
        </td>
      </tr>

    </table>
  </td></tr>
</table>
</body>
</html>
