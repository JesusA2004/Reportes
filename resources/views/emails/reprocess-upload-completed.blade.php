<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reprocesamiento completado — {{ $upload->dataSource?->name ?? 'Archivo' }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,.08);">

      <!-- Header -->
      <tr>
        <td style="background:#0f172a;padding:28px 36px;">
          <p style="margin:0 0 6px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;">Sistema Reportes</p>
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:900;line-height:1.3;">Reprocesamiento completado</h1>
        </td>
      </tr>

      <!-- Status banner -->
      <tr>
        <td style="background:#dcfce7;padding:14px 36px;border-bottom:1px solid #bbf7d0;">
          <p style="margin:0;color:#15803d;font-size:13px;font-weight:700;">✓ &nbsp;Archivo reprocesado correctamente</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:36px 36px 28px;">
          <p style="margin:0 0 20px;color:#334155;font-size:15px;line-height:1.7;">
            Hola{{ $user?->name ? ', ' . $user->name : '' }},
          </p>
          <p style="margin:0 0 28px;color:#334155;font-size:15px;line-height:1.7;">
            El archivo <strong style="color:#0f172a;">{{ $upload->dataSource?->name ?? $upload->original_name }}</strong>
            del periodo <strong style="color:#0f172a;">{{ $period->label }}</strong> fue reprocesado exitosamente.
            Los datos fueron actualizados en la base de datos.
          </p>

          <!-- Info table -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:28px;">
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;width:40%;">Periodo</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;font-weight:700;">{{ $period->label }}</td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Fuente</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $upload->dataSource?->name ?? '—' }}</td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Archivo</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $upload->original_name }}</td>
            </tr>
            @if(!empty($stats['log']))
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Resultado</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $stats['log'] }}</td>
            </tr>
            @endif
          </table>

          @if(!empty($stats))
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;overflow:hidden;margin-bottom:28px;">
            @if(isset($stats['rows_read']))
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;width:60%;">Filas leídas</td>
              <td style="padding:12px 18px;font-size:13px;color:#1e40af;font-weight:700;">{{ number_format($stats['rows_read']) }}</td>
            </tr>
            @endif
            @if(isset($stats['rows_inserted']))
            <tr style="border-bottom:1px solid #bfdbfe;">
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;">Registros insertados</td>
              <td style="padding:12px 18px;font-size:13px;color:#1e40af;font-weight:700;">{{ number_format($stats['rows_inserted']) }}</td>
            </tr>
            @endif
            @if(isset($stats['rows_skipped']))
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#1d4ed8;font-weight:600;">Filas omitidas</td>
              <td style="padding:12px 18px;font-size:13px;color:#1e40af;font-weight:700;">{{ number_format($stats['rows_skipped']) }}</td>
            </tr>
            @endif
          </table>
          @endif

          <!-- CTA -->
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
            <tr>
              <td style="background:#4f46e5;border-radius:12px;padding:14px 28px;">
                <a href="{{ route('historico-general.index') }}" style="color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;">
                  Abrir Histórico General →
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
