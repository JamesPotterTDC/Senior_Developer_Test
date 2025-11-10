<?php

use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ReservationController;
use Illuminate\Support\Facades\Route;

Route::get('/events/{event}', [EventController::class, 'show']);
Route::post('/events/{event}/reserve', [ReservationController::class, 'reserve']);
Route::post('/reservations/{reservation}/purchase', [ReservationController::class, 'purchase']);


