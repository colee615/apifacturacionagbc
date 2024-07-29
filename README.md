# Proyecto Laravel

Este es un proyecto desarrollado con el framework Laravel 11.

## Requisitos

- PHP >= 8.2
- Composer
- Postgres

## Instalación

1. Clona el repositorio:

    ```sh
    git clone https://github.com/colee615/apifacturacionagbc.git
    cd tu-repositorio
    ```
2. Instala las dependencias de PHP:

    ```sh
    composer install
    ```
3.  JWT en Laravel es tymon/jwt-auth. Puedes instalarlo usando Composer::

    ejecuta:composer require tymon/jwt-auth

4. Genera la clave de la aplicación:

    ```sh
   php artisan jwt:secret
    ```
5. Configura tu archivo `.env` con la información de tu base de datos.
6. Ejecuta las migraciones y los seeders:

    ```sh
    php artisan migrate
    ```
7. Inicia el servidor de desarrollo:

    ```sh
    php artisan serve
    ```

## Uso

Accede a la aplicación en tu navegador en `http://localhost:8000`.

## Estructura del Proyecto

- `app/`: Contiene la lógica de la aplicación.
- `config/`: Contiene los archivos de configuración.
- `database/`: Contiene las migraciones y seeders.
- `public/`: Contiene los archivos públicos como CSS, JS, e imágenes.
- `resources/`: Contiene las vistas y los archivos de recursos.
- `routes/`: Contiene las definiciones de rutas de la aplicación.
- `tests/`: Contiene los tests de la aplicación.
- `routes/`: Contiene los apirest de la aplicación.

## Comandos Útiles

- `php artisan migrate`: Ejecuta las migraciones.
- `php artisan db:seed`: Ejecuta los seeders.
- `php artisan make:model "nombre" -rm --api `: Crea un nuevo modelo, controlador y su migración correspondiente.


## Rutas Agetic

- `https://sefe.demo.agetic.gob.bo/facturacion/`: Base Url.
- `/emision/individual`: Realizar la emision de la factura.
- `/consulta/{codigo_seguimientp}`: Consulta del la venta.
- `/anulacion/{cuf}`: Anular la factura.
