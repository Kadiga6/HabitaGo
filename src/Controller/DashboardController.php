<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\ConsommationRepository;
use App\Repository\ContratRepository;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        ConsommationRepository $consommationRepo, 
        ContratRepository $contratRepo,
        \App\Service\PaiementMetierService $paiementMetier
    ): Response
    {
        $user = $this->getUser();

        // Récupérer le contrat actif de l'utilisateur
        $contrat = $contratRepo->findActiveContractForUser($user);
        
        $totauxConsommation = [
            'electricite' => 0,
            'eau' => 0,
            'gaz' => 0,
        ];

        if ($contrat) {
            // Récupérer les totaux réels de consommation
            $totauxConsommation = $consommationRepo->getTotauxByLogement($contrat->getLogement());
        }

        // Récupérer les statistiques de paiement liées
        $paymentStats = $paiementMetier->getStatsForUser($user);
        $logement = null;

if ($contrat) {
    $logement = $contrat->getLogement();
}


       return $this->render('dashboard/index.html.twig', [
    'totauxConsommation' => $totauxConsommation,
    'contrat' => $contrat,
    'logement' => $logement,
    'paymentStats' => $paymentStats,
]);

        
    }
}
