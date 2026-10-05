<!doctype html><html lang="es"><head><meta charset="utf-8"><style>
body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#18334a}h1{font-size:19px}table{width:100%;border-collapse:collapse;margin:16px 0}th,td{padding:5px;border-bottom:1px solid #d7e0e7;text-align:left}th{background:#edf4f6}.amount{text-align:right}tfoot{font-weight:bold}.note{color:#506677}
</style></head><body>
<h1>Auditoría financiera de ventas</h1>
<p>Período: {{ $filters['fechaInicio'] ?? 'Inicio de registros' }} — {{ $filters['fechaFin'] ?? 'Todos los registros' }} · Generado: {{ $generatedAt }} · BOB</p>
<p class="note">{{ $report['meta']['criterio'] }} Estado actual de las facturas del período; no certifica depósitos bancarios.</p>
<table><thead><tr><th>Regional</th><th>Efectivo</th><th>QR facturado y pagado</th><th>Total vendido</th><th>Facturas anuladas (excluido)</th><th>Otros excluidos*</th></tr></thead><tbody>
@foreach($report['sucursales'] as $branch)<tr><td>{{ $branch['nombre'] }}</td>@foreach(['totalEfectivoFacturado','totalQrFacturado','totalVendido','totalFacturasAnuladas'] as $field)<td class="amount">{{ number_format($branch[$field],2,',','.') }}</td>@endforeach<td class="amount">{{ number_format($branch['totalNoIncluido']-$branch['totalFacturasAnuladas'],2,',','.') }}</td></tr>@endforeach
</tbody><tfoot><tr><td>Total</td>@foreach(['totalEfectivoFacturado','totalQrFacturado','totalVendido','totalFacturasAnuladas'] as $field)<td class="amount">{{ number_format($report['resumen'][$field],2,',','.') }}</td>@endforeach<td></td></tr></tfoot></table>
<p class="note">*Contratos, ECA, oficiales, operaciones pendientes u observadas. Los importes excluidos corresponden a registros; una reemisión no representa otro cobro.</p>
<h2>Operaciones ({{ count($rows) }})</h2>
<table><thead><tr><th>Fecha</th><th>Regional / PV</th><th>Factura</th><th>Orden</th><th>Estado fiscal</th><th>Pago</th><th>Importe registro</th><th>Aporta al total</th><th>Motivo exclusión</th></tr></thead><tbody>
@foreach($rows as $row)<tr><td>{{ $row['fecha'] ?? '' }}</td><td>{{ data_get($row,'sucursal.codigoSucursal') }} / {{ data_get($row,'sucursal.puntoVenta') }}</td><td>{{ $row['numeroFactura'] ?? '—' }}</td><td>{{ $row['codigoOrden'] ?? '' }}</td><td>{{ $row['financiero']['estadoFiscal'] }}</td><td>{{ $row['financiero']['medioPago'] }} / {{ $row['financiero']['estadoPago'] }}</td><td class="amount">{{ number_format($row['total'],2,',','.') }}</td><td class="amount">{{ number_format($row['financiero']['totalVendido'],2,',','.') }}</td><td>{{ $row['financiero']['motivoExclusion'] ?? 'Incluida' }}</td></tr>@endforeach
</tbody></table></body></html>
