<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Domaine;

class DomainesSeeder extends Seeder
{
    public function run(): void{
        $domaines = [
            [
                'name' => 'Développement Web',
                'slug' => 'developpement-web',
                'description' => 'HTML, CSS, JavaScript, PHP, Laravel, React...',
                'icon' => 'code',
            ],
            [
                'name' => 'Design Graphique',
                'slug' => 'design-graphique',
                'description' => 'Photoshop, Illustrator, Figma, UI/UX...',
                'icon' => 'palette',
            ],
            [
                'name' => 'Marketing Digital',
                'slug' => 'marketing-digital',
                'description' => 'SEO, Publicité en ligne, Réseaux sociaux...',
                'icon' => 'megaphone',
            ],
            [
                'name' => 'Business & Entrepreneuriat',
                'slug' => 'business-entrepreneuriat',
                'description' => 'Gestion d\'entreprise, Finance, Management...',
                'icon' => 'briefcase',
            ],
            [
                'name' => 'Data Science & IA',
                'slug' => 'data-science-ia',
                'description' => 'Python, Machine Learning, Analytics...',
                'icon' => 'brain',
            ],
            [
                'name' => 'Langues',
                'slug' => 'langues',
                'description' => 'Anglais, Français, Espagnol, Chinois...',
                'icon' => 'languages',
            ],
            [
                'name' => 'Santé & Bien-être',
                'slug' => 'sante-bien-etre',
                'description' => 'Nutrition, Fitness, Yoga, Méditation...',
                'icon' => 'heart',
            ],
            [
                'name' => 'Photographie & Vidéo',
                'slug' => 'photographie-video',
                'description' => 'Photo, Montage vidéo, Production...',
                'icon' => 'camera',
            ],
        ];

        foreach ($domaines as $domaine) {
            Domaine::firstOrCreate(
                ['name' => $domaine['name']],
                $domaine
            );
        }

        $this->command->info('✅ Domaines créés avec succès!');
    }
}