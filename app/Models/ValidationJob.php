<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ValidationJob extends Model
{
    protected $fillable = [
        'job_id',
        'status',
        'total_emails',
        'processed_emails',
        'result_file',
        'error',
    ];
}