<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QhantuyQrController extends Controller
{
    private function syncCartPaymentState(string $internalCode, string $paymentStatus, ?int $transactionId = null, ?string $message = null): void
    {
        $status = strtolower(trim($paymentStatus));
        $estadoPago = match ($status) {
            'success', 'paid', 'completed' => 'pagado',
            'cancelled', 'rejected', 'failed', 'expired' => 'cancelado',
            default => 'pendiente',
        };

        $estadoEmision = match ($estadoPago) {
            'pagado' => 'PAGADO',
            'cancelado' => 'RECHAZADA',
            default => 'PENDIENTE',
        };

        $updates = [
            'metodo_pago' => 'qr',
            'estado_pago' => $estadoPago,
            'estado_emision' => $estadoEmision,
            'updated_at' => now(),
        ];

        if ($transactionId !== null && $transactionId > 0) {
            $updates['codigo_seguimiento'] = (string) $transactionId;
        }
        if ($message !== null && trim($message) !== '') {
            $updates['mensaje_emision'] = trim($message);
        }

        DB::table('facturacion_carts')
            ->where('codigo_orden', $internalCode)
            ->where('estado', 'borrador')
            ->update($updates);
    }
    private function checkoutBaseUrl(): string
    {
        return rtrim((string) config('services.qhantuy_checkout.base_url', ''), '/');
    }

    private function checkPaymentsUrl(): string
    {
        return (string) config('services.qhantuy_checkout.check_payments_url', '');
    }

    private function appkey(): string
    {
        return trim((string) config('services.qhantuy_checkout.appkey', ''));
    }

    private function token(): string
    {
        return trim((string) config('services.qhantuy_checkout.token', ''));
    }

    private function callbackUrl(): string
    {
        return trim((string) config('services.qhantuy_checkout.callback_url', ''));
    }

    private function imageMethod(): string
    {
        $value = strtoupper(trim((string) config('services.qhantuy_checkout.image_method', 'URL')));
        return in_array($value, ['URL', 'BASE64'], true) ? $value : 'URL';
    }

    private function currencyCode(): string
    {
        $value = strtoupper(trim((string) config('services.qhantuy_checkout.currency_code', 'BOB')));
        return $value !== '' ? $value : 'BOB';
    }

    private function qhantuyClient()
    {
        $verify = filter_var(config('services.qhantuy_checkout.ssl_verify', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $timeout = max(10, (int) config('services.qhantuy_checkout.timeout', 45));

        return Http::withHeaders([
            'X-API-Token' => $this->token(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->withOptions([
            'verify' => $verify,
            'force_ip_resolve' => 'v4',
            'http_version' => 1.1,
        ])->connectTimeout(15)
            ->timeout($timeout)
            ->retry(2, 600, fn ($e) => $e instanceof ConnectionException);
    }

    public function checkout(Request $request)
    {
        $validated = $request->validate([
            'customer_email' => ['required', 'email', 'max:120'],
            'customer_first_name' => ['required', 'string', 'max:120'],
            'customer_last_name' => ['required', 'string', 'max:120'],
            'currency_code' => ['nullable', 'string', 'in:BOB'],
            'internal_code' => ['required', 'string', 'max:120'],
            'callback_url' => ['required', 'url', 'max:500'],
            'detail' => ['required', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'numeric', 'gt:0'],
            'image_method' => ['nullable', 'string', 'in:URL,BASE64'],
        ]);

        if ($this->appkey() === '' || $this->token() === '' || $this->checkoutBaseUrl() === '' || $this->callbackUrl() === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Configuracion Qhantuy incompleta en SAFE.',
            ], 500);
        }

        $payload = [
            'appkey' => $this->appkey(),
            'customer_email' => strtolower(trim((string) $validated['customer_email'])),
            'customer_first_name' => trim((string) $validated['customer_first_name']),
            'customer_last_name' => trim((string) $validated['customer_last_name']),
            'currency_code' => $this->currencyCode(),
            'internal_code' => trim((string) $validated['internal_code']),
            'callback_url' => $this->callbackUrl(),
            'payment_method' => 'QRSIMPLE',
            'image_method' => $this->imageMethod(),
            'detail' => trim((string) $validated['detail']),
            'items' => collect($validated['items'])->map(function ($item) {
                return [
                    'name' => trim((string) $item['name']),
                    'quantity' => (float) $item['quantity'],
                    'price' => round((float) $item['price'], 2),
                ];
            })->values()->all(),
        ];

        try {
            $response = $this->qhantuyClient()->post($this->checkoutBaseUrl() . '/checkout', $payload);
            $body = $response->json();

            if (!$response->successful()) {
                Log::warning('Qhantuy checkout rejected', ['status' => $response->status(), 'body' => $body, 'internal_code' => $payload['internal_code']]);
                return response()->json([
                    'ok' => false,
                    'message' => (string) data_get($body, 'message', 'Qhantuy rechazo la solicitud.'),
                    'status_code' => $response->status(),
                    'qhantuy' => is_array($body) ? $body : null,
                ], $response->status());
            }

            $transactionId = data_get($body, 'transaction_id');
            $paymentStatus = strtolower((string) data_get($body, 'payment_status', 'holding'));
            $amount = round((float) data_get($body, 'checkout_amount', collect($payload['items'])->sum(fn ($i) => $i['quantity'] * $i['price'])), 2);
            $currency = strtoupper((string) data_get($body, 'checkout_currency', $this->currencyCode()));
            $imageData = (string) data_get($body, 'image_data', '');

            DB::table('qhantuy_qr_payments')->updateOrInsert(
                ['internal_code' => $payload['internal_code']],
                [
                    'transaction_id' => $transactionId !== null ? (int) $transactionId : null,
                    'checkout_amount' => $amount,
                    'checkout_currency' => $currency,
                    'customer_email' => $payload['customer_email'],
                    'customer_first_name' => $payload['customer_first_name'],
                    'customer_last_name' => $payload['customer_last_name'],
                    'detail' => $payload['detail'],
                    'image_data' => $imageData !== '' ? $imageData : null,
                    'payment_status' => $paymentStatus !== '' ? $paymentStatus : 'holding',
                    'raw_checkout_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                    'last_message' => (string) data_get($body, 'message', ''),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            return response()->json([
                'ok' => true,
                'message' => (string) data_get($body, 'message', 'Orden QR generada.'),
                'internal_code' => $payload['internal_code'],
                'transaction_id' => $transactionId !== null ? (int) $transactionId : null,
                'checkout_amount' => $amount,
                'checkout_currency' => $currency,
                'image_data' => $imageData,
                'payment_status' => $paymentStatus,
            ]);
        } catch (RequestException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Error remoto al crear checkout QR.',
                'detail' => $e->getMessage(),
            ], 502);
        } catch (\Throwable $e) {
            Log::error('Qhantuy checkout unexpected error', ['msg' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Error inesperado al crear checkout QR.',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function callback(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => ['required', 'numeric'],
            'profile_code' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:500'],
            'internal_code' => ['required', 'string', 'max:120'],
            'checkout_amount' => ['required', 'numeric', 'gte:0'],
            'checkout_currency_code' => ['required', 'string', 'in:BOB'],
            'status' => ['required', 'string', 'in:success,cancelled'],
        ]);

        $internalCode = trim((string) $validated['internal_code']);
        $row = DB::table('qhantuy_qr_payments')->where('internal_code', $internalCode)->first();
        if (!$row) {
            return response()->json([
                'ok' => false,
                'message' => 'Internal code no registrado.',
            ], 404);
        }

        if (trim((string) $validated['profile_code']) !== $this->appkey()) {
            return response()->json([
                'ok' => false,
                'message' => 'profile_code invalido.',
            ], 422);
        }

        $incomingAmount = round((float) $validated['checkout_amount'], 2);
        $storedAmount = round((float) ($row->checkout_amount ?? 0), 2);
        if ($storedAmount > 0 && abs($incomingAmount - $storedAmount) > 0.00001) {
            return response()->json([
                'ok' => false,
                'message' => 'Monto no coincide con la venta registrada.',
            ], 422);
        }

        $incomingTxn = (int) $validated['transaction_id'];
        if (!empty($row->transaction_id) && (int) $row->transaction_id !== $incomingTxn) {
            return response()->json([
                'ok' => false,
                'message' => 'transaction_id no coincide con la venta registrada.',
            ], 422);
        }

        $status = strtolower((string) $validated['status']);
        DB::table('qhantuy_qr_payments')
            ->where('id', $row->id)
            ->update([
                'transaction_id' => $incomingTxn,
                'payment_status' => $status,
                'last_message' => (string) ($validated['message'] ?? ''),
                'raw_callback_params' => json_encode($request->query(), JSON_UNESCAPED_UNICODE),
                'paid_at' => $status === 'success' ? now() : $row->paid_at,
                'cancelled_at' => $status === 'cancelled' ? now() : $row->cancelled_at,
                'updated_at' => now(),
            ]);

        $this->syncCartPaymentState(
            $internalCode,
            $status,
            $incomingTxn,
            (string) ($validated['message'] ?? '')
        );

        return response()->json([
            'ok' => true,
            'internal_code' => $internalCode,
            'transaction_id' => $incomingTxn,
            'payment_status' => $status,
            'message' => $status === 'success'
                ? 'Pago QR confirmado. Preventa marcada como pagada.'
                : 'Callback procesado. Preventa actualizada.',
        ]);
    }

    public function checkPayments(Request $request)
    {
        $validated = $request->validate([
            'payment_ids' => ['nullable', 'array', 'min:1'],
            'payment_ids.*' => ['numeric'],
            'internal_code' => ['nullable', 'string', 'max:120'],
        ]);

        if ($this->appkey() === '' || $this->token() === '' || $this->checkPaymentsUrl() === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Configuracion Qhantuy incompleta en SAFE.',
            ], 500);
        }

        $paymentIds = collect((array) ($validated['payment_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $row = null;
        if ($paymentIds === [] && !empty($validated['internal_code'])) {
            $row = DB::table('qhantuy_qr_payments')
                ->where('internal_code', trim((string) $validated['internal_code']))
                ->first();
            if ($row && !empty($row->transaction_id)) {
                $paymentIds = [(int) $row->transaction_id];
            }
        }

        if (!$row && $paymentIds !== []) {
            $row = DB::table('qhantuy_qr_payments')
                ->where('transaction_id', (int) $paymentIds[0])
                ->first();
        }

        if ($paymentIds === []) {
            return response()->json([
                'ok' => false,
                'message' => 'Debes enviar payment_ids o internal_code con transaction_id registrado.',
            ], 422);
        }

        if ($row && $this->shouldUseCachedCheckResponse($row)) {
            return response()->json($this->buildCachedCheckPaymentsResponse($row));
        }

        try {
            $response = $this->qhantuyClient()->post($this->checkPaymentsUrl(), [
                'appkey' => $this->appkey(),
                'payment_ids' => $paymentIds,
            ]);
            $body = $response->json();

            if (!$response->successful()) {
                return response()->json([
                    'ok' => false,
                    'message' => (string) data_get($body, 'message', 'No se pudo consultar el estado de pago QR.'),
                    'status_code' => $response->status(),
                    'qhantuy' => is_array($body) ? $body : null,
                ], $response->status());
            }

            $firstItem = data_get($body, 'items.0');
            $source = is_array($firstItem) ? $firstItem : (is_array($body) ? $body : []);

            $transactionId = (int) (
                data_get($source, 'id')
                ?? data_get($body, 'id')
                ?? $paymentIds[0]
            );
            $status = strtolower(trim((string) (
                $source['payment_status'] ?? $source['payment_status '] ?? data_get($body, 'payment_status', 'holding')
            )));
            $amount = round((float) (
                data_get($source, 'checkout_amount')
                ?? data_get($body, 'checkout_amount')
                ?? 0
            ), 2);
            $currency = strtoupper(trim((string) (
                data_get($source, 'checkout_currency')
                ?? data_get($body, 'checkout_currency')
                ?? $this->currencyCode()
            )));
            $qrUrl = trim((string) (
                data_get($source, 'qr_url')
                ?? data_get($body, 'qr_url')
                ?? ''
            ));

            $target = $row ?: DB::table('qhantuy_qr_payments')->where('transaction_id', $transactionId)->first();
            if ($target) {
                $resolvedImageData = trim((string) ($target->image_data ?? ''));
                if ($resolvedImageData === '' && $qrUrl !== '') {
                    $resolvedImageData = $qrUrl;
                }

                DB::table('qhantuy_qr_payments')
                    ->where('id', $target->id)
                    ->update([
                        'transaction_id' => $transactionId,
                        'checkout_amount' => $amount > 0 ? $amount : $target->checkout_amount,
                        'checkout_currency' => $currency !== '' ? $currency : $target->checkout_currency,
                        'image_data' => $resolvedImageData !== '' ? $resolvedImageData : $target->image_data,
                        'payment_status' => $status,
                        'raw_check_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                        'last_message' => (string) data_get($body, 'message', ''),
                        'paid_at' => $status === 'success' ? now() : $target->paid_at,
                        'cancelled_at' => $status === 'cancelled' ? now() : $target->cancelled_at,
                        'updated_at' => now(),
                    ]);

                $this->syncCartPaymentState(
                    (string) ($target->internal_code ?? ''),
                    $status,
                    $transactionId,
                    (string) data_get($body, 'message', '')
                );
            }

            return response()->json([
                'ok' => true,
                'message' => (string) data_get($body, 'message', 'Consulta QR realizada.'),
                'transaction_id' => $transactionId,
                'checkout_amount' => $amount,
                'checkout_currency' => $currency,
                'qr_url' => $qrUrl,
                'image_data' => ($target->image_data ?? null) ?: $qrUrl,
                'payment_status' => $status,
                'estado_pago' => match ($status) {
                    'success', 'paid', 'completed' => 'pagado',
                    'cancelled', 'rejected', 'failed', 'expired' => 'cancelado',
                    default => 'pendiente',
                },
                'qhantuy' => $body,
            ]);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '429') && $row) {
                Log::warning('Qhantuy check-payments rate limited. Se devolvera estado cacheado.', [
                    'transaction_id' => $row->transaction_id ?? null,
                    'internal_code' => $row->internal_code ?? null,
                ]);

                return response()->json(
                    $this->buildCachedCheckPaymentsResponse(
                        $row,
                        'Qhantuy limito temporalmente las consultas. Se muestra el ultimo estado disponible.'
                    ),
                    200
                );
            }

            Log::error('Qhantuy check-payments unexpected error', ['msg' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Error inesperado al consultar pago QR.',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    private function shouldUseCachedCheckResponse(object $row): bool
    {
        if (empty($row->updated_at)) {
            return false;
        }

        try {
            $updatedAt = \Illuminate\Support\Carbon::parse((string) $row->updated_at);
        } catch (\Throwable) {
            return false;
        }

        return now()->diffInSeconds($updatedAt) < 15;
    }

    private function buildCachedCheckPaymentsResponse(object $row, ?string $message = null): array
    {
        $raw = json_decode((string) ($row->raw_check_response ?? ''), true);
        $status = strtolower((string) ($row->payment_status ?? 'holding'));

        return [
            'ok' => true,
            'message' => $message ?: ((string) ($row->last_message ?? '') ?: 'Estado QR recuperado desde cache reciente.'),
            'transaction_id' => (int) ($row->transaction_id ?? 0),
            'checkout_amount' => round((float) ($row->checkout_amount ?? 0), 2),
            'checkout_currency' => strtoupper((string) ($row->checkout_currency ?? $this->currencyCode())),
            'qr_url' => (string) ($row->image_data ?? ''),
            'image_data' => (string) ($row->image_data ?? ''),
            'payment_status' => $status,
            'estado_pago' => match ($status) {
                'success', 'paid', 'completed' => 'pagado',
                'cancelled', 'rejected', 'failed', 'expired' => 'cancelado',
                default => 'pendiente',
            },
            'qhantuy' => is_array($raw) ? $raw : null,
        ];
    }
}
