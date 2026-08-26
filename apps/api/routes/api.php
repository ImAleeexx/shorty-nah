<?php

declare(strict_types=1);

use App\Http\Controllers\PublicConfigurationController;
use Illuminate\Support\Facades\Route;

// Read before anyone signs in, so the interface can render branding on first
// paint. Carries only the registry's exposed subset.
Route::get('/v1/config', PublicConfigurationController::class)->name('config.public');
