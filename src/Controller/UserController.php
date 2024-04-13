<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\User;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\HttpFoundation\Response;

class UserController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['POST'])]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $email = $data['email'];
        $password = $data['password'];

        // Validation de l'email et du mot de passe
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email address'], 400);
        }

        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return $this->json(['error' => 'Password must be at least 8 characters long and include at least one uppercase letter and one number'], 400);
        }

        // Vérification de l'unicité de l'email
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            return $this->json(['error' => 'Email already used'], 409);
        }

        $user = new User();
        $user->setEmail($email);
        $user->setPassword($userPasswordHasher->hashPassword($user, $password));
        $user->setRegisterDate(new \DateTime());
        $user->setRoles(['ROLE_USER']);

        $entityManager->persist($user);
        $entityManager->flush();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'registerDate' => $user->getRegisterDate()->format('Y-m-d H:i:s'),
        ]);
    }
}
