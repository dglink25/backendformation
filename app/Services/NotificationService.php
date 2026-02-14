<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class NotificationService
{
    /**
     * Créer une notification ET envoyer un email
     */
    public function creer($userId, $type, $titre, $message, $lien = null, $data = [])
    {
        try {
            $notification = Notification::create([
                'user_id' => $userId,
                'type'    => $type,
                'titre'   => $titre,
                'message' => $message,
                'lien'    => $lien,
                'data'    => $data,
            ]);

            // Envoyer l'email en arrière-plan sans bloquer
            $this->envoyerEmailNotification($notification);

            return $notification;

        } catch (\Exception $e) {
            Log::error('Erreur création notification: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Envoyer un email pour chaque notification
     * Respecte les préférences de l'utilisateur
     */
    private function envoyerEmailNotification(Notification $notification)
    {
        try {
            $user = User::with('profile')->find($notification->user_id);

            if (!$user || !$user->email) {
                return;
            }

            // Vérifier les préférences email de l'utilisateur
            if ($user->profile && $user->profile->notifications_preferences) {
                $prefs = is_array($user->profile->notifications_preferences)
                    ? $user->profile->notifications_preferences
                    : json_decode($user->profile->notifications_preferences, true);

                $typesCommunaute = ['nouveau_message', 'reponse_commentaire', 'nouveau_membre'];
                $typesFormation  = ['nouvelle_formation', 'nouveau_cours', 'inscription_validee', 'certificat_obtenu'];
                $typesRetrait    = ['retrait_approuve', 'retrait_rejete', 'retrait_complete'];

                if (in_array($notification->type, $typesCommunaute)) {
                    if (isset($prefs['email_communaute']) && !$prefs['email_communaute']) {
                        Log::info('📵 Email communauté désactivé pour user_id=' . $user->id);
                        return;
                    }
                }

                if (in_array($notification->type, $typesFormation)) {
                    if (isset($prefs['email_formations']) && !$prefs['email_formations']) {
                        Log::info('📵 Email formations désactivé pour user_id=' . $user->id);
                        return;
                    }
                }

                if (in_array($notification->type, $typesRetrait)) {
                    if (isset($prefs['email_retraits']) && !$prefs['email_retraits']) {
                        Log::info('📵 Email retraits désactivé pour user_id=' . $user->id);
                        return;
                    }
                }
            }

            // Icône selon le type
            $icons = [
                'nouvelle_formation'  => '📚',
                'nouveau_message'     => '💬',
                'paiement_recu'       => '💰',
                'inscription_validee' => '✅',
                'certificat_obtenu'   => '🎓',
                'nouveau_cours'       => '📖',
                'reponse_commentaire' => '💭',
                'nouveau_membre'      => '👤',
                'retrait_approuve'    => '✅',
                'retrait_rejete'      => '❌',
                'retrait_complete'    => '💸',
            ];
            $icone = $icons[$notification->type] ?? '🔔';

            Mail::send(
                'emails.notification',
                compact('notification', 'user', 'icone'),
                function ($mail) use ($user, $notification) {
                    $mail->to($user->email, $user->name)
                         ->subject($notification->titre . ' - E-Learning Platform');
                }
            );

            Log::info('📧 Email notification envoyé', [
                'user_id' => $user->id,
                'type'    => $notification->type,
            ]);

        } catch (\Exception $e) {
            // Ne jamais bloquer la création de notification si l'email échoue
            Log::error('❌ Erreur envoi email notification: ' . $e->getMessage(), [
                'notification_id' => $notification->id ?? null,
                'user_id'         => $notification->user_id ?? null,
            ]);
        }
    }

    // =========================================================
    // MÉTHODES DE NOTIFICATION
    // =========================================================

    /**
     * Notifier une nouvelle formation publiée (tous les apprenants)
     */
    public function notifierNouvelleFormation($formation)
    {
        try {
            if ($formation->statut !== 'publie') {
                Log::warning('⚠️ Tentative de notification pour formation non publiée', [
                    'formation_id' => $formation->id,
                    'statut'       => $formation->statut,
                ]);
                return;
            }

            $apprenants = User::where('role', 'apprenant')->get();

            foreach ($apprenants as $apprenant) {
                $this->creer(
                    $apprenant->id,
                    'nouvelle_formation',
                    'Nouvelle formation disponible ! 🎓',
                    "La formation \"{$formation->titre}\" vient d'être publiée dans le domaine {$formation->domaine->name}. Consultez le catalogue pour en savoir plus.",
                    "/apprenant/catalogue",
                    [
                        'formation_id'          => $formation->id,
                        'formation_titre'       => $formation->titre,
                        'formation_lien_public' => $formation->lien_public,
                        'domaine_id'            => $formation->domaine_id,
                        'domaine_nom'           => $formation->domaine->name,
                        'formateur_id'          => $formation->formateur_id,
                        'formateur_nom'         => $formation->formateur->name,
                        'prix'                  => $formation->prix,
                        'is_free'               => $formation->is_free,
                    ]
                );
            }

            Log::info("🔔 Notifications 'nouvelle formation' envoyées", [
                'formation_id'      => $formation->id,
                'formation_titre'   => $formation->titre,
                'statut'            => $formation->statut,
                'nombre_apprenants' => $apprenants->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierNouvelleFormation: ' . $e->getMessage(), [
                'formation_id' => $formation->id ?? null,
                'trace'        => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Notifier un nouveau message dans une communauté
     */
    public function notifierNouveauMessage($messageCommunaute, $communaute)
    {
        try {
            Log::info('🔔 Préparation notifications nouveau message', [
                'message_id'    => $messageCommunaute->id,
                'communaute_id' => $communaute->id,
                'auteur_id'     => $messageCommunaute->user_id,
            ]);

            $membres = $communaute->membres()
                ->where('user_id', '!=', $messageCommunaute->user_id)
                ->get();

            if ($membres->isEmpty()) {
                Log::info('ℹ️ Aucun membre à notifier', ['communaute_id' => $communaute->id]);
                return;
            }

            $contenuMessage = $messageCommunaute->message ?? '';
            $apercu = Str::limit($contenuMessage, 100);

            foreach ($membres as $membre) {
                $this->creer(
                    $membre->id,
                    'nouveau_message',
                    "Nouveau message dans {$communaute->nom}",
                    "{$messageCommunaute->user->name} a posté un message" . ($apercu ? " : {$apercu}" : ""),
                    "/communaute/{$communaute->id}",
                    [
                        'message_id'     => $messageCommunaute->id,
                        'communaute_id'  => $communaute->id,
                        'communaute_nom' => $communaute->nom,
                        'auteur_id'      => $messageCommunaute->user_id,
                        'auteur_nom'     => $messageCommunaute->user->name,
                    ]
                );
            }

            Log::info("✅ Notifications 'nouveau message' envoyées", [
                'message_id'              => $messageCommunaute->id,
                'communaute_id'           => $communaute->id,
                'nombre_membres_notifies' => $membres->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierNouveauMessage: ' . $e->getMessage(), [
                'message_id'    => $messageCommunaute->id ?? null,
                'communaute_id' => $communaute->id ?? null,
                'trace'         => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Notifier le formateur d'un nouveau paiement reçu
     */
    public function notifierPaiementRecu($paiement)
    {
        try {
            $formation  = $paiement->formation;
            $formateur  = $formation->formateur;
            $commission = ($formation->commission_admin / 100) * $paiement->montant;
            $montantNet = $paiement->montant - $commission;

            $this->creer(
                $formateur->id,
                'paiement_recu',
                'Nouveau paiement reçu ! 💰',
                "{$paiement->user->name} s'est inscrit à votre formation \"{$formation->titre}\" pour {$paiement->montant} FCFA. Vous recevrez {$montantNet} FCFA (après commission de {$commission} FCFA).",
                "/formateur/revenus",
                [
                    'paiement_id'     => $paiement->id,
                    'formation_id'    => $formation->id,
                    'formation_titre' => $formation->titre,
                    'apprenant_id'    => $paiement->user_id,
                    'apprenant_nom'   => $paiement->user->name,
                    'montant_brut'    => $paiement->montant,
                    'commission'      => $commission,
                    'montant_net'     => $montantNet,
                ]
            );

            Log::info("🔔 Notification 'paiement reçu' envoyée", [
                'formateur_id' => $formateur->id,
                'paiement_id'  => $paiement->id,
                'montant'      => $paiement->montant,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierPaiementRecu: ' . $e->getMessage());
        }
    }

    /**
     * Notifier l'apprenant que son inscription est validée
     */
    public function notifierInscriptionValidee($inscription)
    {
        try {
            $this->creer(
                $inscription->user_id,
                'inscription_validee',
                'Inscription validée ! ✅',
                "Votre inscription à la formation \"{$inscription->formation->titre}\" a été validée. Vous pouvez maintenant accéder au contenu complet.",
                "/apprenant/formations/{$inscription->formation_id}",
                [
                    'inscription_id'  => $inscription->id,
                    'formation_id'    => $inscription->formation_id,
                    'formation_titre' => $inscription->formation->titre,
                ]
            );

            Log::info("🔔 Notification 'inscription validée' envoyée", [
                'apprenant_id' => $inscription->user_id,
                'formation_id' => $inscription->formation_id,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierInscriptionValidee: ' . $e->getMessage());
        }
    }

    /**
     * Notifier les inscrits d'un nouveau chapitre ajouté
     */
    public function notifierNouveauCours($chapitre)
    {
        try {
            $module    = $chapitre->module;
            $formation = $module->formation;

            $inscrits = $formation->inscriptions()
                ->whereIn('statut', ['active', 'approuvee', 'en_cours'])
                ->get();

            foreach ($inscrits as $inscription) {
                $this->creer(
                    $inscription->user_id,
                    'nouveau_cours',
                    "Nouveau contenu disponible ! 📖",
                    "Un nouveau chapitre \"{$chapitre->titre}\" a été ajouté au module \"{$module->titre}\" dans la formation \"{$formation->titre}\"",
                    "/apprenant/formations/{$formation->id}",
                    [
                        'chapitre_id'     => $chapitre->id,
                        'chapitre_titre'  => $chapitre->titre,
                        'module_id'       => $module->id,
                        'module_titre'    => $module->titre,
                        'formation_id'    => $formation->id,
                        'formation_titre' => $formation->titre,
                    ]
                );
            }

            Log::info("🔔 Notifications 'nouveau cours' envoyées", [
                'chapitre_id'     => $chapitre->id,
                'formation_id'    => $formation->id,
                'nombre_inscrits' => $inscrits->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierNouveauCours: ' . $e->getMessage());
        }
    }

    /**
     * Notifier l'apprenant qu'il a obtenu un certificat
     */
    public function notifierCertificatObtenu($certificat)
    {
        try {
            $this->creer(
                $certificat->user_id,
                'certificat_obtenu',
                '🎉 Félicitations ! Certificat obtenu',
                "Vous avez terminé avec succès la formation \"{$certificat->formation->titre}\" et obtenu votre certificat. Téléchargez-le dès maintenant !",
                "/apprenant/certificats/{$certificat->id}",
                [
                    'certificat_id'   => $certificat->id,
                    'formation_id'    => $certificat->formation_id,
                    'formation_titre' => $certificat->formation->titre,
                ]
            );

            Log::info("🔔 Notification 'certificat obtenu' envoyée", [
                'apprenant_id'  => $certificat->user_id,
                'certificat_id' => $certificat->id,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierCertificatObtenu: ' . $e->getMessage());
        }
    }

    /**
     * Notifier les membres existants d'un nouveau membre dans la communauté
     */
    public function notifierNouveauMembreCommunaute($communaute, $nouveauMembre)
    {
        try {
            $membresExistants = $communaute->membres()
                ->where('user_id', '!=', $nouveauMembre->id)
                ->get();

            foreach ($membresExistants as $membre) {
                $this->creer(
                    $membre->id,
                    'nouveau_membre',
                    "Nouveau membre dans {$communaute->nom}",
                    "{$nouveauMembre->name} vient de rejoindre la communauté !",
                    "/communaute/{$communaute->id}",
                    [
                        'communaute_id'      => $communaute->id,
                        'nouveau_membre_id'  => $nouveauMembre->id,
                        'nouveau_membre_nom' => $nouveauMembre->name,
                    ]
                );
            }

            Log::info("🔔 Notifications 'nouveau membre' envoyées", [
                'communaute_id'     => $communaute->id,
                'nouveau_membre_id' => $nouveauMembre->id,
                'nombre_notifies'   => $membresExistants->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierNouveauMembreCommunaute: ' . $e->getMessage());
        }
    }

    /**
     * Notifier l'auteur d'un commentaire qu'une réponse a été postée
     */
    public function notifierReponseCommentaire($reponse, $commentaireParent)
    {
        try {
            if ($reponse->user_id !== $commentaireParent->user_id) {
                $this->creer(
                    $commentaireParent->user_id,
                    'reponse_commentaire',
                    "Réponse à votre message",
                    "{$reponse->user->name} a répondu à votre message : " . Str::limit($reponse->message, 100),
                    "/communaute/{$reponse->communaute_id}",
                    [
                        'reponse_id'            => $reponse->id,
                        'commentaire_parent_id' => $commentaireParent->id,
                        'communaute_id'         => $reponse->communaute_id,
                        'auteur_id'             => $reponse->user_id,
                        'auteur_nom'            => $reponse->user->name,
                    ]
                );

                Log::info("🔔 Notification 'réponse commentaire' envoyée", [
                    'destinataire_id' => $commentaireParent->user_id,
                    'reponse_id'      => $reponse->id,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierReponseCommentaire: ' . $e->getMessage());
        }
    }

    /**
     * Notifier le formateur que son retrait a été approuvé
     */
    public function notifierRetraitApprouve($withdrawal)
    {
        try {
            $this->creer(
                $withdrawal->formateur_id,
                'retrait_approuve',
                'Retrait approuvé ! ✅',
                "Votre demande de retrait de {$withdrawal->montant_demande} FCFA a été approuvée. Le versement sera effectué sous peu.",
                "/formateur/withdrawals",
                [
                    'withdrawal_id' => $withdrawal->id,
                    'montant'       => $withdrawal->montant_demande,
                ]
            );

            Log::info("🔔 Notification 'retrait approuvé' envoyée", [
                'formateur_id'  => $withdrawal->formateur_id,
                'withdrawal_id' => $withdrawal->id,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierRetraitApprouve: ' . $e->getMessage());
        }
    }

    /**
     * Notifier le formateur que son retrait a été rejeté
     */
    public function notifierRetraitRejete($withdrawal, $raison = null)
    {
        try {
            $messageRaison = $raison ? " Raison : {$raison}" : "";

            $this->creer(
                $withdrawal->formateur_id,
                'retrait_rejete',
                'Demande de retrait rejetée ❌',
                "Votre demande de retrait de {$withdrawal->montant_demande} FCFA a été rejetée.{$messageRaison}",
                "/formateur/withdrawals",
                [
                    'withdrawal_id' => $withdrawal->id,
                    'montant'       => $withdrawal->montant_demande,
                    'raison'        => $raison,
                ]
            );

            Log::info("🔔 Notification 'retrait rejeté' envoyée", [
                'formateur_id'  => $withdrawal->formateur_id,
                'withdrawal_id' => $withdrawal->id,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierRetraitRejete: ' . $e->getMessage());
        }
    }

    /**
     * Notifier le formateur que son retrait a été versé
     */
    public function notifierRetraitComplete($withdrawal)
    {
        try {
            $this->creer(
                $withdrawal->formateur_id,
                'retrait_complete',
                'Paiement effectué ! 💸',
                "Votre retrait de {$withdrawal->montant_demande} FCFA a été versé sur votre compte Mobile Money.",
                "/formateur/withdrawals",
                [
                    'withdrawal_id' => $withdrawal->id,
                    'montant'       => $withdrawal->montant_demande,
                ]
            );

            Log::info("🔔 Notification 'retrait complété' envoyée", [
                'formateur_id'  => $withdrawal->formateur_id,
                'withdrawal_id' => $withdrawal->id,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur notifierRetraitComplete: ' . $e->getMessage());
        }
    }

    // =========================================================
    // UTILITAIRES
    // =========================================================

    public function getNotifications($userId, $limit = 20)
    {
        return Notification::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function compterNonLues($userId)
    {
        return Notification::where('user_id', $userId)
            ->where('lu', false)
            ->count();
    }

    public function marquerToutCommeLu($userId)
    {
        return Notification::where('user_id', $userId)
            ->where('lu', false)
            ->update(['lu' => true, 'lu_at' => now()]);
    }

    public function nettoyerAnciennesNotifications()
    {
        try {
            $count = Notification::where('created_at', '<', now()->subDays(30))
                ->where('lu', true)
                ->delete();

            Log::info("🧹 {$count} anciennes notifications supprimées");
            return $count;

        } catch (\Exception $e) {
            Log::error('Erreur nettoyage notifications: ' . $e->getMessage());
            return 0;
        }
    }
}