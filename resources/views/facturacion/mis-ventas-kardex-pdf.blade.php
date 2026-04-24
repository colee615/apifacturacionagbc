<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Kardex Diario de Rendicion</title>
    <style>
        @page {
            margin: 16px 14px 18px 14px;
        }

        body {
            font-family: DejaVu Serif, serif;
            font-size: 11px;
            color: #111;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .no-border td {
            border: none;
        }

        .header-banner {
            width: 100%;
            height: auto;
            display: block;
        }

        .label-box {
            border: 1px solid #333;
            padding: 7px 10px;
            display: inline-block;
            font-size: 11px;
            margin-top: 10px;
        }

        .side-box {
            width: 220px;
            margin-left: auto;
            margin-top: 10px;
        }

        .side-box td {
            border: 1px solid #333;
            padding: 4px 8px;
            font-size: 11px;
        }

        .meta td {
            border: 1px solid #333;
            padding: 6px 8px;
            font-size: 10px;
        }

        .meta .field {
            width: 18%;
            font-weight: 700;
        }

        .meta .value {
            width: 32%;
        }

        .grid th,
        .grid td {
            border: 1px solid #333;
            padding: 4px 5px;
        }

        .grid th {
            text-align: center;
            font-weight: 700;
            font-size: 10px;
        }

        .grid td {
            font-size: 9.4px;
            height: 20px;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .totals td {
            border: 1px solid #333;
            padding: 5px 8px;
            font-size: 10px;
            font-weight: 700;
        }

        .observaciones td {
            border: 1px solid #333;
            padding: 8px;
            vertical-align: top;
        }

        .obs-label {
            width: 27%;
            font-weight: 700;
        }

        .obs-lines {
            padding: 0 !important;
        }

        .obs-grid {
            width: 100%;
            height: 90px;
            border-collapse: collapse;
        }

        .obs-grid td {
            border: 0;
            border-right: 1px solid #888;
            height: 90px;
            padding: 0 8px;
            font-size: 9px;
            vertical-align: top;
        }

        .obs-grid td:last-child {
            border-right: 0;
        }
    </style>
</head>
<body>
@php
    $headerImagePath = public_path('images/encabezado_contratos.jpeg');
    $headerImage = file_exists($headerImagePath) ? 'data:image/jpeg;base64,' . base64_encode(file_get_contents($headerImagePath)) : null;
    $sucursal = $user->sucursal;
    $oficinaPostal = trim((string) ($sucursal->nombre ?? $sucursal->descripcion ?? $sucursal->municipio ?? ''));
    $isAdmisionesEms = collect($carts)->contains(function ($cart) {
        $rawItems = data_get($cart, 'items', []);
        $cartItems = $rawItems instanceof \Illuminate\Support\Collection
            ? $rawItems
            : (is_array($rawItems) ? collect($rawItems) : collect());
        return $cartItems->contains(function ($item) {
            $titulo = strtoupper(trim((string) data_get($item, 'titulo', '')));
            $servicio = strtoupper(trim((string) data_get($item, 'nombre_servicio', '')));

            return str_contains($titulo, 'ADMISION EMS') || str_contains($servicio, 'EMS');
        });
    });
    $ventanilla = $isAdmisionesEms
        ? 'Admisiones'
        : ($sucursal ? ('Punto ' . trim((string) ($sucursal->puntoVenta ?? ''))) : '');
    if (!empty($forceVentanilla ?? '')) {
        $ventanilla = (string) $forceVentanilla;
    }
    $fechaRecaudacion = $filters['from'] && $filters['to']
        ? ($filters['from'] === $filters['to'] ? $filters['from'] : ($filters['from'] . ' al ' . $filters['to']))
        : ($filters['from'] ?: ($filters['to'] ?: $generatedAt->format('Y-m-d')));
    $observacionFiltros = collect([
        $filters['q'] !== '' ? 'Busqueda: ' . $filters['q'] : null,
        $filters['estado'] !== 'all' ? 'Estado: ' . strtoupper($filters['estado']) : null,
        $filters['estado_emision'] !== 'all' ? 'Emision: ' . $filters['estado_emision'] : null,
    ])->filter()->implode(' | ');
@endphp

@if($headerImage)
    <div style="margin-bottom: 8px;">
        <img src="{{ $headerImage }}" class="header-banner" alt="Encabezado Contratos">
    </div>
@endif

<table class="no-border" style="margin-bottom: 8px;">
    <tr>
        <td style="width: 55%;">
            <div class="label-box">KARDEX DIARIO DE RENDICION</div>
        </td>
        <td style="width: 45%;">
            <table class="side-box">
                <tr><td>Direccion de Operaciones</td></tr>
                <tr><td>Distribucion</td></tr>
                <tr><td>Kardex 2</td></tr>
            </table>
        </td>
    </tr>
</table>

<table class="meta" style="margin-bottom: 10px;">
    <tr>
        <td class="field">Oficina Postal:</td>
        <td class="value">{{ $oficinaPostal !== '' ? $oficinaPostal : '-' }}</td>
        <td class="field">Nombre Responsable:</td>
        <td class="value">{{ $user->name }}</td>
    </tr>
    <tr>
        <td class="field">Ventanilla:</td>
        <td class="value">{{ $ventanilla !== '' ? $ventanilla : '-' }}</td>
        <td class="field">Fecha de recaudacion:</td>
        <td class="value">{{ $fechaRecaudacion }}</td>
    </tr>
</table>

<table class="grid">
    <thead>
        <tr>
            <th style="width: 4%;">N°</th>
            <th style="width: 10%;">FECHA</th>
            <th style="width: 13%;">ORIGEN</th>
            <th style="width: 13%;">TIPO DE ENVIO</th>
            <th style="width: 18%;">CODIGO DE ITEM</th>
            <th style="width: 11%;">PESO DE ENVIO</th>
            <th style="width: 8%;">CANTIDAD</th>
            <th style="width: 12%;">N° FACTURA</th>
            <th style="width: 11%;">IMPORTE</th>
        </tr>
    </thead>
    <tbody>
        @forelse($rows as $index => $row)
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td class="center">{{ $row['fecha'] }}</td>
                <td>{{ $row['origen'] }}</td>
                <td>{{ $row['tipo_envio'] }}</td>
                <td>{{ $row['codigo_item'] }}</td>
                <td class="right">{{ number_format((float) $row['peso'], 3) }}</td>
                <td class="center">{{ $row['cantidad'] }}</td>
                <td class="center">{{ $row['numero_factura'] }}</td>
                <td class="right">{{ number_format((float) $row['importe_general'], 2) }}</td>
            </tr>
        @empty
            @for($i = 0; $i < 14; $i++)
                <tr>
                    <td>&nbsp;</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor
        @endforelse
        @if($rows->count() < 14)
            @for($i = $rows->count(); $i < 14; $i++)
                <tr>
                    <td class="center">{{ $i + 1 }}</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
            @endfor
        @endif
    </tbody>
</table>

<table class="totals" style="margin-top: 0;">
    <tr>
        <td style="width: 89%;" class="right">TOTAL PARCIAL</td>
        <td style="width: 11%;" class="right">Bs {{ number_format((float) $totals['parcial'], 2) }}</td>
    </tr>
    <tr>
        <td class="right">TOTAL GENERAL</td>
        <td class="right">Bs {{ number_format((float) $totals['general'], 2) }}</td>
    </tr>
</table>

<table class="observaciones" style="margin-top: 14px;">
    <tr>
        <td class="obs-label">Observaciones:</td>
        <td class="obs-lines">
            <table class="obs-grid">
                <tr>
                    <td>{{ $observacionFiltros !== '' ? $observacionFiltros : '' }}</td>
                    <td></td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
