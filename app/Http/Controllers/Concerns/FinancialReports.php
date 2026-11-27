<?php

namespace App\Http\Controllers\Concerns;

use App\Support\FinancialReport;
use App\Support\FinancialSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait FinancialReports
{
    public function financialReportRows(array $filters): \Generator
    {
        $ownSnapshot = DB::transactionLevel() === 0 && DB::connection()->getDriverName() === 'pgsql';
        if ($ownSnapshot) {
            DB::beginTransaction();
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
        }
        try {
            foreach ($this->mergedServiceReportVentaStream($filters, true, true) as $row) {
                $financial = FinancialSale::classify($row);
                $row['financiero'] = $financial;
                $row['medioPago'] = $financial['medioPago'];
                if ($financial['medioPago'] === 'TARJETA NFC') {
                    // The persisted `qr` value identifies the Qhantuy checkout
                    // channel. Reports must expose the actual payment instrument.
                    $row['metodo_pago'] = 'tarjeta';
                }
                yield $row;
            }
        } finally {
            if ($ownSnapshot) DB::rollBack();
        }
    }

    public function financialReportSnapshot(array $filters, int $issueLimit = 500, bool $includeInvoiceDetails = false): array
    {
        return FinancialReport::build($this->financialReportRows($filters), $issueLimit, $includeInvoiceDetails);
    }

    public function reporteFacturas(Request $request)
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $facturas = [];

        foreach ($this->financialReportRows($filters) as $row) {
            $sucursal = (array) ($row['sucursal'] ?? []);
            $financiero = (array) ($row['financiero'] ?? []);

            $facturas[] = [
                'codigoSucursal' => (int) ($sucursal['codigoSucursal'] ?? $row['codigoSucursal'] ?? 0),
                'puntoVenta' => (int) ($sucursal['puntoVenta'] ?? $row['puntoVenta'] ?? 0),
                'regional' => trim((string) (
                    $sucursal['departamento']
                    ?? $sucursal['nombre']
                    ?? $row['departamento']
                    ?? 'Sin regional'
                )),
                'numeroFactura' => trim((string) ($row['numeroFactura'] ?? $row['numero_factura'] ?? '')) ?: '-',
                'importe' => round((float) ($row['total'] ?? 0), 2),
                'cajera' => trim((string) data_get($row, 'usuario.nombre', '')) ?: 'Sin usuario',
                'medioPago' => trim((string) ($financiero['medioPago'] ?? $row['medioPago'] ?? 'SIN DATO')),
                'estadoFactura' => strtoupper(trim((string) (
                    $financiero['estadoFiscal']
                    ?? $row['estado_emision']
                    ?? $row['estado_sufe']
                    ?? 'SIN ESTADO'
                ))),
                'categoria' => $financiero['categoria'] ?? 'NORMAL',
                'origenVentaTipo' => $row['origenVentaTipo'] ?? $row['origen_venta_tipo'] ?? '',
            ];
        }

        return response()->json([
            'filters' => $filters,
            'cantidad' => count($facturas),
            'facturas' => $facturas,
        ]);
    }

    public function auditoriaFinanciera(Request $request)
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $report = $this->financialReportSnapshot(
            $filters,
            (int) ($filters['limite'] ?? 500),
            $request->boolean('incluirDetalleFacturas')
        );
        return response()->json(['filters'=>$filters,'generadoEn'=>now()->toIso8601String(),
            'alcance'=>'Estado actual de las operaciones registradas en el período. Los cierres históricos y los extractos bancarios se contrastan por separado.'] + $report);
    }

    public function dashboardVentas(Request $request)
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $report = $this->financialReportSnapshot($filters, 0);

        return response()->json([
            'filters' => $filters,
            'generadoEn' => now()->toIso8601String(),
            'resumen' => $report['resumen'],
            'porFecha' => $report['porFecha'],
            'sucursales' => $report['sucursales'],
            'version' => $report['version'],
        ]);
    }
}
