<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // PostgreSQL concurrent indexes avoid blocking invoice writes while the
    // index is being built on the shared API database.
    public $withinTransaction = false;

    public function up(): void
    {
        if (Schema::hasTable('ventas') && Schema::hasColumn('ventas', 'estado') && Schema::hasColumn('ventas', 'created_at')) {
            $this->createIndex('ventas', ['estado', 'created_at', 'id'], 'ventas_estado_created_id_idx');
        }

        if (Schema::hasTable('detalle_ventas') && Schema::hasColumn('detalle_ventas', 'venta_id')) {
            $this->createIndex('detalle_ventas', ['venta_id', 'id'], 'detalle_ventas_venta_id_id_idx');
        }

        if (Schema::hasTable('facturacion_carts') && Schema::hasColumn('facturacion_carts', 'created_at')) {
            $this->createIndex('facturacion_carts', ['created_at', 'id'], 'carts_created_id_idx');
            if (Schema::hasColumn('facturacion_carts', 'emitido_en')) {
                $this->createIndex('facturacion_carts', ['emitido_en', 'id'], 'carts_emitido_id_idx');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ventas')) {
            $this->dropIndex('ventas', 'ventas_estado_created_id_idx');
        }

        if (Schema::hasTable('detalle_ventas')) {
            $this->dropIndex('detalle_ventas', 'detalle_ventas_venta_id_id_idx');
        }

        if (Schema::hasTable('facturacion_carts')) {
            $this->dropIndex('facturacion_carts', 'carts_created_id_idx');
            if (Schema::hasColumn('facturacion_carts', 'emitido_en')) {
                $this->dropIndex('facturacion_carts', 'carts_emitido_id_idx');
            }
        }
    }

    private function createIndex(string $table, array $columns, string $name): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            $wrappedColumns = implode(', ', array_map(fn (string $column) => '"'.$column.'"', $columns));
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS "'.$name.'" ON "'.$table.'" ('.$wrappedColumns.')');
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function dropIndex(string $table, string $name): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS "'.$name.'"');
            return;
        }

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
    }
};
