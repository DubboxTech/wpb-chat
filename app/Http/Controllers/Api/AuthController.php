<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Login user.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->authService->login(
            $request->email,
            $request->password,
            $request->ip()
        );

        $statusCode = $result['success'] ? 200 : 401;
        
        return response()->json($result, $statusCode);
    }

    /**
     * Verify 2FA code.
     */
    public function verifyTwoFactor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|integer|exists:users,id',
            'code' => 'required|string|size:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->authService->verifyTwoFactor(
            $request->user_id,
            $request->code,
            $request->ip()
        );

        $statusCode = $result['success'] ? 200 : 401;
        
        return response()->json($result, $statusCode);
    }

    /**
     * Refresh JWT token.
     */
    public function refresh(): JsonResponse
    {
        $result = $this->authService->refresh();
        $statusCode = $result['success'] ? 200 : 401;
        
        return response()->json($result, $statusCode);
    }

    /**
     * Logout user.
     */
    public function logout(): JsonResponse
    {
        $result = $this->authService->logout();
        
        return response()->json($result);
    }

    /**
     * Get authenticated user.
     */
    public function me(): JsonResponse
    {
        $user = $this->authService->me();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuário não autenticado.',
            ], 401);
        }

        return response()->json([
            'success' => true,
            'user' => $user->load('roles'),
        ]);
    }

    /**
     * Enable 2FA for user.
     */
    public function enableTwoFactor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|in:email,sms',
            'phone' => 'required_if:type,sms|string|min:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = auth()->user();
        $result = $this->authService->enableTwoFactor(
            $user,
            $request->type,
            $request->phone
        );

        return response()->json($result);
    }

    /**
     * Disable 2FA for user.
     */
    public function disableTwoFactor(): JsonResponse
    {
        $user = auth()->user();
        $result = $this->authService->disableTwoFactor($user);

        return response()->json($result);
    }

    /**
     * Get user's 2FA status.
     */
    public function twoFactorStatus(): JsonResponse
    {
        $user = auth()->user();

        return response()->json([
            'success' => true,
            'two_factor_enabled' => $user->two_factor_enabled,
            'two_factor_type' => $user->two_factor_type,
            'two_factor_phone' => $user->two_factor_phone ? 
                substr($user->two_factor_phone, 0, 3) . '****' . substr($user->two_factor_phone, -2) : 
                null,
        ]);
    }
}

