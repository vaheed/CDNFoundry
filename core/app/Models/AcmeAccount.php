<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcmeAccount extends Model
{
    protected $guarded = [];

    protected $hidden = ['private_key_ciphertext'];

    protected function casts(): array
    {
        return ['private_key_ciphertext' => 'encrypted'];
    }
}
