<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Accounts\PasswordReset;
use App\Services\Accounts\PhoneVerifier;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Jibiat\Socle\Rules\PhoneNumber as PhoneRule;

class PasswordResetController extends Controller
{
    public function start(Request $request, PasswordReset $reset, PhoneVerifier $verifier, Tenancy $tenancy): JsonResponse
    {
        $request->validate(['phone' => ['required', new PhoneRule]], [], ['phone' => 'numéro de téléphone']);

        [$verification, $token, $code] = $reset->start($request->string('phone'), $tenancy->store(), $request->ip());

        return response()->json(VerificationController::pending($verification, $token, $code, $verifier), 202);
    }
}
