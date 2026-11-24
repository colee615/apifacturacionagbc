<?php

namespace App\Http\Controllers\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

trait RecoversFiscalCartInvoices
{
    public function lookupFiscalInvoiceByOrder(string $order): array
    {
        try {
            $response = $this->ageticClient()->connectTimeout(3)->timeout(5)->retry(1)
                ->get($this->ageticBaseUrl().'/consulta/'.rawurlencode($order), ['tipo' => 'CO']);
        } catch (\Throwable) {
            return ['result' => 'unknown'];
        }

        $body = (array) $response->json();
        if (!$response->successful()) {
            $message = trim((string) ($body['message'] ?? $body['mensaje'] ?? ''));
            if ($response->status() === 400 && mb_strtolower($message) === 'recurso no encontrado') {
                return ['result' => 'not_found'];
            }

            return ['result' => 'unknown'];
        }

        $state = strtoupper(trim((string) ($body['estado'] ?? '')));
        if (in_array($state, ['PROCESADO', 'OBSERVADO', 'RECHAZADO'], true)) {
            try {
                return [
                    'result' => 'found',
                    'invoice' => $this->sufeValidator->validateConsultaFacturaResponse($body),
                ];
            } catch (ValidationException) {
                return ['result' => 'unknown'];
            }
        }

        return ['result' => 'pending'];
    }

    public function fetchProcessedInvoice(string $identifier, bool $byOrder = true): ?array
    {
        try {
            $response = $this->ageticClient()->connectTimeout(3)->timeout(5)->retry(1)
                ->get($this->ageticBaseUrl().'/consulta/'.rawurlencode($identifier), $byOrder ? ['tipo' => 'CO'] : []);

            if (!$response->successful() || !in_array($response->json('estado'), ['PROCESADO', 'OBSERVADO', 'RECHAZADO'], true)) {
                return null;
            }

            return $this->sufeValidator->validateConsultaFacturaResponse((array) $response->json());
        } catch (ConnectionException|ValidationException) {
            return null;
        }
    }

