<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\User;
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

    #[Route('/login', name: 'app_login', methods: ['POST'])]
    public function apiLoginCheck()
    {
        throw new \Exception('Should not be reached');
    }

    #[Route('/user/delete', name: 'app_delete_user', methods: ['DELETE'])]
    public function deleteUser(Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $userId = $data['id'] ?? null;

        if (!$userId) {
            return new JsonResponse(['error' => 'User ID is required'], Response::HTTP_BAD_REQUEST);
        }

        $userToDelete = $entityManager->getRepository(User::class)->find($userId);

        if (!$userToDelete) {
            return new JsonResponse(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        $currentUser = $security->getUser();

        if (in_array('ROLE_ADMIN', $currentUser->getRoles()) || $currentUser === $userToDelete) {
            $entityManager->remove($userToDelete);
            $entityManager->flush();

            return new JsonResponse(['success' => 'User deleted successfully']);
        } else {
            throw new UnauthorizedHttpException('You are not authorized to delete this user.');
        }
    }


}
