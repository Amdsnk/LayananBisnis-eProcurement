<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentLine extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
