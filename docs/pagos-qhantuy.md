# Pagos QR y tarjeta con Qhantuy

Qhantuy procesa los pagos. AGETIC/SEFE procesa la facturación mediante sus rutas existentes. Crear o confirmar un cobro no solicita una factura a SEFE. La documentación PPE no se utiliza para estos cobros.

Implementación basada en la guía Qhantuy Checkout v11.1.0, de 28/09/2026, entregada en `w.pdf`.

## Preparación del demo

### Base local de esta copia

Se creó una instancia PostgreSQL 18 aislada en `storage/local-postgres`, con base `agbc_pruebas_local`, usuario `agbc_local` y puerto `55432`, escuchando únicamente en `127.0.0.1`. El `.env` local contiene sus credenciales y claves locales generadas; no es una copia del `.env` del servidor ni incluye las credenciales de Qhantuy/AGETIC compartidas en la conversación. Los datos de PostgreSQL y `.env` están excluidos de Git. Se aplicaron las 48 migraciones, incluida la ampliación para tarjeta.

Después de reiniciar el equipo, iniciar PostgreSQL desde PowerShell en la carpeta del proyecto:

```powershell
& 'C:/Program Files/PostgreSQL/18/bin/pg_ctl.exe' -D "$PWD/storage/local-postgres" -l "$PWD/storage/logs/postgres-local.log" -o '-h 127.0.0.1 -p 55432' -w start
```

Para detener únicamente esta instancia:

```powershell
& 'C:/Program Files/PostgreSQL/18/bin/pg_ctl.exe' -D "$PWD/storage/local-postgres" -m fast -w stop
```

La migración inicial de usuarios permite crear la base vacía aunque la tabla histórica `sucursales` no exista; si existe, conserva la creación de su clave foránea. Una migración posterior elimina esa referencia antigua.

### Conexión a Qhantuy testing

1. Instalar dependencias con `composer install`.
2. Configurar en `.env` las variables Qhantuy incluidas en `.env.example`. Obtener token, appkey y profile_code de testing de Qhantuy. Las credenciales de producción son distintas.
3. Configurar `QHANTUY_CHECKOUT_CALLBACK_URL=https://<dominio-publico>/api/qhantuy/callback` y, para tarjeta, preferiblemente `QHANTUY_CHECKOUT_RETURN_URL` con la página del pedido en el frontend.
4. Aplicar las migraciones con `php artisan migrate` y refrescar configuración con `php artisan config:clear`.
5. El frontend debe mostrar el QR o abrir `payment_url`. Debe consultar el estado del pago en el backend al volver de la pasarela. El retorno del navegador no acredita el pago.

No enviar token ni appkey de Qhantuy al navegador. Las rutas de creación, consulta y anulación requieren la autenticación `factura.auth` ya utilizada por el proyecto. El callback es público.

## Rutas

| Método | Ruta | Uso |
| --- | --- | --- |
| POST | `/api/factura-venta/pagos/checkout` | Crear un cobro QR o enlace de tarjeta |
| POST | `/api/factura-venta/pagos/check-payments` | Consultar una transacción registrada |
| POST | `/api/factura-venta/pagos/cancel-payment` | Anular una orden pendiente |
| GET | `/api/qhantuy/callback` | Recibir aviso y verificarlo contra Qhantuy |

Las rutas antiguas `/api/factura-venta/qr/*` se mantienen como alias. Por defecto se usa `QRSIMPLE`; el parámetro `image_method` sigue admitido como alias de `qr_format`. El callback configurado en el servidor prevalece sobre el enviado por el cliente.

### QR

```json
{
  "internal_code": "DEMO-QR-0001",
  "payment_method": "QRSIMPLE",
  "qr_format": "URL",
  "detail": "Prueba de servicio postal",
  "items": [{"name": "Envio", "quantity": 1, "price": 10}]
}
```

