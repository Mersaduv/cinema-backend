<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpokenLanguage extends Model
{
    protected $fillable = [
        'iso_639_1',
        'name',
        'english_name',
    ];
}