    public function linkRecoveredCartInvoice(
        int $cartId,
        string $order,
        array $invoice,
        callable $buildPayload,
        callable $persistTraceability,
    ): bool {
        $tracking = trim((string) ($invoice['codFactura'] ?? ''));
        $number = trim((string) ($invoice['nroFactura'] ?? ''));
        $cuf = trim((string) ($invoice['cuf'] ?? ''));
        $header = (array) data_get($invoice, 'detalleFactura.cabecera', []);
        if (($invoice['estado'] ?? '') !== 'PROCESADO' || ($invoice['codigoOrden'] ?? '') !== $order
            || $tracking === '' || !ctype_digit($number) || $cuf === ''
            || (string) ($header['numeroFactura'] ?? '') !== $number || ($header['cuf'] ?? '') !== $cuf
            || empty($header['fechaEmision'])) {
            return false;
        }

        try {
            $issuedAt = Carbon::parse($header['fechaEmision'], config('app.timezone'));
        } catch (\Exception) {
            return false;
        }

        return DB::transaction(function () use ($cartId, $order, $invoice, $tracking, $number, $cuf, $header, $issuedAt, $buildPayload, $persistTraceability) {
            $cart = DB::table('facturacion_carts')->where('id', $cartId)->lockForUpdate()->first();
            if (!$cart || $cart->codigo_orden !== $order || !in_array($cart->estado_emision, ['EMISION_INCIERTA', 'PENDIENTE'], true)
                || $cart->canal_emision !== 'factura_electronica' || $cart->estado_pago !== 'pagado'
                || $issuedAt->lt(Carbon::parse($cart->created_at)) || $issuedAt->gt(now()->addMinutes(5))) {
                return false;
            }

            $payload = $this->sufeValidator->validateIndividualPayload($buildPayload($cart));
            $payload['detalle'] = $this->consolidateDetalleLines($payload['detalle']);
            if ($payload['codigoOrden'] !== $order || (int) data_get($payload, 'origenVenta.id') !== $cartId
                || !$this->matchesRecoveredFiscalDetails($header, (array) data_get($invoice, 'detalleFactura.detalle', []), $payload)) {
                Log::warning('factura.recuperacion.datos_no_coinciden', ['cart_id' => $cartId, 'orden' => $order]);
                return false;
            }

            $matching = DB::table('ventas')->where(function ($query) use ($order, $tracking, $cuf, $cartId) {
                $query->where('codigoOrden', $order)->orWhere('codigoSeguimiento', $tracking)->orWhere('cuf', $cuf)
                    ->orWhere(function ($otherActive) use ($cartId) {
                        $otherActive->where('origen_venta_id', (string) $cartId)
                            ->whereIn('origen_venta_tipo', ['facturacion_cart', 'facturacion_cart_remote'])
                            ->whereIn('estado_sufe', ['RECEPCIONADA', 'PENDIENTE', 'PROCESADA']);
                    });
            })->lockForUpdate()->get();
            $existing = $matching->first();
            if ($existing && ($matching->count() !== 1 || $existing->codigoOrden !== $order
                || (string) $existing->codigoSeguimiento !== $tracking
                || (string) $existing->origen_venta_id !== (string) $cartId
                || !in_array($existing->origen_venta_tipo, ['facturacion_cart', 'facturacion_cart_remote'], true)
                || !in_array($existing->estado_sufe, ['RECEPCIONADA', 'PENDIENTE', 'PROCESADA'], true)
                || (filled($existing->cuf) && $existing->cuf !== $cuf)
                || (filled($existing->numero_factura) && (string) $existing->numero_factura !== $number))) {
                Log::warning('factura.recuperacion.referencia_ocupada', ['cart_id' => $cartId, 'orden' => $order]);
                return false;
            }

            if ($existing) {
                $saleId = (int) $existing->id;
            } else {
                $sale = $this->createVenta($payload, $order, $tracking, 'PROCESADA');
                $saleId = $sale['id'];
                $this->createDetalleVentas($sale, $payload);
                $this->addVentaToCaja($payload, $issuedAt->toDateString());
            }

            $note = $this->latestNotificationByCodigoSeguimiento($tracking);
            $notification = json_decode((string) ($note?->detalle ?? ''), true);
            $confirmedNotice = $note?->estado === 'EXITO' && data_get($notification, 'tipoEmision') === 'EMISION'
                && data_get($notification, 'cuf') === $cuf && (string) data_get($notification, 'nroFactura') === $number;

            DB::table('ventas')->where('id', $saleId)->update([
                'estado_sufe' => 'PROCESADA',
                'numero_factura' => $number,
                'cuf' => $cuf,
                'created_at' => $existing?->created_at ?? $issuedAt,
                'tipo_emision_sufe' => 'EMISION',
                'fecha_notificacion_sufe' => $confirmedNotice ? $note->fecha : ($existing?->fecha_notificacion_sufe ?? null),
                'url_pdf' => $confirmedNotice ? data_get($notification, 'urlPdf') : ($existing?->url_pdf ?? null),
                'url_xml' => $confirmedNotice ? data_get($notification, 'urlXml') : ($existing?->url_xml ?? null),
                'updated_at' => now(),
            ]);
            DB::table('facturacion_carts')->where('id', $cartId)->update([
                'estado' => 'emitido',
                'codigo_seguimiento' => $tracking,
                'codigo_seguimiento_fiscal' => $tracking,
                'emitido_en' => $cart->emitido_en ?? $issuedAt,
                'cerrado_en' => $cart->cerrado_en ?? $issuedAt,
                'respuesta_emision' => json_encode(['estadoPuente' => 'PROCESADA', 'recuperada_por_consulta' => true]),
                'updated_at' => now(),
            ]);
            $venta = DB::table('ventas')->where('id', $saleId)->first();
            $this->syncFacturacionCartFromVenta($venta, 'PROCESADA');
            app(\App\Http\Controllers\CajaDiariaController::class)->reconciliarEfectivoRecuperado($venta);
            $persistTraceability($saleId);
            Log::info('factura.recuperacion.vinculada', ['cart_id' => $cartId, 'venta_id' => $saleId, 'orden' => $order]);

            return true;
        }, 3);
    }

