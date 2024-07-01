<!DOCTYPE html>
<html>
<head>
    <title>Restablecimiento de contraseña</title>
</head>
<body>
    <h1>Restablecimiento de Contraseña</h1>
    <p>Hola {{ $fundacione->nombre_fundacion }},</p>
    <p>Has solicitado un restablecimiento de contraseña en nuestro sistema.</p>
    <p>Para restablecer tu contraseña, haz clic en el siguiente enlace:</p>
    <p>El token para restablecer la contraseña es: {{ $fundacione->confirmation_token }}</p>
    <p>Si no solicitaste este restablecimiento, puedes ignorar este correo.</p>
    <p>Gracias,</p>
    <p>Tu equipo de {{ config('app.name') }}</p>
</body>
</html>
