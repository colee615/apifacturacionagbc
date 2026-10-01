<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Venta;
use App\Models\Notificacione;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait StreamsServiceReports
{
    private static ?array $serviceReportCartColumnCache = null;

    /**
     * Reads report inputs in bounded batches. It deliberately emits carts and
     * linked sales through the same mapping/filtering rules as the old report.
     */
    private function mergedServiceReportVentaStream(array $filters, bool $includeAnnulled = false): \Generator
    {
        $batchSize = 1000;
        $cartQuery = Schema::hasTable('facturacion_carts')
            ? $this->buildFacturacionCartReportQuery($filters)
            : null;

        if ($cartQuery && ! $includeAnnulled) {
            $cartQuery->whereRaw("upper(coalesce(estado_emision, '')) not in ('ANULADA', 'ANULADO')");
        }

        if ($cartQuery) {
            $lastCartId = 0;
            while (true) {
                $cartRows = (clone $cartQuery)
                    ->reorder()
                    ->where('id', '>', $lastCartId)
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->select($this->serviceReportCartColumns())
                    ->get();

                if ($cartRows->isEmpty()) {
                    break;
                }

                $lastCartId = (int) $cartRows->last()->id;
                foreach ($this->mapServiceReportCartChunk($cartRows, $includeAnnulled) as $payload) {
                    yield $payload;
                }
                unset($cartRows);
            }
        }

        $ventasQuery = $this->applyVentaFilters(Venta::query(), $filters);
        if ($includeAnnulled) {
            $ventasQuery->where(function ($statusQuery) {
                $statusQuery->whereRaw("upper(coalesce(estado_sufe, '')) in ('PROCESADA', 'REGISTRADA_OFICIAL', 'ANULADA', 'ANULADO', 'ANULACION_SOLICITADA')");

                if (Schema::hasColumn('ventas', 'anulada_at')) {
                    $statusQuery->orWhereNotNull('anulada_at');
                }
            });
        } else {
            $ventasQuery->whereRaw("upper(coalesce(estado_sufe, '')) in ('PROCESADA', 'REGISTRADA_OFICIAL')");
        }

        if ($cartQuery) {
            // Keep the old duplicate suppression without materializing every
            // matching cart ID in a PHP array / SQL IN clause.
            $matchingCartIds = (clone $cartQuery)->reorder()->select('id');
            if ((clone $matchingCartIds)->exists()) {
                $ventasQuery->where(function ($query) use ($matchingCartIds) {
                    $query->whereNotIn('origen_venta_tipo', ['facturacion_cart', 'facturacion_cart_remote'])
                        ->orWhereNull('origen_venta_tipo')
                        ->orWhere(function ($unlinked) use ($matchingCartIds) {
                            $unlinked->whereNotNull('origen_venta_id')
                                ->whereNotExists(function ($notExists) use ($matchingCartIds) {
                                    $notExists->selectRaw('1')
                                        ->fromSub(clone $matchingCartIds, 'service_report_carts')
                                        ->whereRaw('cast(service_report_carts.id as varchar) = cast(ventas.origen_venta_id as varchar)');
                                });
                        });
                });
            }
        }

        $ventaColumns = array_values(array_filter([
            'id', 'created_at', 'codigoOrden', 'codigoSeguimiento', 'numero_factura',
            'origen_venta_id', 'origen_venta_tipo', 'origen_usuario_id', 'origen_usuario_nombre',
            $this->hasOrigenUsuarioEmailColumn() ? 'origen_usuario_email' : null,
            $this->hasOrigenUsuarioAliasColumn() ? 'origen_usuario_alias' : null,
            $this->hasOrigenUsuarioCarnetColumn() ? 'origen_usuario_carnet' : null,
            'origen_sucursal_id', 'origen_sucursal_nombre',
            $this->hasOrigenSucursalCodigoColumn() ? 'origen_sucursal_codigo' : null,
            'codigoSucursal', 'puntoVenta', 'razonSocial', 'documentoIdentidad', 'codigoCliente',
            'total', 'estado_sufe', 'tipo_emision_sufe', 'cuf', 'url_pdf', 'url_xml',
            'observacion_sufe', 'fecha_notificacion_sufe', 'departamento',
            Schema::hasColumn('ventas', 'canal_operativo') ? 'canal_operativo' : null,
            Schema::hasColumn('ventas', 'metodo_pago') ? 'metodo_pago' : null,
            Schema::hasColumn('ventas', 'canal_emision') ? 'canal_emision' : null,
            Schema::hasColumn('ventas', 'estado_pago') ? 'estado_pago' : null,
            Schema::hasColumn('ventas', 'estado_emision') ? 'estado_emision' : null,
            Schema::hasColumn('ventas', 'qr_transaction_id') ? 'qr_transaction_id' : null,
            Schema::hasColumn('ventas', 'anulada_at') ? 'anulada_at' : null,
            Schema::hasColumn('ventas', 'es_cuenta_por_cobrar') ? 'es_cuenta_por_cobrar' : null,
            Schema::hasColumn('ventas', 'empresa_nombre') ? 'empresa_nombre' : null,
            Schema::hasColumn('ventas', 'empresa_sigla') ? 'empresa_sigla' : null,
        ]));

        $lastVentaId = 0;
        while (true) {
            $ventaRows = (clone $ventasQuery)
                ->reorder()
                ->where('id', '>', $lastVentaId)
                ->orderBy('id')
                ->limit($batchSize)
                ->get($ventaColumns);

            if ($ventaRows->isEmpty()) {
                break;
            }

            $lastVentaId = (int) $ventaRows->last()->id;
            foreach ($this->mapServiceReportVentaChunk($ventaRows, $includeAnnulled) as $payload) {
                yield $payload;
            }
            unset($ventaRows);
        }
    }

    private function mapServiceReportCartChunk(Collection $cartRows, bool $includeAnnulled): Collection
    {
        $cartItemsMap = $this->serviceReportCartItemsMapFromRows($cartRows);
        $cartFiscalBackfillMap = $this->facturacionCartFiscalBackfillMap($cartRows);
        $seguimientos = $cartRows
            ->map(fn ($cart) => (string) (($cart->codigo_seguimiento_fiscal ?? null) ?: ($cart->codigo_seguimiento ?? '')))
            ->filter()
            ->values()
            ->all();
        [$cartNotificationBackfillMap] = $this->serviceReportNotificationMaps($seguimientos);

        $payloads = $cartRows
            ->map(fn ($cart) => $this->mapFacturacionCartToVentaPayload(
                $cart,
                $cartItemsMap[(int) $cart->id] ?? [],
                $cartFiscalBackfillMap[(string) $cart->id] ?? null,
                $cartNotificationBackfillMap[(string) (($cart->codigo_seguimiento_fiscal ?? null) ?: ($cart->codigo_seguimiento ?? ''))] ?? null
            ))
            ->map(function (array $payload) use ($cartFiscalBackfillMap, $includeAnnulled) {
                if ($includeAnnulled) {
                    $cartId = (string) ($payload['cartId'] ?? 0);
                    $linkedVenta = (array) data_get($cartFiscalBackfillMap, $cartId, []);
                    $payload['estado_sufe'] = strtoupper(trim((string) ($linkedVenta['estado_sufe'] ?? '')));
                    $payload['anulada_at'] = $linkedVenta['anulada_at'] ?? data_get($payload, 'anulacion.anuladaAt');
                }

                return $payload;
            })
            ->reject(fn (array $payload) => $this->shouldExcludeCartFromServiceReport($payload, $cartFiscalBackfillMap, $includeAnnulled))
            ->map(function (array $payload) use ($includeAnnulled) {
                if ($includeAnnulled) {
                    $payload['anulada'] = $this->isServiceReportAnnulled($payload);
                    $payload['estadoFiscal'] = $this->serviceReportFiscalStatus($payload);
                    $payload['estadoPago'] = trim((string) ($payload['estado_pago'] ?? '')) !== ''
                        ? strtolower(trim((string) $payload['estado_pago']))
                        : null;
                    $payload['medioPago'] = $this->isQrPaymentRow($payload) ? 'QR' : 'EFECTIVO';
                    $payload['incluidaEnTotales'] = ! $payload['anulada'];
                }

                // These fields are not part of the service report response.
                unset(
                    $payload['respuesta_emision'], $payload['historial_qr'], $payload['qrCancelacion'],
                    $payload['cliente'], $payload['tipo_documento'], $payload['numero_documento'],
                    $payload['razon_social'], $payload['mensaje_emision'], $payload['incidencia_revisada_at'],
                    $payload['incidencia_revisada_por'], $payload['incidencia_revision_nota'],
                    $payload['modalidad_facturacion'], $payload['canal_operativo'],
                    $payload['es_cuenta_por_cobrar'], $payload['empresa_nombre'], $payload['empresa_sigla'],
                    $payload['anulacion'], $payload['qr_transaction_id']
                );

                return $payload;
            })
            ->values();

        return $this->attachServiceReportRegional($payloads);
    }

    private function mapServiceReportVentaChunk(Collection $ventas, bool $includeAnnulled): Collection
    {
        $detalleMaps = $this->serviceReportDetalleMapsFromRows($ventas);
        $itemsCountMaps = $this->itemsCountMapsFromRows($ventas);
        $seguimientos = $ventas->pluck('codigoSeguimiento')->all();
        [$notificationsMap, $numeroFacturaMap] = $this->serviceReportNotificationMaps($seguimientos);
        $numeroFacturaBridgeMap = $this->numeroFacturaMapFromBridgeCartRows($ventas);

        $payloads = $ventas->map(function (Venta $venta) use ($detalleMaps, $itemsCountMaps, $notificationsMap, $numeroFacturaMap, $numeroFacturaBridgeMap, $includeAnnulled) {
            $ventaId = (int) $venta->id;
            $cartId = (int) ($venta->origen_venta_id ?? 0);
            $codigoSeguimiento = trim((string) ($venta->codigoSeguimiento ?? ''));
            $notification = $codigoSeguimiento !== '' ? ($notificationsMap[$codigoSeguimiento] ?? null) : null;
            $status = $this->protocolStatusFromVentaNotification($venta, $notification);
            $numeroFactura = trim((string) (
                $venta->numero_factura
                ?: ($numeroFacturaMap[$codigoSeguimiento] ?? ($numeroFacturaBridgeMap[$cartId] ?? ''))
            ));
            $detalle = $detalleMaps['detalle'][$ventaId] ?? [];
            if ($detalle === [] && $cartId > 0) {
                $detalle = $detalleMaps['cart'][$cartId] ?? [];
            }
            $itemsCount = (int) ($itemsCountMaps['detalle'][$ventaId] ?? 0);
            if ($itemsCount === 0 && $cartId > 0) {
                $itemsCount = (int) ($itemsCountMaps['cart'][$cartId] ?? 0);
            }

            $payload = [
                'id' => $venta->id,
                'fecha' => optional($venta->created_at)->format('Y-m-d H:i:s'),
                'codigoOrden' => $venta->codigoOrden,
                'codigoSeguimiento' => $venta->codigoSeguimiento,
                'numeroFactura' => $numeroFactura !== '' ? $numeroFactura : null,
                'origenVentaId' => $venta->origen_venta_id,
                'origenVentaTipo' => $venta->origen_venta_tipo,
                'usuario' => [
                    'id' => $venta->origen_usuario_id,
                    'nombre' => $venta->origen_usuario_nombre,
                    'email' => $venta->origen_usuario_email,
                    'alias' => $venta->origen_usuario_alias,
                    'carnet' => $venta->origen_usuario_carnet,
                ],
                'sucursal' => [
                    'id' => $venta->origen_sucursal_id,
                    'nombre' => $venta->origen_sucursal_nombre,
                    'codigoSucursal' => $venta->origen_sucursal_codigo ?: (int) $venta->codigoSucursal,
                    'puntoVenta' => (int) $venta->puntoVenta,
                    'departamento' => $venta->departamento,
                ],
                'detalle' => $detalle,
                'itemsCount' => $itemsCount,
                'cantidad' => max(1, $itemsCount ?: count($detalle)),
                'total' => (float) $venta->total,
                'status' => $status,
            ];

            if ($includeAnnulled) {
                $payload['estado_sufe'] = strtoupper(trim((string) ($venta->estado_sufe ?? '')));
                $payload['estado_pago'] = strtolower(trim((string) ($venta->estado_pago ?? '')));
                $payload['estado_emision'] = strtoupper(trim((string) ($venta->estado_emision ?? '')));
                $payload['metodo_pago'] = strtolower(trim((string) ($venta->metodo_pago ?? '')));
                $payload['canal_emision'] = strtolower(trim((string) ($venta->canal_emision ?? '')));
                $payload['qr_transaction_id'] = $venta->qr_transaction_id ?? null;
                $payload['anulada_at'] = $venta->anulada_at ?? null;
                $payload['anulada'] = $this->isServiceReportAnnulled($payload);
                $payload['estadoFiscal'] = $this->serviceReportFiscalStatus($payload);
                $payload['estadoPago'] = $payload['estado_pago'] !== '' ? $payload['estado_pago'] : null;
                $payload['medioPago'] = $this->isQrPaymentRow($payload) ? 'QR' : 'EFECTIVO';
                $payload['incluidaEnTotales'] = ! $payload['anulada'];
            }

            return $payload;
        })->values();

        return $this->attachServiceReportRegional($payloads);
    }

    private function serviceReportCartColumns(): array
    {
        if (self::$serviceReportCartColumnCache !== null) {
            return self::$serviceReportCartColumnCache;
        }

        $columns = [
            'id', 'created_at', 'emitido_en', 'respuesta_emision', 'codigo_orden',
            'codigo_seguimiento', 'codigo_seguimiento_fiscal', 'canal_emision', 'estado',
            'estado_pago', 'estado_emision', 'metodo_pago', 'qr_transaction_id', 'tipo_documento',
            'numero_documento', 'razon_social', 'total', 'cantidad_items', 'origen_usuario_id',
            'origen_usuario_nombre', 'origen_usuario_email', 'origen_usuario_alias', 'origen_usuario_carnet',
            'origen_sucursal_id', 'origen_sucursal_codigo',
            'origen_sucursal_nombre', 'anulada_at', 'anulada_por_user_id', 'anulada_por_nombre',
            'anulada_por_email', 'anulacion_motivo', 'anulacion_tipo',
            'anulacion_autorizada_por_user_id', 'anulacion_autorizada_por_email', 'qr_cancelado_at',
            'qr_cancelado_por_user_id', 'qr_cancelado_por_nombre', 'qr_cancelado_por_email',
            'qr_cancelacion_motivo', 'qr_cancelacion_origen', 'qr_cancelacion_transaction_id',
            'qr_cancelacion_mensaje', 'mensaje_emision', 'incidencia_revisada_at',
            'incidencia_revisada_por', 'incidencia_revision_nota', 'modalidad_facturacion',
            'canal_operativo', 'es_cuenta_por_cobrar', 'empresa_nombre', 'empresa_sigla',
        ];
        $available = array_flip(Schema::getColumnListing('facturacion_carts'));
        return self::$serviceReportCartColumnCache = array_values(array_filter(
            array_unique($columns),
            fn ($column) => array_key_exists($column, $available)
        ));
    }

    private function serviceReportNotificationMaps(array $seguimientos): array
    {
        $seguimientos = array_values(array_unique(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $seguimientos
        ))));
        if ($seguimientos === []) {
            return [[], []];
        }

        $notifications = [];
        $numeroFactura = [];
        $rows = Notificacione::query()
            ->whereIn('codigo_seguimiento', $seguimientos)
            ->orderByDesc('id')
            ->get(['id', 'codigo_seguimiento', 'estado', 'detalle']);

        foreach ($rows as $row) {
            $codigo = trim((string) $row->codigo_seguimiento);
            if ($codigo === '') {
                continue;
            }
            if (! array_key_exists($codigo, $notifications)) {
                $notifications[$codigo] = $row;
            }
            if (! array_key_exists($codigo, $numeroFactura)) {
                $numero = $this->extractNumeroFacturaFromDetalle((string) $row->detalle);
                if ($numero !== null) {
                    $numeroFactura[$codigo] = $numero;
                }
            }
        }

        return [$notifications, $numeroFactura];
    }

    private function serviceReportCartItemsMapFromRows(Collection $cartRows): array
    {
        if ($cartRows->isEmpty() || ! $this->hasFacturacionCartItemsTable()) {
            return [];
        }

        $cartIds = $cartRows->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        if ($cartIds === []) {
            return [];
        }

        return DB::table('facturacion_cart_items')
            ->whereIn('cart_id', $cartIds)
            ->orderBy('id')
            ->get([
                'cart_id', 'id', 'codigo', 'titulo', 'nombre_servicio', 'nombre_destinatario',
                'origen_tipo', 'origen_id', 'resumen_origen', 'cantidad', 'monto_base',
                'monto_extras', 'total_linea',
            ])
            ->groupBy('cart_id')
            ->map(function ($items) {
                return collect($items)->map(function ($item) {
                    $resumen = json_decode((string) ($item->resumen_origen ?? ''), true);
                    if (! is_array($resumen)) {
                        $resumen = [];
                    }

                    $cantidad = (float) ($item->cantidad ?? 1);
                    $base = (float) ($item->monto_base ?? 0);
                    $extras = (float) ($item->monto_extras ?? 0);
                    $totalLinea = (float) ($item->total_linea ?? round(($base + $extras) * max(1, $cantidad), 2));
                    $descripcionServicio = trim((string) ($resumen['descripcion_servicio'] ?? ''));
                    $titulo = trim((string) ($item->titulo ?? ''));
                    $nombreServicio = trim((string) ($item->nombre_servicio ?? ''));
                    $descripcion = $descripcionServicio !== ''
                        ? $descripcionServicio
                        : ($titulo !== '' ? $titulo : ($nombreServicio !== '' ? $nombreServicio : 'Sin detalle'));

                    return [
                        'id' => (int) ($item->id ?? 0),
                        'codigo' => (string) (($item->codigo ?? '') !== '' ? $item->codigo : ('ITEM-'.(int) $item->id)),
                        'descripcion' => $descripcion,
                        'titulo' => $descripcion,
                        'nombre_servicio' => $nombreServicio !== '' ? $nombreServicio : $titulo,
                        'nombre_destinatario' => (string) ($item->nombre_destinatario ?? ''),
                        'origen_tipo' => (string) ($item->origen_tipo ?? ''),
                        'resumen_origen' => $resumen,
                        'cantidad' => $cantidad,
                        'precio' => $base,
                        'monto_base' => $base,
                        'monto_extras' => $extras,
                        'total_linea' => $totalLinea,
                    ];
                })->values()->all();
            })
            ->toArray();
    }

    private function serviceReportDetalleMapsFromRows(Collection $ventas): array
    {
        $ventaIds = $ventas->pluck('id')->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $detalleVentasMap = [];
        if ($ventaIds !== []) {
            $detalleVentasMap = DB::table('detalle_ventas')
                ->whereIn('venta_id', $ventaIds)
                ->orderBy('id')
                ->get(['venta_id', 'id', 'codigo', 'descripcion', 'cantidad', 'precio'])
                ->groupBy('venta_id')
                ->map(function ($items) {
                    return collect($items)->map(function ($item) {
                        $cantidad = (float) ($item->cantidad ?? 1);
                        $precio = (float) ($item->precio ?? 0);

                        return [
                            'id' => (int) ($item->id ?? 0),
                            'codigo' => (string) ($item->codigo ?? ''),
                            'descripcion' => (string) ($item->descripcion ?? 'Sin detalle'),
                            'titulo' => (string) ($item->descripcion ?? 'Sin detalle'),
                            'nombre_servicio' => (string) ($item->descripcion ?? 'Sin detalle'),
                            'nombre_destinatario' => null,
                            'origen_tipo' => 'detalle_venta',
                            'resumen_origen' => [],
                            'cantidad' => $cantidad,
                            'precio' => $precio,
                            'monto_base' => $precio,
                            'monto_extras' => 0.0,
                            'total_linea' => round($cantidad * $precio, 2),
                        ];
                    })->values()->all();
                })
                ->toArray();
        }

        $cartIds = $ventas
            ->filter(fn ($venta) => in_array((string) ($venta->origen_venta_tipo ?? ''), ['facturacion_cart', 'facturacion_cart_remote'], true))
            ->pluck('origen_venta_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();
        $cartItemsMap = [];
        if ($cartIds !== [] && $this->hasFacturacionCartItemsTable()) {
            $cartItemsMap = DB::table('facturacion_cart_items')
                ->whereIn('cart_id', $cartIds)
                ->orderBy('id')
                ->get([
                    'cart_id', 'id', 'origen_tipo', 'origen_id', 'codigo', 'titulo',
                    'nombre_servicio', 'nombre_destinatario', 'resumen_origen', 'cantidad',
                    'monto_base', 'monto_extras', 'total_linea',
                ])
                ->groupBy('cart_id')
                ->map(function ($items) {
                    return collect($items)->map(function ($item) {
                        $resumen = json_decode((string) ($item->resumen_origen ?? ''), true);
                        if (! is_array($resumen)) {
                            $resumen = [];
                        }
                        $cantidad = (float) ($item->cantidad ?? 1);
                        $base = (float) ($item->monto_base ?? 0);
                        $extras = (float) ($item->monto_extras ?? 0);
                        $totalLinea = (float) ($item->total_linea ?? round(($base + $extras) * max(1, $cantidad), 2));
                        $descripcionServicio = trim((string) ($resumen['descripcion_servicio'] ?? ''));
                        $titulo = trim((string) ($item->titulo ?? ''));
                        $nombreServicio = trim((string) ($item->nombre_servicio ?? ''));
                        $descripcion = $descripcionServicio !== ''
                            ? $descripcionServicio
                            : ($titulo !== '' ? $titulo : ($nombreServicio !== '' ? $nombreServicio : 'Sin detalle'));

                        return [
                            'id' => (int) ($item->id ?? 0),
                            'codigo' => (string) (($item->codigo ?? '') !== '' ? $item->codigo : ('ITEM-'.(int) $item->id)),
                            'descripcion' => $descripcion,
                            'titulo' => $descripcion,
                            'nombre_servicio' => $nombreServicio !== '' ? $nombreServicio : $titulo,
                            'nombre_destinatario' => (string) ($item->nombre_destinatario ?? ''),
                            'origen_tipo' => (string) ($item->origen_tipo ?? ''),
                            'resumen_origen' => $resumen,
                            'cantidad' => $cantidad,
                            'precio' => $base,
                            'monto_base' => $base,
                            'monto_extras' => $extras,
                            'total_linea' => $totalLinea,
                        ];
                    })->values()->all();
                })
                ->toArray();
        }

        return ['detalle' => $detalleVentasMap, 'cart' => $cartItemsMap];
    }

    private function attachServiceReportRegional(Collection $payloads): Collection
    {
        if ($payloads->isEmpty()) {
            return $payloads;
        }

        $regionalLookupRows = $payloads->map(fn (array $row) => [
            'origen_sucursal_codigo' => data_get($row, 'sucursal.codigoSucursal'),
            'codigoSucursal' => data_get($row, 'sucursal.codigoSucursal'),
        ]);
        $regionalMap = $this->kardexRegionalMap($regionalLookupRows);

        return $payloads->map(function (array $row) use ($regionalMap) {
            $codigoSucursal = trim((string) data_get($row, 'sucursal.codigoSucursal', ''));
            $regional = $regionalMap->get($codigoSucursal);
            $regionalNombre = $this->resolveKardexRegionalName([
                'origen_sucursal_departamento' => data_get($row, 'sucursal.departamento'),
                'origen_sucursal_nombre' => data_get($row, 'sucursal.nombre'),
            ], $regional);
            $row['regional'] = [
                'nombre' => $regionalNombre !== '-' ? $regionalNombre : 'SIN REGIONAL',
                'codigoSucursal' => data_get($row, 'sucursal.codigoSucursal'),
            ];

            return $row;
        })->values();
    }

    private function newServiceReportRowSpool(): array
    {
        return ['buffer' => [], 'paths' => [], 'rowCount' => 0];
    }

    private function appendServiceReportSpoolRow(array &$spool, array $row): void
    {
        $spool['buffer'][] = $row;
        $spool['rowCount']++;
        if (count($spool['buffer']) >= 5000) {
            $this->flushServiceReportSpool($spool);
        }
    }

    private function flushServiceReportSpool(array &$spool): void
    {
        if ($spool['buffer'] === []) {
            return;
        }

        usort($spool['buffer'], fn (array $left, array $right) =>
            (strtotime((string) ($right['fecha'] ?? '1970-01-01 00:00:00')) ?: 0)
            <=> (strtotime((string) ($left['fecha'] ?? '1970-01-01 00:00:00')) ?: 0)
        );
        $path = tempnam(sys_get_temp_dir(), 'service-report-rows-');
        if ($path === false) {
            throw new \RuntimeException('No se pudo crear el archivo temporal para el detalle del reporte.');
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            @unlink($path);
            throw new \RuntimeException('No se pudo abrir el archivo temporal para el detalle del reporte.');
        }

        try {
            foreach ($spool['buffer'] as $row) {
                $serialized = serialize($row);
                $record = pack('N', strlen($serialized)).$serialized;
                $written = 0;
                while ($written < strlen($record)) {
                    $bytes = fwrite($handle, substr($record, $written));
                    if ($bytes === false || $bytes === 0) {
                        throw new \RuntimeException('No se pudo escribir el detalle temporal del reporte.');
                    }
                    $written += $bytes;
                }
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($path);
            throw $exception;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $spool['paths'][] = $path;
        $spool['buffer'] = [];
    }

    private function serviceReportDetailStreamResponse(array $filters, array $detail, array $spool)
    {
        unset($detail['rows']);

        return new StreamedResponse(function () use ($filters, $detail, $spool) {
            $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
            $handles = [];
            try {
                echo '{"filters":'.json_encode($filters, $flags).',"servicio":{';
                $firstField = true;
                foreach ($detail as $key => $value) {
                    if (! $firstField) {
                        echo ',';
                    }
                    echo json_encode((string) $key, $flags).':'.json_encode($value, $flags);
                    $firstField = false;
                }
                if (! $firstField) {
                    echo ',';
                }
                echo '"rows":[';

                $queue = new \SplPriorityQueue();
                $queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
                $sequence = 0;
                foreach ($spool['paths'] as $runIndex => $path) {
                    $handle = fopen($path, 'rb');
                    if ($handle === false) {
                        throw new \RuntimeException('No se pudo leer el detalle temporal del reporte.');
                    }
                    $handles[$runIndex] = $handle;
                    $row = $this->readServiceReportSpoolRow($handle);
                    if ($row === null) {
                        continue;
                    }
                    $timestamp = strtotime((string) ($row['fecha'] ?? '1970-01-01 00:00:00')) ?: 0;
                    $queue->insert(['run' => $runIndex, 'row' => $row], [$timestamp, -$sequence++]);
                }

                $firstRow = true;
                while (! $queue->isEmpty()) {
                    $entry = $queue->extract();
                    if (! $firstRow) {
                        echo ',';
                    }
                    echo json_encode($entry['data']['row'], $flags);
                    $firstRow = false;

                    $runIndex = $entry['data']['run'];
                    $row = $this->readServiceReportSpoolRow($handles[$runIndex]);
                    if ($row === null) {
                        continue;
                    }
                    $timestamp = strtotime((string) ($row['fecha'] ?? '1970-01-01 00:00:00')) ?: 0;
                    $queue->insert(['run' => $runIndex, 'row' => $row], [$timestamp, -$sequence++]);
                }
                echo ']}}';
            } finally {
                foreach ($handles as $handle) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                $this->deleteServiceReportSpool($spool);
            }
        }, 200, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    private function readServiceReportSpoolRow($handle): ?array
    {
        $header = fread($handle, 4);
        if ($header === false || $header === '') {
            return null;
        }
        if (strlen($header) !== 4) {
            throw new \RuntimeException('El archivo temporal del detalle está incompleto.');
        }

        $length = unpack('Nlength', $header)['length'] ?? 0;
        if ($length <= 0) {
            return null;
        }

        $serialized = '';
        while (strlen($serialized) < $length) {
            $chunk = fread($handle, min(1024 * 1024, $length - strlen($serialized)));
            if ($chunk === false || $chunk === '') {
                throw new \RuntimeException('El archivo temporal del detalle está incompleto.');
            }
            $serialized .= $chunk;
        }

        $row = @unserialize($serialized, ['allowed_classes' => false]);

        if (! is_array($row)) {
            throw new \RuntimeException('El archivo temporal del detalle no contiene una fila válida.');
        }

        return $row;
    }

    private function deleteServiceReportSpool(array $spool): void
    {
        foreach ($spool['paths'] ?? [] as $path) {
            if (is_string($path) && is_file($path)) {
                @unlink($path);
            }
        }
    }
}
