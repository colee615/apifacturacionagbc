# Flujo de pruebas locales de pagos

## Simulación completa sin conectar a proveedores

Para probar primero QR y tarjeta hasta confirmar una factura ficticia, no configurar credenciales externas ni usar tarjeta o banca móvil. Mantener el `.env` apuntando a la base local `agbc_pruebas_local` e iniciar PostgreSQL si está detenido. Ejecutar desde la carpeta del proyecto:

```sh
php vendor/bin/phpunit tests/Feature/FacturaVentaApiBridgeTest.php --filter test_simulacion_ --testdox
```

Resultados esperados:

```text
✔ Simulacion qr pagado y factura confirmada
✔ Simulacion tarjeta pagada y factura confirmada
OK (2 tests, 30 assertions)
```

Cada prueba crea una orden pendiente, simula un callback, verifica el pago consultando un Qhantuy ficticio, solicita explícitamente una factura, recibe una notificación SEFE ficticia y consulta el resultado `FACTURADA`. Verifica también el método fiscal 5 para QR y 2 para tarjeta. Las llamadas externas están bloqueadas y sustituidas por respuestas de prueba.

La factura, CUF, imagen QR, enlace de tarjeta y direcciones de PDF/XML son ficticios. No se obtiene un PDF real ni una factura registrada en SEFE demo/SIN. Los cambios de cada prueba se revierten al finalizar y no aparecerán en la lista de ventas del usuario local. No hace falta iniciar `artisan serve` ni iniciar sesión para estas pruebas automáticas. Esta comprobación recorre las API de pagos y facturación con una solicitud explícita de emisión; no comprueba una pantalla de usuario ni la conversión desde el botón de un carrito.

Las secciones siguientes describen pruebas manuales contra Qhantuy testing, que requieren las condiciones y credenciales del proveedor.

## Alcance

Qhantuy procesa cobros; AGETIC/SEFE solo factura. Estas pruebas usan las rutas de pagos y no solicitan facturas. El proyecto contiene la API: las llamadas siguientes pueden hacerse con Postman o un cliente equivalente. El usuario creado aquí es local y no es una cuenta de Qhantuy ni del banco.

No se ha acreditado que una tarjeta real o una transferencia desde banca móvil queden sin cargo. Para las pruebas sin dinero real, pedir a Qhantuy credenciales de testing, datos de tarjeta de prueba, casos de aprobación/rechazo y el mecanismo de simulación de pagos QR. La guía v11.1.0 indica que el QR de testing puede ser una imagen demo no pagable y requiere coordinar la simulación del callback. No proporciona datos de tarjeta de prueba ni una garantía de ausencia de cargos con instrumentos reales.

## 1. Iniciar la base y la aplicación

La base local debe estar iniciada en `127.0.0.1:55432`. Las instrucciones de inicio de PostgreSQL están en `docs/pagos-qhantuy.md`.

En la carpeta del proyecto:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

El usuario `pruebas.local@agbc.test` tiene rol `admin`. Sus credenciales generadas están en `storage/logs/local-test-user.json`, excluido de Git.

## 2. Verificar el acceso

Enviar `POST http://127.0.0.1:8000/admin/login`, con `Accept: application/json` y `Content-Type: application/json`:

```json
{
  "email": "pruebas.local@agbc.test",
  "password": "<contraseña local generada>"
}
```

Debe responder HTTP 200 y un `token`. Para las siguientes llamadas añadir `Authorization: Bearer <token>`.

## 3. Completar únicamente la conexión de testing

En el `.env` local configurar token, appkey y profile_code confirmados por Qhantuy como pertenecientes a testing. Mantener las tres URL del proveedor en `testingcheckout.qhantuy.com` y el timeout en 60 segundos. El usuario local no sustituye estas credenciales.

`QHANTUY_CHECKOUT_CALLBACK_URL` debe llegar a esta aplicación local mediante una dirección pública HTTPS y terminar en `/api/qhantuy/callback`. Si se deja el callback del servidor de SAFE, las notificaciones irán a ese servidor. La URL de retorno de tarjeta debe apuntar al frontend de pruebas, si existe.

Después de guardar los ajustes:

```sh
php artisan config:clear
```

El `.env` local generado para las migraciones no contiene los tokens externos compartidos en la conversación. Hasta configurarlos, el checkout devuelve un error de configuración; no significa que el usuario local sea incorrecto.

## 4. Crear una orden QR

`POST http://127.0.0.1:8000/api/factura-venta/pagos/checkout`

