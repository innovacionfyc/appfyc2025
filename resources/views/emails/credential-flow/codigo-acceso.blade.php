<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Código de acceso para consultar certificados</title></head>
<body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
    <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:16px;padding:28px;">
        <tr><td>
            <p style="margin:0 0 8px;font-weight:bold;color:#942934;">{{ $entidad }}</p>
            <h1 style="margin:0 0 16px;font-size:20px;">Código de acceso para consultar certificados</h1>
            <p style="margin:0 0 12px;">Código para consultar y descargar certificados:</p>
            <p style="margin:0 0 16px;font-size:32px;letter-spacing:8px;font-weight:bold;">{{ $codigo }}</p>
            <p style="margin:0 0 12px;">Vigencia de {{ $vigencia }} minutos. El código es de un solo uso.</p>
            <p style="margin:0 0 12px;color:#64748b;font-size:14px;">Si el mensaje no aparece en la bandeja de entrada, revisar también Spam o Correo no deseado.</p>
            <p style="margin:0;color:#64748b;font-size:14px;">Si el código no fue solicitado, este mensaje puede ignorarse. Sin este código no es posible consultar los certificados.</p>
        </td></tr>
    </table>
</td></tr></table>
</body>
</html>
