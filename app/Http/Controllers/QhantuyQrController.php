<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Services\QhantuyQrCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class QhantuyQrController extends Controller
{
    public const CHECKOUT_REQUESTED = 'checkout_requested';

    private static ?bool $hasQrCancelAuditColumns = null;

    public function __construct(private readonly QhantuyQrCredentials $credentials)
    {
    }

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

    private function syncCartPaymentState(
        string $internalCode,
        string $paymentStatus,
        ?int $transactionId = null,
        ?string $message = null,
        ?int $cartId = null,
    ): void {
        $normalizedInternalCode = $this->normalizeQrInternalCode($internalCode);

        $cart = DB::table('facturacion_carts')
            ->when($cartId !== null, fn ($query) => $query->where('id', $cartId))
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
            'metodo_pago' => 'qr',
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

    private function appkeyForPayment(?object $payment): string
    {
        $credentialId = (int) ($payment->qhantuy_credential_id ?? 0);

        return $credentialId > 0 ? $this->credentials->appkey($credentialId) : $this->appkey();
    }

    private function paymentByCode(string $internalCode): ?object
    {
        if ($internalCode === '') {
            return null;
        }

        $payments = DB::table('qhantuy_qr_payments');
        $payment = (clone $payments)->where('internal_code', $internalCode)->first();
        $normalized = $this->normalizeQrInternalCode($internalCode);

        return $payment ?? ($normalized !== $internalCode
            ? $payments->where('internal_code', $normalized)->first()
            : null);
    }

    private function credentialForCheckout(?object $cart, bool $requireCashierAssignment = false): ?int
    {
        $cashier = trim((string) ($cart->origen_usuario_id ?? ''));
        $branch = trim((string) ($cart->origen_sucursal_codigo ?? ''));
        $pointOfSale = trim((string) ($cart->origen_sucursal_id ?? ''));

        if ($cashier !== '' && !str_starts_with($cashier, 'cliente_online_') && $branch !== '' && $pointOfSale !== '') {
            $assigned = $this->credentials->activeCredentialId($cashier, $branch, $pointOfSale);
            if ($assigned !== null) {
                return $assigned;
            }
        }

        if ($requireCashierAssignment) {
            return null;
        }

        $appkey = $this->appkey();

        return $appkey === '' ? null : $this->credentials->rememberKey($appkey);
    }

    private function withoutProviderCredentials(mixed $value, #[\SensitiveParameter] string $appkey): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $entry) {
                $field = strtolower(str_replace(['-', '_'], '', (string) $key));
                if (in_array($field, ['appkey', 'xapitoken', 'apitoken', 'profilecode'], true)) {
                    unset($value[$key]);
                    continue;
                }

                $value[$key] = $this->withoutProviderCredentials($entry, $appkey);
            }
        } elseif (is_string($value) && $appkey !== '') {
            $value = str_replace($appkey, '[redacted]', $value);
        }

        return $value;
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

    private function qhantuyClient(?int $timeoutLimit = null)
    {
        $verify = filter_var(config('services.qhantuy_checkout.ssl_verify', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        $connectTimeout = max(5, (int) config('services.qhantuy_checkout.connect_timeout', 20));
        $timeout = max(10, (int) config('services.qhantuy_checkout.timeout', 60));

        if ($timeoutLimit !== null) {
            $timeout = min($timeout, $timeoutLimit);
            $connectTimeout = min($connectTimeout, $timeout);
        }

        return Http::withHeaders([
            'X-API-Token' => $this->token(),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->withOptions([
            'verify' => $verify,
            'force_ip_resolve' => 'v4',
            'http_version' => 1.1,
            'allow_redirects' => false,
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
            'has_profile_code' => $this->profileCode() !== '',
        ];
    }

    public function checkout(Request $request)
    {
        return $this->createCheckout($request);
    }

    public function checkoutForCart(Request $request, object $cart)
    {
        return $this->createCheckout($request, $cart);
    }

    private function createCheckout(Request $request, ?object $cart = null)
    {
        $requestStartedAt = microtime(true);
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
            'payment_method' => ['nullable', 'string', 'in:QRSIMPLE,T2P'],
            'payment_type' => ['nullable', 'string', 'in:EMBED,REDIRECT'],
            'image_method' => ['nullable', 'string', 'in:URL,BASE64'],
        ]);

        $paymentMethod = strtoupper(trim((string) ($validated['payment_method'] ?? 'QRSIMPLE')));

        Log::debug('Qhantuy checkout request received', [
            'internal_code' => (string) ($validated['internal_code'] ?? ''),
            'request_keys' => array_keys($validated),
            'items_count' => count((array) ($validated['items'] ?? [])),
            'config' => $this->maskedCheckoutConfig(),
        ]);

        if ($this->token() === '' || $this->checkoutBaseUrl() === '' || $this->callbackUrl() === '') {
            Log::error('Qhantuy checkout missing configuration', [
                'internal_code' => (string) ($validated['internal_code'] ?? ''),
                'config' => $this->maskedCheckoutConfig(),
            ]);

            return response()->json([
                'ok' => false,
                'payment_method' => $paymentMethod,
                'message' => 'Configuracion Qhantuy incompleta en SAFE.',
            ], 500);
        }

        $payload = [
            'customer_email' => strtolower(trim((string) $validated['customer_email'])),
            'customer_first_name' => trim((string) $validated['customer_first_name']),
            'customer_last_name' => trim((string) $validated['customer_last_name']),
            'currency_code' => $this->currencyCode(),
            'internal_code' => trim((string) $validated['internal_code']),
            'callback_url' => $this->callbackUrl(),
            'payment_method' => $paymentMethod,
            'detail' => trim((string) $validated['detail']),
            'items' => collect($validated['items'])->map(function ($item) {
                return [
                    'name' => trim((string) $item['name']),
                    'quantity' => (float) $item['quantity'],
                    'price' => round((float) $item['price'], 2),
                ];
            })->values()->all(),
        ];

        if ($paymentMethod === 'QRSIMPLE') {
            $payload['payment_type'] = trim((string) ($validated['payment_type'] ?? 'EMBED'));
            $payload['image_method'] = trim((string) ($validated['image_method'] ?? $this->imageMethod()));
        }

        $payloadAmount = round((float) collect($payload['items'])->sum(fn ($item) => $item['quantity'] * $item['price']), 2);
        $checkout = null;
        $ownsCheckout = false;

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
            $existing = DB::table('qhantuy_qr_payments')->where('internal_code', $payload['internal_code'])->first();
            if ($existing) {
                return $this->existingCheckout($existing, $payloadAmount, $payload['currency_code'], $cart->id ?? null, $paymentMethod);
            }

            $credentialId = $this->credentialForCheckout($cart, $paymentMethod === 'T2P');
            if ($credentialId === null) {
                return response()->json([
                    'ok' => false,
                    'code' => $paymentMethod === 'T2P' ? 't2p_cashier_assignment_missing' : 'qhantuy_cashbox_missing',
                    'payment_method' => $paymentMethod,
                    'message' => $paymentMethod === 'T2P'
                        ? 'Esta cajera no tiene una caja Qhantuy POS asignada para cobrar con tarjeta sin contacto.'
                        : 'La caja general de Qhantuy no está configurada en el API.',
                ], $paymentMethod === 'T2P' ? 422 : 500);
            }

            $payload['appkey'] = $this->credentials->appkey($credentialId);
            $ownsCheckout = DB::table('qhantuy_qr_payments')->insertOrIgnore([
                'internal_code' => $payload['internal_code'],
                'qhantuy_cart_id' => $cart->id ?? null,
                'qhantuy_credential_id' => $credentialId,
                'payment_method' => $paymentMethod,
                'checkout_amount' => $payloadAmount,
                'checkout_currency' => $payload['currency_code'],
                'customer_email' => $payload['customer_email'],
                'customer_first_name' => $payload['customer_first_name'],
                'customer_last_name' => $payload['customer_last_name'],
                'detail' => $payload['detail'],
                'payment_status' => self::CHECKOUT_REQUESTED,
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;
            $checkout = DB::table('qhantuy_qr_payments')->where('internal_code', $payload['internal_code'])->first();
            if (!$ownsCheckout || !$checkout) {
                return $checkout
                    ? $this->existingCheckout($checkout, $payloadAmount, $payload['currency_code'], $cart->id ?? null, $paymentMethod)
                    : $this->checkoutAwaitingConfirmation(409, $paymentMethod);
            }

            $response = $this->qhantuyClient()->post($this->checkoutUrl(), $payload);
            $body = $this->withoutProviderCredentials($response->json(), $payload['appkey']);
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
                if (in_array($response->status(), [400, 401, 403, 404, 422], true)
                    && !$this->providerReturnedCheckoutId($body)) {
                    $this->releaseRejectedCheckout($checkout);
                }
                Log::warning('Qhantuy checkout rejected', ['status' => $response->status(), 'body' => $body, 'internal_code' => $payload['internal_code']]);
                return response()->json([
                    'ok' => false,
                    'payment_method' => $paymentMethod,
                    'message' => (string) data_get($body, 'message', 'Qhantuy rechazo la solicitud.'),
                    'status_code' => $response->status(),
                    'qhantuy' => is_array($body) ? $body : null,
                ], $response->status());
            }

            $transactionId = $this->providerReturnedCheckoutId($body);
            $paymentStatus = $this->normalizeQrPaymentStatus(
                data_get($body, 'payment_status')
                ?? data_get($body, 'items.0.payment_status')
                ?? data_get($body, 'status')
                ?? data_get($body, 'items.0.status')
                ?? 'holding'
            );
            $providerAmount = $this->parseCheckoutAmount(
                data_get($body, 'checkout_amount')
                ?? data_get($body, 'items.0.checkout_amount')
            );
            if ($paymentStatus === 'success' && $providerAmount === null) {
                $paymentStatus = 'holding';
            }
            $amount = $providerAmount !== null ? $providerAmount : $payloadAmount;
            $currency = strtoupper(trim((string) (data_get($body, 'checkout_currency') ?? data_get($body, 'items.0.checkout_currency') ?? $payload['currency_code'])));
            $imageData = trim((string) (
                data_get($body, 'image_data')
                ?? data_get($body, 'qr_url')
                ?? data_get($body, 'items.0.image_data')
                ?? data_get($body, 'items.0.qr_url')
                ?? ''
            ));

            // Qhantuy can return HTTP 200 when its bank provider failed. Such a
            // response is not a usable checkout unless it has a transaction ID and a QR image.
            $providerRejected = data_get($body, 'process') === false;
            $requiresImage = $paymentMethod === 'QRSIMPLE';
            if ($providerRejected || (int) $transactionId <= 0 || ($requiresImage && $imageData === '')) {
                if ($providerRejected && (int) $transactionId <= 0) {
                    $this->releaseRejectedCheckout($checkout);
                }
                $providerMessage = trim((string) data_get($body, 'message', ''));
                $message = $providerMessage !== ''
                    ? $providerMessage
                    : ($requiresImage
                        ? 'Qhantuy no devolvió un identificador de transacción y una imagen QR.'
                        : 'Qhantuy no devolvió un identificador para el cobro por tarjeta sin contacto.');

                Log::warning('Qhantuy respondió sin un checkout QR utilizable', [
                    'internal_code' => $payload['internal_code'],
                    'payment_method' => $paymentMethod,
                    'transaction_id' => $transactionId,
                    'has_image_data' => $imageData !== '',
                    'provider_process' => data_get($body, 'process'),
                    'provider_message' => $message,
                ]);

                return response()->json([
                    'ok' => false,
                    'code' => 'qr_checkout_failed',
                    'payment_method' => $paymentMethod,
                    'message' => $message,
                    'provider_message' => $message,
                    'internal_code' => $payload['internal_code'],
                    'payment_status' => 'failed',
                ], 502);
            }

            $amountMismatch = $providerAmount !== null && abs($providerAmount - $payloadAmount) >= 0.01;
            $currencyMismatch = $currency !== $payload['currency_code'];
            $updated = DB::table('qhantuy_qr_payments')
                ->where('id', $checkout->id)
                ->whereNull('transaction_id')
                ->where('payment_status', self::CHECKOUT_REQUESTED)
                ->update([
                    'transaction_id' => $transactionId,
                    'payment_method' => $paymentMethod,
                    'image_data' => $amountMismatch || $currencyMismatch ? null : $imageData,
                    'payment_status' => $amountMismatch || $currencyMismatch ? 'amount_mismatch' : $paymentStatus,
                    'raw_checkout_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                    'last_message' => (string) data_get($body, 'message', ''),
                    'updated_at' => now(),
                ]);
            if ($updated !== 1) {
                $current = DB::table('qhantuy_qr_payments')->where('id', $checkout->id)->first();

                return $current ? $this->existingCheckout($current, $payloadAmount, $payload['currency_code'], $cart->id ?? null, $paymentMethod)
                    : $this->checkoutAwaitingConfirmation(409, $paymentMethod);
            }

            Log::debug('Qhantuy checkout persisted', [
                'internal_code' => $payload['internal_code'],
                'transaction_id' => $transactionId,
                'payment_status' => $paymentStatus,
                'checkout_amount' => $amount,
                'checkout_currency' => $currency,
                'has_image_data' => $imageData !== '',
                'elapsed_ms' => $elapsedMs,
            ]);

            if ($amountMismatch || $currencyMismatch) {
                Log::critical('Qhantuy devolvió un importe o una moneda distinta para el QR.', [
                    'internal_code' => $payload['internal_code'],
                    'transaction_id' => $transactionId,
                    'requested_amount' => $payloadAmount,
                    'provider_amount' => $providerAmount,
                    'provider_currency' => $currency,
                ]);

                $cancelled = false;
                if ($transactionId !== null && (int) $transactionId > 0) {
                    $cancelRequest = Request::create('/api/factura-venta/qr/cancel', 'POST', [
                        'transaction_id' => (int) $transactionId,
                        'internal_code' => $payload['internal_code'],
                        'reason' => 'Importe o moneda del QR diferente a la venta.',
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
                            'last_message' => 'Monto de cobro distinto al total solicitado; cancelación no confirmada.',
                            'updated_at' => now(),
                        ]);
                }

                return response()->json([
                    'ok' => false,
                    'code' => 'qr_amount_mismatch',
                    'payment_method' => $paymentMethod,
                    'message' => $cancelled
                        ? 'Qhantuy devolvió un cobro con importe o moneda diferente. La orden fue cancelada. Intenta nuevamente.'
                        : 'Qhantuy devolvió un cobro con importe o moneda diferente y no confirmó su cancelación. Contacta a soporte antes de reintentar.',
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
                'message' => (string) data_get($body, 'message', $paymentMethod === 'T2P'
                    ? 'Solicitud de pago enviada a QPOS. Esperando confirmación de la tarjeta.'
                    : 'Orden QR generada.'),
                'internal_code' => $payload['internal_code'],
                'transaction_id' => $transactionId !== null ? (int) $transactionId : null,
                'payment_method' => $paymentMethod,
                'checkout_amount' => $amount,
                'checkout_currency' => $currency,
                'image_data' => $imageData !== '' ? $imageData : null,
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
                'connect_timeout' => (int) config('services.qhantuy_checkout.connect_timeout', 20),
                'timeout' => (int) config('services.qhantuy_checkout.timeout', 60),
                'ssl_verify' => filter_var(config('services.qhantuy_checkout.ssl_verify', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
                'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
                'internal_code' => $payload['internal_code'],
                'exception_class' => get_class($e),
                'payload' => $this->maskQhantuyPayloadForLogs($payload),
                'config' => $this->maskedCheckoutConfig(),
            ]);

            if ($ownsCheckout && $checkout && preg_match('/cURL error [567]:/i', $e->getMessage())) {
                $this->releaseRejectedCheckout($checkout);

                return response()->json([
                    'ok' => false,
                    'payment_method' => $paymentMethod,
                    'message' => 'No se pudo conectar con Qhantuy para crear el cobro.',
                ], 504);
            }

            return $this->checkoutAwaitingConfirmation(504, $paymentMethod);
        } catch (RequestException $e) {
            Log::error('Qhantuy checkout request exception', [
                'internal_code' => $payload['internal_code'],
                'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
                'exception_class' => get_class($e),
                'payload' => $this->maskQhantuyPayloadForLogs($payload),
                'config' => $this->maskedCheckoutConfig(),
                'response_status' => $e->response?->status(),
            ]);

            return response()->json([
                'ok' => false,
                'payment_method' => $paymentMethod,
                'message' => 'Error remoto al crear el cobro en Qhantuy.',
            ], 502);
        } catch (\Throwable $e) {
            Log::error('Qhantuy checkout unexpected error', [
                'internal_code' => $payload['internal_code'] ?? '',
                'elapsed_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
                'exception_class' => get_class($e),
                'payload' => isset($payload) && is_array($payload) ? $this->maskQhantuyPayloadForLogs($payload) : null,
                'config' => $this->maskedCheckoutConfig(),
            ]);
            return response()->json([
                'ok' => false,
                'payment_method' => $paymentMethod,
                'message' => 'Error inesperado al crear el cobro en Qhantuy.',
            ], 500);
        }
    }

    private function providerReturnedCheckoutId(mixed $body): int
    {
        return (int) (data_get($body, 'transaction_id') ?? data_get($body, 'id') ?? data_get($body, 'items.0.id') ?? 0);
    }

    private function releaseRejectedCheckout(object $checkout): void
    {
        DB::table('qhantuy_qr_payments')->where('id', $checkout->id)
            ->whereNull('transaction_id')->where('payment_status', self::CHECKOUT_REQUESTED)->delete();
    }

    private function checkoutAwaitingConfirmation(int $httpStatus = 409, string $paymentMethod = 'QRSIMPLE')
    {
        $label = strtoupper(trim($paymentMethod)) === 'T2P' ? 'el cobro con tarjeta' : 'el QR';

        return response()->json([
            'ok' => false,
            'code' => 'qr_checkout_unconfirmed',
            'payment_method' => strtoupper(trim($paymentMethod)),
            'message' => 'Qhantuy aún no confirma la creación de ' . $label . '. Consulta esta misma venta antes de reintentar.',
        ], $httpStatus);
    }

    private function existingCheckout(
        object $payment,
        float $amount,
        string $currency,
        ?int $cartId,
        string $paymentMethod = 'QRSIMPLE'
    )
    {
        $storedMethod = strtoupper(trim((string) ($payment->payment_method ?? 'QRSIMPLE')));
        $paymentLabel = $storedMethod === 'T2P' ? 'el cobro con tarjeta sin contacto' : 'el QR';
        if ($storedMethod !== strtoupper(trim($paymentMethod))) {
            return response()->json([
                'ok' => false,
                'code' => 'qr_payment_method_conflict',
                'message' => 'Esta orden de pago ya fue creada con otro medio. Consulta su estado y no generes otra orden para la misma venta.',
                'internal_code' => (string) ($payment->internal_code ?? ''),
                'payment_method' => $storedMethod,
            ], 409);
        }

        if (isset($payment->qhantuy_cart_id) && (int) $payment->qhantuy_cart_id !== $cartId) {
            return response()->json([
                'ok' => false,
                'code' => 'qr_order_conflict',
                'payment_method' => $storedMethod,
                'message' => 'La referencia de cobro ya pertenece a otra venta. Solicita revisión antes de continuar.',
            ], 409);
        }

        if (abs((float) $payment->checkout_amount - $amount) >= 0.01 || $payment->checkout_currency !== $currency
            || $payment->payment_status === 'amount_mismatch') {
            return response()->json([
                'ok' => false,
                'code' => 'qr_amount_mismatch',
                'payment_method' => $storedMethod,
                'message' => 'Esta orden de cobro tiene un importe o una moneda diferente. Solicita revisión antes de continuar.',
            ], 409);
        }

        if ($this->normalizeQrPaymentStatus($payment->payment_status) === 'cancelled') {
            return response()->json([
                'ok' => false,
                'code' => 'qr_checkout_cancelled',
                'message' => 'La orden anterior para ' . $paymentLabel . ' se cerró sin pago. Puedes volver a intentarlo desde esta venta.',
                'payment_method' => $storedMethod,
            ], 409);
        }

        if ((int) $payment->transaction_id > 0) {
            return response()->json($this->buildCachedCheckPaymentsResponse($payment) + [
                'internal_code' => $payment->internal_code,
                'payment_method' => $storedMethod,
            ]);
        }

        if ($payment->payment_status !== self::CHECKOUT_REQUESTED || $this->checkPaymentsUrl() === '') {
            return $this->checkoutAwaitingConfirmation(409, $storedMethod);
        }

        // A lost HTTP response does not mean the provider rejected the order.
        // Look up the same reference with the key reserved before the original call.
        $created = \Illuminate\Support\Carbon::parse($payment->created_at);
        $appkey = $this->appkeyForPayment($payment);
        $response = $this->qhantuyClient(12)->post($this->checkPaymentsUrl(), [
            'appkey' => $appkey,
            'start_date' => $created->copy()->subDay()->toDateString(),
            'end_date' => $created->copy()->addDay()->toDateString(),
        ]);
        $matches = collect($response->json('items', []))->filter(fn ($item) =>
            data_get($item, 'internal_code') === $payment->internal_code && (int) data_get($item, 'id') > 0
        );
        if (!$response->successful() || $response->json('process') === false || $matches->count() !== 1) {
            return $this->checkoutAwaitingConfirmation(409, $storedMethod);
        }

        $request = Request::create('/api/factura-venta/qr/check-payments', 'POST', [
            'internal_code' => $payment->internal_code,
            'payment_ids' => [(int) data_get($matches->first(), 'id')],
        ]);
        $request->headers->set('Accept', 'application/json');
        $confirmation = $this->consultPayment($request, syncCart: false);
        if (!$confirmation->isSuccessful()) {
            return $confirmation;
        }

        $confirmedPayment = $this->paymentByCode($payment->internal_code);

        return $confirmedPayment && (int) $confirmedPayment->transaction_id > 0
            ? $this->existingCheckout($confirmedPayment, $amount, $currency, $cartId, $storedMethod)
            : $this->checkoutAwaitingConfirmation(409, $storedMethod);
    }

    public function callback(Request $request)
    {
        Log::debug('Qhantuy callback received', [
            'method' => $request->method(),
            'internal_code' => $request->query('internal_code'),
            'transaction_id' => $request->query('transaction_id'),
        ]);

        $validated = $request->validate([
            'transaction_id' => ['required', 'integer', 'min:1'],
            'profile_code' => ['required', 'string', 'max:200'],
            'message' => ['nullable', 'string', 'max:500'],
            'internal_code' => ['required', 'string', 'max:120'],
            'checkout_amount' => ['required', 'numeric', 'gte:0'],
            'checkout_currency_code' => ['nullable', 'string', 'in:BOB'],
            'checkout_currency' => ['nullable', 'string', 'in:BOB'],
            'status' => ['required', 'string', 'in:success,cancelled'],
        ]);

        $internalCode = trim((string) $validated['internal_code']);
        $row = $this->paymentByCode($internalCode);
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

        $expectedProfileCode = empty($row->qhantuy_credential_id) ? $this->profileCode() : '';
        if ($expectedProfileCode !== '' && trim((string) $validated['profile_code']) !== $expectedProfileCode) {
            Log::warning('Qhantuy callback rejected due to invalid profile_code', [
                'internal_code' => $internalCode,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'profile_code invalido.',
            ], 422);
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

        $status = $this->normalizeQrPaymentStatus((string) $validated['status']);
        $currentStatus = $this->normalizeQrPaymentStatus((string) ($row->payment_status ?? 'holding'));
        if ($currentStatus === 'success' && $status === 'cancelled') {
            Log::warning('Qhantuy cancellation callback ignored because payment was already confirmed.', [
                'internal_code' => $internalCode,
                'transaction_id' => $incomingTxn,
            ]);

            return response()->json([
                'ok' => true,
                'payment_status' => 'success',
                'message' => 'Se conservó el pago confirmado; se ignoró la cancelación tardía.',
            ]);
        }

        if ($currentStatus !== $status || (int) ($row->transaction_id ?? 0) !== $incomingTxn) {
            $consult = Request::create('/api/factura-venta/qr/check-payments', 'POST', [
                'internal_code' => $row->internal_code,
                'payment_ids' => [$incomingTxn],
            ]);
            $consult->headers->set('Accept', 'application/json');
            if (!$this->consultPayment($consult, expectedStatus: $status)->isSuccessful()) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Qhantuy todavía no confirmó el estado de esta notificación.',
                ], 503);
            }
        }

        return response()->json([
            'ok' => true,
            'internal_code' => $internalCode,
            'transaction_id' => $incomingTxn,
            'payment_method' => strtoupper((string) ($row->payment_method ?? 'QRSIMPLE')),
            'payment_status' => $status,
            'message' => $status === 'success'
                ? 'Pago confirmado. Preventa marcada como pagada.'
                : 'Callback procesado. Preventa actualizada.',
        ]);
    }

    public function checkPayments(Request $request)
    {
        return $this->consultPayment($request);
    }

    private function consultPayment(Request $request, ?string $expectedStatus = null, bool $syncCart = true)
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

        if ($this->token() === '' || $this->checkPaymentsUrl() === '') {
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

        $internalCode = trim((string) ($validated['internal_code'] ?? ''));
        $row = $this->paymentByCode($internalCode);
        $storedId = (int) ($row->transaction_id ?? 0);
        if ($row && $storedId > 0 && $paymentIds === []) {
            $paymentIds = [$storedId];
        }
        if ($row && $storedId > 0 && $paymentIds !== [$storedId]) {
            return $this->paymentReferenceMismatch();
        }
        if (!$row && $paymentIds !== []) {
            $matches = DB::table('qhantuy_qr_payments')->where('transaction_id', $paymentIds[0])->limit(2)->get();
            if ($matches->count() > 1 || ($internalCode !== '' && $matches->isNotEmpty())) {
                return $this->paymentReferenceMismatch();
            }
            $row = $matches->first();
        }

        if ($row && strtolower(trim((string) ($row->payment_status ?? ''))) === 'amount_mismatch') {
            return response()->json([
                'ok' => false,
                'code' => 'qr_amount_mismatch',
                'message' => 'El cobro tiene una diferencia de monto y está bloqueado para pago y facturación. Requiere revisión manual.',
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

        if ($row && $storedId > 0 && $expectedStatus === null && $syncCart && $this->shouldUseCachedCheckResponse($row)) {
            Log::debug('Qhantuy check-payments resolved from cache', [
                'transaction_id' => $row->transaction_id ?? null,
                'internal_code' => $row->internal_code ?? null,
                'payment_status' => $row->payment_status ?? null,
            ]);

            return response()->json($this->buildCachedCheckPaymentsResponse($row));
        }

        try {
            $appkey = $this->appkeyForPayment($row);
            if ($appkey === '') {
                return response()->json(['ok' => false, 'message' => 'La caja general de Qhantuy no está configurada en el API.'], 500);
            }
            $response = $this->qhantuyClient($expectedStatus === null && $syncCart ? null : 12)->post($this->checkPaymentsUrl(), [
                'appkey' => $appkey,
                'payment_ids' => $paymentIds,
            ]);
            $body = $this->withoutProviderCredentials($response->json(), $appkey);

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
            $providerCode = trim((string) data_get($source, 'internal_code', ''));
            $providerCurrency = strtoupper(trim((string) data_get($source, 'checkout_currency', '')));
            $requiresExactMatch = $expectedStatus !== null || !empty($row->qhantuy_credential_id);
            if ($requiresExactMatch && (!$row || !is_array($firstItem) || data_get($body, 'process') === false
                || (int) data_get($source, 'id') !== $paymentIds[0]
                || $providerCode !== $row->internal_code || $providerCurrency !== $row->checkout_currency)) {
                return $this->paymentReferenceMismatch();
            }
            if (!$row && $internalCode !== '' && ($providerCode === ''
                || $this->normalizeQrInternalCode($providerCode) !== $this->normalizeQrInternalCode($internalCode))) {
                return $this->paymentReferenceMismatch();
            }

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
            if ($expectedStatus !== null && $status !== $expectedStatus) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Qhantuy aún no devuelve el estado indicado para esta orden QR.',
                ], 503);
            }
            $amountFromProvider = $this->parseCheckoutAmount(
                data_get($source, 'checkout_amount')
                ?? data_get($body, 'checkout_amount')
            );
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

            $target = $row;
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
                    'payment_method' => strtoupper((string) ($target->payment_method ?? 'QRSIMPLE')),
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
                    ->where(function ($query) use ($transactionId) {
                        $query->whereNull('transaction_id')->orWhere('transaction_id', $transactionId);
                    })
                    ->where('payment_status', '<>', 'amount_mismatch')
                    ->when($status !== 'success', fn ($query) => $query->where('payment_status', '<>', 'success'))
                    ->update([
                        'transaction_id' => $transactionId,
                        'checkout_amount' => $target->checkout_amount,
                        'checkout_currency' => $currency !== '' ? $currency : $target->checkout_currency,
                        'image_data' => $resolvedImageData !== '' ? $resolvedImageData : $target->image_data,
                        'payment_status' => $status,
                        'raw_check_response' => json_encode($body, JSON_UNESCAPED_UNICODE),
                        'last_message' => (string) data_get($body, 'message', ''),
                        'paid_at' => $status === 'success' ? ($target->paid_at ?? now()) : $target->paid_at,
                        'cancelled_at' => $status === 'cancelled' ? ($target->cancelled_at ?? now()) : $target->cancelled_at,
                        'updated_at' => now(),
                    ]);

                if ($updated !== 1) {
                    $current = DB::table('qhantuy_qr_payments')->where('id', $target->id)->first();
                    if (!$current || (int) $current->transaction_id !== $transactionId || $current->payment_status === 'amount_mismatch') {
                        return $this->paymentReferenceMismatch();
                    }
                    if ($expectedStatus !== null && $current->payment_status !== $expectedStatus) {
                        return $this->paymentReferenceMismatch();
                    }

                    return response()->json($this->buildCachedCheckPaymentsResponse($current));
                }

                if ($syncCart) {
                    $this->syncCartPaymentState(
                        (string) ($target->internal_code ?? ''),
                        $status,
                        $transactionId,
                        (string) data_get($body, 'message', ''),
                        isset($target->qhantuy_cart_id) ? (int) $target->qhantuy_cart_id : null,
                    );
                }
            }

            return response()->json([
                'ok' => true,
                'message' => (string) data_get($body, 'message', strtoupper((string) ($target->payment_method ?? 'QRSIMPLE')) === 'T2P'
                    ? 'Consulta de pago con tarjeta realizada.'
                    : 'Consulta QR realizada.'),
                'transaction_id' => $transactionId,
                'payment_method' => strtoupper((string) ($target->payment_method ?? $row->payment_method ?? 'QRSIMPLE')),
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

            Log::error('Qhantuy check-payments unexpected error', ['exception_class' => get_class($e), 'payment_ids' => $paymentIds]);
            return response()->json([
                'ok' => false,
                'message' => 'Error inesperado al consultar pago QR.',
            ], 500);
        }
    }

    private function paymentReferenceMismatch()
    {
        return response()->json([
            'ok' => false,
            'code' => 'qr_transaction_mismatch',
            'message' => 'Las referencias devueltas o solicitadas no coinciden con la orden QR registrada.',
        ], 409);
    }

    public function cancelPayment(Request $request)
    {
        $validated = $request->validate([
            'transaction_id' => ['nullable', 'integer', 'min:1'],
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

        $row = $this->paymentByCode($internalCode);
        if (!$row && $transactionId > 0) {
            $matches = DB::table('qhantuy_qr_payments')->where('transaction_id', $transactionId)->limit(2)->get();
            if ($matches->count() > 1 || ($internalCode !== '' && $matches->isNotEmpty())) {
                return $this->paymentReferenceMismatch();
            }
            $row = $matches->first();
        }

        $storedTransactionId = (int) ($row->transaction_id ?? 0);
        if ($row && $transactionId > 0 && $transactionId !== $storedTransactionId) {
            return $this->paymentReferenceMismatch();
        }
        if ($cart && $row && isset($row->qhantuy_cart_id) && (int) $row->qhantuy_cart_id !== (int) $cart->id) {
            return $this->paymentReferenceMismatch();
        }
        if ($cart && $internalCode !== '' && $normalizedInternalCode !== $this->normalizeQrInternalCode((string) ($cart->codigo_orden ?? ''))) {
            return $this->paymentReferenceMismatch();
        }
        if ($cart && $transactionId > 0 && !empty($cart->qr_transaction_id) && $transactionId !== (int) $cart->qr_transaction_id) {
            return $this->paymentReferenceMismatch();
        }

        if (!$cart && $row) {
            $resolvedInternalCode = trim((string) ($row->internal_code ?? ''));
            $resolvedNormalizedCode = $resolvedInternalCode !== '' ? $this->normalizeQrInternalCode($resolvedInternalCode) : '';
            $cart = DB::table('facturacion_carts')
                ->when(isset($row->qhantuy_cart_id), fn ($query) => $query->where('id', $row->qhantuy_cart_id))
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

        if ($transactionId <= 0 && ($row->payment_status ?? null) === self::CHECKOUT_REQUESTED) {
            return $this->checkoutAwaitingConfirmation();
        }

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
                    (string) ($row->last_message ?? 'Cobro QR ya estaba cancelado.'),
                    isset($row->qhantuy_cart_id) ? (int) $row->qhantuy_cart_id : null,
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

        if ($this->token() === '' || $this->cancelPaymentUrl() === '') {
            return response()->json([
                'ok' => false,
                'message' => 'Configuracion Qhantuy incompleta en SAFE.',
            ], 500);
        }

        $payload = [
            'transaction_id' => $transactionId,
        ];
        if ($reason !== '') {
            $payload['reason'] = $reason;
        }

        try {
            $payload['appkey'] = $this->appkeyForPayment($row);
            if ($payload['appkey'] === '') {
                return response()->json(['ok' => false, 'message' => 'La caja general de Qhantuy no está configurada en el API.'], 500);
            }
            $response = $this->qhantuyClient()->post($this->cancelPaymentUrl(), $payload);
            $body = $this->withoutProviderCredentials($response->json(), $payload['appkey']);

            Log::debug('Qhantuy cancel-payment response received', [
                'transaction_id' => $transactionId,
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_summary' => $this->summarizeQhantuyResponseBody($body),
            ]);

            if (!$response->successful()) {
                return response()->json([
                    'ok' => false,
                    'message' => (string) data_get($body, 'message', 'No se pudo cancelar el cobro QR.'),
                    'status_code' => $response->status(),
                    'qhantuy' => is_array($body) ? $body : null,
                ], $response->status());
            }

            $processOk = filter_var(data_get($body, 'process'), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($processOk !== true) {
                return response()->json([
                    'ok' => false,
                    'message' => (string) data_get($body, 'message', 'Qhantuy no permitio cancelar el cobro QR.'),
                    'qhantuy' => is_array($body) ? $body : null,
                ], 422);
            }

            $source = is_array(data_get($body, 'item')) ? data_get($body, 'item') : (is_array($body) ? $body : []);
            $providerTransactionId = data_get($source, 'transaction_id');
            if ($providerTransactionId !== null && (int) $providerTransactionId !== $transactionId) {
                return $this->paymentReferenceMismatch();
            }
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

            $this->syncCartPaymentState($internalCode, $status, $transactionId, $message, isset($cart->id) ? (int) $cart->id : null);

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
            return response()->json($this->withoutProviderCredentials($response?->json(), (string) ($payload['appkey'] ?? '')) ?? [
                'ok' => false,
                'message' => 'Error remoto al cancelar el cobro QR.',
            ], $response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'No se pudo conectar con Qhantuy para cancelar el cobro QR.',
            ], 504);
        } catch (\Throwable $e) {
            Log::error('Qhantuy cancel-payment unexpected error', ['exception_class' => get_class($e), 'transaction_id' => $transactionId]);
            return response()->json([
                'ok' => false,
                'message' => 'Error inesperado al cancelar el cobro QR.',
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
            'message' => $message ?: ((string) ($row->last_message ?? '') ?: (strtoupper((string) ($row->payment_method ?? 'QRSIMPLE')) === 'T2P'
                ? 'Estado de pago con tarjeta recuperado desde cache reciente.'
                : 'Estado QR recuperado desde cache reciente.')),
            'payment_method' => strtoupper((string) ($row->payment_method ?? 'QRSIMPLE')),
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
