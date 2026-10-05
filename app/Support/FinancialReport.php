<?php

namespace App\Support;

final class FinancialReport
{
    public static function emptyTotals(): array
    {
        return array_fill_keys(['totalVendido', 'totalQrFacturado', 'totalEfectivoFacturado',
            'totalFacturasAnuladas', 'totalQrCancelado', 'totalQrPendiente',
            'totalQrPagadoPendienteFactura', 'totalEcaFacturado', 'totalContratosNoSumados',
            'totalOficial', 'totalNoIncluido', 'totalRegistros', 'diferenciaCabeceraDetalle'], 0.0)
            + array_fill_keys(['cantidadRegistros', 'facturadas', 'qrFacturadas', 'electronicasFacturadas',
                'facturasAnuladas', 'qrCancelado', 'qrPendiente', 'qrPagadoPendienteFactura',
                'ecaFacturadas', 'contratosNoSumados', 'oficiales', 'observadas'], 0);
    }

    public static function branchKey(array $sale): string
    {
        return (int) data_get($sale, 'sucursal.codigoSucursal', $sale['codigoSucursal'] ?? 0).'-'.(int) data_get($sale, 'sucursal.puntoVenta', $sale['puntoVenta'] ?? 0);
    }

    public static function totals(iterable $sales): array
    {
        return self::build($sales, 0)['resumen'];
    }