La respuesta contiene `transaction_id`, `image_data`, `qr_url` y `payment_status`. `qr_format: BASE64` devuelve la imagen codificada en lugar de una URL.

### Tarjeta mediante enlace

```json
{
  "internal_code": "DEMO-TARJETA-0001",
  "payment_method": "CYBERSOURCE",
  "return_url": "https://<frontend>/pedidos/DEMO-TARJETA-0001",
  "customer_email": "comprador@example.com",
  "detail": "Prueba de servicio postal",
  "items": [{"name": "Envio", "quantity": 1, "price": 10}]
}
```

El backend fuerza `payment_type: REDIRECT` y devuelve `payment_url`. El comprador introduce su tarjeta en la pasarela de Qhantuy; esta aplicación no recibe PAN, CVV ni fecha de vencimiento. Qhantuy puede ofrecer otros métodos habilitados en ese mismo enlace. `CYBERSOURCE` identifica el flujo solicitado y no demuestra por sí solo el instrumento final utilizado por el comprador.

Se mantiene BOB y `POST_PAYMENT`: el comprador paga el total de los ítems y el comercio asume su comisión acordada. No se habilita `PRE_CHARGE`, porque modificaría el importe a conciliar con la venta. Cantidad mínima por ítem: 1; precio positivo y total mínimo: 0.10 BOB. Los datos de nombre, apellido y correo son opcionales según la guía.

### Consulta y anulación

```json
{"payment_ids": [3301245]}
```

También se permite consultar con `{"internal_code":"DEMO-QR-0001"}` si ya hay un transaction_id registrado. No se realizan consultas masivas sin filtro. La confirmación exige transacción, importe y moneda coincidentes; si el proveedor devuelve internal_code, también debe coincidir. Respuestas vacías, inválidas o `process: false` no confirman pagos. Las respuestas recientes de una consulta válida se almacenan temporalmente; el callback siempre realiza una verificación remota.

Para anular:

```json
{"transaction_id": 3301245, "reason": "Prueba finalizada sin pago"}
```

Solo se acepta la anulación si el proveedor confirma la transacción y su estado `cancelled`. Un cobro confirmado no puede anularse con esta operación; no implementa devoluciones.

## Duplicados y resultados inciertos

Qhantuy admite códigos internos repetidos. El backend reserva el código antes de llamar al proveedor y usa un bloqueo durante la creación. Repetir la misma solicitud devuelve la orden existente; cambiar sus datos devuelve 409. Usar un caché compartido con bloqueos si hay varias instancias de la aplicación.

Si la creación agota el tiempo de espera, no se envía de nuevo automáticamente: la orden podría haberse creado en Qhantuy. Primero conciliar con el proveedor. Una vez identificado el transaction_id, puede consultarse enviando `payment_ids` e `internal_code` juntos: el backend exige que la referencia devuelta coincida antes de asociar la transacción. No reutilizar códigos históricos ni crear otra orden mientras la anterior pueda cobrarse.

Se conserva el nombre de tabla `qhantuy_qr_payments` por compatibilidad; ahora registra también el flujo de tarjeta, su enlace y la huella de la solicitud. No se transforma el identificador de transacción del proveedor en un código de factura.

## Pruebas

```sh
php vendor/bin/phpunit tests/Feature/QhantuyCheckoutTest.php
```

La suite usa SQLite en memoria y simula HTTP, sin credenciales reales ni cobros. Cubre QR, enlace de tarjeta, duplicados, timeout de creación, importes mínimos, callbacks falsos/repetidos, diferencias de moneda/importe/referencia, consulta fallida y anulación.

Para probar el flujo real en testing, Qhantuy debe habilitar ambos métodos y proporcionar la simulación de confirmación: su guía advierte que el QR demo puede no ser pagable desde una aplicación bancaria. Usar únicamente los datos de prueba de tarjeta que proporcione Qhantuy y verificar pago, consulta y anulación con esas credenciales.
