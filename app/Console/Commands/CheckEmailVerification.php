<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CheckEmailVerification extends Command
{
    protected $signature = 'user:check-email {email}';
    protected $description = 'Vérifier le statut de vérification d\'email d\'un utilisateur';

    public function handle()
    {
        $email = $this->argument('email');
        
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            $this->error('❌ Utilisateur non trouvé avec cet email: ' . $email);
            return 1;
        }
        
        $this->info('👤 Utilisateur trouvé:');
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line("  ID: {$user->id}");
        $this->line("  Nom: {$user->name}");
        $this->line("  Email: {$user->email}");
        $this->line("  Rôle: {$user->role}");
        $this->line("  Actif: " . ($user->is_active ? '✅ Oui' : '❌ Non'));
        $this->line("  Email vérifié: " . ($user->email_verified_at ? '✅ Oui' : '❌ Non'));
        
        if ($user->email_verified_at) {
            $this->line("  Vérifié le: {$user->email_verified_at}");
        }
        
        $this->line('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        
        // Vérifier les tokens en attente
        $verifications = \DB::table('email_verifications')
            ->where('email', $email)
            ->get();
            
        if ($verifications->count() > 0) {
            $this->warn('⚠️ ' . $verifications->count() . ' token(s) de vérification en attente:');
            foreach ($verifications as $verification) {
                $this->line("  - Créé le: {$verification->created_at}");
            }
        } else {
            $this->info('✅ Aucun token de vérification en attente');
        }
        
        // Proposer de corriger si nécessaire
        if (!$user->email_verified_at) {
            $this->newLine();
            if ($this->confirm('❓ Voulez-vous marquer cet email comme vérifié manuellement?', false)) {
                $user->update(['email_verified_at' => now()]);
                $this->info('✅ Email marqué comme vérifié!');
                
                // Nettoyer les tokens
                \DB::table('email_verifications')->where('email', $email)->delete();
                $this->info('🧹 Tokens de vérification supprimés');
            }
        }
        
        return 0;
    }
}