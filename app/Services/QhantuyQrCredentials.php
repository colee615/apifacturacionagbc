<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QhantuyQrCredentials
{
    public function activeCredentialId(string $cashierId, string $branchCode, string $pointSaleId): ?int
    {
        $credentialId = DB::table('qhantuy_qr_cashier_assignments')
            ->where($this->cashierIdentity($cashierId, $branchCode, $pointSaleId))
            ->value('credential_id');

        return $credentialId === null ? null : (int) $credentialId;
    }

    public function rememberKey(#[\SensitiveParameter] string $appkey): int
    {
        $appkey = trim($appkey);
        $encryptionKey = (string) config('app.key');
        if ($appkey === '' || $encryptionKey === '') {
            throw new RuntimeException('No se pudo guardar la credencial de caja QR.');
        }

        $fingerprint = hash_hmac('sha256', $appkey, $encryptionKey);
        $query = DB::table('qhantuy_qr_credentials')->where('appkey_fingerprint', $fingerprint);
        $credentialId = $query->value('id');
        if ($credentialId !== null) {
            return (int) $credentialId;
        }

        DB::table('qhantuy_qr_credentials')->insertOrIgnore([
            'appkey_fingerprint' => $fingerprint,
            'encrypted_appkey' => Crypt::encryptString($appkey),
            'created_at' => now(),
        ]);

        $credentialId = $query->value('id');
        if ($credentialId === null) {
            throw new RuntimeException('No se pudo guardar la credencial de caja QR.');
        }

        return (int) $credentialId;
    }

    public function appkey(int $credentialId): string
    {
        $encrypted = DB::table('qhantuy_qr_credentials')->where('id', $credentialId)->value('encrypted_appkey');
        if (!is_string($encrypted) || $encrypted === '') {
            throw new RuntimeException('La credencial de caja QR no está disponible.');
        }

        try {
            $appkey = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            throw new RuntimeException('No se pudo leer la credencial de caja QR.');
        }

        if (trim($appkey) === '') {
            throw new RuntimeException('La credencial de caja QR no está disponible.');
        }

        return $appkey;
    }

    public function assign(string $cashierId, string $branchCode, string $pointSaleId, #[\SensitiveParameter] string $appkey): void
    {
        $identity = $this->cashierIdentity($cashierId, $branchCode, $pointSaleId);
        $credentialId = $this->rememberKey($appkey);
        $now = now();

        DB::table('qhantuy_qr_cashier_assignments')->upsert(
            [$identity + ['credential_id' => $credentialId, 'created_at' => $now, 'updated_at' => $now]],
            array_keys($identity),
            ['credential_id', 'updated_at']
        );
    }

    public function useGeneralCashbox(string $cashierId, string $branchCode, string $pointSaleId): void
    {
        DB::table('qhantuy_qr_cashier_assignments')
            ->where($this->cashierIdentity($cashierId, $branchCode, $pointSaleId))
            ->delete();
    }

    private function cashierIdentity(string $cashierId, string $branchCode, string $pointSaleId): array
    {
        return [
            'cashier_user_id' => trim($cashierId),
            'branch_code' => trim($branchCode),
            'point_sale_id' => trim($pointSaleId),
        ];
    }
}