    public static function build(iterable $sales, int $issueLimit = 500): array
    {
        $summary = self::emptyTotals();
        $branches = $users = $days = $issues = $activeCufs = $activePayments = $paidQr = $canceledPayments = [];
        $issueCount = 0;
        $paidQrCents = 0;
        foreach ($sales as $sale) {
            $f = FinancialSale::classify($sale);
            $key = self::branchKey($sale);
            $branch = (array) ($sale['sucursal'] ?? []);
            $branches[$key] ??= self::emptyTotals() + ['codigoSucursal'=>(int) ($branch['codigoSucursal'] ?? 0), 'puntoVenta'=>(int) ($branch['puntoVenta'] ?? 0), 'nombre'=>$branch['departamento'] ?? $branch['nombre'] ?? $key];
            $userKey = $key.'|'.(string) data_get($sale,'usuario.id','sin-usuario');
            $users[$userKey] ??= self::emptyTotals() + ['usuario'=>$sale['usuario']??null, 'sucursal'=>$branch];
            $day = substr((string) ($sale['fecha'] ?? ''), 0, 10);
            $days[$day] ??= self::emptyTotals() + ['fecha'=>$day];
            $delta = self::emptyTotals();
            $amount = $f['importeCentavos'] /100.0;
            $delta['cantidadRegistros'] = 1;
            $delta['totalRegistros'] = $amount;
            if ($f['incluidaEnTotalVendido']) {
                $delta['totalVendido'] = $amount;
                $delta['facturadas'] = 1;
                $delta[$f['medioPago']==='QR' ? 'totalQrFacturado' : 'totalEfectivoFacturado'] = $amount;
                $delta[$f['medioPago']==='QR' ? 'qrFacturadas' : 'electronicasFacturadas'] = 1;
            } else {
                $delta['totalNoIncluido'] = $amount;
            }
            if ($f['anulada']) {
                $delta['totalFacturasAnuladas'] = $amount;
                $delta['facturasAnuladas'] = 1;
            } elseif ($f['facturaVigente'] || $f['categoria']==='OFICIAL') {
                $field = ['CONTRATO'=>'totalContratosNoSumados','ECA'=>'totalEcaFacturado','OFICIAL'=>'totalOficial'][$f['categoria']] ?? null;
                if ($field) {
                    $delta[$field] = $amount;
                    $delta[['CONTRATO'=>'contratosNoSumados','ECA'=>'ecaFacturadas','OFICIAL'=>'oficiales'][$f['categoria']]] = 1;
                }
            }
            $paymentKey = $key.'|'.($sale['qr_transaction_id'] ?? $sale['cartId'] ?? $sale['origenVentaId'] ?? $sale['id'] ?? '');
            if ($f['medioPago']==='QR') {
                if ($f['pagoCancelado'] && !isset($canceledPayments[$paymentKey])) {
                    $delta['totalQrCancelado']=$amount; $delta['qrCancelado']=1; $canceledPayments[$paymentKey]=true;
                }
                elseif (!$f['pagoCancelado'] && !$f['anulada'] && !$f['pagoConfirmado']) { $delta['totalQrPendiente']=$amount; $delta['qrPendiente']=1; }
                elseif (!$f['pagoCancelado'] && !$f['anulada'] && !$f['facturaVigente']) { $delta['totalQrPagadoPendienteFactura']=$amount; $delta['qrPagadoPendienteFactura']=1; }
            }
            if (!$f['facturaVigente'] && !$f['anulada'] && $f['categoria']!=='OFICIAL') $delta['observadas']=1;
            $detailDifference = $f['importeCentavos'] - FinancialSale::detailCents($sale);
            $delta['diferenciaCabeceraDetalle']=$detailDifference/100.0;
            foreach ($delta as $field=>$value) {
                foreach ([&$summary, &$branches[$key], &$users[$userKey], &$days[$day]] as &$bucket) {
                    $bucket[$field] = is_float($value) ? (FinancialSale::cents($bucket[$field]) + FinancialSale::cents($value))/100.0 : $bucket[$field]+$value;
                }
                unset($bucket);
            }
            $reasons=[];
            if ($detailDifference!==0) $reasons[]='DIFERENCIA_CABECERA_DETALLE';
            if (empty($sale['detalle'])) $reasons[]='SIN_DETALLE';
            if ($f['pagoCancelado'] && $f['facturaVigente']) $reasons[]='FACTURA_VIGENTE_PAGO_CANCELADO';
            if ($f['motivoExclusion']==='PAGO_SIN_EVIDENCIA') $reasons[]='PAGO_SIN_EVIDENCIA';
            if ($f['motivoExclusion']==='PAGO_PENDIENTE' && $f['facturaVigente']) $reasons[]='FACTURA_VIGENTE_PAGO_PENDIENTE';
            if ($f['pagoConfirmado'] && !$f['facturaVigente'] && !$f['anulada'] && $f['categoria']==='NORMAL') $reasons[]='COBRO_SIN_FACTURA_VIGENTE';
            $cuf=trim((string)($sale['cuf']??''));
            $payment=trim((string)($sale['qr_transaction_id']??''));
            if ($f['facturaVigente']) {
                if ($cuf!=='' && isset($activeCufs[$cuf])) $reasons[]='CUF_VIGENTE_DUPLICADO';
                if ($cuf!=='') $activeCufs[$cuf]=true;
                if ($f['medioPago']==='QR' && $payment!=='') {
                    if (isset($activePayments[$payment])) $reasons[]='QR_COMPARTIDO_ENTRE_FACTURAS_VIGENTES';
                    $activePayments[$payment]=true;
                }
            }
            if ($payment!=='' && $f['medioPago']==='QR' && $f['pagoConfirmado'] && !isset($paidQr[$payment])) {
                $paidQr[$payment]=['ventaId'=>$sale['id']??null,'cartId'=>$sale['cartId']??$sale['origenVentaId']??null,
                    'fecha'=>$sale['fecha']??null,'numeroFactura'=>$sale['numeroFactura']??null,'codigoOrden'=>$sale['codigoOrden']??null,
                    'sucursal'=>$branch,'total'=>$amount,'diferenciaDetalle'=>$detailDifference/100.0,'motivos'=>['QR_PAGADO_SOLO_FACTURAS_ANULADAS'],'financiero'=>$f];
                $paidQrCents+=$f['importeCentavos'];
            }
            if ($reasons!==[]) {
                $issueCount++;
                if (count($issues)<$issueLimit) $issues[]=['ventaId'=>$sale['id']??null,'cartId'=>$sale['cartId']??$sale['origenVentaId']??null,
                    'fecha'=>$sale['fecha']??null,'numeroFactura'=>$sale['numeroFactura']??null,'codigoOrden'=>$sale['codigoOrden']??null,
                    'sucursal'=>$branch,'total'=>$amount,'diferenciaDetalle'=>$detailDifference/100.0,'motivos'=>$reasons,'financiero'=>$f];
            }
        }
        foreach ($paidQr as $payment => $row) {
            if (!isset($activePayments[$payment]) && $row['financiero']['anulada']) {
                $issueCount++;
                if (count($issues)<$issueLimit) $issues[]=$row;
            }
        }
        $summary['diferenciaMediosPago']=(FinancialSale::cents($summary['totalVendido'])-FinancialSale::cents($summary['totalQrFacturado'])-FinancialSale::cents($summary['totalEfectivoFacturado']))/100.0;
        $summary['cantidadVentas']=$summary['facturadas'];
        ksort($branches); ksort($days);
        return ['version'=>FinancialSale::VERSION,'resumen'=>$summary,'sucursales'=>array_values($branches),'porUsuarios'=>array_values($users),'porFecha'=>array_values($days),
            'pagosQr'=>['cantidadTransaccionesIdentificadas'=>count($paidQr),'importeVinculadoSinDuplicar'=>$paidQrCents/100.0,
                'alcance'=>'Importes asociados en la base local; requiere contraste con extracto bancario. No representa devoluciones.'],
            'incidencias'=>$issues,'meta'=>['cantidadIncidencias'=>$issueCount,'incidenciasTruncadas'=>$issueCount>count($issues),'limiteIncidencias'=>$issueLimit,
                'criterio'=>'Total vendido = efectivo facturado + QR facturado con pago confirmado. Anuladas y pagos cancelados no suman. ECA, contratos y oficiales se informan por separado.']];
    }
}
