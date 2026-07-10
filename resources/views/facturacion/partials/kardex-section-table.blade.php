<div class="section-title">{{ $section['title'] }}</div>
<table class="grid">
    <thead>
        <tr>
            <th style="width: 4%;">Nro.</th>
            <th style="width: 10%;">Fecha</th>
            <th style="width: 16%;">Tipo de venta / envio</th>
            <th style="width: 12%;">Emision</th>
            <th style="width: 12%;">Cobro</th>
            <th style="width: 18%;">Orden / paquetes</th>
            <th style="width: 11%;">Peso total</th>
            <th style="width: 8%;">Cantidad</th>
            <th style="width: 10%;">Nro. factura</th>
            <th style="width: 9%;">Importe</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sectionRows as $index => $row)
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td class="center">{{ $row['fecha'] }}</td>
                <td>{{ $row['tipo_envio'] }}</td>
                <td>{{ $row['emision_label'] }}</td>
                <td style="white-space: pre-line;">{{ $row['cobro_label'] }}{{ !empty($row['cobro_detalle']) ? "\n" . $row['cobro_detalle'] : '' }}</td>
                <td style="white-space: pre-line;">{{ $row['codigo_referencia'] ?? $row['codigo_item'] }}</td>
                <td class="right">{{ number_format((float) $row['peso'], 3) }}</td>
                <td class="center">{{ $row['cantidad'] }}</td>
                <td class="center">{{ $row['numero_factura'] }}</td>
                <td class="right">{{ number_format((float) $row['importe_general'], 2) }}</td>
            </tr>
        @endforeach
        <tr>
            <td colspan="9" class="right" style="font-weight: 700;">{{ $section['total_label'] }}</td>
            <td class="right" style="font-weight: 700;">Bs {{ number_format((float) $sectionRows->sum(fn ($row) => (float) data_get($row, 'importe_general', 0)), 2) }}</td>
        </tr>
    </tbody>
</table>
