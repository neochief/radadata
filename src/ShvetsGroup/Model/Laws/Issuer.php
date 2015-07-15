<?php

namespace ShvetsGroup\Model\Laws;

use Illuminate\Database\Eloquent\Model;

class Issuer extends Model
{
    const field_name = 'Видавники';

    public $timestamps = false;
    public $primaryKey = 'name';
    public $fillable = ['id', 'name', 'full_name', 'group_name', 'website', 'url', 'international'];
}

