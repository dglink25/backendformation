<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AuthController extends Controller
{
    /**
     * ✅ INSCRIPTION avec vérification d'email obligatoire
     */
    public function register(Request $request)
    {
        try {
            $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|string|email|max:255|unique:users',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'apprenant',
                'onboarding_step' => 'role',
                'email_verified_at' => null, // ✅ Email NON vérifié
                'is_active' => true,
            ]);

            // ✅ Envoyer l'email de vérification
            $this->sendVerificationEmail($user);

            Log::info('✅ Nouvel utilisateur inscrit - Email de vérification envoyé', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Inscription réussie ! Veuillez consulter votre email pour confirmer votre adresse.',
                'email' => $user->email,
                'requires_verification' => true,
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('❌ Erreur inscription:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'inscription',
            ], 500);
        }
    }

    /**
     * ✅ CONNEXION - Bloque si email non vérifié
     */
    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user || !Hash::check($request->password, $user->password)) {
                Log::warning('❌ Échec connexion - Credentials invalides', [
                    'email' => $request->email,
                    'user_exists' => !is_null($user),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Email ou mot de passe incorrect',
                ], 401);
            }

            // ✅ Log détaillé de l'état de l'utilisateur
            Log::info('🔐 Tentative de connexion', [
                'user_id' => $user->id,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'email_verified_at' => $user->email_verified_at,
                'email_verified' => !is_null($user->email_verified_at),
            ]);

            if (!$user->is_active) {
                Log::warning('❌ Compte désactivé', ['user_id' => $user->id]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Votre compte a été désactivé',
                ], 403);
            }

            // ✅ VÉRIFICATION EMAIL OBLIGATOIRE
            if (!$user->email_verified_at) {
                Log::warning('❌ Email non vérifié', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Veuillez vérifier votre adresse email avant de vous connecter. Consultez votre boîte de réception.',
                    'email_verified' => false,
                    'email' => $user->email,
                ], 403);
            }

            // Charger les relations
            $user->load(['profile', 'domaines']);

            // Créer le token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Déterminer si l'onboarding est nécessaire
            $needsOnboarding = $user->needsOnboarding();

            Log::info('✅ Connexion réussie', [
                'user_id' => $user->id,
                'needs_onboarding' => $needsOnboarding,
            ]);

            return response()->json([
                'success' => true,
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'profile' => $user->profile,
                    'domaines' => $user->domaines,
                    'needs_onboarding' => $needsOnboarding,
                    'onboarding_step' => $user->onboarding_step,
                    'email_verified' => true,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('❌ Erreur connexion:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la connexion',
            ], 500);
        }
    }

    /**
     * ✅ VÉRIFIER L'EMAIL avec le token
     */
    public function verifyEmail(Request $request)
    {
        try {
            Log::info('📧 Tentative de vérification d\'email', [
                'email' => $request->email,
                'has_token' => !empty($request->token),
            ]);

            $request->validate([
                'token' => 'required|string',
                'email' => 'required|email',
            ]);

            // Chercher le token de vérification
            $verification = DB::table('email_verifications')
                ->where('email', $request->email)
                ->where('token', $request->token)
                ->first();

            if (!$verification) {
                Log::warning('⚠️ Token de vérification invalide', [
                    'email' => $request->email,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Lien de vérification invalide ou expiré',
                ], 400);
            }

            // Vérifier l'expiration (24 heures)
            $createdAt = Carbon::parse($verification->created_at);
            if ($createdAt->addHours(24)->isPast()) {
                DB::table('email_verifications')->where('email', $request->email)->delete();
                
                Log::warning('⚠️ Token de vérification expiré', [
                    'email' => $request->email,
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Ce lien de vérification a expiré. Veuillez demander un nouveau lien.',
                    'expired' => true,
                ], 400);
            }

            // Trouver l'utilisateur
            $user = User::where('email', $request->email)->first();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur non trouvé',
                ], 404);
            }

            // ✅ Marquer l'email comme vérifié
            $user->update([
                'email_verified_at' => now(),
            ]);

            // Supprimer le token
            DB::table('email_verifications')->where('email', $request->email)->delete();

            Log::info('✅ Email vérifié avec succès', ['user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Votre email a été vérifié avec succès ! Vous pouvez maintenant vous connecter.',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('❌ Erreur verifyEmail:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la vérification',
            ], 500);
        }
    }

    /**
     * ✅ RENVOYER l'email de vérification
     */
    public function resendVerification(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            $user = User::where('email', $request->email)->first();

            if ($user->email_verified_at) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cet email est déjà vérifié',
                ], 400);
            }

            // Supprimer les anciens tokens
            DB::table('email_verifications')->where('email', $request->email)->delete();

            // Envoyer un nouveau mail
            $this->sendVerificationEmail($user);

            Log::info('📧 Email de vérification renvoyé', ['email' => $request->email]);

            return response()->json([
                'success' => true,
                'message' => 'Email de vérification renvoyé avec succès',
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur resendVerification:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi',
            ], 500);
        }
    }

    /**
     * ✅ ENVOYER l'email de vérification
     */
    private function sendVerificationEmail($user)
    {
        // Créer un token unique
        $token = Str::random(64);

        // Stocker dans la DB
        DB::table('email_verifications')->insert([
            'email' => $user->email,
            'token' => $token,
            'created_at' => now(),
        ]);

        // Créer le lien de vérification
        $verificationLink = env('FRONTEND_URL', 'http://localhost:5173') . 
                           '/verify-email?token=' . $token . 
                           '&email=' . urlencode($user->email);

        // Envoyer l'email
        try {
            Mail::send('emails.verify-email', [
                'user' => $user,
                'verificationLink' => $verificationLink,
            ], function ($message) use ($user) {
                $message->to($user->email);
                $message->subject('Vérifiez votre adresse email - E-Learning Platform');
            });

            Log::info('📧 Email de vérification envoyé', ['email' => $user->email]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur envoi email de vérification:', [
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Confirmer le mot de passe (pour les actions sensibles)
     */
    public function confirmPassword(Request $request)
    {
        try {
            $request->validate([
                'password' => 'required',
            ]);

            $user = $request->user();

            if (!Hash::check($request->password, $user->password)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Mot de passe incorrect',
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe confirmé',
                'confirmed_at' => now()->toIso8601String(),
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur confirmPassword:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la confirmation',
            ], 500);
        }
    }

    /**
     * Mot de passe oublié
     */
    public function forgotPassword(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email|exists:users,email',
            ]);

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucun utilisateur trouvé avec cet email',
                ], 404);
            }

            // Supprimer les anciens tokens
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            // Créer un nouveau token
            $token = Str::random(64);

            DB::table('password_reset_tokens')->insert([
                'email' => $request->email,
                'token' => Hash::make($token),
                'created_at' => Carbon::now(),
            ]);

            // Créer le lien
            $resetLink = env('FRONTEND_URL', 'http://localhost:5173') . 
                         '/reset-password?token=' . $token . 
                         '&email=' . urlencode($request->email);

            // Envoyer l'email
            try {
                Mail::send('emails.reset-password', [
                    'user' => $user,
                    'resetLink' => $resetLink,
                    'token' => $token,
                ], function ($message) use ($user) {
                    $message->to($user->email);
                    $message->subject('Réinitialisation de votre mot de passe - E-Learning Platform');
                });

                Log::info('Email de réinitialisation envoyé', [
                    'email' => $request->email,
                    'user_id' => $user->id,
                ]);

            } catch (\Exception $mailError) {
                Log::error('Erreur envoi email de réinitialisation:', [
                    'email' => $request->email,
                    'error' => $mailError->getMessage(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'envoi de l\'email',
                ], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Un email de réinitialisation a été envoyé',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur forgotPassword:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la demande',
            ], 500);
        }
    }

    /**
     * Réinitialiser le mot de passe
     */
    public function resetPassword(Request $request)
    {
        try {
            $request->validate([
                'token' => 'required',
                'email' => 'required|email|exists:users,email',
                'password' => 'required|string|min:8|confirmed',
            ]);

            $passwordReset = DB::table('password_reset_tokens')
                ->where('email', $request->email)
                ->first();

            if (!$passwordReset || !Hash::check($request->token, $passwordReset->token)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ce lien de réinitialisation est invalide',
                ], 400);
            }

            // Vérifier l'expiration
            $createdAt = Carbon::parse($passwordReset->created_at);
            if ($createdAt->addMinutes(60)->isPast()) {
                DB::table('password_reset_tokens')->where('email', $request->email)->delete();
                
                return response()->json([
                    'success' => false,
                    'message' => 'Ce lien a expiré',
                ], 400);
            }

            // Mettre à jour le mot de passe
            $user = User::where('email', $request->email)->first();
            $user->update([
                'password' => Hash::make($request->password),
            ]);

            // Supprimer le token
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();

            // Supprimer tous les tokens d'accès
            $user->tokens()->delete();

            Log::info('Mot de passe réinitialisé', ['user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Mot de passe réinitialisé avec succès',
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur resetPassword:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la réinitialisation',
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->user()->currentAccessToken()->delete();
            return response()->json(['success' => true, 'message' => 'Déconnexion réussie']);
        } catch (\Exception $e) {
            Log::error('Erreur déconnexion:', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur'], 500);
        }
    }

    public function me(Request $request)
    {
        try {
            $user = $request->user();
            $user->load(['profile', 'domaines']);

            return response()->json([
                'success' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'profile' => $user->profile,
                    'domaines' => $user->domaines,
                    'needs_onboarding' => $user->needsOnboarding(),
                    'onboarding_step' => $user->onboarding_step,
                    'email_verified' => !is_null($user->email_verified_at),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur me:', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erreur'], 500);
        }
    }
}