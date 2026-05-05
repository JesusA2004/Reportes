<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Error al actualizar base de datos — {{ $period->label }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:40px 16px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 4px 32px rgba(0,0,0,.08);">

      <!-- Header -->
      <tr>
        <td style="background:#0f172a;padding:28px 36px;">
          <p style="margin:0 0 6px;color:#64748b;font-size:11px;font-weight:700;letter-spacing:.15em;text-transform:uppercase;">Sistema Reportes</p>
          <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:900;line-height:1.3;">Error al actualizar base de datos</h1>
        </td>
      </tr>

      <!-- Status banner -->
      <tr>
        <td style="background:#fef2f2;padding:14px 36px;border-bottom:1px solid #fecaca;">
          <p style="margin:0;color:#dc2626;font-size:13px;font-weight:700;">✗ &nbsp;El proceso terminó con error</p>
        </td>
      </tr>

      <!-- Body -->
      <tr>
        <td style="padding:36px 36px 28px;">
          <p style="margin:0 0 20px;color:#334155;font-size:15px;line-height:1.7;">
            Hola{{ $user?->name ? ', ' . $user->name : '' }},
          </p>
          <p style="margin:0 0 28px;color:#334155;font-size:15px;line-height:1.7;">
            No se pudo actualizar la base de datos del periodo <strong style="color:#0f172a;">{{ $period->label }}</strong>.
            Revisa las fuentes cargadas y vuelve a intentar desde Histórico General.
          </p>

          <!-- Info table -->
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-bottom:20px;">
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;width:40%;">Periodo</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;font-weight:700;">{{ $period->label }}</td>
            </tr>
            <tr style="border-bottom:1px solid #e2e8f0;">
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Inicio</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $run->started_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
            <tr>
              <td style="padding:12px 18px;font-size:13px;color:#64748b;font-weight:600;">Finalización</td>
              <td style="padding:12px 18px;font-size:13px;color:#0f172a;">{{ $run->finished_at?->format('d/m/Y H:i') ?? '—' }}</td>
            </tr>
          </table>

          @if($errorMessage)
          <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:16px 18px;margin-bottom:28px;">
            <p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:.08em;">Motivo del error</p>
            <p style="margin:0;font-size:13px;color:#7f1d1d;font-family:'Courier New',monospace;line-height:1.6;word-break:break-all;">{{ $errorMessage }}</p>
          </div>
          @endif

          <!-- CTA -->
          <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
            <tr>
              <td style="background:#4f46e5;border-radius:12px;padding:14px 28px;">
                <a href="{{ route('historico-general.index') }}" style="color:#ffffff;font-size:14px;font-weight:700;text-decoration:none;display:inline-block;">
                  Revisar Histórico General →
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
