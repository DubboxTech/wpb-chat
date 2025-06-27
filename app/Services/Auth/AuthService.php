<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;

class AuthService
{
    /**
     * Attempt to authenticate user with email and password.
     */
    public function login(string $email, string $password, string $ip): array
    {
        $user = User::where('email', $email)->active()->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return [
                'success' => false,
                'message' => 'Credenciais inválidas.',
            ];
        }

        // Check if 2FA is enabled
        if ($user->two_factor_enabled) {
            $code = $user->generateTwoFactorCode();
            $this->sendTwoFactorCode($user, $code);

            return [
                'success' => true,
                'requires_2fa' => true,
                'message' => 'Código de verificação enviado.',
                'user_id' => $user->id,
            ];
        }

        // Generate JWT token
        try {
            $token = JWTAuth::fromUser($user);
            $user->updateLastLogin($ip);

            return [
                'success' => true,
                'token' => $token,
                'user' => $user->load('roles'),
                'expires_in' => auth('api')->factory()->getTTL() * 60,
            ];
        } catch (JWTException $e) {
            Log::error('JWT Token generation failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Erro interno do servidor.',
            ];
        }
    }

    /**
     * Verify 2FA code and complete login.
     */
    public function verifyTwoFactor(int $userId, string $code, string $ip): array
    {
        $user = User::find($userId);

        if (!$user || !$user->verifyTwoFactorCode($code)) {
            return [
                'success' => false,
                'message' => 'Código de verificação inválido ou expirado.',
            ];
        }

        try {
            $token = JWTAuth::fromUser($user);
            $user->updateLastLogin($ip);

            return [
                'success' => true,
                'token' => $token,
                'user' => $user->load('roles'),
                'expires_in' => auth('api')->factory()->getTTL() * 60,
            ];
        } catch (JWTException $e) {
            Log::error('JWT Token generation failed: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => 'Erro interno do servidor.',
            ];
        }
    }

    /**
     * Refresh JWT token.
     */
    public function refresh(): array
    {
        try {
            $token = JWTAuth::refresh();
            
            return [
                'success' => true,
                'token' => $token,
                'expires_in' => auth('api')->factory()->getTTL() * 60,
            ];
        } catch (JWTException $e) {
            return [
                'success' => false,
                'message' => 'Token inválido.',
            ];
        }
    }

    /**
     * Logout user.
     */
    public function logout(): array
    {
        try {
            JWTAuth::invalidate(JWTAuth::getToken());
            
            return [
                'success' => true,
                'message' => 'Logout realizado com sucesso.',
            ];
        } catch (JWTException $e) {
            return [
                'success' => false,
                'message' => 'Erro ao fazer logout.',
            ];
        }
    }

    /**
     * Get authenticated user.
     */
    public function me(): ?User
    {
        try {
            return JWTAuth::parseToken()->authenticate();
        } catch (JWTException $e) {
            return null;
        }
    }

    /**
     * Send 2FA code to user.
     */
    private function sendTwoFactorCode(User $user, string $code): void
    {
        if ($user->two_factor_type === 'email') {
            $this->sendEmailCode($user, $code);
        } elseif ($user->two_factor_type === 'sms') {
            $this->sendSmsCode($user, $code);
        }
    }

    /**
     * Send 2FA code via email.
     */
    private function sendEmailCode(User $user, string $code): void
    {
        try {
            Mail::send('emails.two-factor-code', ['code' => $code, 'user' => $user], function ($message) use ($user) {
                $message->to($user->email)
                        ->subject('Código de Verificação - ' . config('app.name'));
            });
        } catch (\Exception $e) {
            Log::error('Failed to send 2FA email: ' . $e->getMessage());
        }
    }

    /**
     * Send 2FA code via SMS.
     */
    private function sendSmsCode(User $user, string $code): void
    {
        if (!$user->two_factor_phone) {
            return;
        }

        try {
            // Implement SMS sending logic here (Twilio, etc.)
            // For now, just log the code
            Log::info("2FA SMS code for {$user->email}: {$code}");
        } catch (\Exception $e) {
            Log::error('Failed to send 2FA SMS: ' . $e->getMessage());
        }
    }

    /**
     * Enable 2FA for user.
     */
    public function enableTwoFactor(User $user, string $type, ?string $phone = null): array
    {
        if ($type === 'sms' && !$phone) {
            return [
                'success' => false,
                'message' => 'Número de telefone é obrigatório para SMS.',
            ];
        }

        $user->update([
            'two_factor_enabled' => true,
            'two_factor_type' => $type,
            'two_factor_phone' => $phone,
        ]);

        return [
            'success' => true,
            'message' => 'Autenticação de dois fatores ativada com sucesso.',
        ];
    }

    /**
     * Disable 2FA for user.
     */
    public function disableTwoFactor(User $user): array
    {
        $user->update([
            'two_factor_enabled' => false,
            'two_factor_type' => null,
            'two_factor_phone' => null,
            'two_factor_code' => null,
            'two_factor_expires_at' => null,
            'two_factor_verified_at' => null,
        ]);

        return [
            'success' => true,
            'message' => 'Autenticação de dois fatores desativada.',
        ];
    }
}

