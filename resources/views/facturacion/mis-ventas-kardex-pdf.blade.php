<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Kardex Diario de Rendicion</title>
    <style>
        @page {
            margin: 14px 14px 18px 14px;
        }

        body {
            font-family: Verdana, DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #1f2937;
            margin: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .report-shell {
            width: 100%;
        }

        .report-title {
            margin: 10px 0 3px;
            text-align: center;
            font-size: 19px;
            font-weight: 700;
            color: #1d3360;
        }

        .report-subtitle {
            margin: 0 0 10px;
            text-align: center;
            font-size: 10px;
            color: #6b7280;
        }

        .header-wrap {
            width: 100%;
            margin-bottom: 10px;
        }

        .header-wrap td {
            border: 0;
            padding: 0;
            vertical-align: middle;
        }

        .header-logo-left {
            width: 82mm;
            height: auto;
            display: block;
        }

        .header-logo-right {
            width: 46mm;
            height: auto;
            display: block;
            margin-left: auto;
        }

        .meta td {
            border: 1px solid #6b7280;
            padding: 7px 9px;
            font-size: 10px;
            vertical-align: top;
        }

        .meta .field {
            width: 18%;
            font-weight: 700;
            background: #f8fafc;
            color: #111827;
        }

        .meta .value {
            width: 32%;
        }

        .section-title {
            margin: 12px 0 6px;
            padding: 7px 9px;
            border: 1px solid #6b7280;
            background: #f3f4f6;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            color: #111827;
        }

        .grid th,
        .grid td {
            border: 1px solid #6b7280;
            padding: 5px 6px;
        }

        .grid th {
            text-align: center;
            font-weight: 700;
            font-size: 9.4px;
            background: #f8fafc;
            color: #111827;
        }

        .grid td {
            font-size: 8.9px;
            line-height: 1.35;
            vertical-align: top;
        }

        .center {
            text-align: center;
        }

        .right {
            text-align: right;
        }

        .totals td {
            border: 1px solid #6b7280;
            padding: 6px 8px;
            font-size: 10px;
            font-weight: 700;
        }

        .observaciones td {
            border: 1px solid #6b7280;
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

        .page-break {
            page-break-before: always;
        }

        .cashier-meta td {
            border: 1px solid #6b7280;
            padding: 6px 8px;
            font-size: 9.5px;
            vertical-align: middle;
        }

        .cashier-name {
            font-weight: 700;
            font-size: 11px;
            color: #1d3360;
        }

        .cashier-muted {
            color: #6b7280;
            font-size: 9px;
        }

        .summary-grid td {
            border: 1px solid #6b7280;
            padding: 7px 8px;
            font-size: 10px;
        }

        .summary-label {
            font-weight: 700;
            text-transform: uppercase;
            background: #f3f4f6;
            color: #111827;
        }

        .summary-value-strong {
            font-weight: 700;
            color: #1d3360;
        }
    </style>
</head>
<body>
@php
    $scope = $scope ?? 'own';
    $resolveEmbeddedImage = function (array $candidates) {
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            if (! file_exists($candidate)) {
                continue;
            }

            $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
            $mime = match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'svg' => 'image/svg+xml',
                default => 'image/png',
            };

            return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($candidate));
        }

        return null;
    };

    $frontendStaticPath = 'C:/xampp/htdocs/Git proyectos/frontfacturacionagbc/static/assets/imagenes';
    $ministerioLogo = $resolveEmbeddedImage([
        public_path('assets/imagenes/MOPSV.png'),
        public_path('images/MOPSV.png'),
        $frontendStaticPath . '/MOPSV.png',
    ]);
    $correosLogo = $resolveEmbeddedImage([
        public_path('assets/imagenes/AGBClogo1.png'),
        public_path('images/AGBClogo1.png'),
        $frontendStaticPath . '/AGBClogo1.png',
    ]);
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
    $fechaRecaudacion = $filters['from'] && $filters['to']
        ? ($filters['from'] === $filters['to'] ? $filters['from'] : ($filters['from'] . ' al ' . $filters['to']))
        : ($filters['from'] ?: ($filters['to'] ?: $generatedAt->format('Y-m-d')));
    $observacionFiltros = collect([
        $filters['q'] !== '' ? 'Busqueda: ' . $filters['q'] : null,
        $filters['estado'] !== 'all' ? 'Estado: ' . strtoupper($filters['estado']) : null,
        $filters['estado_emision'] !== 'all' ? 'Emision: ' . $filters['estado_emision'] : null,
    ])->filter()->implode(' | ');
    $sectionConfigs = [
        'factura_electronica' => [
            'title' => 'Facturacion electronica',
            'total_label' => 'TOTAL FACTURACION ELECTRONICA EN CAJA',
        ],
        'qr_facturado' => [
            'title' => 'Pagos QR facturados',
            'total_label' => 'TOTAL QR FACTURADO REFERENCIAL NO SUMADO A CAJA',
        ],
        'qr_pagado_pendiente_factura' => [
            'title' => 'Pagos QR confirmados pendientes de factura',
            'total_label' => 'TOTAL QR PAGADO PENDIENTE DE FACTURA',
        ],
        'qr_pendiente' => [
            'title' => 'Pagos QR pendientes',
            'total_label' => 'TOTAL QR PENDIENTE',
        ],
        'qr_cancelado' => [
            'title' => 'Pagos QR no concretados',
            'total_label' => 'TOTAL QR CANCELADO / NO PAGADO',
        ],
        'oficial' => [
            'title' => 'Envios oficiales',
            'total_label' => 'TOTAL ENVIOS OFICIALES',
        ],
    ];
    $rowsByChannel = collect($rows)->groupBy(fn ($row) => (string) data_get($row, 'section_key', strtolower((string) data_get($row, 'canal_emision', 'factura_electronica'))));
    $paidSectionKeys = ['factura_electronica', 'qr_facturado', 'qr_pagado_pendiente_factura', 'oficial'];
    $cashSectionKeys = ['factura_electronica', 'oficial'];
    $unpaidSectionKeys = ['qr_pendiente', 'qr_cancelado'];
    $hasPaidRows = collect($paidSectionKeys)->contains(fn ($key) => $rowsByChannel->get($key, collect())->isNotEmpty());
    $hasUnpaidRows = collect($unpaidSectionKeys)->contains(fn ($key) => $rowsByChannel->get($key, collect())->isNotEmpty());
    $paidRows = collect($rows)->filter(fn ($row) => in_array((string) data_get($row, 'section_key', ''), $paidSectionKeys, true))->values();
    $cashRows = collect($rows)->filter(fn ($row) => in_array((string) data_get($row, 'section_key', ''), $cashSectionKeys, true))->values();
    $unpaidRows = collect($rows)->filter(fn ($row) => in_array((string) data_get($row, 'section_key', ''), $unpaidSectionKeys, true))->values();
    $branchGroups = collect($rows)
        ->groupBy(fn ($row) => trim((string) data_get($row, 'origen_usuario_id', data_get($row, 'origen_usuario_email', 'sin-usuario'))))
        ->map(function ($groupRows) {
            $groupRows = collect($groupRows)
                ->sortBy(fn ($row) => (int) data_get($row, 'fecha_sort', 0))
                ->values();
            $first = $groupRows->first();

            return [
                'usuario_id' => trim((string) data_get($first, 'origen_usuario_id', '')),
                'nombre' => trim((string) data_get($first, 'origen_usuario_nombre', 'Sin usuario')),
                'email' => trim((string) data_get($first, 'origen_usuario_email', '')),
                'rows' => $groupRows,
                'ventas' => $groupRows->count(),
                'cobradas' => $groupRows->filter(fn ($row) => (bool) data_get($row, 'cobrada', false))->count(),
                'pendientes' => $groupRows->filter(fn ($row) => ! (bool) data_get($row, 'cobrada', false))->count(),
                'total' => round((float) $groupRows->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2),
                'total_caja' => round((float) $groupRows
                    ->filter(fn ($row) => (bool) data_get($row, 'contabiliza_en_caja', false))
                    ->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2),
            ];
        })
        ->sortByDesc('total')
        ->values();
