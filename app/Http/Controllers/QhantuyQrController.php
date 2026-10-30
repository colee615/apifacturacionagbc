<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class QhantuyQrController extends Controller
{
    private static ?bool $hasQrCancelAuditColumns = null;

    private function hasQrCancelAuditColumns(): bool
    {
        if (self::$hasQrCancelAuditColumns === null) {
            self::$hasQrCancelAuditColumns =
                Schema::hasColumn('facturacion_carts', 'qr_cancelado_at')
                && Schema::hasColumn('facturacion_carts', 'qr_cancelado_por_user_id')
                && Schema::hasColumn('facturacion_carts', 'qr_cancelado_por_nombre')
                && Schema::hasColumn('facturacion_carts', 'qr_cancelado_por_email')
                && Schema::hasColumn('facturacion_carts', 'qr_cancelacion_motivo')
                && Schema::hasColumn('facturacion_carts', 'qr_cancelacion_origen')
                && Schema::hasColumn('facturacion_carts', 'qr_cancelacion_transaction_id')
                && Schema::hasColumn('facturacion_carts', 'qr_cancelacion_mensaje');
        }

        return self::$hasQrCancelAuditColumns;
    }

    private function qrCancellationAuditColumns(
        string $message,
        string $reason = '',
        ?int $transactionId = null,
        string $origin = 'LOCAL'
    ): array {
        if (!$this->hasQrCancelAuditColumns()) {
            return [];
        }

        $user = auth('api')->user() ?? request()->user();

        return [
            'qr_cancelado_at' => now(),
            'qr_cancelado_por_user_id' => $user?->id,
            'qr_cancelado_por_nombre' => $user?->name,
            'qr_cancelado_por_email' => $user?->email,
            'qr_cancelacion_motivo' => trim($reason) !== '' ? trim($reason) : null,
            'qr_cancelacion_origen' => trim($origin) !== '' ? trim($origin) : 'LOCAL',
            'qr_cancelacion_transaction_id' => $transactionId && $transactionId > 0 ? (string) $transactionId : null,
            'qr_cancelacion_mensaje' => trim($message) !== '' ? trim($message) : null,
        ];
    }

    private function applyQrCancellationAuditToCart(
        ?object $cart,
        string $message,
        string $reason = '',
        ?int $transactionId = null,
        string $origin = 'LOCAL',
        ?string $internalCode = null,
        ?string $normalizedInternalCode = null
    ): ?object {
        $auditUpdates = $this->qrCancellationAuditColumns($message, $reason, $transactionId, $origin);
        if ($auditUpdates === []) {
            return $cart;
        }

        $query = DB::table('facturacion_carts');
        if ($cart && !empty($cart->id)) {
            $query->where('id', (int) $cart->id);
        } elseif (!blank($internalCode)) {
            $query->where(function ($builder) use ($internalCode, $normalizedInternalCode) {
                $builder->where('codigo_orden', $internalCode);
                if (!blank($normalizedInternalCode) && $normalizedInternalCode !== $internalCode) {
                    $builder->orWhere('codigo_orden', $normalizedInternalCode);
                }
            });
        } else {
            return $cart;
        }

        $query->update(array_merge($auditUpdates, ['updated_at' => now()]));

        if ($cart && !empty($cart->id)) {
            return DB::table('facturacion_carts')->where('id', (int) $cart->id)->first();
        }

        if (!blank($internalCode)) {
            return DB::table('facturacion_carts')
                ->where(function ($builder) use ($internalCode, $normalizedInternalCode) {
                    $builder->where('codigo_orden', $internalCode);
                    if (!blank($normalizedInternalCode) && $normalizedInternalCode !== $internalCode) {
                        $builder->orWhere('codigo_orden', $normalizedInternalCode);
                    }
                })
                ->orderByDesc('id')
                ->first();
        }

        return $cart;
    }

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

    private function parseCheckoutAmount($value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) ? round((float) $value, 2) : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $amount = trim($value);
        if ($amount === '') {
            return null;
        }

        // Qhantuy can return values such as "2,178.00". Remove commas only
        // when they form valid thousands groups; otherwise preserve decimals.
        if (preg_match('/^-?(?:\d+|\d{1,3}(?:,\d{3})+)(?:\.\d+)?$/D', $amount)) {
            $amount = str_replace(',', '', $amount);
        } elseif (preg_match('/^-?\d{1,3}(?:\.\d{3})+,\d{1,2}$/D', $amount)) {
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
        } elseif (preg_match('/^-?\d+,\d{1,2}$/D', $amount)) {
            $amount = str_replace(',', '.', $amount);
        } elseif (!is_numeric($amount)) {
            return null;
        }

        $parsed = (float) $amount;

        return is_finite($parsed) && $parsed >= 0 ? round($parsed, 2) : null;
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
        $payment = DB::table('qhantuy_qr_payments')->where('internal_code', $internalCode)->first();
        if ($status === 'success' && isset($cart->total)
            && (!$payment || abs(round((float) $cart->total, 2) - round((float) $payment->checkout_amount, 2)) >= 0.01)) {
            Log::critical('El pago fue recibido pero el total del carrito cambió; se requiere conciliación.', [
                'internal_code' => $internalCode, 'transaction_id' => $transactionId,
            ]);
            return;
        }
        $estadoPago = $this->qrPaymentStateFromStatus($status);
        $estadoEmisionActual = strtoupper(trim((string) ($cart->estado_emision ?? '')));
        $isFiscalInvoiceAttempt = strtolower(trim((string) ($cart->canal_emision ?? ''))) !== 'qr'
            && (
                trim((string) (($cart->codigo_seguimiento_fiscal ?? null) ?: ($cart->codigo_seguimiento ?? ''))) !== ''
                || in_array($estadoEmisionActual, [
                    'EMITIENDO',
                    'EMISION_INCIERTA',
                    'PENDIENTE',
                    'ERROR',
                    'RECHAZADA',
                    'FACTURADA',
                    'ANULACION_SOLICITADA',
                    'ANULACION_OBSERVADA',
                    'ANULADA',
                ], true)
            );

        $updates = [
            'metodo_pago' => ($payment->payment_method ?? 'QRSIMPLE') === 'CYBERSOURCE' ? 'tarjeta' : 'qr',
            'estado_pago' => $estadoPago,
            'estado_emision' => $isFiscalInvoiceAttempt ? $cart->estado_emision : 'NO_APLICA',
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

    private function cancelCartLocally(object $cart, string $message, ?object $row = null, string $reason = ''): array
    {
        $updates = [
            'metodo_pago' => 'qr',
            'estado' => 'pendiente_pago',
            'estado_pago' => 'cancelado',
            'estado_emision' => 'NO_APLICA',
            'mensaje_emision' => trim($message) !== '' ? trim($message) : 'Cobro QR cancelado localmente.',
            'emitido_en' => null,
            'cerrado_en' => null,
            'updated_at' => now(),
        ];

        $updates = array_merge(
            $updates,
            $this->qrCancellationAuditColumns(
                $updates['mensaje_emision'],
                $reason,
                (int) ($row->transaction_id ?? $cart->qr_transaction_id ?? 0),
                'LOCAL'
            )
        );

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
        $connectTimeout = max(5, (int) config('services.qhantuy_checkout.connect_timeout', 20));
        $timeout = max(60, (int) config('services.qhantuy_checkout.timeout', 60));

        return Http::withHeaders([
            'X-API-Token' => $this->token(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->withOptions([
            'verify' => $verify,
            'force_ip_resolve' => 'v4',
            'http_version' => 1.1,
        ])->connectTimeout($connectTimeout)
            ->timeout($timeout);
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
            'connect_timeout' => (int) config('services.qhantuy_checkout.connect_timeout', 20),
            'timeout' => (int) config('services.qhantuy_checkout.timeout', 60),
            'has_token' => $token !== '',
            'has_appkey' => $appkey !== '',
            'appkey_prefix' => $appkey !== '' ? substr($appkey, 0, 8) : '',
            'has_profile_code' => $this->profileCode() !== '',
            'profile_code_prefix' => $this->profileCode() !== '' ? substr($this->profileCode(), 0, 8) : '',
        ];
    }

    public function checkout(Request $request)
    {
        $request->merge(['internal_code' => is_string($request->input('internal_code')) ? trim($request->input('internal_code')) : $request->input('internal_code')]);
        $request->validate(['internal_code' => ['required', 'string', 'max:120']]);
        $lock = Cache::lock('qhantuy-checkout:' . hash('sha256', trim($request->input('internal_code'))),
            max(90, (int) config('services.qhantuy_checkout.timeout', 60) + 30));
        if (!$lock->get()) {
            return response()->json(['ok' => false, 'message' => 'La orden ya se está procesando. Consulta su estado antes de reintentar.'], 409);
        }
        try {
            return $this->createCheckout($request);
        } finally {
            $lock->release();
        }
    }

    private function createCheckout(Request $request)
    {
        $requestStartedAt = microtime(true);
        $validated = $request->validate([
            'customer_email' => ['nullable', 'email', 'max:120'],
            'customer_first_name' => ['nullable', 'string', 'max:120'],
            'customer_last_name' => ['nullable', 'string', 'max:120'],
            'currency_code' => ['nullable', 'string', 'in:BOB'],
            'internal_code' => ['required', 'string', 'max:120'],
            'callback_url' => ['nullable', 'url', 'max:255'],
            'detail' => ['required', 'string', 'max:200'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'min:1'],
            'items.*.price' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', 'string', 'in:QRSIMPLE,CYBERSOURCE'],
            'payment_type' => ['nullable', 'string', 'in:EMBED,REDIRECT'],
            'return_url' => ['nullable', 'url:http,https', 'max:255'],
            'qr_format' => ['nullable', 'string', 'in:URL,BASE64'],
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
            'customer_email' => strtolower(trim((string) ($validated['customer_email'] ?? ''))),
            'customer_first_name' => trim((string) ($validated['customer_first_name'] ?? '')),
            'customer_last_name' => trim((string) ($validated['customer_last_name'] ?? '')),
            'currency_code' => $this->currencyCode(),
            'internal_code' => trim((string) $validated['internal_code']),
            'callback_url' => $this->callbackUrl(),
            'payment_method' => $validated['payment_method'] ?? 'QRSIMPLE',
            'transaction_type' => 'POST_PAYMENT',
            'detail' => trim((string) $validated['detail']),
            'items' => collect($validated['items'])->map(function ($item) {
                return [
                    'name' => trim((string) $item['name']),
                    'quantity' => (float) $item['quantity'],
                    'price' => round((float) $item['price'], 2),
                ];
            })->values()->all(),
        ];

        if ($payload['payment_method'] === 'CYBERSOURCE') {
            $payload['payment_type'] = 'REDIRECT';
            $returnUrl = $validated['return_url'] ?? config('services.qhantuy_checkout.return_url');
            if ($returnUrl) {
                $payload['return_url'] = $returnUrl;
            }
        } else {
            $payload['qr_format'] = $validated['qr_format'] ?? $validated['image_method'] ?? $this->imageMethod();
            $payload['payment_flow'] = 'CLIENT';
        }
        $payloadAmount = round((float) collect($payload['items'])->sum(fn ($i) => $i['quantity'] * $i['price']), 2);
        if ($payloadAmount < 0.10 || collect($payload['items'])->contains(fn ($item) => $item['price'] <= 0)) {
            return response()->json(['ok' => false, 'message' => 'El total mínimo es 0.10 BOB y cada precio debe ser mayor a cero.'], 422);
        }
        $cart = DB::table('facturacion_carts')->where('codigo_orden', $payload['internal_code'])->latest('id')->first();
        if ($cart && isset($cart->total) && abs(round((float) $cart->total, 2) - $payloadAmount) >= 0.01) {
            return response()->json(['ok' => false, 'message' => 'El importe solicitado no coincide con el total del carrito.'], 422);
        }
        // A stable request fingerprint prevents reusing an order for another amount or method.
        $requestHash = hash('sha256', json_encode($payload));
        $existing = DB::table('qhantuy_qr_payments')->where('internal_code', $payload['internal_code'])->first();
        if ($existing) {
            if (($existing->request_hash ?? null) !== $requestHash) {
                return response()->json(['ok' => false, 'code' => 'payment_order_conflict', 'message' => 'El código de orden ya está registrado. Consulta su estado o usa otro código para una nueva orden.'], 409);
            }
            if (empty($existing->transaction_id) || in_array($existing->payment_status, ['amount_mismatch', 'checkout_failed', 'creating'], true)) {
                return response()->json(['ok' => false, 'code' => 'payment_creation_unresolved', 'message' => 'La creación anterior no tiene un resultado utilizable. Conciliar con Qhantuy antes de crear otra orden.'], 409);
            }
            return response()->json(array_merge($this->buildCachedCheckPaymentsResponse($existing), [
                'internal_code' => $existing->internal_code,
                'payment_method' => $existing->payment_method,
                'payment_url' => $existing->payment_url,
                'reused' => true,
            ]));
        }
        // Reserve before sending: Qhantuy accepts duplicate internal_code values.
        // Keep uncertain requests reserved after timeouts to prevent a second charge order.
        DB::table('qhantuy_qr_payments')->insert([
            'internal_code' => $payload['internal_code'],
            'payment_method' => $payload['payment_method'],
            'request_hash' => $requestHash,
            'checkout_amount' => $payloadAmount,
            'checkout_currency' => $payload['currency_code'],
            'payment_status' => 'creating',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Log::debug('Qhantuy checkout payload prepared', [
            'internal_code' => $payload['internal_code'],
            'url' => $this->checkoutUrl(),
            'request_meta' => [
                'method' => $request->method(),
                'path' => $request->path(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 240),
            ],
            'payload' => $this->maskQhantuyPayloadForLogs($payload),
        ]);

        try {
            $response = $this->qhantuyClient()->post($this->checkoutUrl(), $payload);
            $body = $response->json();
            $elapsedMs = (int) round((microtime(true) - $requestStartedAt) * 1000);

            Log::debug('Qhantuy checkout response received', [
                'internal_code' => $payload['internal_code'],
                'status' => $response->status(),
                'successful' => $response->successful(),
                'elapsed_ms' => $elapsedMs,
                'headers' => [
                    'content_type' => $response->header('Content-Type'),
                    'server' => $response->header('Server'),
                    'content_length' => $response->header('Content-Length'),
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
            $payloadAmount = round((float) collect($payload['items'])->sum(fn ($i) => $i['quantity'] * $i['price']), 2);
            $providerAmount = $this->parseCheckoutAmount(
                data_get($body, 'checkout_amount')
                ?? data_get($body, 'items.0.checkout_amount')
            );
            $amount = $providerAmount !== null ? $providerAmount : $payloadAmount;
            $currency = strtoupper((string) data_get($body, 'checkout_currency', $this->currencyCode()));
            $imageData = trim((string) (
                data_get($body, 'image_data')
                ?? data_get($body, 'qr_url')
                ?? data_get($body, 'items.0.image_data')
                ?? data_get($body, 'items.0.qr_url')
                ?? ''
            ));

            // Qhantuy can return HTTP 200 when its bank provider failed. Such a
            // response is not a usable checkout unless it has a transaction ID
            // and a QR image; do not persist it as a pending/holding payment.
            $paymentUrl = trim((string) data_get($body, 'payment_url', ''));
            $providerRejected = data_get($body, 'process') !== true
                || (isset($body['internal_code']) && $body['internal_code'] !== $payload['internal_code']);
            $usableArtifact = $payload['payment_method'] === 'CYBERSOURCE'
                ? filter_var($paymentUrl, FILTER_VALIDATE_URL) && parse_url($paymentUrl, PHP_URL_SCHEME) === 'https'
                : $imageData !== '';
            if ($providerRejected || !is_numeric($transactionId) || (int) $transactionId <= 0 || !$usableArtifact) {
                DB::table('qhantuy_qr_payments')->where('internal_code', $payload['internal_code'])->update([
                    'payment_status' => 'checkout_failed',
                    'transaction_id' => is_numeric($transactionId) && (int) $transactionId > 0 ? (int) $transactionId : null,
                    'raw_checkout_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
                $providerMessage = trim((string) data_get($body, 'message', ''));
                $message = $providerMessage !== ''
                    ? $providerMessage
                    : 'Qhantuy no devolvió una orden utilizable para el método de pago solicitado.';

                Log::warning('Qhantuy respondió sin un checkout QR utilizable', [
                    'internal_code' => $payload['internal_code'],
                    'transaction_id' => $transactionId,
                    'has_image_data' => $imageData !== '',
                    'provider_process' => data_get($body, 'process'),
                    'provider_message' => $message,
                ]);

                return response()->json([
                    'ok' => false,
                    'code' => 'qr_checkout_failed',
                    'message' => $message,
                    'provider_message' => $message,
                    'internal_code' => $payload['internal_code'],
                    'payment_status' => 'failed',
                ], 502);
            }

            DB::table('qhantuy_qr_payments')->updateOrInsert(
                ['internal_code' => $payload['internal_code']],
                [
                    'transaction_id' => $transactionId !== null ? (int) $transactionId : null,
                    'payment_method' => $payload['payment_method'],
                    'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
                    'request_hash' => $requestHash,
                    // Keep the amount SAFE asked Qhantuy to collect. The provider's
                    // response is checked separately and must never replace this baseline.
                    'checkout_amount' => $payloadAmount,
                    'checkout_currency' => $currency,
                    'customer_email' => $payload['customer_email'],
                    'customer_first_name' => $payload['customer_first_name'],
                    'customer_last_name' => $payload['customer_last_name'],
                    'detail' => $payload['detail'],
                    'image_data' => $imageData !== '' ? $imageData : null,
                    'payment_status' => 'holding',
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
                'elapsed_ms' => $elapsedMs,
            ]);

            if ($currency !== $payload['currency_code'] || ($providerAmount !== null && abs($providerAmount - $payloadAmount) >= 0.01)) {
                Log::critical('Qhantuy devolvió un monto QR distinto al solicitado.', [
                    'internal_code' => $payload['internal_code'],
                    'transaction_id' => $transactionId,
                    'requested_amount' => $payloadAmount,
                    'provider_amount' => $providerAmount,
                ]);

                $cancelled = false;
                if ($transactionId !== null && (int) $transactionId > 0) {
                    $cancelRequest = Request::create('/api/factura-venta/qr/cancel', 'POST', [
                        'transaction_id' => (int) $transactionId,
                        'internal_code' => $payload['internal_code'],
                        'reason' => 'Monto del QR distinto al total de la venta.',
                    ]);
                    $cancelResponse = $this->cancelPayment($cancelRequest);
                    $cancelBody = json_decode($cancelResponse->getContent(), true);
                    $cancelled = $cancelResponse->isSuccessful()
                        && (bool) data_get($cancelBody, 'ok', false);
                }

                if (!$cancelled) {
                    DB::table('qhantuy_qr_payments')
                        ->where('internal_code', $payload['internal_code'])
                        ->update([
                            'payment_status' => 'amount_mismatch',
                            'last_message' => 'Monto QR distinto al total solicitado; cancelación no confirmada.',
                            'updated_at' => now(),
                        ]);
                }

                return response()->json([
                    'ok' => false,
                    'code' => 'qr_amount_mismatch',
                    'message' => $cancelled
                        ? 'Qhantuy devolvió un QR por un monto distinto. El QR fue cancelado; no se realizó ningún cobro. Intenta nuevamente.'
                        : 'Qhantuy devolvió un QR por un monto distinto y no se pudo confirmar su cancelación. No pagues ese QR; contacta a soporte antes de reintentar.',
                    'internal_code' => $payload['internal_code'],
                    'transaction_id' => $transactionId !== null ? (int) $transactionId : null,
                    'requested_amount' => $payloadAmount,
                    'checkout_amount' => $providerAmount,
                    'payment_status' => $cancelled ? 'cancelled' : $paymentStatus,
                    'qr_cancel_confirmed' => $cancelled,
                ], 409);
            }

            return response()->json([
                'ok' => true,
                'payment_method' => $payload['payment_method'],
                'payment_url' => $paymentUrl !== '' ? $paymentUrl : null,
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
                'payment_status' => 'holding',
                'qhantuy' => is_array($body) ? $body : null,
            ]);
        } catch (ConnectionException $e) {
            Log::error('Qhantuy checkout connection error', [
                'url' => $this->checkoutBaseUrl() . '/checkout',
                'normalized_url' => $this->checkoutUrl(),
                'connect_timeout' => (int) config('services.qhantuy_checkout.connect_timeout', 20),
                'timeout' => (int) config('services.qhantuy_checkout.timeout', 60),
                'ssl_verify' => filter_var(config('services.qhantuy_checkout.ssl_verify', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
                'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
                'msg' => $e->getMessage(),
                'internal_code' => $payload['internal_code'],
                'exception_class' => get_class($e),
                'payload' => $this->maskQhantuyPayloadForLogs($payload),
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
                'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
                'msg' => $e->getMessage(),
                'exception_class' => get_class($e),
                'payload' => $this->maskQhantuyPayloadForLogs($payload),
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
                'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
                'msg' => $e->getMessage(),
                'exception_class' => get_class($e),
                'payload' => isset($payload) && is_array($payload) ? $this->maskQhantuyPayloadForLogs($payload) : null,
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
            'transaction_id' => ['required', 'integer', 'min:1'],
            'profile_code' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:500'],
            'internal_code' => ['required', 'string', 'max:120'],
            'checkout_amount' => ['required', 'numeric', 'gte:0'],
            'checkout_currency_code' => ['nullable', 'string', 'in:BOB'],
            'checkout_currency' => ['nullable', 'string', 'in:BOB'],
            'status' => ['required', 'string', 'in:success'],
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

        if (strtolower(trim((string) ($row->payment_status ?? ''))) === 'amount_mismatch') {
            Log::critical('Qhantuy callback rejected because payment is blocked for amount mismatch.', [
                'internal_code' => $internalCode,
                'transaction_id' => $validated['transaction_id'] ?? null,
            ]);

            return response()->json([
                'ok' => false,
                'code' => 'qr_amount_mismatch',
                'message' => 'El pago está bloqueado por una diferencia de monto y requiere revisión manual.',
            ], 409);
        }

        $incomingAmount = round((float) $validated['checkout_amount'], 2);
        $storedAmount = round((float) ($row->checkout_amount ?? 0), 2);
        if ($storedAmount <= 0 || abs($incomingAmount - $storedAmount) > 0.00001) {
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

        $verification = Request::create('/api/factura-venta/pagos/check-payments', 'POST', [
            'payment_ids' => [$incomingTxn],
            'internal_code' => $internalCode,
        ]);
        // Request attributes cannot be supplied through query parameters or JSON input.
        $verification->attributes->set('qhantuy_callback_verification', true);
        $response = $this->checkPayments($verification);
        $result = json_decode($response->getContent(), true);
        if (!$response->isSuccessful() || ($result['payment_status'] ?? null) !== 'success') {
            return response()->json([
                'ok' => false,
                'message' => 'El pago aún no pudo confirmarse con Qhantuy. Se requiere reintento de notificación o consulta posterior.',
            ], 503);
        }
        DB::table('qhantuy_qr_payments')->where('id', $row->id)->update([
            'raw_callback_params' => json_encode($validated, JSON_UNESCAPED_UNICODE),
        ]);
        return $response;
    }

    private function manualSimulationAllowed(): bool
    {
        $connection = config('database.default');
        return app()->environment('local', 'testing')
            && (bool) config('services.qhantuy_checkout.manual_simulation', false)
            && parse_url($this->checkoutBaseUrl(), PHP_URL_HOST) === 'testingcheckout.qhantuy.com'
            && ($connection === 'sqlite' || in_array(config("database.connections.$connection.host"), ['127.0.0.1', 'localhost', '::1'], true));
    }

    public function simulatePayment(Request $request)
    {
        abort_unless($this->manualSimulationAllowed(), 403, 'La simulación manual solo está habilitada en pruebas locales.');
        $data = $request->validate([
            'internal_code' => ['required', 'string', 'max:255'],
            'transaction_id' => ['required', 'integer', 'min:1'],
            'checkout_amount' => ['required', 'numeric', 'min:0.10'],
            'confirm_simulation' => ['required', 'accepted'],
        ]);

        return DB::transaction(function () use ($data) {
            $row = DB::table('qhantuy_qr_payments')->where('internal_code', $data['internal_code'])->lockForUpdate()->first();
            abort_unless($row, 404, 'Orden local no encontrada.');
            abort_unless((int) $row->transaction_id === (int) $data['transaction_id']
                && abs((float) $row->checkout_amount - (float) $data['checkout_amount']) < 0.005, 409, 'La transacción o el importe no coincide.');
            $raw = json_decode((string) ($row->raw_check_response ?? ''), true);
            if ($row->payment_status === 'success' && ($raw['simulation'] ?? false) === true) {
                return response()->json($this->buildCachedCheckPaymentsResponse($row));
            }
            abort_unless(in_array($row->payment_status, ['holding', 'expired'], true), 409, 'La orden no admite simulación en su estado actual.');
            $audit = ['simulation' => true, 'source' => 'local_manual', 'simulated_at' => now()->toIso8601String(), 'actor_id' => auth('api')->id()];
            DB::table('qhantuy_qr_payments')->where('id', $row->id)->update([
                'payment_status' => 'success', 'paid_at' => now(), 'updated_at' => now(),
                'last_message' => 'Pago SIMULADO manualmente en local; no confirmado por Qhantuy.',
                'raw_check_response' => json_encode($audit),
            ]);
            $this->syncCartPaymentState($row->internal_code, 'success', (int) $row->transaction_id, 'Pago SIMULADO localmente.');
            Log::notice('Pago simulado manualmente en local', array_merge($audit, ['transaction_id' => $row->transaction_id, 'internal_code' => $row->internal_code]));
            return response()->json($this->buildCachedCheckPaymentsResponse(DB::table('qhantuy_qr_payments')->where('id', $row->id)->first()));
        });
    }

    public function checkPayments(Request $request)
    {
        $validated = $request->validate([
            'payment_ids' => ['nullable', 'array', 'min:1', 'max:1'],
            'payment_ids.*' => ['integer', 'min:1'],
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
        if (!empty($validated['internal_code'])) {
            $row = DB::table('qhantuy_qr_payments')
                ->where('internal_code', trim((string) $validated['internal_code']))
                ->first();
            if ($row && !empty($row->transaction_id)) {
                if ($paymentIds !== [] && $paymentIds !== [(int) $row->transaction_id]) {
                    return response()->json(['ok' => false, 'message' => 'La transacción no corresponde a la orden indicada.'], 422);
                }
                $paymentIds = [(int) $row->transaction_id];
            }
        }

        if (!$row && $paymentIds !== []) {
            $row = DB::table('qhantuy_qr_payments')
                ->where('transaction_id', (int) $paymentIds[0])
                ->first();
        }

        if ($row && data_get(json_decode((string) ($row->raw_check_response ?? ''), true), 'simulation') === true) {
            abort_unless($this->manualSimulationAllowed(), 403, 'Este registro corresponde a una simulación local.');
            return response()->json($this->buildCachedCheckPaymentsResponse($row));
        }

        if ($row && strtolower(trim((string) ($row->payment_status ?? ''))) === 'amount_mismatch') {
            return response()->json([
                'ok' => false,
                'code' => 'qr_amount_mismatch',
                'message' => 'El QR tiene una diferencia de monto y está bloqueado para cobro y facturación. Requiere revisión manual.',
                'transaction_id' => (int) ($row->transaction_id ?? 0),
                'internal_code' => (string) ($row->internal_code ?? ''),
                'checkout_amount' => round((float) ($row->checkout_amount ?? 0), 2),
                'payment_status' => 'amount_mismatch',
            ], 409);
        }

        if ($paymentIds === []) {
            return response()->json([
                'ok' => false,
                'message' => 'Debes enviar payment_ids o internal_code con transaction_id registrado.',
            ], 422);
        }

        if ($row && !$request->attributes->get('qhantuy_callback_verification') && $this->shouldUseCachedCheckResponse($row)) {
            Log::debug('Qhantuy check-payments resolved from cache', [
                'transaction_id' => $row->transaction_id ?? null,
                'internal_code' => $row->internal_code ?? null,
                'payment_status' => $row->payment_status ?? null,
            ]);

            return response()->json($this->buildCachedCheckPaymentsResponse($row));
        }

        try {
            $client = $this->qhantuyClient();
            if ($request->attributes->get('qhantuy_callback_verification')) {
                $client->connectTimeout(3)->timeout(10);
            }
            $response = $client->post($this->checkPaymentsUrl(), [
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

            if (!is_array($body) || data_get($body, 'process') !== true || !is_array(data_get($body, 'items.0'))) {
                return response()->json(['ok' => false, 'message' => 'Qhantuy no devolvió una consulta de pago válida.'], 502);
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
                ?? 0
            );
            if (!in_array($transactionId, $paymentIds, true)) {
                Log::critical('Qhantuy devolvió una transacción distinta a la consultada; no se actualizará ningún pago.', [
                    'requested_payment_ids' => $paymentIds,
                    'provider_transaction_id' => $transactionId,
                    'internal_code' => $row->internal_code ?? ($validated['internal_code'] ?? null),
                    'provider_status' => data_get($source, 'payment_status') ?? data_get($source, 'status'),
                ]);

                return response()->json([
                    'ok' => false,
                    'code' => 'qr_transaction_mismatch',
                    'message' => 'Qhantuy respondió con una transacción distinta a la consultada. El estado no se actualizará automáticamente.',
                    'transaction_id' => $transactionId,
                    'payment_status' => 'unknown',
                ], 502);
            }
            $status = $this->normalizeQrPaymentStatus(
                $source['payment_status']
                ?? $source['payment_status ']
                ?? data_get($body, 'payment_status')
                ?? $source['status']
                ?? data_get($body, 'status')
                ?? 'holding'
            );
            $amountFromProvider = $this->parseCheckoutAmount(
                data_get($source, 'checkout_amount')
                ?? data_get($body, 'checkout_amount')
            );
            $currency = strtoupper(trim((string) (
                data_get($source, 'checkout_currency')
                ?? data_get($body, 'checkout_currency')
                ?? ''
            )));
            $qrUrl = trim((string) (
                data_get($source, 'qr_url')
                ?? data_get($body, 'qr_url')
                ?? ''
            ));

            $target = $row ?: DB::table('qhantuy_qr_payments')->where('transaction_id', $transactionId)->first();
            if (!$target || (empty($target->transaction_id) && ($source['internal_code'] ?? null) !== $target->internal_code)
                || $currency !== $target->checkout_currency
                || (isset($source['internal_code']) && $source['internal_code'] !== $target->internal_code)) {
                return response()->json(['ok' => false, 'code' => 'payment_identity_mismatch', 'message' => 'La moneda o referencia de la orden no coincide con el registro local.'], 409);
            }
            // A confirmed payment cannot regress due to a delayed pending/cancelled response.
            if ($target->payment_status === 'success' && $status !== 'success') {
                return response()->json($this->buildCachedCheckPaymentsResponse($target));
            }
            if ($status === 'success' && $amountFromProvider === null) {
                Log::critical('Qhantuy confirmó el pago sin devolver el importe; no se marcará como pagado.', [
                    'internal_code' => $target->internal_code ?? ($validated['internal_code'] ?? null),
                    'transaction_id' => $transactionId,
                    'requested_amount' => round((float) ($target->checkout_amount ?? 0), 2),
                    'provider_status' => $status,
                ]);

                return response()->json([
                    'ok' => false,
                    'code' => 'qr_amount_unverified',
                    'message' => 'Qhantuy confirmó el pago, pero no informó el importe para validarlo. Se requiere revisión antes de facturar.',
                    'transaction_id' => $transactionId,
                    'internal_code' => $target->internal_code ?? ($validated['internal_code'] ?? null),
                    'requested_amount' => round((float) ($target->checkout_amount ?? 0), 2),
                    'checkout_amount' => null,
                    'payment_status' => 'amount_unverified',
                ], 409);
            }
            $amount = $amountFromProvider !== null && $amountFromProvider > 0
                ? $amountFromProvider
                : round((float) ($target->checkout_amount ?? 0), 2);
            $expectedAmount = round((float) ($target->checkout_amount ?? 0), 2);
            if ($target && ($expectedAmount <= 0 || ($amountFromProvider !== null && abs($amountFromProvider - $expectedAmount) >= 0.01))) {
                Log::critical('La consulta QR devolvió un monto distinto al solicitado; no se marcará el pago como confirmado.', [
                    'internal_code' => $target->internal_code ?? null,
                    'transaction_id' => $transactionId,
                    'requested_amount' => $expectedAmount,
                    'provider_amount' => $amountFromProvider,
                    'provider_status' => $status,
                ]);

                DB::table('qhantuy_qr_payments')
                    ->where('id', $target->id)
                    ->update([
                        'payment_status' => 'amount_mismatch',
                        'raw_check_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                        'last_message' => 'Qhantuy devolvió un importe distinto al solicitado; requiere revisión manual.',
                        'updated_at' => now(),
                    ]);

                return response()->json([
                    'ok' => false,
                    'code' => 'qr_amount_mismatch',
                    'message' => 'Qhantuy devolvió un importe distinto al de la venta. El pago no se conciliará automáticamente; requiere revisión.',
                    'transaction_id' => $transactionId,
                    'internal_code' => $target->internal_code ?? ($validated['internal_code'] ?? null),
                    'requested_amount' => $expectedAmount,
                    'checkout_amount' => $amountFromProvider,
                    'payment_status' => 'amount_mismatch',
                ], 409);
            }
            if ($target) {
                $resolvedImageData = trim((string) ($target->image_data ?? ''));
                if ($resolvedImageData === '' && $qrUrl !== '') {
                    $resolvedImageData = $qrUrl;
                }

                $updated = DB::table('qhantuy_qr_payments')
                    ->where('id', $target->id)
                    ->when($status !== 'success', fn ($query) => $query->where('payment_status', '!=', 'success'))
                    ->update([
                        'transaction_id' => $transactionId,
                        'checkout_amount' => $target->checkout_amount,
                        'checkout_currency' => $currency !== '' ? $currency : $target->checkout_currency,
                        'image_data' => $resolvedImageData !== '' ? $resolvedImageData : $target->image_data,
                        'payment_url' => data_get($source, 'payment_url') ?? $target->payment_url,
                        'payment_status' => $status,
                        'raw_check_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                        'last_message' => (string) data_get($body, 'message', ''),
                        'paid_at' => $status === 'success' ? ($target->paid_at ?? now()) : $target->paid_at,
                        'cancelled_at' => $status === 'cancelled' ? now() : $target->cancelled_at,
                        'updated_at' => now(),
                    ]);

                if (!$updated) {
                    return response()->json($this->buildCachedCheckPaymentsResponse(
                        DB::table('qhantuy_qr_payments')->where('id', $target->id)->first()
                    ));
                }

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
                'payment_method' => $target->payment_method ?? 'QRSIMPLE',
                'payment_url' => data_get($source, 'payment_url') ?? $target->payment_url ?? null,
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

        if ($row && $transactionId > 0 && (int) $row->transaction_id !== $transactionId) {
            return response()->json(['ok' => false, 'message' => 'La transacción no corresponde a la orden indicada.'], 422);
        }
        if ($cart && $row && $this->normalizeQrInternalCode($cart->codigo_orden) !== $this->normalizeQrInternalCode($row->internal_code)) {
            return response()->json(['ok' => false, 'message' => 'El carrito no corresponde a la orden indicada.'], 422);
        }
        $transactionId = $transactionId > 0 ? $transactionId : (int) ($row->transaction_id ?? 0);
        $internalCode = $internalCode !== '' ? $internalCode : trim((string) ($row->internal_code ?? ($cart->codigo_orden ?? '')));
        $normalizedInternalCode = $internalCode !== '' ? $this->normalizeQrInternalCode($internalCode) : '';

        if ($transactionId <= 0 && $cart) {
            $localMessage = $reason !== ''
                ? 'Cobro QR cancelado localmente. Motivo: ' . $reason
                : 'Cobro QR cancelado localmente porque no existe un QR generado activo para anular.';

            return response()->json($this->cancelCartLocally($cart, $localMessage, $row, $reason));
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
            $updatedCart = $this->applyQrCancellationAuditToCart(
                $cart,
                (string) ($row->last_message ?? 'El cobro QR ya estaba cancelado.'),
                $reason,
                $transactionId,
                'LOCAL',
                $internalCode,
                $normalizedInternalCode
            );

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
                'cart' => $updatedCart,
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

            $processOk = data_get($body, 'process') === true;
            if (!$processOk || (int) data_get($body, 'item.transaction_id') !== $transactionId
                || data_get($body, 'item.current_status') !== 'cancelled') {
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
                $updated = DB::table('qhantuy_qr_payments')
                    ->where('id', $row->id)
                    ->where('payment_status', '!=', 'success')
                    ->update([
                        'payment_status' => $status,
                        'raw_check_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                        'last_message' => $message,
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if ($row && !$updated) {
                return response()->json(['ok' => false, 'message' => 'El pago ya fue confirmado y no puede cancelarse.'], 409);
            }

            $this->syncCartPaymentState($internalCode, $status, $transactionId, $message);

            $updatedCart = $this->applyQrCancellationAuditToCart(
                $cart,
                $message,
                $reason,
                $transactionId,
                'PROVEEDOR',
                $internalCode,
                $normalizedInternalCode
            );

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
        $estado = strtolower(trim((string) ($cart->estado ?? '')));
        $estadoEmision = strtoupper(trim((string) ($cart->estado_emision ?? 'NO_APLICA')));
        $canalEmision = strtolower(trim((string) ($cart->canal_emision ?? '')));
        $metodoPago = strtolower(trim((string) ($cart->metodo_pago ?? '')));
        $isQr = $canalEmision === 'qr' || $metodoPago === 'qr' || trim((string) ($cart->qr_transaction_id ?? '')) !== '';
        $isRejectedDiscarded = $estado === 'descartado' && $estadoEmision === 'RECHAZADA';
        $isPaidShipmentIncident = $isQr
            && in_array($estadoPago, ['pagado', 'paid', 'success', 'completed', 'approved', 'confirmed'], true)
            && str_contains(strtoupper((string) ($cart->mensaje_emision ?? '')), 'QR_MISSING_SHIPMENT_DATA');
        $isAmountMismatchIncident = $isQr
            && str_contains(strtoupper((string) ($cart->mensaje_emision ?? '')), 'QR_AMOUNT_MISMATCH');

        if (!(($isQr && in_array($estadoPago, ['cancelado', 'fallido'], true)) || $isRejectedDiscarded || $isPaidShipmentIncident || $isAmountMismatchIncident)) {
            return response()->json([
                'ok' => false,
                'message' => 'Solo se puede revisar una incidencia QR cancelada/fallida, un pago confirmado con datos de envío incompletos o una factura rechazada descartada.',
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
        $note = trim((string) ($validated['note'] ?? '')) ?: ($isRejectedDiscarded
            ? 'Factura rechazada descartada y revisada manualmente.'
            : 'Incidencia QR revisada manualmente.');

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
            'message' => $isRejectedDiscarded
                ? 'La factura rechazada descartada fue marcada como revisada.'
                : ($isPaidShipmentIncident
                    ? 'La incidencia del pago confirmado fue marcada como revisada.'
                    : 'La incidencia QR fue marcada como revisada.'),
            'cart' => DB::table('facturacion_carts')->where('id', (int) $cart->id)->first(),
        ]);
    }

    private function shouldUseCachedCheckResponse(object $row): bool
    {
        if (empty($row->updated_at) || empty($row->raw_check_response)) {
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
            'payment_method' => $row->payment_method ?? 'QRSIMPLE',
            'payment_url' => $row->payment_url ?? null,
            'qr_url' => (string) ($row->image_data ?? ''),
            'image_data' => (string) ($row->image_data ?? ''),
            'payment_status' => $status,
            'estado_pago' => $this->qrPaymentStateFromStatus($status),
            'qhantuy' => is_array($raw) ? $raw : null,
            'simulation' => ($raw['simulation'] ?? false) === true,
        ];
    }
}