```json
{
  "internal_code": "DEMO-QR-0001",
  "payment_method": "QRSIMPLE",
  "qr_format": "URL",
  "detail": "Prueba QR sin dinero real",
  "items": [{"name": "Servicio de prueba", "quantity": 1, "price": 10}]
}
```

Esperar `ok: true`, `transaction_id`, `image_data` y `payment_status: holding`. Abrir la imagen para comprobar su visualización. No pagar con una aplicación bancaria real: solicitar a Qhantuy la simulación de esta transacción y conservar su ID.

## 5. Consultar antes y después de la simulación

`POST http://127.0.0.1:8000/api/factura-venta/pagos/check-payments`

```json
{"internal_code": "DEMO-QR-0001"}
```

Antes de simular debe estar pendiente (`holding`); después, si Qhantuy confirma el importe y la identidad de la orden, debe pasar a `success` y `estado_pago: pagado`. La caché de una consulta válida puede conservar el estado pendiente durante 8 segundos. El callback omite esa caché y verifica directamente con Qhantuy.

Cambiar manualmente la URL del callback a `status=success` no acredita un pago: el backend consulta a Qhantuy para comprobarlo.

## 6. Probar tarjeta con datos ficticios del proveedor

Enviar a la misma ruta de checkout:

```json
{
  "internal_code": "DEMO-TARJETA-0001",
  "payment_method": "CYBERSOURCE",
  "detail": "Prueba de tarjeta sin dinero real",
  "items": [{"name": "Servicio de prueba", "quantity": 1, "price": 10}]
}
```

Abrir `payment_url`. Antes de introducir datos, confirmar con Qhantuy que el enlace y la caja están en testing. Usar exclusivamente la tarjeta de prueba facilitada por ellos y completar los escenarios de aprobación y rechazo indicados. Consultar con `internal_code: DEMO-TARJETA-0001` para validar el resultado. No es necesario que haya imagen QR.

Si no hay frontend ni return_url, el navegador puede volver al callback y mostrar JSON: verificar el estado con la ruta de consulta.

## 7. Probar anulación y duplicados

Crear otra orden con un código nuevo y no simular su pago. Anularla con `POST /api/factura-venta/pagos/cancel-payment`:

```json
{"internal_code": "DEMO-QR-0002", "reason": "Anulación de prueba"}
```

Debe devolver `cancelled` solo cuando Qhantuy confirme la anulación. Una orden pagada no debe poder anularse mediante esta operación; no es un reembolso.

Repetir exactamente un checkout con su mismo código debe reutilizar la orden (`reused: true`). Repetir el código cambiando el importe debe devolver 409. Tras un timeout de creación, conciliar con Qhantuy antes de crear otra orden: no usar un código nuevo para saltarse una creación incierta.

## 8. Pruebas automáticas sin cobros

```sh
php vendor/bin/phpunit tests/Feature/QhantuyCheckoutTest.php
```

Estas pruebas usan SQLite en memoria y respuestas HTTP simuladas. No usan tarjetas, banca móvil ni las credenciales del proveedor. Se verificaron 16 casos de pagos; además pasaron 12 pruebas relacionadas con validación de facturas y cálculos financieros.


## Simulación manual local autorizada para pruebas

Habilitar `QHANTUY_MANUAL_SIMULATION=true` y ejecutar `php artisan config:clear`.
Requiere APP_ENV local/testing, base de Qhantuy testing y base de datos local.
Con el mismo Bearer de inicio de sesión:

POST http://127.0.0.1:8000/api/factura-venta/pagos/simular-pago

```json
{
  "internal_code": "DEMO-QR-0005",
  "transaction_id": 24310,
  "checkout_amount": 10,
  "confirm_simulation": true
}
```

La orden debe existir localmente y estar pendiente o vencida. Se comprueban referencia,
transacción e importe. También funciona con órdenes de tarjeta. Devuelve
`simulation: true`, `payment_status: success` y `estado_pago: pagado`.
No realiza ningún cobro ni modifica el estado remoto de Qhantuy. La consulta local
check-payments conserva la simulación sin contactar al proveedor. Los reintentos
conservan la fecha del primer pago simulado. La auditoría queda en el registro de
pago y en el log. No copiar los registros de prueba a producción.

Esto simula únicamente el pago: no emite automáticamente una factura. Continuar
con el paso de emisión de factura de esta guía; la emisión demo de AGETIC necesita
sus credenciales y datos fiscales válidos.
