<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'profile_completed',
        'role_selected_at',
        'onboarding_step',
        'email_verified_at', 
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'profile_completed' => 'boolean',
        'role_selected_at' => 'datetime',
    ];

    // Relations
    public function profile()
    {
        return $this->hasOne(UserProfile::class, 'user_id', 'id');
    }

    public function domaines()
    {
        return $this->belongsToMany(Domaine::class, 'user_domaines');
    }

    // Vérifications de rôles
    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function isFormateur(): bool
    {
        return $this->role === 'formateur';
    }

    public function isApprenant(): bool
    {
        return $this->role === 'apprenant';
    }

    // Vérifier si le profil est complété
    public function needsOnboarding(): bool
    {
        return !$this->profile_completed && !$this->isSuperAdmin();
    }

    // Ajouter ces méthodes dans la classe User

// Relations formations (en tant que formateur)
public function formationsCreees()
{
    return $this->hasMany(Formation::class, 'formateur_id');
}

// Relations inscriptions (en tant qu'apprenant)
public function inscriptions()
{
    return $this->hasMany(Inscription::class);
}

public function formationsSuivies()
{
    return $this->belongsToMany(Formation::class, 'inscriptions')
        ->withPivot('statut', 'progres', 'is_blocked')
        ->withTimestamps();
}

// Relations communautés
public function communautes()
{
    return $this->belongsToMany(Communaute::class, 'communaute_membres')
        ->withPivot('role', 'is_muted', 'joined_at')
        ->withTimestamps();
}

// Relations quiz
public function quizResultats()
{
    return $this->hasMany(QuizResultat::class);
}

// Relations progression
public function progressions()
{
    return $this->hasMany(ProgressionChapitre::class);
}

// Relations paiements
public function paiements()
{
    return $this->hasMany(Paiement::class);
}

// Statistiques formateur
public function totalApprenants()
{
    return Inscription::whereIn('formation_id', $this->formationsCreees->pluck('id'))
        ->whereIn('statut', ['active', 'approuvee'])
        ->distinct('user_id')
        ->count();
}

public function revenusTotal()
{
    return Paiement::whereIn('formation_id', $this->formationsCreees->pluck('id'))
        ->where('statut', 'complete')
        ->sum('montant');
}
}