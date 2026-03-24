<?php

namespace App\Controller;

use App\Entity\Paiement;
use App\Form\PaiementType;
use App\Repository\ContratRepository;
use App\Repository\PaiementRepository;
use App\Service\PaiementMetierService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/payments', name: 'payments_')]
class PaymentsController extends AbstractController
{
    private PaiementMetierService $paiementMetier;

    public function __construct(PaiementMetierService $paiementMetier)
    {
        $this->paiementMetier = $paiementMetier;
    }

    #[Route('', name: 'index')]
    public function index(
        PaiementRepository $paiementRepository,
        ContratRepository $contratRepository
    ): Response
    {
        // Récupérer l'utilisateur connecté
        $utilisateur = $this->getUser();

        // Récupérer les contrats de l'utilisateur
        $contrats = $contratRepository->findBy(['utilisateur' => $utilisateur]);
        
        // Récupérer tous les paiements des contrats de cet utilisateur
        $contratIds = array_map(fn($c) => $c->getId(), $contrats);
        
        // Générer les paiements manquants pour chaque contrat
        foreach ($contrats as $contrat) {
            $this->paiementMetier->genererPaiementsAttendus($contrat);
        }

        $paiements = [];
        if (!empty($contratIds)) {
            $paiements = $paiementRepository->findByContratIds($contratIds);
        }

        // Mettre à jour les statuts selon la logique métier
        foreach ($paiements as $paiement) {
            $this->paiementMetier->determinerStatut($paiement);
        }

        // Trier les paiements par ordre chronologique décroissant (plus récent d'abord)
        usort($paiements, function($a, $b) {
            try {
                $numA = $this->paiementMetier->determinerNumeroMois($a->getContrat(), $a->getPeriode());
                $numB = $this->paiementMetier->determinerNumeroMois($b->getContrat(), $b->getPeriode());
                return $numB <=> $numA;
            } catch (\Exception $e) {
                return 0;
            }
        });

        // Calculer les statistiques globales
        $stats = $this->paiementMetier->getStatsForUser($utilisateur);

        // Déterminer le paiement à régler (en attente ou en retard)
        $paiementAPayer = null;
        foreach ($paiements as $paiement) {
            if (in_array($paiement->getStatut(), ['en_attente', 'en_retard'], true)) {
                $paiementAPayer = $paiement;
                break;
            }
        }

        // Déterminer le contrat actif (ou le premier contrat)
$contrat = null;

foreach ($contrats as $c) {
    if ($c->getStatut() === 'actif') {
        $contrat = $c;
        break;
    }
}

// fallback si aucun statut "actif"
if (!$contrat && !empty($contrats)) {
    $contrat = $contrats[0];
}


        return $this->render('payments/index.html.twig', [
            'paiements' => $paiements,
            'stats' => $stats,
            'contrats' => $contrats,
            'contrat' => $contrat,
            'paiementAPayer' => $paiementAPayer,
        ]);
    }

    #[Route('/{id}/pay', name: 'pay')]
    public function pay(
        Paiement $paiement,
        Request $request,
        EntityManagerInterface $em,
        \App\Repository\CarteBancaireRepository $carteRepo
    ): Response
    {
        // Vérifier que l'utilisateur a accès à ce paiement
        if ($paiement->getContrat()->getUtilisateur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        // Valider le paiement selon la logique métier
        $erreurs = $this->paiementMetier->validerPaiement($paiement);
        if (!empty($erreurs)) {
            foreach ($erreurs as $erreur) {
                $this->addFlash('error', $erreur);
            }
            return $this->redirectToRoute('payments_index');
        }

        $form = $this->createForm(PaiementType::class, $paiement);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Enregistrer la date et le statut du paiement
            $paiement->setDatePaiement(new \DateTime());
            $paiement->setStatut('paye');

            $em->flush();

            $this->addFlash('success', sprintf(
                'Paiement pour %s effectué avec succès !',
                $paiement->getPeriode()
            ));

            return $this->redirectToRoute('payments_success', ['id' => $paiement->getId()]);
        }

        return $this->render('payments/pay.html.twig', [
            'paiement' => $paiement,
            'form' => $form->createView(),
            'cards' => $carteRepo->findBy(['utilisateur' => $this->getUser()]),
        ]);
    }

    #[Route('/{id}/success', name: 'success')]
    public function success(Paiement $paiement): Response
    {
        if ($paiement->getContrat()->getUtilisateur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('payments/success.html.twig', [
            'paiement' => $paiement,
        ]);
    }
}






