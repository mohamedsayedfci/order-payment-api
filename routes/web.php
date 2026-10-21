<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['service' => 'Order and Payment API', 'version' => 'v1']));
