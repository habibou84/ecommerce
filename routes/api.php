<?php

use App\Enums\Permission;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\PlatformAccountController;
use App\Http\Controllers\Api\V1\SignupController;
use App\Http\Controllers\Api\V1\StoreController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('v1.')->group(function () {
    // ── jibiat-marchand.com : création de boutique, retrouver ses boutiques ──
    Route::middleware('zone:platform')->group(function () {
        Route::get('signup/slug', [SignupController::class, 'checkSlug'])->middleware('throttle:60,1')->name('signup.slug');
        Route::post('signup', [SignupController::class, 'start'])->middleware('throttle:verification-start')->name('signup');

        Route::post('account/login', [PlatformAccountController::class, 'login'])->middleware('throttle:login')->name('account.login');
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('account/stores', [PlatformAccountController::class, 'stores'])->name('account.stores');
            Route::post('account/stores/{store}/open', [PlatformAccountController::class, 'open'])->whereNumber('store')->name('account.open');
            Route::post('account/logout', [PlatformAccountController::class, 'logout'])->name('account.logout');
        });
    });

    // ── Code SMS et mot de passe oublié : plateforme et boutiques ──
    Route::middleware('zone:platform,store')->group(function () {
        Route::post('password/forgot', [PasswordResetController::class, 'start'])->middleware('throttle:verification-start')->name('password.forgot');
        Route::post('verifications/{token}/resend', [VerificationController::class, 'resend'])->middleware('throttle:verification-code')->name('verifications.resend');
        Route::post('verifications/{token}/confirm', [VerificationController::class, 'confirm'])->middleware('throttle:verification-code')->name('verifications.confirm');
    });

    // ── {slug}.jibiat-marchand.com : gestion de la boutique ──
    Route::middleware('zone:store')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');
        Route::get('invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
        Route::post('invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware('throttle:login')->name('invitations.accept');

        Route::middleware(['auth:sanctum', 'member'])->group(function () {
            Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
            Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::post('auth/switch/{store}', [AuthController::class, 'switch'])->whereNumber('store')->name('auth.switch');

            Route::get('store', [StoreController::class, 'show'])->name('store.show');
            Route::middleware('permission:'.Permission::StoreManage->value)->group(function () {
                Route::patch('store', [StoreController::class, 'update'])->name('store.update');
                Route::put('store/slug', [StoreController::class, 'changeSlug'])->name('store.slug');
            });

            Route::middleware('permission:'.Permission::TeamManage->value)->group(function () {
                Route::get('team/roles', [MemberController::class, 'roles'])->name('team.roles');
                Route::get('team/members', [MemberController::class, 'index'])->name('team.members');
                Route::patch('team/members/{member}', [MemberController::class, 'update'])->whereNumber('member')->name('team.members.update');
                Route::delete('team/members/{member}', [MemberController::class, 'destroy'])->whereNumber('member')->name('team.members.destroy');
                Route::get('team/invitations', [InvitationController::class, 'index'])->name('team.invitations');
                Route::post('team/invitations', [InvitationController::class, 'store'])->name('team.invitations.store');
                Route::delete('team/invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')->name('team.invitations.destroy');
            });
        });
    });
});
