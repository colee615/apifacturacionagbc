# Notificaciones de SEFE demo

En el proyecto existe un listado de notificaciones en `GET /admin/notificaciones`
y el receptor principal `POST /notificacion/{codigoSeguimiento}`.

Se agrega el receptor de desarrollo `POST /dev/notificacion/{codigoSeguimiento}`.
`GET /dev/notificacion` devuelve HTTP 405 con instrucciones: ese resultado es
normal al abrirlo desde un navegador. SEFE envia POST y agrega el seguimiento.

Para cambiar el destino efectivo:

1. Publicar esta version en la aplicacion de desarrollo del servidor SAFE.
2. En la configuracion del sistema cliente de AGETIC demo, cambiar el campo
   `urlNotificacion` a `https://safe.correos.gob.bo/dev/notificacion`.
3. Guardar y realizar una nueva emision demo. Comprobar `urlNotificacion` en la
   consulta de esa nueva solicitud y la recepcion en el listado de notificaciones.

El destino se configura en AGETIC; no se envia como parte de la solicitud de
emision individual de este proyecto. Modificar rutas locales no modifica el panel
de AGETIC ni publica cambios en el servidor.

El receptor principal conserva su procesador y su sincronizacion de ventas.
El receptor demo guarda exclusivamente en `notificaciones_demo`: no modifica
`ventas`, `notificaciones`, carritos ni cajas. Esto permite publicar el receptor
demo junto al principal sin que una prueba con el mismo seguimiento altere una
factura real. El alias POST `/dev/notificaciones/{codigoSeguimiento}` tambien
recibe notificaciones demo.

El apartado de consulta es GET `/dev/notificaciones`, con el Bearer de inicio de
sesion o de integracion admitido por `factura.auth`. Devuelve 25 registros por
pagina y permite `?codigoSeguimiento=...`. No es una pantalla HTML; es una API.
Las notificaciones demo no actualizan automaticamente una venta local: para
validar su resultado fiscal, usar la consulta a SEFE demo.

Antes de publicar, respaldar la base y el `.env` del servidor. Instalar las
dependencias del lockfile y aplicar `php artisan migrate --force`, luego limpiar
las caches de configuracion y rutas. No reemplazar el `.env` de produccion por
el local. Mantener APP_ENV=production y QHANTUY_MANUAL_SIMULATION=false.
Las migraciones nuevas agregan columnas y una tabla sin borrar ventas.
Publicar primero el receptor y comprobarlo antes de cambiar `urlNotificacion`
exclusivamente en AGETIC demo; conservar el destino del cliente de produccion.
El servidor debe preservar `/dev` al enviar esta ruta a Laravel para que no
termine entrando al receptor principal por una reescritura del proxy.

Los callbacks quedan exentos de CSRF porque son llamadas servidor a servidor;
se mantiene la validacion del contenido y de coincidencia del seguimiento.
