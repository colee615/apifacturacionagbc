<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaqueteFacturaController extends Controller
{
    private const FACTURA_VIGENTE = [
        'PROCESADA',
        'PROCESADO',
        'FACTURADA',
        'EMITIDO',
        'REGISTRADA_OFICIAL',
        'ANULACION_SOLICITADA',
        'ANULACION_OBSERVADA',
    ];

    private const FACTURA_ANULADA = ['ANULADA', 'ANULADO'];

    private const EMISION_NO_CONFIRMADA = [
        'PENDIENTE',
        'RECEPCIONADA',
        'RECHAZADA',
        'RECHAZADO',
        'ERROR',
        'FALLIDA',
        'DESCARTADA',
    ];

    public function consultar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'codigos_paquete' => ['required', 'array', 'min:1', 'max:500'],
            'codigos_paquete.*' => ['required', 'string', 'max:50'],
        ]);

        return $this->consultarCodigos($validated['codigos_paquete']);
    }

    public function consultarUno(string $codigoPaquete): JsonResponse
    {
        if (trim($codigoPaquete) === '' || mb_strlen($codigoPaquete) > 50) {
            return response()->json([
                'message' => 'El código de paquete debe tener entre 1 y 50 caracteres.',
            ], 422);
        }

        return $this->consultarCodigos([$codigoPaquete]);
    }

    private function consultarCodigos(array $codigosSolicitados): JsonResponse
    {
        $codigos = collect($codigosSolicitados)
            ->map(fn ($codigo) => mb_strtoupper(trim((string) $codigo)))
            ->filter()
            ->unique()
            ->values();

        if ($codigos->isEmpty()) {
            return response()->json([
                'message' => 'Envía al menos un código de paquete válido.',
            ], 422);
        }

        // Una sola consulta busca todos los códigos exactos y conserva cada venta/factura asociada.
        $rows = DB::table('detalle_ventas as dv')
            ->join('ventas as v', 'v.id', '=', 'dv.venta_id')
            ->whereIn('dv.codigo', $codigos->all())
            ->orderBy('dv.codigo')
            ->orderByDesc('v.id')
            ->get([
                'dv.codigo as codigo_paquete',
                'dv.descripcion as descripcion_paquete',
                'dv.cantidad as cantidad_paquete',
                'dv.precio as precio_paquete',
                'v.id as venta_id',
                'v.codigoOrden as codigo_orden',
                'v.codigoSeguimiento as codigo_seguimiento',
                'v.numero_factura',
                'v.cuf',
                'v.estado_sufe',
                'v.tipo_emision_sufe',
                'v.estado as estado_venta',
                'v.total as total_venta',
                'v.created_at as fecha_venta',
                'v.anulada_at',
                'v.anulacion_tipo',
                'v.anulacion_motivo',
                'v.motivo',
                'v.origen_venta_tipo',
                'v.origen_sistema',
            ]);

        $numeroFacturaPorSeguimiento = $this->buscarNumerosFactura($rows);
        $filasPorCodigo = $rows->groupBy(fn ($row) => mb_strtoupper(trim((string) $row->codigo_paquete)));

        $resultados = $codigos->map(function (string $codigo) use ($filasPorCodigo, $numeroFacturaPorSeguimiento) {
            $filas = $filasPorCodigo->get($codigo, collect());
            $coincidencias = $filas
                ->groupBy('venta_id')
                ->map(fn (Collection $detalleVenta) => $this->formatearVenta(
                    $detalleVenta,
                    $numeroFacturaPorSeguimiento
                ))
                ->values();

            $facturasVigentes = $coincidencias->filter(fn (array $item) => $item['es_factura_vigente']);
            $facturasRegulares = $facturasVigentes->filter(fn (array $item) => ! $item['es_retour']);
            $facturasRetour = $coincidencias->filter(fn (array $item) => $item['es_retour']);
            $facturasAnuladas = $coincidencias->filter(fn (array $item) => $item['anulada']);

            if ($facturasRegulares->isNotEmpty()) {
                $estadoPaquete = 'FACTURADA';
            } elseif ($facturasVigentes->isNotEmpty()) {
                $estadoPaquete = 'SOLO_RETOUR';
            } elseif ($facturasAnuladas->isNotEmpty()) {
                $estadoPaquete = 'SOLO_ANULADA';
            } elseif ($coincidencias->isNotEmpty()) {
                $estadoPaquete = 'SIN_FACTURA';
            } else {
                $estadoPaquete = 'NO_ENCONTRADO';
            }

            return [
                'codigo_paquete' => $codigo,
                'estado' => $estadoPaquete,
                'facturado' => $facturasRegulares->isNotEmpty(),
                'tiene_documento_fiscal_vigente' => $facturasVigentes->isNotEmpty(),
                'cantidad_facturas_vigentes' => $facturasVigentes->count(),
                'cantidad_facturas_anuladas' => $facturasAnuladas->count(),
                'cantidad_facturas_retour' => $facturasRetour->count(),
                'multiples_facturas_vigentes' => $facturasVigentes->count() > 1,
                'coincidencias' => $coincidencias,
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'cantidad_codigos' => $resultados->count(),
            'resultados' => $resultados,
        ]);
    }

    private function formatearVenta(Collection $detalleVenta, array $numeroFacturaPorSeguimiento): array
    {
        $venta = $detalleVenta->first();
        $estadoSufe = mb_strtoupper(trim((string) ($venta->estado_sufe ?? '')));
        $numeroFactura = trim((string) ($venta->numero_factura ?? ''));
        $seguimiento = trim((string) ($venta->codigo_seguimiento ?? ''));

        if ($numeroFactura === '' && $seguimiento !== '') {
            $numeroFactura = (string) ($numeroFacturaPorSeguimiento[$seguimiento] ?? '');
        }

        $cuf = trim((string) ($venta->cuf ?? ''));
        $anulada = in_array($estadoSufe, self::FACTURA_ANULADA, true)
            || ! empty($venta->anulada_at);
        $estadoEnEspera = in_array($estadoSufe, self::EMISION_NO_CONFIRMADA, true);
        $estadoVigente = in_array($estadoSufe, self::FACTURA_VIGENTE, true);
        $tieneIdentidadFiscal = $numeroFactura !== '' || $cuf !== '';
        $esFacturaVigente = ! $anulada
            && ($estadoVigente || ($tieneIdentidadFiscal && ! $estadoEnEspera));

        $datosTipo = implode(' ', array_filter([
            $venta->tipo_emision_sufe,
            $venta->origen_venta_tipo,
            $venta->anulacion_tipo,
            $venta->anulacion_motivo,
            $venta->motivo,
            ...$detalleVenta->pluck('descripcion_paquete')->all(),
        ]));
        $esRetour = preg_match('/\b(RETOUR|RETORNO|DEVOLUCI[ÓO]N)\b/iu', $datosTipo) === 1;

        $importePaquete = $detalleVenta->sum(function ($linea) {
            return round((float) $linea->precio_paquete * (float) $linea->cantidad_paquete, 2);
        });

        return [
            'venta_id' => (int) $venta->venta_id,
            'codigo_orden' => $venta->codigo_orden,
            'codigo_seguimiento' => $seguimiento !== '' ? $seguimiento : null,
            'tipo_documento' => $esRetour ? 'RETOUR' : 'FACTURA',
            'es_retour' => $esRetour,
            'estado_fiscal' => $estadoSufe !== '' ? $estadoSufe : 'SIN_ESTADO',
            'es_factura_vigente' => $esFacturaVigente,
            'anulada' => $anulada,
            'factura' => [
                'numero' => $numeroFactura !== '' ? $numeroFactura : null,
                'cuf' => $cuf !== '' ? $cuf : null,
                'fecha' => $venta->fecha_venta,
            ],
            'tipo_emision' => $venta->tipo_emision_sufe,
            'tipo_origen' => $venta->origen_venta_tipo ?: $venta->origen_sistema,
            'motivo_anulacion' => $venta->anulacion_motivo,
            'importe_paquete' => round($importePaquete, 2),
            'total_venta' => (float) $venta->total_venta,
            'detalle' => $detalleVenta->map(fn ($linea) => [
                'descripcion' => $linea->descripcion_paquete,
                'cantidad' => (float) $linea->cantidad_paquete,
                'precio_unitario' => (float) $linea->precio_paquete,
            ])->values(),
        ];
    }

    private function buscarNumerosFactura(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $seguimientos = $rows->pluck('codigo_seguimiento')
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->unique()
            ->values();

        if ($seguimientos->isEmpty()) {
            return [];
        }

        $mapa = [];
        DB::table('notificaciones')
            ->whereIn('codigo_seguimiento', $seguimientos->all())
            ->orderByDesc('id')
            ->get(['codigo_seguimiento', 'detalle'])
            ->each(function ($notificacion) use (&$mapa) {
                $seguimiento = trim((string) $notificacion->codigo_seguimiento);
                if ($seguimiento === '' || isset($mapa[$seguimiento])) {
                    return;
                }

                $detalle = is_array($notificacion->detalle)
                    ? $notificacion->detalle
                    : json_decode((string) $notificacion->detalle, true);
                if (! is_array($detalle)) {
                    return;
                }

                foreach ([
                    data_get($detalle, 'nroFactura'),
                    data_get($detalle, 'numeroFactura'),
                    data_get($detalle, 'factura.nroFactura'),
                    data_get($detalle, 'factura.numeroFactura'),
                    data_get($detalle, 'consultaSefe.nroFactura'),
                    data_get($detalle, 'consultaSefe.numeroFactura'),
                    data_get($detalle, 'datos.nroFactura'),
                ] as $candidato) {
                    $numero = trim((string) $candidato);
                    if ($numero !== '') {
                        $mapa[$seguimiento] = $numero;
                        break;
                    }
                }
            });

        return $mapa;
    }
}
