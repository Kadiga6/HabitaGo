<?php
 //ConsumptionController.php 
namespace App\Controller;

use App\Entity\Consommation;
use App\Form\ConsommationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class ConsumptionController extends AbstractController
{
    // Route pour afficher l'historique des consommations
    #[Route('/consumption', name: 'consumption')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        // Récupérer l'utilisateur connecté
        $utilisateur = $this->getUser();

        // Récupérer le premier contrat actif de l'utilisateur
        $logement = null;
        foreach ($utilisateur->getContrats() as $contrat) {
            if ($contrat->getStatut() === 'actif' || is_null($contrat->getDateFin()) || $contrat->getDateFin() > new \DateTime()) {
                $logement = $contrat->getLogement();
                break;
            }
        }

        // Récupérer toutes les consommations du logement
        $consommations = [];
        if ($logement) {
            $consommations = $entityManager->getRepository(Consommation::class)
                ->findBy(['logement' => $logement], ['periodeFin' => 'DESC']);
        }

        // Calculer les totaux du mois en cours
        $moisActuel = new \DateTime('first day of this month');
        $finMois = new \DateTime('last day of this month');

        $totalElectricite = 0;
        $totalEau = 0;
        $totalGaz = 0;

        foreach ($consommations as $conso) {
            // Vérifier si la consommation est du mois en cours
            if ($conso->getPeriodeDebut() >= $moisActuel && $conso->getPeriodeFin() <= $finMois) {
                $type = strtolower($conso->getType());
                if (str_contains($type, 'elec')) {
                    $totalElectricite += (float) $conso->getValeur();
                } elseif (str_contains($type, 'eau')) {
                    $totalEau += (float) $conso->getValeur();
                } elseif (str_contains($type, 'gaz')) {
                    $totalGaz += (float) $conso->getValeur();
                }
            }
        }

        return $this->render('consumption/index.html.twig', [
            'consommations' => $consommations,
            'logement' => $logement,
            'total_electricite' => $totalElectricite,
            'total_eau' => $totalEau,
            'total_gaz' => $totalGaz,
        ]);
    }

    // Route pour créer une nouvelle consommation
    #[Route('/consumption/new', name: 'consumption_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        // Récupérer l'utilisateur connecté
        $utilisateur = $this->getUser();

        // Récupérer le logement de l'utilisateur
        $logement = null;
        foreach ($utilisateur->getContrats() as $contrat) {
            if ($contrat->getStatut() === 'actif' || is_null($contrat->getDateFin()) || $contrat->getDateFin() > new \DateTime()) {
                $logement = $contrat->getLogement();
                break;
            }
        }

        // Si pas de logement, afficher un message d'erreur
        if (!$logement) {
            $this->addFlash('warning', 'Vous n\'avez pas encore de logement actif. Veuillez d\'abord configurer un contrat de location.');
            return $this->redirectToRoute('consumption');
        }

        // Créer une nouvelle consommation
        $consommation = new Consommation();
        $consommation->setLogement($logement);

        // Créer le formulaire
        $form = $this->createForm(ConsommationFormType::class, $consommation);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Récupérer le type de consommation du formulaire
            $type = $form->get('type')->getData();

            // Définir automatiquement l'unité selon le type
            switch ($type) {
                case 'eau':
                    $consommation->setUnite('m³');
                    break;
                case 'electricite':
                case 'gaz':
                    $consommation->setUnite('kWh');
                    break;
            }

            // Définir la date de création
            $consommation->setDateCreation(new \DateTime());

            // Enregistrer en base de données
            $entityManager->persist($consommation);
            $entityManager->flush();

            // Afficher un message de succès
            $this->addFlash('success', 'Votre consommation a été enregistrée avec succès !');

            // Rediriger vers la page de consommations
            return $this->redirectToRoute('consumption');
        }

        return $this->render('consumption/new.html.twig', [
            'form' => $form,
            'logement' => $logement,
        ]);
    }
}
