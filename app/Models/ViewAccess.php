<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ViewAccess extends Model
{
   protected $table = 'views_access';

   protected $fillable = [
      'name',
      'slug',
      'route',
      'description',
      'is_active',
   ];

   protected $casts = [
      'is_active' => 'boolean',
   ];

   public function roles()
   {
      return $this->belongsToMany(Role::class, 'role_view')->withTimestamps();
   }
}