    public function linkRejectedCartOrder(int $cartId, string $order, array $invoice): bool
    {
        $tracking = trim((string) ($invoice['codFactura'] ?? ''));
        if (($invoice['codigoOrden'] ?? null) !== $order || $tracking === ''
            || !in_array($invoice['estado'] ?? '', ['OBSERVADO', 'RECHAZADO'], true)
            || filled($invoice['cuf'] ?? null) || filled($invoice['nroFactura'] ?? null)) {
            return false;
        }

        return DB::transaction(function () use ($cartId, $order, $tracking) {
            $cart = DB::table('facturacion_carts')->where('id', $cartId)->lockForUpdate()->first();
            if (!$cart || $cart->codigo_orden !== $order || $cart->canal_emision !== 'factura_electronica'
                || !in_array($cart->estado_emision, ['EMISION_INCIERTA', 'PENDIENTE'], true)
                || filled($cart->numero_factura ?? null)
                || (filled($cart->codigo_seguimiento_fiscal) && $cart->codigo_seguimiento_fiscal !== $tracking)) {
                return false;
            }
            $sale = DB::table('ventas')->where('codigoOrden', $order)->lockForUpdate()->first();
            if ($sale && (!in_array($sale->estado_sufe, ['RECEPCIONADA', 'PENDIENTE', 'OBSERVADA'], true)
                || (int) $sale->origen_venta_id !== $cartId || (string) $sale->codigoSeguimiento !== $tracking
                || filled($sale->numero_factura) || filled($sale->cuf))) {
                return false;
            }
            // Si ya se registró el ingreso de caja, el flujo regular de observaciones
            // conserva el asiento y sus datos para la conciliación.
            if ($sale) {
                DB::table('ventas')->where('id', $sale->id)->update(['estado_sufe' => 'OBSERVADA', 'updated_at' => now()]);
            }
            DB::table('facturacion_carts')->where('id', $cartId)->update([
                'estado_emision' => 'RECHAZADA',
                'mensaje_emision' => 'SEFE confirmó el rechazo de esta orden.',
                'updated_at' => now(),
            ]);

            return true;
        });
    }

    private function matchesRecoveredFiscalDetails(array $header, array $lines, array $payload): bool
    {
        foreach ([
            'codigoSucursal' => 'codigoSucursal',
            'codigoPuntoVenta' => 'puntoVenta',
            'codigoMetodoPago' => 'metodoPago',
            'codigoTipoDocumentoIdentidad' => 'tipoDocumentoIdentidad',
            'numeroDocumento' => 'documentoIdentidad',
        ] as $remote => $local) {
            if (!isset($header[$remote], $payload[$local]) || trim((string) $header[$remote]) !== trim((string) $payload[$local])) {
                return false;
            }
        }

        if ((int) ($header['codigoDocumentoSector'] ?? $payload['documentoSector']) !== (int) $payload['documentoSector']
            || !is_numeric($header['montoTotal'] ?? null)
            || round((float) $header['montoTotal'], 2) !== round((float) $payload['montoTotal'], 2)) {
            return false;
        }

        $signature = static fn (array $line, string $product, string $sin) => [
            trim((string) ($line[$product] ?? '')),
            trim((string) ($line[$sin] ?? '')),
            (int) ($line['unidadMedida'] ?? 0),
            number_format((float) ($line['cantidad'] ?? 0), 4, '.', ''),
            number_format((float) ($line['precioUnitario'] ?? 0), 2, '.', ''),
        ];
        $official = array_map(fn (array $line) => $signature($line, 'codigoProducto', 'codigoProductoSin'), $lines);
        $requested = array_map(fn (array $line) => $signature($line, 'codigo', 'codigoSin'), $payload['detalle']);
        sort($official);
        sort($requested);

        return $official !== [] && $official === $requested;
    }
}
