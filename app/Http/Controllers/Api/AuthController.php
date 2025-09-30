<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Log;

class AuthController extends Controller
{
    /**
     * Redirect to Microsoft OAuth
     */
    public function redirectToMicrosoft(): JsonResponse
    {
        try {
            $redirectUrl = Socialite::driver('microsoft')
                ->scopes(['openid', 'profile', 'email', 'User.Read'])
                ->redirect()
                ->getTargetUrl();

            return response()->json([
                'success' => true,
                'redirect_url' => $redirectUrl,
                'message' => 'Redirect to Microsoft OAuth'
            ]);
        } catch (\Exception $e) {
            Log::error('Microsoft OAuth redirect failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Microsoft OAuth configuration error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Microsoft OAuth callback
     */
    public function handleMicrosoftCallback(Request $request): JsonResponse
    {
        try {
            $microsoftUser = Socialite::driver('microsoft')->user();
            
            // Find or create admin user
            $adminUser = AdminUser::firstOrCreate(
                ['microsoft_id' => $microsoftUser->getId()],
                [
                    'email' => $microsoftUser->getEmail(),
                    'first_name' => $microsoftUser->user['givenName'] ?? 'Unknown',
                    'last_name' => $microsoftUser->user['surname'] ?? 'User',
                    'department' => $microsoftUser->user['jobTitle'] ?? null,
                    'is_active' => true,
                    'microsoft_data' => $microsoftUser->user,
                ]
            );

            // Update last login
            $adminUser->updateLastLogin();

            // Create API token
            $token = $adminUser->createToken('API Token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login successful',
                'user' => $adminUser,
                'token' => $token,
                'token_type' => 'Bearer'
            ]);

        } catch (\Exception $e) {
            Log::error('Microsoft OAuth callback failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Authentication failed',
                'error' => $e->getMessage()
            ], 401);
        }
    }

    /**
     * Simulate Microsoft authentication for testing
     */
    public function simulateLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:admin_users,email',
        ]);

        try {
            $adminUser = AdminUser::where('email', $request->email)->first();

            if (!$adminUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if (!$adminUser->is_active) {
                return response()->json([
                    'success' => false,
                    'message' => 'Account is inactive'
                ], 403);
            }

            // Update last login
            $adminUser->updateLastLogin();

            // Create API token
            $token = $adminUser->createToken('API Token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Simulated login successful',
                'user' => $adminUser,
                'token' => $token,
                'token_type' => 'Bearer',
                'simulation_mode' => true
            ]);

        } catch (\Exception $e) {
            Log::error('Simulated login failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Simulated authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available users for simulation
     */
    public function getSimulationUsers(): JsonResponse
    {
        $users = AdminUser::select('id', 'email', 'first_name', 'last_name', 'department', 'is_active')
            ->where('is_active', true)
            ->get();

        return response()->json([
            'success' => true,
            'users' => $users,
            'message' => 'Available users for simulation'
        ]);
    }

    /**
     * Get current authenticated user
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        
        return response()->json([
            'success' => true,
            'user' => $user,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name')
        ]);
    }

    /**
     * Logout user (revoke token)
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            // Revoke the current token
            $request->user()->currentAccessToken()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Logout failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Logout failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout from all devices (revoke all tokens)
     */
    public function logoutAll(Request $request): JsonResponse
    {
        try {
            // Revoke all tokens for the user
            $request->user()->tokens()->delete();

            return response()->json([
                'success' => true,
                'message' => 'Logged out from all devices successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Logout all failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Logout from all devices failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            // Revoke current token
            $request->user()->currentAccessToken()->delete();
            
            // Create new token
            $token = $user->createToken('API Token')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Token refreshed successfully',
                'token' => $token,
                'token_type' => 'Bearer'
            ]);
        } catch (\Exception $e) {
            Log::error('Token refresh failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Token refresh failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}