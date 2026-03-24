<?php
// filepath: c:\wamp64\www\IRIS\Bachelor\HabitaLife\src\Controller\SettingsController.php

namespace App\Controller;

use App\Entity\CarteBancaire;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/settings')]
class SettingsController extends AbstractController
{
    #[Route('', name: 'settings')]
    public function index(EntityManagerInterface $entityManager): Response
    {
        // Récupérer l'utilisateur connecté
        $user = $this->getUser();

        return $this->render('settings/index.html.twig', [
            'user' => $user,
            'cards' => $entityManager->getRepository(CarteBancaire::class)->findBy(['utilisateur' => $user]),
        ]);
    }

    #[Route('/update-profile', name: 'settings_update_profile', methods: ['POST'])]
    public function updateProfile(Request $request, EntityManagerInterface $entityManager): Response
    {
        // Récupérer l'utilisateur connecté
        $user = $this->getUser();

        // Récupérer les données du formulaire
        $prenom = $request->request->get('prenom');
        $nom = $request->request->get('nom');
        $email = $request->request->get('email');

        // Validation basique
        if (!$prenom || !$nom || !$email) {
            $this->addFlash('error', 'Tous les champs sont obligatoires.');
            return $this->redirectToRoute('settings');
        }

        // Vérifier si l'email est unique (en excluant l'utilisateur actuel)
        $existingUser = $entityManager->getRepository(Utilisateur::class)
            ->findOneBy(['email' => $email]);
        
        if ($existingUser && $existingUser->getId() !== $user->getId()) {
            $this->addFlash('error', 'Cet email est déjà utilisé par un autre compte.');
            return $this->redirectToRoute('settings');
        }

        // Mettre à jour les données
        $user->setPrenom($prenom);
        $user->setNom($nom);
        $user->setEmail($email);
        $user->setDateModification(new \DateTime());

        // Enregistrer
        $entityManager->flush();

        $this->addFlash('success', 'Vos informations personnelles ont été mises à jour avec succès !');
        return $this->redirectToRoute('settings');
    }

    #[Route('/delete-account', name: 'settings_delete_account', methods: ['POST'])]
    public function deleteAccount(Request $request, EntityManagerInterface $entityManager): Response
    {
        // Récupérer l'utilisateur connecté
        $user = $this->getUser();

        // Vérifier le token CSRF pour la sécurité
        if (!$this->isCsrfTokenValid('delete_account', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('settings');
        }

        // Supprimer l'utilisateur
        $entityManager->remove($user);
        $entityManager->flush();

        // Déconnecter l'utilisateur
        return $this->redirectToRoute('app_logout');
    }

    #[Route('/update-password', name: 'settings_update_password', methods: ['POST'])]
    public function updatePassword(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response
    {
        /** @var Utilisateur $user */
        $user = $this->getUser();
        $currentPassword = $request->request->get('current_password');
        $newPassword = $request->request->get('new_password');
        $confirmPassword = $request->request->get('confirm_password');

        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            $this->addFlash('error', 'Le mot de passe actuel est incorrect.');
            return $this->redirectToRoute('settings');
        }

        if ($newPassword !== $confirmPassword) {
            $this->addFlash('error', 'Les nouveaux mots de passe ne correspondent pas.');
            return $this->redirectToRoute('settings');
        }

        $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
        $user->setMotDePasse($hashedPassword);
        $user->setDateModification(new \DateTime());

        $entityManager->flush();

        $this->addFlash('success', 'Votre mot de passe a été mis à jour avec succès !');
        return $this->redirectToRoute('settings');
    }

    #[Route('/cards/add', name: 'settings_add_card', methods: ['POST'])]
    public function addCard(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        $titulaire = $request->request->get('titulaire');
        $numero = $request->request->get('numero');
        $expiration = $request->request->get('expiration');
        $type = $request->request->get('type');

        if (!$titulaire || !$numero || !$expiration) {
            $this->addFlash('error', 'Veuillez remplir tous les champs de la carte.');
            return $this->redirectToRoute('settings');
        }

        $card = new CarteBancaire();
        $card->setTitulaire($titulaire);
        $card->setDernierQuatre(substr(str_replace(' ', '', $numero), -4));
        $card->setDateExpiration($expiration);
        $card->setType($type ?? 'Visa');
        $card->setUtilisateur($user);

        $entityManager->persist($card);
        $entityManager->flush();

        $this->addFlash('success', 'Votre moyen de paiement a été ajouté avec succès !');
        return $this->redirectToRoute('settings');
    }

    #[Route('/cards/{id}/edit', name: 'settings_edit_card', methods: ['POST'])]
    public function editCard(Request $request, CarteBancaire $card, EntityManagerInterface $entityManager): Response
    {
        if ($card->getUtilisateur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $titulaire = $request->request->get('titulaire');
        $numero = $request->request->get('numero');
        $expiration = $request->request->get('expiration');
        $type = $request->request->get('type');

        if (!$titulaire || !$expiration) {
            $this->addFlash('error', 'Veuillez remplir les champs obligatoires.');
            return $this->redirectToRoute('settings');
        }

        $card->setTitulaire($titulaire);
        if ($numero && strlen($numero) >= 4) {
            $card->setDernierQuatre(substr(str_replace(' ', '', $numero), -4));
        }
        $card->setDateExpiration($expiration);
        if ($type) {
            $card->setType($type);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Votre moyen de paiement a été mis à jour.');
        return $this->redirectToRoute('settings');
    }

    #[Route('/cards/{id}/delete', name: 'settings_delete_card', methods: ['POST'])]
    public function deleteCard(CarteBancaire $card, EntityManagerInterface $entityManager): Response
    {
        if ($card->getUtilisateur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        $entityManager->remove($card);
        $entityManager->flush();

        $this->addFlash('success', 'Le moyen de paiement a été supprimé.');
        return $this->redirectToRoute('settings');
    }
}