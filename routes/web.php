<?php

use App\Http\Controllers\EmailValidationController;

Route::get('/',      [EmailValidationController::class, 'index'])->name('home');
Route::get('/bulk',  [EmailValidationController::class, 'bulk'])->name('bulk');
Route::post('/check-single', [EmailValidationController::class, 'checkSingle'])->name('check.single');
Route::post('/check-bulk',   [EmailValidationController::class, 'checkBulk'])->name('check.bulk');