@endphp

<div class="report-shell">
@if($ministerioLogo || $correosLogo)
    <table class="header-wrap">
        <tr>
            <td style="width: 68%;">
                @if($ministerioLogo)
                    <img src="{{ $ministerioLogo }}" class="header-logo-left" alt="Ministerio de Obras Publicas">
                @endif
            </td>
            <td style="width: 32%; text-align: right;">
                @if($correosLogo)
                    <img src="{{ $correosLogo }}" class="header-logo-right" alt="Correos de Bolivia">
                @endif
            </td>
        </tr>
    </table>
@endif

<div class="report-title">{{ $scope === 'branch' ? 'KARDEX DE SUCURSAL' : 'KARDEX DE VENTAS COBRADAS' }}</div>
<div class="report-subtitle">
    {{ $scope === 'branch' ? 'Resumen detallado por cajero y ventas del rango seleccionado' : 'Detalle consolidado de ventas del rango seleccionado' }}
</div>

<table class="meta" style="margin-bottom: 10px;">
    <tr>
        <td class="field">Oficina Postal:</td>
        <td class="value">{{ $oficinaPostal !== '' ? $oficinaPostal : '-' }}</td>
        <td class="field">{{ $scope === 'branch' ? 'Encargado sucursal:' : 'Nombre Responsable:' }}</td>
        <td class="value">{{ $user->name }}</td>
    </tr>
    <tr>
        <td class="field">Ventanilla:</td>
        <td class="value">{{ $ventanilla !== '' ? $ventanilla : '-' }}</td>
        <td class="field">Fecha de recaudacion:</td>
        <td class="value">{{ $fechaRecaudacion }}</td>
    </tr>
