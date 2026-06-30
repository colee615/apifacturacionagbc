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
    $rowsCollection = $rows instanceof \Illuminate\Support\Collection ? $rows->values() : collect($rows);
    $sampleRows = collect([
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'LA PAZ', 'tipo_envio' => 'EMS NACIONAL', 'codigo_item' => 'EN100000001BO', 'peso' => 0.250, 'cantidad' => 1, 'numero_factura' => '1001', 'importe_general' => 18.50],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'COCHABAMBA', 'tipo_envio' => 'CERTIFICADO', 'codigo_item' => 'EN100000002BO', 'peso' => 0.180, 'cantidad' => 1, 'numero_factura' => '1002', 'importe_general' => 12.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'SANTA CRUZ', 'tipo_envio' => 'ENCOMIENDA', 'codigo_item' => 'CP100000003BO', 'peso' => 1.200, 'cantidad' => 1, 'numero_factura' => '1003', 'importe_general' => 35.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'ORURO', 'tipo_envio' => 'EXPRESO', 'codigo_item' => 'EN100000004BO', 'peso' => 0.500, 'cantidad' => 1, 'numero_factura' => '1004', 'importe_general' => 22.50],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'POTOSI', 'tipo_envio' => 'CERTIFICADO', 'codigo_item' => 'EN100000005BO', 'peso' => 0.220, 'cantidad' => 1, 'numero_factura' => '1005', 'importe_general' => 13.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'TARIJA', 'tipo_envio' => 'EMS NACIONAL', 'codigo_item' => 'EN100000006BO', 'peso' => 0.310, 'cantidad' => 1, 'numero_factura' => '1006', 'importe_general' => 19.50],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'SUCRE', 'tipo_envio' => 'ENCOMIENDA', 'codigo_item' => 'CP100000007BO', 'peso' => 0.950, 'cantidad' => 1, 'numero_factura' => '1007', 'importe_general' => 28.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'BENI', 'tipo_envio' => 'EXPRESO', 'codigo_item' => 'EN100000008BO', 'peso' => 0.430, 'cantidad' => 1, 'numero_factura' => '1008', 'importe_general' => 24.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'PANDO', 'tipo_envio' => 'CERTIFICADO', 'codigo_item' => 'EN100000009BO', 'peso' => 0.150, 'cantidad' => 1, 'numero_factura' => '1009', 'importe_general' => 11.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'LA PAZ', 'tipo_envio' => 'EMS NACIONAL', 'codigo_item' => 'EN100000010BO', 'peso' => 0.275, 'cantidad' => 1, 'numero_factura' => '1010', 'importe_general' => 18.50],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'COCHABAMBA', 'tipo_envio' => 'EXPRESO', 'codigo_item' => 'EN100000011BO', 'peso' => 0.620, 'cantidad' => 1, 'numero_factura' => '1011', 'importe_general' => 26.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'SANTA CRUZ', 'tipo_envio' => 'ENCOMIENDA', 'codigo_item' => 'CP100000012BO', 'peso' => 1.450, 'cantidad' => 1, 'numero_factura' => '1012', 'importe_general' => 39.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'ORURO', 'tipo_envio' => 'CERTIFICADO', 'codigo_item' => 'EN100000013BO', 'peso' => 0.210, 'cantidad' => 1, 'numero_factura' => '1013', 'importe_general' => 12.00],
        ['fecha' => $generatedAt->format('d/m/Y'), 'origen' => 'TARIJA', 'tipo_envio' => 'EMS NACIONAL', 'codigo_item' => 'EN100000014BO', 'peso' => 0.330, 'cantidad' => 1, 'numero_factura' => '1014', 'importe_general' => 20.00],
    ]);
    $displayRows = $rowsCollection->isEmpty()
        ? $sampleRows
        : $rowsCollection->take(14)->concat($sampleRows)->take(14)->values();
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
        @foreach($displayRows as $index => $row)
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
        @endforeach
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
