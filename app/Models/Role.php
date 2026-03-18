<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
   protected $fillable = [
      'name',
      'slug',
      'description',
      'is_system',
   ];

   protected $casts = [
      'is_system' => 'boolean',
   ];

   public function permissions()
   {
      return $this->belongsToMany(Permission::class, 'role_permission')->withTimestamps();
   }

   public function views()
   {
      return $this->belongsToMany(ViewAccess::class, 'role_view')->withTimestamps();
   }

   public function usuarios()
   {
      return $this->belongsToMany(Usuario::class, 'usuario_role')->withTimestamps();
   }
}
