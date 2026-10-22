<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'supervisor')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'ventas.void')->value('id');

        if (! $roleId || ! $permissionId) {
            return;
        }

        DB::table('role_permission')->insertOrIgnore([
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission')) {
            return;
        }

        $roleId = DB::table('roles')->where('slug', 'supervisor')->value('id');
        $permissionId = DB::table('permissions')->where('slug', 'ventas.void')->value('id');

        if ($roleId && $permissionId) {
            DB::table('role_permission')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
        }
    }
};