</table>

@if($scope === 'branch')
    <div class="section-title">Kardex agrupado por cajero</div>
    <table class="summary-grid" style="margin-bottom: 10px;">
        <tr>
            <td class="summary-label" style="width: 25%;">Cajeros con ventas</td>
            <td class="summary-value-strong" style="width: 25%;">{{ $branchGroups->count() }}</td>
            <td class="summary-label" style="width: 25%;">Total ventas</td>
            <td class="summary-value-strong" style="width: 25%;">{{ collect($rows)->count() }}</td>
        </tr>
        <tr>
            <td class="summary-label">Total en caja</td>
            <td class="summary-value-strong">Bs {{ number_format((float) $cashRows->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
            <td class="summary-label">Total emitido</td>
            <td class="summary-value-strong">Bs {{ number_format((float) collect($rows)->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
        </tr>
    </table>

    @forelse($branchGroups as $cashierIndex => $group)
        <div class="section-title">Cajero {{ $cashierIndex + 1 }}: {{ $group['nombre'] }}</div>
        <table class="cashier-meta" style="margin-bottom: 6px;">
            <tr>
                <td style="width: 38%;">
                    <div class="cashier-name">{{ $group['nombre'] }}</div>
                    <div class="cashier-muted">{{ $group['email'] !== '' ? $group['email'] : 'Sin correo registrado' }}</div>
                </td>
                <td style="width: 14%;" class="center"><strong>{{ $group['ventas'] }}</strong><br>ventas</td>
                <td style="width: 14%;" class="center"><strong>{{ $group['cobradas'] }}</strong><br>cobradas</td>
                <td style="width: 14%;" class="center"><strong>{{ $group['pendientes'] }}</strong><br>pendientes</td>
                <td style="width: 20%;" class="right"><strong>Bs {{ number_format((float) $group['total_caja'], 2) }}</strong><br>total en caja</td>
            </tr>
        </table>

        <table class="grid" style="margin-bottom: 10px;">
            <thead>
                <tr>
                    <th style="width: 4%;">Nro.</th>
                    <th style="width: 12%;">Fecha</th>
                    <th style="width: 45%;">Detalle</th>
                    <th style="width: 25%;">Paquete / codigos</th>
                    <th style="width: 8%;">Factura</th>
                    <th style="width: 6%;">Estado</th>
                    <th style="width: 10%;">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach($group['rows'] as $index => $row)
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td class="center">{{ data_get($row, 'fecha_hora', data_get($row, 'fecha', '-')) }}</td>
                        <td>
                            <strong>{{ data_get($row, 'detalle_items', data_get($row, 'tipo_envio', 'Sin detalle')) }}</strong>
                            @if((string) data_get($row, 'codigo_item', '') !== '')
                                <br>{{ data_get($row, 'codigo_item') }}
                            @endif
                            @if((string) data_get($row, 'cliente', '') !== '')
                                <br>{{ data_get($row, 'cliente') }}
                            @endif
                            @if((string) data_get($row, 'detalle_resumen', '') !== '')
                                <br>{{ data_get($row, 'detalle_resumen') }}
                            @endif
                        </td>
                        <td style="white-space: pre-line;">{{ data_get($row, 'codigo_referencia', '-') }}</td>
                        <td class="center">{{ data_get($row, 'numero_factura', '-') }}</td>
                        <td class="center">{{ (bool) data_get($row, 'cobrada', false) ? 'Cobrada' : 'Pend.' }}</td>
                        <td class="right">{{ number_format((float) data_get($row, 'importe_general', 0), 2) }}</td>
                    </tr>
                @endforeach
                <tr>
                    <td colspan="6" class="right" style="font-weight: 700;">SUBTOTAL {{ strtoupper($group['nombre']) }}</td>
                    <td class="right" style="font-weight: 700;">Bs {{ number_format((float) $group['total'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    @empty
        <table class="grid">
            <tbody>
                <tr>
                    <td class="center" style="padding: 12px;">No se encontraron ventas de sucursal con los filtros aplicados.</td>
                </tr>
            </tbody>
        </table>
    @endforelse

    <table class="totals" style="margin-top: 4px;">
        <tr>
            <td style="width: 89%;" class="right">TOTAL EN CAJA SUCURSAL</td>
            <td style="width: 11%;" class="right">Bs {{ number_format((float) $cashRows->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
        </tr>
        <tr>
            <td class="right">TOTAL PENDIENTE / NO COBRADO</td>
            <td class="right">Bs {{ number_format((float) $unpaidRows->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
        </tr>
        <tr>
            <td class="right">TOTAL GENERAL SUCURSAL</td>
            <td class="right">Bs {{ number_format((float) collect($rows)->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
        </tr>
    </table>
@else
    <div class="section-title">Kardex de ventas cobradas</div>
    @if($hasPaidRows)
        @foreach($paidSectionKeys as $channelKey)
            @php($sectionRows = $rowsByChannel->get($channelKey, collect())->values())
            @continue($sectionRows->isEmpty())
            @include('facturacion.partials.kardex-section-table', ['section' => $sectionConfigs[$channelKey], 'sectionRows' => $sectionRows])
        @endforeach
    @else
        <table class="grid">
            <tbody>
                <tr>
                    <td class="center" style="padding: 12px;">No se encontraron ventas cobradas con los filtros aplicados.</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table class="totals" style="margin-top: 0;">
        <tr>
            <td style="width: 89%;" class="right">TOTAL PARCIAL EN CAJA</td>
            <td style="width: 11%;" class="right">Bs {{ number_format((float) $cashRows->sum(fn ($row) => (float) data_get($row, 'importe_parcial', 0)), 2) }}</td>
        </tr>
        <tr>
            <td class="right">TOTAL GENERAL EN CAJA</td>
            <td class="right">Bs {{ number_format((float) $cashRows->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
        </tr>
        <tr>
            <td class="right">TOTAL QR REFERENCIAL NO SUMADO A CAJA</td>
            <td class="right">Bs {{ number_format((float) $paidRows->filter(fn ($row) => strtolower((string) data_get($row, 'metodo_pago', '')) === 'qr')->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
        </tr>
    </table>

    <div class="page-break"></div>
    <div class="section-title">Kardex de ventas no cobradas</div>
    @if($hasUnpaidRows)
        @foreach($unpaidSectionKeys as $channelKey)
            @php($sectionRows = $rowsByChannel->get($channelKey, collect())->values())
            @continue($sectionRows->isEmpty())
            @include('facturacion.partials.kardex-section-table', ['section' => $sectionConfigs[$channelKey], 'sectionRows' => $sectionRows])
        @endforeach
    @else
        <table class="grid">
            <tbody>
                <tr>
                    <td class="center" style="padding: 12px;">No se encontraron ventas no cobradas con los filtros aplicados.</td>
                </tr>
            </tbody>
        </table>
    @endif

    <table class="totals" style="margin-top: 0;">
        <tr>
            <td style="width: 89%;" class="right">TOTAL VENTAS NO COBRADAS</td>
            <td style="width: 11%;" class="right">Bs {{ number_format((float) $unpaidRows->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
        </tr>
    </table>
@endif

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
</div>
</body>
</html>
