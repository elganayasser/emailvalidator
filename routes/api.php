<?php

use App\Http\Controllers\EmailValidationController;

Route::post('/validate',      [EmailValidationController::class, 'apiValidate']);
Route::post('/validate-bulk', [EmailValidationController::class, 'apiValidateBulk']);
