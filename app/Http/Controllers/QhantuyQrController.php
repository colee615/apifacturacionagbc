<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QhantuyQrController extends Controller
{
    private function summarizeCheckoutItems(array $items): array
    {
        return collect($items)
            ->map(function ($item) {
                return [
                    'name' => trim((string) ($item['name'] ?? '')),
                    'quantity' => (float) ($item['quantity'] ?? 0),
                    'price' => round((float) ($item['price'] ?? 0), 2),
                ];
            })
            ->values()
            ->all();
    }

    private function maskQhantuyPayloadForLogs(array $payload): array
    {
        $masked = $payload;
        if (isset($masked['appkey'])) {
            $appkey = trim((string) $masked['appkey']);
            $masked['appkey'] = $appkey !== '' ? substr($appkey, 0, 8) . '***' : '';
        }

        if (isset($masked['customer_email'])) {
            $email = trim((string) $masked['customer_email']);
            $parts = explode('@', $email, 2);
            if (count($parts) === 2) {
                $local = $parts[0];
                $masked['customer_email'] = substr($local, 0, 3) . '***@' . $parts[1];
            }
        }

        if (isset($masked['items']) && is_array($masked['items'])) {
            $masked['items'] = $this->summarizeCheckoutItems($masked['items']);
        }

        return $masked;
    }

    private function summarizeQhantuyResponseBody($body): array
    {
        if (!is_array($body)) {
            return ['type' => gettype($body)];
        }

        return [
            'message' => (string) data_get($body, 'message', ''),
            'transaction_id' => data_get($body, 'transaction_id') ?? data_get($body, 'id') ?? data_get($body, 'items.0.id'),
            'payment_status' => data_get($body, 'payment_status') ?? data_get($body, 'items.0.payment_status') ?? data_get($body, 'status') ?? data_get($body, 'items.0.status'),
            'checkout_amount' => data_get($body, 'checkout_amount') ?? data_get($body, 'items.0.checkout_amount'),
            'checkout_currency' => data_get($body, 'checkout_currency') ?? data_get($body, 'items.0.checkout_currency'),
            'has_image_data' => trim((string) (data_get($body, 'image_data') ?? data_get($body, 'qr_url') ?? data_get($body, 'items.0.image_data') ?? data_get($body, 'items.0.qr_url') ?? '')) !== '',
            'items_count' => count((array) data_get($body, 'items', [])),
        ];
    }

    private function normalizeQrPaymentStatus(?string $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'success', 'successful', 'succeeded', 'paid', 'completed', 'approved', 'confirmed' => 'success',
            'cancelled', 'canceled', 'rejected', 'failed', 'failure', 'expired', 'error' => 'cancelled',
            default => 'holding',
        };
    }

    private function qrPaymentStateFromStatus(?string $status): string
    {
        return match ($this->normalizeQrPaymentStatus($status)) {
            'success' => 'pagado',
            'cancelled' => 'cancelado',
            default => 'pendiente',
        };
    }

    private function syncCartPaymentState(string $internalCode, string $paymentStatus, ?int $transactionId = null, ?string $message = null): void
    {
        $normalizedInternalCode = $this->normalizeQrInternalCode($internalCode);

        $cart = DB::table('facturacion_carts')
            ->where(function ($query) use ($internalCode, $normalizedInternalCode) {
                $query->where('codigo_orden', $internalCode);
                if ($normalizedInternalCode !== $internalCode) {
                    $query->orWhere('codigo_orden', $normalizedInternalCode);
                }
            })
            ->orderByDesc('id')
            ->first();

        if (!$cart) {
            return;
        }

        $status = $this->normalizeQrPaymentStatus($paymentStatus);
        $estadoPago = $this->qrPaymentStateFromStatus($status);

        $updates = [
            'metodo_pago' => 'qr',
            'estado_pago' => $estadoPago,
            'estado_emision' => 'NO_APLICA',
            'updated_at' => now(),
        ];

        if ($estadoPago === 'pagado') {
            $updates['estado'] = 'emitido';
            $updates['emitido_en'] = $cart->emitido_en ?? now();
            $updates['cerrado_en'] = $cart->cerrado_en ?? now();
        } else {
            $updates['estado'] = 'pendiente_pago';
            $updates['emitido_en'] = null;
            $updates['cerrado_en'] = null;
        }

        if ($transactionId !== null && $transactionId > 0) {
            $updates['qr_transaction_id'] = (string) $transactionId;
        }
        if ($message !== null && trim($message) !== '') {
            $updates['mensaje_emision'] = trim($message);
        }
        DB::table('facturacion_carts')
            ->where('id', (int) $cart->id)
            ->update($updates);
    }

    private function cancelCartLocally(object $cart, string $message, ?object $row = null): array
    {
        $updates = [
            'metodo_pago' => 'qr',
            'estado' => 'pendiente_pago',
            'estado_pago' => 'cancelado',
            'estado_emision' => 'NO_APLICA',
            'mensaje_emision' => trim($message) !== '' ? trim($message) : 'Venta QR invalidada localmente.',
            'emitido_en' => null,
            'cerrado_en' => null,
            'updated_at' => now(),
        ];

        DB::table('facturacion_carts')
            ->where('id', (int) $cart->id)
            ->update($updates);

        if ($row) {
            DB::table('qhantuy_qr_payments')
                ->where('id', (int) $row->id)
                ->update([
                    'payment_status' => 'cancelled',
                    'last_message' => $updates['mensaje_emision'],
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return [
            'ok' => true,
            'message' => $updates['mensaje_emision'],
            'transaction_id' => null,
            'payment_status' => 'cancelled',
            'estado_pago' => 'cancelado',
            'cart' => DB::table('facturacion_carts')->where('id', (int) $cart->id)->first(),
            'local_only' => true,
        ];
    }

    private function normalizeQrInternalCode(string $internalCode): string
    {
        $internalCode = trim($internalCode);
        if ($internalCode !== '' && preg_match('/^(?:qv|fqc|vqc)-(\d+)$/i', $internalCode, $matches)) {
            return Venta::formatCodigoOrdenFromNumberWithPrefix((int) $matches[1], Venta::CODIGO_ORDEN_QR_PREFIX);
        }

        return $internalCode;
    }
    private function checkoutBaseUrl(): string
    {
        return rtrim((string) config('services.qhantuy_checkout.base_url', ''), '/');
    }

    private function checkoutUrl(): string
    {
        $base = $this->checkoutBaseUrl();

        if ($base === '') {
            return '';
        }

        return str_ends_with(strtolower($base), '/checkout')
            ? $base
            : $base . '/checkout';
    }

    private function checkPaymentsUrl(): string
    {
        return (string) config('services.qhantuy_checkout.check_payments_url', '');
    }

    private function cancelPaymentUrl(): string
    {
        return (string) config('services.qhantuy_checkout.cancel_payment_url', '');
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

    private function profileCode(): string
    {
        return trim((string) config('services.qhantuy_checkout.profile_code', ''));
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
        $connectTimeout = max(5, (int) config('services.qhantuy_checkout.connect_timeout', 15));
        $timeout = max(10, (int) config('services.qhantuy_checkout.timeout', 45));

        return Http::withHeaders([
            'X-API-Token' => $this->token(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->withOptions([
            'verify' => $verify,
            'force_ip_resolve' => 'v4',
            'http_version' => 1.1,
        ])->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->retry(2, 600, fn ($e) => $e instanceof ConnectionException);
    }

    private function maskedCheckoutConfig(): array
    {
        $token = $this->token();
        $appkey = $this->appkey();

        return [
            'checkout_url' => $this->checkoutUrl(),
            'check_payments_url' => $this->checkPaymentsUrl(),
            'cancel_payment_url' => $this->cancelPaymentUrl(),
            'callback_url' => $this->callbackUrl(),
            'currency_code' => $this->currencyCode(),
            'image_method' => $this->imageMethod(),
            'ssl_verify' => filter_var(config('services.qhantuy_checkout.ssl_verify', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
            'connect_timeout' => (int) config('services.qhantuy_checkout.connect_timeout', 15),
            'timeout' => (int) config('services.qhantuy_checkout.timeout', 45),
            'has_token' => $token !== '',
            'token_prefix' => $token !== '' ? substr($token, 0, 6) : '',
            'has_appkey' => $appkey !== '',
            'appkey_prefix' => $appkey !== '' ? substr($appkey, 0, 8) : '',
            'has_profile_code' => $this->profileCode() !== '',
            'profile_code_prefix' => $this->profileCode() !== '' ? substr($this->profileCode(), 0, 8) : '',
        ];
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
            'payment_type' => ['nullable', 'string', 'in:EMBED,REDIRECT'],
            'image_method' => ['nullable', 'string', 'in:URL,BASE64'],
        ]);

        Log::debug('Qhantuy checkout request received', [
            'internal_code' => (string) ($validated['internal_code'] ?? ''),
            'request_keys' => array_keys($validated),
            'items_count' => count((array) ($validated['items'] ?? [])),
            'config' => $this->maskedCheckoutConfig(),
        ]);

        if ($this->appkey() === '' || $this->token() === '' || $this->checkoutBaseUrl() === '' || $this->callbackUrl() === '') {
            Log::error('Qhantuy checkout missing configuration', [
                'internal_code' => (string) ($validated['internal_code'] ?? ''),
                'config' => $this->maskedCheckoutConfig(),
            ]);

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
            'payment_type' => trim((string) ($validated['payment_type'] ?? 'EMBED')),
            'image_method' => trim((string) ($validated['image_method'] ?? $this->imageMethod())),
            'detail' => trim((string) $validated['detail']),
            'items' => collect($validated['items'])->map(function ($item) {
                return [
                    'name' => trim((string) $item['name']),
                    'quantity' => (float) $item['quantity'],
                    'price' => round((float) $item['price'], 2),
                ];
            })->values()->all(),
        ];

        Log::debug('Qhantuy checkout payload prepared', [
            'internal_code' => $payload['internal_code'],
            'url' => $this->checkoutUrl(),
            'payload' => $this->maskQhantuyPayloadForLogs($payload),
        ]);

        try {
            $response = $this->qhantuyClient()->post($this->checkoutUrl(), $payload);
            $body = $response->json();

            Log::debug('Qhantuy checkout response received', [
                'internal_code' => $payload['internal_code'],
                'status' => $response->status(),
                'successful' => $response->successful(),
                'headers' => [
                    'content_type' => $response->header('Content-Type'),
                ],
                'body_summary' => $this->summarizeQhantuyResponseBody($body),
            ]);

            if (!$response->successful()) {
                Log::warning('Qhantuy checkout rejected', ['status' => $response->status(), 'body' => $body, 'internal_code' => $payload['internal_code']]);
                return response()->json([
                    'ok' => false,
                    'message' => (string) data_get($body, 'message', 'Qhantuy rechazo la solicitud.'),
                    'status_code' => $response->status(),
                    'qhantuy' => is_array($body) ? $body : null,
                ], $response->status());
            }

            $transactionId = data_get($body, 'transaction_id')
                ?? data_get($body, 'id')
                ?? data_get($body, 'items.0.id');
            $paymentStatus = $this->normalizeQrPaymentStatus(
                data_get($body, 'payment_status')
                ?? data_get($body, 'items.0.payment_status')
                ?? data_get($body, 'status')
                ?? data_get($body, 'items.0.status')
                ?? 'holding'
            );
            $amount = round((float) data_get($body, 'checkout_amount', collect($payload['items'])->sum(fn ($i) => $i['quantity'] * $i['price'])), 2);
            $currency = strtoupper((string) data_get($body, 'checkout_currency', $this->currencyCode()));
            $imageData = trim((string) (
                data_get($body, 'image_data')
                ?? data_get($body, 'qr_url')
                ?? data_get($body, 'items.0.image_data')
                ?? data_get($body, 'items.0.qr_url')
                ?? ''
            ));

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

            Log::debug('Qhantuy checkout persisted', [
                'internal_code' => $payload['internal_code'],
                'transaction_id' => $transactionId,
                'payment_status' => $paymentStatus,
                'checkout_amount' => $amount,
                'checkout_currency' => $currency,
                'has_image_data' => $imageData !== '',
            ]);

            return response()->json([
                'ok' => true,
                'message' => (string) data_get($body, 'message', 'Orden QR generada.'),
                'internal_code' => $payload['internal_code'],
                'transaction_id' => $transactionId !== null ? (int) $transactionId : null,
                'checkout_amount' => $amount,
                'checkout_currency' => $currency,
                'image_data' => $imageData,
                'qr_url' => trim((string) (
                    data_get($body, 'qr_url')
                    ?? data_get($body, 'items.0.qr_url')
                    ?? $imageData
                )),
                'payment_status' => $paymentStatus,
                'qhantuy' => is_array($body) ? $body : null,
            ]);
        } catch (ConnectionException $e) {
            Log::error('Qhantuy checkout connection error', [
                'url' => $this->checkoutBaseUrl() . '/checkout',
                'normalized_url' => $this->checkoutUrl(),
                'connect_timeout' => (int) config('services.qhantuy_checkout.connect_timeout', 15),
                'timeout' => (int) config('services.qhantuy_checkout.timeout', 45),
                'ssl_verify' => filter_var(config('services.qhantuy_checkout.ssl_verify', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
                'msg' => $e->getMessage(),
                'internal_code' => $payload['internal_code'],
                'payload' => $payload,
                'config' => $this->maskedCheckoutConfig(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'No se pudo conectar con Qhantuy para crear el checkout QR.',
                'detail' => $e->getMessage(),
            ], 504);
        } catch (RequestException $e) {
            Log::error('Qhantuy checkout request exception', [
                'internal_code' => $payload['internal_code'],
                'msg' => $e->getMessage(),
                'payload' => $payload,
                'config' => $this->maskedCheckoutConfig(),
                'response_status' => $e->response?->status(),
                'response_body' => $e->response?->body(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Error remoto al crear checkout QR.',
                'detail' => $e->getMessage(),
            ], 502);
        } catch (\Throwable $e) {
            Log::error('Qhantuy checkout unexpected error', [
                'internal_code' => $payload['internal_code'] ?? '',
                'msg' => $e->getMessage(),
                'payload' => $payload ?? null,
                'config' => $this->maskedCheckoutConfig(),
                'trace_head' => substr($e->getTraceAsString(), 0, 2000),
            ]);
            return response()->json([
                'ok' => false,
                'message' => 'Error inesperado al crear checkout QR.',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function callback(Request $request)
    {
        Log::debug('Qhantuy callback received', [
            'method' => $request->method(),
            'query' => $request->query(),
            'full_url' => $request->fullUrl(),
        ]);

        $validated = $request->validate([
            'transaction_id' => ['required', 'numeric'],
            'profile_code' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:500'],
            'internal_code' => ['required', 'string', 'max:120'],
            'checkout_amount' => ['required', 'numeric', 'gte:0'],
            'checkout_currency_code' => ['nullable', 'string', 'in:BOB'],
            'checkout_currency' => ['nullable', 'string', 'in:BOB'],
            'status' => ['required', 'string', 'in:success,cancelled'],
        ]);

        $internalCode = trim((string) $validated['internal_code']);
        $row = DB::table('qhantuy_qr_payments')->where('internal_code', $internalCode)->first();
        if (!$row) {
            Log::warning('Qhantuy callback ignored because internal_code was not found', [
                'internal_code' => $internalCode,
                'transaction_id' => $validated['transaction_id'] ?? null,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Internal code no registrado.',
            ], 404);
        }

        $expectedProfileCode = $this->profileCode();
        if ($expectedProfileCode !== '' && trim((string) $validated['profile_code']) !== $expectedProfileCode) {
            Log::warning('Qhantuy callback rejected due to invalid profile_code', [
                'internal_code' => $internalCode,
                'profile_code_prefix' => substr((string) $validated['profile_code'], 0, 8),
                'expected_profile_code_prefix' => substr($expectedProfileCode, 0, 8),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'profile_code invalido.',
            ], 422);
        }

        if ($expectedProfileCode === '') {
            Log::warning('Qhantuy callback received without configured profile_code in SAFE', [
                'internal_code' => $internalCode,
                'profile_code_prefix' => substr((string) $validated['profile_code'], 0, 8),
            ]);
        }

        $incomingAmount = round((float) $validated['checkout_amount'], 2);
        $storedAmount = round((float) ($row->checkout_amount ?? 0), 2);
        if ($storedAmount > 0 && abs($incomingAmount - $storedAmount) > 0.00001) {
            Log::warning('Qhantuy callback rejected due to amount mismatch', [
                'internal_code' => $internalCode,
                'incoming_amount' => $incomingAmount,
                'stored_amount' => $storedAmount,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Monto no coincide con la venta registrada.',
            ], 422);
        }

        $incomingTxn = (int) $validated['transaction_id'];
        if (!empty($row->transaction_id) && (int) $row->transaction_id !== $incomingTxn) {
            Log::warning('Qhantuy callback rejected due to transaction mismatch', [
                'internal_code' => $internalCode,
                'incoming_transaction_id' => $incomingTxn,
                'stored_transaction_id' => (int) ($row->transaction_id ?? 0),
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'transaction_id no coincide con la venta registrada.',
            ], 422);
        }

        $status = $this->normalizeQrPaymentStatus((string) $validated['status']);
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

        Log::debug('Qhantuy callback applied successfully', [
            'internal_code' => $internalCode,
            'transaction_id' => $incomingTxn,
            'normalized_status' => $status,
        ]);

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

        Log::debug('Qhantuy check-payments requested', [
            'payment_ids' => (array) ($validated['payment_ids'] ?? []),
            'internal_code' => (string) ($validated['internal_code'] ?? ''),
            'config' => $this->maskedCheckoutConfig(),
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
            Log::debug('Qhantuy check-payments resolved from cache', [
                'transaction_id' => $row->transaction_id ?? null,
                'internal_code' => $row->internal_code ?? null,
                'payment_status' => $row->payment_status ?? null,
            ]);

            return response()->json($this->buildCachedCheckPaymentsResponse($row));
        }

        try {
            $response = $this->qhantuyClient()->post($this->checkPaymentsUrl(), [
                'appkey' => $this->appkey(),
                'payment_ids' => $paymentIds,
            ]);
            $body = $response->json();

            Log::debug('Qhantuy check-payments response received', [
                'payment_ids' => $paymentIds,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_summary' => $this->summarizeQhantuyResponseBody($body),
            ]);

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

            if (is_array($body) && array_key_exists('items', $body) && empty((array) $body['items'])) {
                Log::warning('Qhantuy check-payments returned empty items list', [
                    'payment_ids' => $paymentIds,
                    'body' => $body,
                    'internal_code' => $row->internal_code ?? ($validated['internal_code'] ?? null),
                ]);
            }

            $transactionId = (int) (
                data_get($source, 'id')
                ?? data_get($body, 'id')
                ?? $paymentIds[0]
            );
            $status = $this->normalizeQrPaymentStatus(
                $source['payment_status']
                ?? $source['payment_status ']
                ?? data_get($body, 'payment_status')
                ?? $source['status']
                ?? data_get($body, 'status')
                ?? 'holding'
            );
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
                'estado_pago' => $this->qrPaymentStateFromStatus($status),
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

    public function cancelPayment(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => ['nullable', 'numeric'],
            'internal_code' => ['nullable', 'string', 'max:120'],
            'cart_id' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $transactionId = (int) ($validated['transaction_id'] ?? 0);
        $internalCode = trim((string) ($validated['internal_code'] ?? ''));
        $normalizedInternalCode = $internalCode !== '' ? $this->normalizeQrInternalCode($internalCode) : '';
        $cartId = (int) ($validated['cart_id'] ?? 0);
        $reason = trim((string) ($validated['reason'] ?? ''));

        $cart = null;
        if ($cartId > 0) {
            $cart = DB::table('facturacion_carts')->where('id', $cartId)->first();
            if ($cart && $internalCode === '') {
                $internalCode = trim((string) ($cart->codigo_orden ?? ''));
                $normalizedInternalCode = $internalCode !== '' ? $this->normalizeQrInternalCode($internalCode) : '';
            }
            if ($cart && $transactionId <= 0) {
                $transactionId = (int) ($cart->qr_transaction_id ?? 0);
            }
        }

        $row = null;
        if ($internalCode !== '') {
            $row = DB::table('qhantuy_qr_payments')
                ->where(function ($query) use ($internalCode, $normalizedInternalCode) {
                    $query->where('internal_code', $internalCode);
                    if ($normalizedInternalCode !== '' && $normalizedInternalCode !== $internalCode) {
                        $query->orWhere('internal_code', $normalizedInternalCode);
                    }
                })
                ->first();
        }

        if (!$row && $transactionId > 0) {
            $row = DB::table('qhantuy_qr_payments')->where('transaction_id', $transactionId)->first();
        }

        if (!$cart && $row) {
            $resolvedInternalCode = trim((string) ($row->internal_code ?? ''));
            $resolvedNormalizedCode = $resolvedInternalCode !== '' ? $this->normalizeQrInternalCode($resolvedInternalCode) : '';
            $cart = DB::table('facturacion_carts')
                ->where(function ($query) use ($resolvedInternalCode, $resolvedNormalizedCode) {
                    $query->where('codigo_orden', $resolvedInternalCode);
                    if ($resolvedNormalizedCode !== '' && $resolvedNormalizedCode !== $resolvedInternalCode) {
                        $query->orWhere('codigo_orden', $resolvedNormalizedCode);
                    }
                })
                ->orderByDesc('id')
                ->first();
        }

        if (!$row && !$cart) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro una venta QR pendiente para cancelar.',
            ], 404);
        }

        $transactionId = $transactionId > 0 ? $transactionId : (int) ($row->transaction_id ?? 0);
        $internalCode = $internalCode !== '' ? $internalCode : trim((string) ($row->internal_code ?? ($cart->codigo_orden ?? '')));
        $normalizedInternalCode = $internalCode !== '' ? $this->normalizeQrInternalCode($internalCode) : '';

        if ($transactionId <= 0 && $cart) {
            $localMessage = $reason !== ''
                ? 'Venta QR invalidada localmente. Motivo: ' . $reason
                : 'Venta QR invalidada localmente porque no existe un QR generado para anular.';

            return response()->json($this->cancelCartLocally($cart, $localMessage, $row));
        }

        if ($transactionId <= 0 || $internalCode === '') {
            return response()->json([
                'ok' => false,
                'message' => 'La venta QR no tiene transaction_id o codigo interno suficiente para cancelacion.',
            ], 422);
        }

        $currentStatus = $this->normalizeQrPaymentStatus((string) (
            $row->payment_status
            ?? $cart->estado_pago
            ?? 'holding'
        ));

        if ($currentStatus === 'success') {
            return response()->json([
                'ok' => false,
                'message' => 'El QR ya fue pagado y no puede cancelarse.',
            ], 422);
        }

        if ($currentStatus === 'cancelled') {
            if ($row) {
                $this->syncCartPaymentState(
                    (string) ($row->internal_code ?? $internalCode),
                    'cancelled',
                    (int) ($row->transaction_id ?? $transactionId),
                    (string) ($row->last_message ?? 'Cobro QR ya estaba cancelado.')
                );
            }

            return response()->json([
                'ok' => true,
                'message' => 'El cobro QR ya estaba cancelado.',
                'transaction_id' => $transactionId,
                'payment_status' => 'cancelled',
                'estado_pago' => $this->qrPaymentStateFromStatus('cancelled'),
            ]);
        }

        Log::debug('Qhantuy cancel-payment requested', [
            'transaction_id' => $transactionId,
            'internal_code' => $internalCode,
            'cart_id' => (int) ($cart->id ?? 0),
            'config' => $this->maskedCheckoutConfig(),
        ]);

        if ($this->appkey() === '' || $this->token() === '' || $this->cancelPaymentUrl() === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Configuracion Qhantuy incompleta en SAFE.',
            ], 500);
        }

        $payload = [
            'appkey' => $this->appkey(),
            'transaction_id' => $transactionId,
        ];
        if ($reason !== '') {
            $payload['reason'] = $reason;
        }

        try {
            $response = $this->qhantuyClient()->post($this->cancelPaymentUrl(), $payload);
            $body = $response->json();

            Log::debug('Qhantuy cancel-payment response received', [
                'transaction_id' => $transactionId,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'raw_body' => $response->body(),
                'json_body' => $body,
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'ok' => false,
                    'message' => (string) data_get($body, 'message', 'No se pudo cancelar el cobro QR.'),
                    'status_code' => $response->status(),
                    'qhantuy' => is_array($body) ? $body : null,
                ], $response->status());
            }

            $processOk = filter_var(data_get($body, 'process', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($processOk === false) {
                return response()->json([
                    'ok' => false,
                    'message' => (string) data_get($body, 'message', 'Qhantuy no permitio cancelar el cobro QR.'),
                    'qhantuy' => is_array($body) ? $body : null,
                ], 422);
            }

            $source = is_array(data_get($body, 'item')) ? data_get($body, 'item') : (is_array($body) ? $body : []);
            $status = $this->normalizeQrPaymentStatus(
                data_get($source, 'current_status')
                ?? data_get($body, 'current_status')
                ?? 'cancelled'
            );
            $message = (string) data_get($body, 'message', 'Cobro QR anulado satisfactoriamente.');

            if ($row) {
                DB::table('qhantuy_qr_payments')
                    ->where('id', $row->id)
                    ->update([
                        'payment_status' => $status,
                        'raw_check_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                        'last_message' => $message,
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            $this->syncCartPaymentState($internalCode, $status, $transactionId, $message);

            $updatedCart = DB::table('facturacion_carts')
                ->where(function ($query) use ($internalCode, $normalizedInternalCode) {
                    $query->where('codigo_orden', $internalCode);
                    if ($normalizedInternalCode !== '' && $normalizedInternalCode !== $internalCode) {
                        $query->orWhere('codigo_orden', $normalizedInternalCode);
                    }
                })
                ->orderByDesc('id')
                ->first();

            return response()->json([
                'ok' => true,
                'message' => $message,
                'transaction_id' => $transactionId,
                'payment_status' => $status,
                'estado_pago' => $this->qrPaymentStateFromStatus($status),
                'cart' => $updatedCart,
                'qhantuy' => $body,
            ]);
        } catch (RequestException $e) {
            $response = $e->response;
            return response()->json($response?->json() ?? [
                'ok' => false,
                'message' => 'Error remoto al cancelar el cobro QR.',
                'details' => $e->getMessage(),
            ], $response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo conectar con Qhantuy para cancelar el cobro QR.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('Qhantuy cancel-payment unexpected error', ['msg' => $e->getMessage()]);
            return response()->json([
                'ok' => false,
                'message' => 'Error inesperado al cancelar el cobro QR.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function markIncidentReviewed(Request $request)
    {
        $validated = $request->validate([
            'cart_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $cart = DB::table('facturacion_carts')->where('id', (int) $validated['cart_id'])->first();
        if (!$cart) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontro la venta QR indicada.',
            ], 404);
        }

        $estadoPago = strtolower(trim((string) ($cart->estado_pago ?? 'pendiente')));
        $canalEmision = strtolower(trim((string) ($cart->canal_emision ?? '')));
        $metodoPago = strtolower(trim((string) ($cart->metodo_pago ?? '')));
        $isQr = $canalEmision === 'qr' || $metodoPago === 'qr' || trim((string) ($cart->qr_transaction_id ?? '')) !== '';

        if (!$isQr || !in_array($estadoPago, ['cancelado', 'fallido'], true)) {
            return response()->json([
                'ok' => false,
                'message' => 'Solo se puede marcar como revisada una incidencia QR cancelada o fallida.',
            ], 422);
        }

        $reviewer = auth()->user();
        $reviewedBy = trim((string) (
            $reviewer->email
            ?? $reviewer->name
            ?? $reviewer->nombre
            ?? $reviewer->id
            ?? 'sistema'
        ));
        $note = trim((string) ($validated['note'] ?? '')) ?: 'Incidencia QR revisada manualmente.';

        DB::table('facturacion_carts')
            ->where('id', (int) $cart->id)
            ->update([
                'incidencia_revisada_at' => now(),
                'incidencia_revisada_por' => $reviewedBy,
                'incidencia_revision_nota' => $note,
                'updated_at' => now(),
            ]);

        return response()->json([
            'ok' => true,
            'message' => 'La incidencia QR fue marcada como revisada.',
            'cart' => DB::table('facturacion_carts')->where('id', (int) $cart->id)->first(),
        ]);
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

        $status = $this->normalizeQrPaymentStatus((string) ($row->payment_status ?? 'holding'));
        $cacheSeconds = $status === 'holding' ? 8 : 20;

        return abs(now()->diffInSeconds($updatedAt, false)) < $cacheSeconds;
    }

    private function buildCachedCheckPaymentsResponse(object $row, ?string $message = null): array
    {
        $raw = json_decode((string) ($row->raw_check_response ?? ''), true);
        $status = $this->normalizeQrPaymentStatus((string) ($row->payment_status ?? 'holding'));

        return [
            'ok' => true,
            'message' => $message ?: ((string) ($row->last_message ?? '') ?: 'Estado QR recuperado desde cache reciente.'),
            'transaction_id' => (int) ($row->transaction_id ?? 0),
            'checkout_amount' => round((float) ($row->checkout_amount ?? 0), 2),
            'checkout_currency' => strtoupper((string) ($row->checkout_currency ?? $this->currencyCode())),
            'qr_url' => (string) ($row->image_data ?? ''),
            'image_data' => (string) ($row->image_data ?? ''),
            'payment_status' => $status,
            'estado_pago' => $this->qrPaymentStateFromStatus($status),
            'qhantuy' => is_array($raw) ? $raw : null,
        ];
    }
}
