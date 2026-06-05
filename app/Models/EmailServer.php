<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailServer extends Model
{
    //
    protected $fillable = ['smtpServer','CompanyName','validationStatus'];
}
