<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['data' => 'array', 'deadline' => 'immutable_datetime', 'opened_at' => 'immutable_datetime', 'needed_at' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(DocumentLine::class);
    }
}
