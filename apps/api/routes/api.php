<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OperationsController as Ops;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SyncController;
use Illuminate\Support\Facades\Route;

/*
| Diapers & Wipes 360 API v1 (PRD B15). Every protected route is authorised on the server with
| `can:<ability>` (role + bundle matrix in App\Support\Permissions). POSTs that create money, stock or
| messages require an Idempotency-Key header (`idem:<scope>`).
*/

Route::prefix('v1')->group(function () {
    Route::middleware('throttle:public')->prefix('public')->group(function () {
        Route::get('business', [PublicController::class, 'business']);
        Route::get('products', [PublicController::class, 'products']);
    });

    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('auth/pin', [AuthController::class, 'pin'])->middleware('throttle:login');
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Catalogue & pricing
        Route::get('catalogue', [Ops::class, 'catalogue'])->middleware('can:sell');
        Route::patch('variants/{variant}/prices', [Ops::class, 'updatePrices'])->middleware('can:prices.edit');

        // POS
        Route::get('shifts/current', [Ops::class, 'currentShift'])->middleware('can:sell');
        Route::post('shifts/open', [Ops::class, 'openShift'])->middleware(['can:sell', 'idem:shift_open']);
        Route::post('shifts/cash-moves', [Ops::class, 'cashMove'])->middleware(['can:sell', 'idem:cash_move']);
        Route::post('shifts/close', [Ops::class, 'closeShift'])->middleware(['can:sell', 'idem:shift_close']);
        Route::get('sales', [SaleController::class, 'index'])->middleware('can:sales.view');
        Route::get('sales/{order}', [SaleController::class, 'show'])->middleware('can:sales.view');
        Route::post('sales', [SaleController::class, 'store'])->middleware(['can:sell', 'idem:sale']);

        // Manual MoMo verification
        Route::get('payments/momo/pending', [Ops::class, 'pendingMomo'])->middleware('can:momo.verify');
        Route::post('payments/{payment}/verify', [Ops::class, 'verifyMomo'])->middleware(['can:momo.verify', 'idem:momo_verify']);

        // Stock
        Route::post('stock/receipts', [Ops::class, 'receive'])->middleware(['can:stock.receive', 'idem:grn']);
        Route::post('stock/adjustments', [Ops::class, 'adjust'])->middleware(['can:stock.receive', 'idem:adjust']);
        Route::get('stock/expiry', [Ops::class, 'expiry'])->middleware('can:stock.receive');

        // Customers & consent
        Route::get('customers', [Ops::class, 'customers'])->middleware('can:customers.manage');
        Route::post('customers', [Ops::class, 'registerCustomer'])->middleware(['can:customers.manage', 'idem:customer']);
        Route::post('customers/{customer}/consents', [Ops::class, 'consent'])->middleware(['can:customers.manage', 'idem:consent']);

        // Wholesale receivables
        Route::get('receivables', [Ops::class, 'receivables'])->middleware('can:receivables.manage');
        Route::post('customers/{customer}/payments', [Ops::class, 'receivablePayment'])->middleware(['can:receivables.manage', 'idem:rec_payment']);
        Route::patch('customers/{customer}/credit', [Ops::class, 'setCreditLimit'])->middleware('can:credit.set_limits');

        // Offline sync
        Route::post('devices', [SyncController::class, 'registerDevice'])->middleware('can:devices.manage');
        Route::post('devices/{device}/revoke', [SyncController::class, 'revokeDevice'])->middleware('can:devices.manage');
        Route::get('sync/snapshot', [SyncController::class, 'snapshot'])->middleware('can:sell');
        Route::post('sync/events', [SyncController::class, 'upload'])->middleware('can:sell');

        // Owner
        Route::get('audit/verify', [Ops::class, 'verifyAudit'])->middleware('can:settings.manage');
    });
});
