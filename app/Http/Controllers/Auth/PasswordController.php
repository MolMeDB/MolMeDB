<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\UpdateUserPassword;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    /**
     * Change the signed-in user's password (account settings in the frontend).
     */
    public function update(Request $request, UpdateUserPassword $updater): JsonResponse
    {
        $updater->update($request->user(), $request->only(['current_password', 'password', 'password_confirmation']));

        return response()->json(['status' => 'password-updated']);
    }
}
