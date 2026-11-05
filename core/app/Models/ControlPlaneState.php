<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ControlPlaneState extends Model
{
    protected $table = 'control_plane_state';

    public $incrementing = false;

    protected $fillable = ['epoch', 'active_node', 'addresses'];

    protected function casts(): array
    {
        return ['epoch' => 'integer', 'addresses' => 'array'];
    }

    public static function current(): self
    {
        return self::query()->findOrFail(1);
    }
}
