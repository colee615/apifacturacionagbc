<?php

namespace App\Support;

/** Read-only classification of one fiscal attempt and its payment evidence. */
final class FinancialSale
{
    public const VERSION = '2026-10-05.1';
    public const ACTIVE = ['PROCESADA', 'PROCESADO', 'FACTURADA', 'EMITIDO', 'ANULACION_SOLICITADA', 'ANULACION_OBSERVADA'];
    public const ANNULLED = ['ANULADA', 'ANULADO', 'DESCARTADA'];

    public static function cents($value): int
    {
        // Persisted amounts are decimal strings. Round once at the row boundary.
        $value = trim((string) ($value ?? '0'));
        if (!preg_match('/^(-?)(\d+)(?:\.(\d*))?$/', $value, $m)) {
            throw new \InvalidArgumentException('Importe monetario inválido.');
        }
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $amount = (int) $m[2] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);
        return ($m[1] ?? '') === '-' ? -$amount : $amount;
    }

    public static function fiscalState(array $sale): string
    {
        // The specific invoice wins over shared cart metadata from another attempt.
        foreach (['estadoSufe', 'estado_sufe', 'estadoFiscal', 'respuesta_emision.estadoSufe', 'estado_emision', 'status.key'] as $key) {
            $state = strtoupper(trim((string) data_get($sale, $key, '')));
            if ($state !== '') return $state;
        }
        return !empty($sale['anulada']) ? 'ANULADA' : 'SIN_ESTADO';
    }

    public static function isQr(array $sale): bool
    {
        $method = strtolower(trim((string) ($sale['metodo_pago'] ?? '')));
        $order = strtoupper(trim((string) ($sale['codigoOrden'] ?? '')));
        return (int) ($sale['metodoPago'] ?? 0) === 5 || $method === 'qr'
            || strtoupper((string) ($sale['medioPago'] ?? '')) === 'QR'
            || strtolower((string) ($sale['canal_emision'] ?? '')) === 'qr'
            || str_starts_with($order, 'VQC-') || str_starts_with($order, 'VQ-');
    }

    public static function category(array $sale): string
    {
        if (self::fiscalState($sale) === 'REGISTRADA_OFICIAL') return 'OFICIAL';
        $receivable = filter_var($sale['es_cuenta_por_cobrar'] ?? $sale['esCuentaPorCobrar'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($receivable || strtolower((string) ($sale['canal_operativo'] ?? $sale['canalOperativo'] ?? '')) === 'contrato'
            || trim((string) ($sale['empresa_nombre'] ?? '')) !== '' || trim((string) ($sale['empresa_sigla'] ?? '')) !== '') return 'CONTRATO';
        $eca = false;
        foreach (($sale['detalle'] ?? []) as $item) {
            foreach (['descripcion', 'titulo', 'nombre_servicio', 'servicio', 'nombre', 'resumen_origen.descripcion_servicio'] as $field) {
                $label = mb_strtolower(trim((string) data_get($item, $field, '')));
                if (preg_match('/\bcontratos?\b/u', $label)) return 'CONTRATO';
                if (preg_match('/\beca\b/u', $label)) $eca = true;
            }
        }
        return $eca ? 'ECA' : 'NORMAL';
    }

    public static function classify(array $sale): array
    {
        $state = self::fiscalState($sale);
        $annulled = in_array($state, self::ANNULLED, true);
        $active = in_array($state, self::ACTIVE, true);
        $qr = self::isQr($sale);
        $payment = strtolower(trim((string) ($sale['estado_pago'] ?? $sale['estadoPago'] ?? '')));
        $canceled = in_array($payment, ['cancelado', 'anulado', 'fallido', 'reembolsado', 'revertido'], true);
        $paid = in_array($payment, ['pagado', 'confirmado'], true);
        // Legacy cash invoices have no separate payment entity. Never infer QR payment from a CUF.
        $cashLegacy = !$qr && $payment === '' && $active;
        $category = self::category($sale);
        $reason = $annulled ? 'FACTURA_ANULADA'
            : ($canceled ? 'PAGO_CANCELADO'
            : ($category !== 'NORMAL' ? $category
            : (!$active ? 'SIN_FACTURA_VIGENTE'
            : (!$paid && !$cashLegacy ? ($payment === '' ? 'PAGO_SIN_EVIDENCIA' : 'PAGO_PENDIENTE') : null))));
        $amount = self::cents($sale['total'] ?? 0);
        if ($amount < 0) $reason = 'IMPORTE_NEGATIVO';
        return [
            'version' => self::VERSION, 'estadoFiscal' => $state, 'estadoPago' => $payment ?: 'sin_evidencia',
            'medioPago' => $qr ? 'QR' : 'EFECTIVO', 'categoria' => $category,
            'anulada' => $annulled, 'facturaVigente' => $active, 'pagoConfirmado' => $paid || $cashLegacy,
            'pagoCancelado' => $canceled, 'efectivoSinRegistroPago' => $cashLegacy,
            'incluidaEnTotalVendido' => $reason === null, 'motivoExclusion' => $reason,
            'importeCentavos' => $amount, 'totalVendido' => $reason === null ? $amount /100.0 : 0.0,
            'totalQr' => $reason === null && $qr ? $amount /100.0 : 0.0,
            'totalEfectivo' => $reason === null && !$qr ? $amount /100.0 : 0.0,
        ];
    }

    public static function detailCents(array $sale): int
    {
        $sum = 0;
        foreach ($sale['detalle'] ?? [] as $item) {
            $item = (array) $item;
            $sum += self::cents($item['total_linea'] ?? round((float) ($item['cantidad'] ?? 0) * (float) ($item['precio'] ?? $item['monto_base'] ?? 0), 2));
        }
        return $sum;
    }

    /** Explicit reconciliation row; original line amounts are never changed. */
    public static function reconciledDetails(array $sale): array
    {
        $details = (array) ($sale['detalle'] ?? []);
        if (!array_key_exists('total', $sale)) return $details;
        $difference = self::cents($sale['total']) - self::detailCents($sale);
        if ($difference !== 0 || $details === []) {
            $details[] = ['descripcion' => 'DIFERENCIA CABECERA / DETALLE', 'cantidad' => 1,
                'precio' => $difference /100.0, 'total_linea' => $difference /100.0, 'tipoLinea' => 'conciliacion'];
        }
        return $details;
    }
}
