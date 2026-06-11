<?php

use App\Http\Controllers\EmailValidationController;

Route::post('/validate',              [EmailValidationController::class, 'apiValidate']);
Route::post('/validate-bulk',         [EmailValidationController::class, 'apiValidateBulk']);
Route::get('/job/{jobId}/status',     [EmailValidationController::class, 'jobStatus']);
Route::get('/job/{jobId}/download',   [EmailValidationController::class, 'jobDownload']);
Route::post('/validate-bulk-json', [EmailValidationController::class, 'apiValidateBulkJson']);
Route::get('job/{jobId}/unverifiable', [EmailValidationController::class, 'jobUnverifiable']);