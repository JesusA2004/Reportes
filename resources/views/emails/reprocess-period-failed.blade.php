<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Error en reprocesamiento — {{ $period->label }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,.08);">

      <!-- Header -->
      <tr>
        <td style="background:#0f172a;padding:28px 36px;">
          <p style="margin:0 0 6px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;">Sistema Reportes</p>
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:900;line-height:1.3;">Error en reprocesamiento del periodo</h1>
        </td>
      </tr>

      <!-- Status banner -->
      <tr>
        <td style="background:#fee2e2;padding:14px 36px;border-bottom:1px solid #fecaca;">
          <p style="margin:0;color:#dc2626;font-size:13px;font-weight:700;">✗ &nbsp;El reprocesamiento terminó con errores</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:36px 36px 28px;">
          <p style="margin:0 0 20px;color:#334155;font-size:15px;line-height:1.7;">
            Hola{{ $user?->name ? ', ' . $user->name : '' }},
          </p>
          <p style="margin:0 0 28px;color:#334155;font-size:15px;line-height:1.7;">
            El reprocesamiento del periodo <strong style="color:#0f172a;">{{ $period->label }}</strong>
            terminó con uno o más errores. Revisa las fuentes indicadas y vuelve a intentarlo.
          </p>

          <!-- Run info -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:28px;">
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;width:40%;">Periodo</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;font-weight:700;">{{ $period->label }}</td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Inicio</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $run->started_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Finalización</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $run->finished_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
            @if($run->log)
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Detalle</td>
              <td style="padding:12px 18px;font-size:13px;color:#7f1d1d;">{{ $run->log }}</td>
            </tr>
            @endif
          </table>

          @if(!empty($results))
          <p style="margin:0 0 12px;font-size:13px;font-weight:700;color:#334155;">Estado por fuente</p>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:28px;">
            @foreach($results as $code => $result)
            <tr style="{{ !$loop->last ? 'border-bottom:1px solid #e2e8f0;' : '' }}background:{{ ($result['ok'] ?? false) ? '#f0fdf4' : '#fef2f2' }};">
              <td style="padding:12px 18px;font-size:13px;font-weight:600;color:#334155;width:55%;">{{ $result['name'] ?? $code }}</td>
              <td style="padding:12px 18px;font-size:13px;">
                @if($result['ok'] ?? false)
                  <span style="color:#15803d;font-weight:700;">✓ {{ number_format($result['inserted'] ?? 0) }} registros</span>
                @else
                  <span style="color:#dc2626;font-weight:700;">✗ Error</span>
                  @if(!empty($result['error']))
                  <br><span style="color:#9a3412;font-size:11px;">{{ $result['error'] }}</span>
                  @endif
                @endif
              </td>
            </tr>
            @endforeach
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
