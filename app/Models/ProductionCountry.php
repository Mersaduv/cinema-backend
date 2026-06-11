<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionCountry extends Model
{
    protected $fillable = [
        'iso_3166_1',
        'name',
        'native_name',
        'english_name',
        'flag_emoji',
    ];
}
