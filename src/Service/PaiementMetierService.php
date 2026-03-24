<?php

namespace App\Service;

use App\Entity\Contrat;
use App\Entity\Paiement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service métier pour gérer la logique des paiements de loyer.
 * 
 * Principes :
 * - Référence absolue : contrat.date_debut
 * - Première échéance : 1 mois après date_debut
 * - Paiement interdit avant date_debut
 * - Statut "en retard" : si date actuelle > date d'échéance et aucun paiement pour cette période
 */
class PaiementMetierService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Détermine la date d'échéance (due date) pour un numéro de mois donné.
     * 
     * Exemple : si date_debut = 20 janvier
     * - mois 1 (février) -> 20 février
     * - mois 2 (mars) -> 20 mars
     */
    public function calculerDateEcheance(Contrat $contrat, int $numeroMois): \DateTime
    {
        $dateDebut = $contrat->getDateDebut();
        $dateEcheance = clone $dateDebut;

        // Ajouter le nombre de mois
        $dateEcheance->modify("+{$numeroMois} month");

        return $dateEcheance;
    }

    /**
     * Détermine le numéro de mois logique depuis le date_debut.
     * 
     * Exemple : si date_debut = 20 janvier et periode = "février"
     * -> retourne 1 (premier mois après le début)
     */
    public function determinerNumeroMois(Contrat $contrat, string $periode): int
    {
        // Convertir le nom du mois en numéro
        $moisMap = [
            'janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4,
            'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8,
            'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12
        ];

        $periodeLower = strtolower($periode);
        
        // Récupérer le mois de la date_debut
        $moisDebut = (int) $contrat->getDateDebut()->format('m');

        // Chercher le mois de la période (extraire le premier mot si y'a une année)
        $parts = explode(' ', $periodeLower);
        $moisNom = $parts[0];
        $anneePeriode = isset($parts[1]) ? (int)$parts[1] : (int)date('Y');

        if (!isset($moisMap[$moisNom])) {
            throw new \InvalidArgumentException("Période invalide : {$periode}");
        }

        $moisPeriode = $moisMap[$moisNom];

        // Calculer la différence en mois
        $anneeDebut = (int) $contrat->getDateDebut()->format('Y');
        $deltaAnnee = $anneePeriode - $anneeDebut;

        return ($moisPeriode - $moisDebut) + ($deltaAnnee * 12);
    }

    
  public function estPaiementAutorise(
    Contrat $contrat,
    \DateTimeInterface $dateEcheance
): bool {
    $dateDebut = $contrat->getDateDebut();

    if (!$dateDebut) {
        return false;
    }

    // Paiement autorisé si l’échéance est >= à la date d’entrée
    return $dateEcheance >= $dateDebut;
}

    public function estEnRetard(Paiement $paiement): bool
    {
        // Si déjà payé, pas en retard
        if ($paiement->getStatut() === 'paye') {
            return false;
        }

        $contrat = $paiement->getContrat();
        $periode = $paiement->getPeriode();
        
        try {
            $numeroMois = $this->determinerNumeroMois($contrat, $periode);
            $dateEcheance = $this->calculerDateEcheance($contrat, $numeroMois);

            $maintenant = new \DateTime();

            // En retard si date d'échéance dépassée et pas payé
            return $dateEcheance < $maintenant && $paiement->getStatut() !== 'paye';
        } catch (\InvalidArgumentException $e) {
            return false;
        }
    }

    /**
     * Met à jour automatiquement le statut d'un paiement selon les règles métier.
     * 
     * Appeller cette méthode avant de persister le paiement.
     */
    public function determinerStatut(Paiement $paiement): void
    {
        // Si le paiement a une date de paiement, il est payé
        if ($paiement->getDatePaiement() !== null) {
            $paiement->setStatut('paye');
            return;
        }

        // Sinon, vérifier s'il est en retard
        if ($this->estEnRetard($paiement)) {
            $paiement->setStatut('en_retard');
        } else {
            $paiement->setStatut('en_attente');
        }
    }

    /**
     * Génère les paiements attendus pour un contrat.
     * 
     * Cette méthode crée les paiements pour chaque mois du contrat
     * jusqu'à la date actuelle (ou date_fin si contrat terminé).
     */
    public function genererPaiementsAttendus(Contrat $contrat): void
    {
        $dateDebut = $contrat->getDateDebut();
        $dateFin = $contrat->getDateFin() ?? new \DateTime();
        $maintenant = new \DateTime();

        // Limiter à la date actuelle
        $dateFinPeriode = min($maintenant, $dateFin);

        // Commencer au mois du début du contrat
        $dateActuelle = clone $dateDebut;

        $numeroMois = 0;

        while ($dateActuelle <= $dateFinPeriode) {
            $periode = $this->formatePeriode($dateActuelle);

            // Vérifier si un paiement existe déjà
            $paiementExistant = $this->em->getRepository(Paiement::class)
                ->findOneBy([
                    'contrat' => $contrat,
                    'periode' => $periode,
                ]);

            // Créer le paiement s'il n'existe pas
            if (!$paiementExistant) {
                $paiement = new Paiement();
                $paiement->setContrat($contrat);
                $paiement->setPeriode($periode);
                $paiement->setMontant($contrat->getMontantLoyer());
                $this->determinerStatut($paiement);

                $this->em->persist($paiement);
            }

            // Avancer d'un mois
            $dateActuelle->modify('+1 month');
            $numeroMois++;
        }

        $this->em->flush();
    }

    /**
     * Calcule les statistiques de paiement pour un utilisateur.
     */
    public function getStatsForUser(\App\Entity\Utilisateur $user): array
    {
        $contrats = $this->em->getRepository(\App\Entity\Contrat::class)->findBy(['utilisateur' => $user]);
        $paiements = [];
        
        foreach ($contrats as $contrat) {
            // S'assurer que les paiements sont à jour
            $this->genererPaiementsAttendus($contrat);
            $repo = $this->em->getRepository(\App\Entity\Paiement::class);
            $paiements = array_merge($paiements, $repo->findBy(['contrat' => $contrat]));
        }

        // Mettre à jour les statuts en temps réel
        foreach ($paiements as $p) {
            $this->determinerStatut($p);
        }
        $this->em->flush();

        return [
            'payes' => count(array_filter($paiements, fn($p) => $p->getStatut() === 'paye')),
            'en_attente' => count(array_filter($paiements, fn($p) => $p->getStatut() === 'en_attente')),
            'en_retard' => count(array_filter($paiements, fn($p) => $p->getStatut() === 'en_retard')),
            'total_attendu' => count($paiements),
        ];
    }

    /**
     * Formate une date en "janvier", "février", etc.
     */
    private function formatePeriode(\DateTime $date): string
    {
        $moisFr = [
            1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
            5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
            9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'
        ];

        $mois = (int) $date->format('m');
        return $moisFr[$mois] . ' ' . $date->format('Y');
    }

    /**
     * Valide qu'un paiement respecte les règles métier avant sauvegarde.
     * 
     * Retourne un tableau d'erreurs (vide = valide).
     */
    public function validerPaiement(Paiement $paiement): array
    {
        $erreurs = [];

        if (!$paiement->getContrat()) {
            $erreurs[] = "Le paiement doit être lié à un contrat.";
            return $erreurs;
        }

        if (!$paiement->getPeriode()) {
            $erreurs[] = "La période doit être spécifiée.";
            return $erreurs;
        }

        $contrat = $paiement->getContrat();
        $dateDebut = $contrat->getDateDebut();

        if (!$dateDebut) {
            $erreurs[] = "Le contrat ne possède pas de date de début valide.";
            return $erreurs;
        }

        $dateEcheance = (clone $dateDebut)->modify('+1 month');

        if (!$this->estPaiementAutorise($contrat, $dateEcheance)) {
            $erreurs[] = sprintf(
                "Paiement refusé : la date d’échéance (%s) est antérieure à la date d’entrée (%s).",
                $dateEcheance->format('d/m/Y'),
                $dateDebut->format('d/m/Y')
            );
        }

        $paiementExistant = $this->em->getRepository(Paiement::class)
            ->findOneBy([
                'contrat' => $paiement->getContrat(),
                'periode' => $paiement->getPeriode(),
            ]);

        if ($paiementExistant && $paiementExistant->getId() !== $paiement->getId()) {
            $erreurs[] = sprintf(
                "Un paiement pour la période %s existe déjà.",
                $paiement->getPeriode()
            );
        }

        return $erreurs;
    }
}
