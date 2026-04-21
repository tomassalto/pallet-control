<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verificá tu cuenta</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:sans-serif">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
      <td align="center" style="padding:40px 16px">
        <table width="100%" style="max-width:480px;background:#fff;border-radius:12px;padding:32px;border:1px solid #e4e4e7">
          <tr>
            <td>
              <h1 style="margin:0 0 8px;font-size:24px;font-weight:700;color:#111">
                📦 Pallet Control
              </h1>
              <p style="margin:0 0 24px;color:#555;font-size:15px">
                Hola <strong>{{ $userName }}</strong>, necesitás verificar tu cuenta antes de poder iniciar sesión.
              </p>
              <a href="{{ $verifyUrl }}"
                 style="display:inline-block;background:#1e40af;color:#fff;padding:14px 28px;border-radius:8px;text-decoration:none;font-weight:600;font-size:15px">
                Verificar mi cuenta
              </a>
              <p style="margin:24px 0 0;color:#999;font-size:13px">
                El link expira en 48 horas. Si no creaste esta cuenta, ignorá este mensaje.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
