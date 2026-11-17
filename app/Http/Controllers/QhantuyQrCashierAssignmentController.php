<?php

namespace App\Http\Controllers;

use App\Services\QhantuyQrCredentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

class QhantuyQrCashierAssignmentController extends Controller
{
    public function __invoke(Request $request, QhantuyQrCredentials $credentials): JsonResponse
    {
        $rules = [
            'cashier_user_id' => ['required', 'string', 'max:60'],
            'branch_code' => ['required', 'string', 'max:60'],
            'point_sale_id' => ['required', 'string', 'max:60'],
        ];

        if ($request->isMethod('PUT')) {
            $rules['appkey'] = ['required', 'string', 'max:2048'];
            $input = $request->isJson() ? $request->json()->all() : $request->request->all();
        } else {
            $input = $request->all();
        }

        $validator = Validator::make($input, $rules);
        if ($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => 'Revisa los datos de la asignación de caja QR.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $cashierId = $data['cashier_user_id'];
        $branchCode = $data['branch_code'];
        $pointSaleId = $data['point_sale_id'];

        try {
            if ($request->isMethod('PUT')) {
                $credentials->assign($cashierId, $branchCode, $pointSaleId, $data['appkey']);
                $configured = true;
            } elseif ($request->isMethod('DELETE')) {
                $credentials->useGeneralCashbox($cashierId, $branchCode, $pointSaleId);
                $configured = false;
            } else {
                $configured = $credentials->activeCredentialId($cashierId, $branchCode, $pointSaleId) !== null;
            }
        } catch (Throwable $e) {
            Log::error('Error al gestionar una asignación de caja QR.', [
                'method' => $request->method(),
                'exception_type' => $e::class,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'La configuración de caja QR no está disponible.',
            ], 503);
        }

        return response()->json(['ok' => true, 'data' => ['configured' => $configured]])
            ->header('Cache-Control', 'no-store');
    }
}
