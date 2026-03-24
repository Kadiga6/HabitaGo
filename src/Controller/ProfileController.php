<?php
// filepath: c:\wamp64\www\IRIS\Bachelor\HabitaLife\src\Controller\ProfileController.php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/profile')]
#[IsGranted('ROLE_USER')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'profile')]
    public function index(): Response
    {
        // Récupérer l'utilisateur connecté
        $user = $this->getUser();

        // Récupérer le logement via le premier contrat actif
        $logement = null;
        foreach ($user->getContrats() as $contrat) {
            if ($contrat->getStatut() === 'actif' || is_null($contrat->getDateFin()) || $contrat->getDateFin() > new \DateTime()) {
                $logement = $contrat->getLogement();
                break;
            }
        }

        // Données du logement (vides si pas de logement actif)
        $housing = null;
        if ($logement) {
            $housing = [
                'adresse' => $logement->getAdresse(),
                'codePostal' => $logement->getCodePostal(),
                'ville' => $logement->getVille(),
                'typeLogement' => $logement->getTypeLogement(),
                'dateCreation' => $logement->getDateCreation(),
            ];
        }

        // Statistiques utilisateur
        $stats = [
            'paymentRate' => 100,
            'totalIssues' => 11,
            'resolvedIssues' => 8,
            'paidPayments' => 5,
            'pendingPayments' => 1,
        ];

        // Activité récente
        $recentActivity = [
            [
                'type' => 'payment',
                'title' => 'Paiement effectué',
                'date' => '05 Janvier 2026',
                'icon' => 'credit-card',
            ],
            [
                'type' => 'issue',
                'title' => 'Incident déclaré',
                'date' => '10 Janvier 2026',
                'icon' => 'exclamation-circle',
            ],
            [
                'type' => 'profile',
                'title' => 'Profil mis à jour',
                'date' => '08 Janvier 2026',
                'icon' => 'person-check',
            ],
            [
                'type' => 'login',
                'title' => 'Connexion',
                'date' => 'Aujourd\'hui 14:32',
                'icon' => 'box-arrow-in-right',
            ],
        ];

       return $this->render('profile/index.html.twig', [
    'user' => $user,
    'housing' => $housing,
    'stats' => $stats,
    'recentActivity' => $recentActivity,
]);

    }
}
?>