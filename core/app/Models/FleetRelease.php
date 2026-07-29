<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FleetRelease extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function versions(): array
    {
        return [
            'gateway' => $this->gateway_image,
            'agent' => $this->agent_image,
            'normal_cell' => $this->normal_cell_image,
            'waf_cell' => $this->waf_cell_image,
        ];
    }
}
