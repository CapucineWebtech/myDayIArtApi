<?php

namespace App\Controller;

use App\Entity\Day;
use App\Entity\Theme;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

class UserController extends AbstractController
{
    private $supportedLocales;

    public function __construct(array $supportedLocales)
    {
        $this->supportedLocales = $supportedLocales;
    }

    private function setLanguage(TranslatorInterface $translator, Request $request): void
    {
        $lang = $request->query->get('language', 'en');
        if (in_array($lang, $this->supportedLocales)) {
            $translator->setLocale($lang);
        }
    }

    #[Route('/register', name: 'app_register', methods: ['POST'])]
    public function register(TranslatorInterface $translator, Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, JWTTokenManagerInterface $JWTTokenManager): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Retrieving request data
        $data = json_decode($request->getContent(), true);
        $email = $data['email'];
        $password = $data['password'];

        // Validating email and password
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => $translator->trans('error.invalid_email')], 400);
        }
        if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return $this->json(['error' => $translator->trans('error.password_criteria')], 400);
        }

        // Checking email uniqueness
        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingUser) {
            return $this->json(['error' => $translator->trans('error.email_used')], 409);
        }

        // Creating user
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($userPasswordHasher->hashPassword($user, $password));
        $user->setRegisterDate(new \DateTime('today', new \DateTimeZone('UTC')));
        $user->setRoles(['ROLE_USER']);
        $entityManager->persist($user);
        $entityManager->flush();

        // Generating JWT token
        $token = $JWTTokenManager->create($user);

        // Sending response (token)
        return $this->json([
            'token' => $token
        ]);
    }

    #[Route('/login', name: 'app_login', methods: ['POST'])]
    public function apiLoginCheck(TranslatorInterface $translator, Request $request)
    {
        $this->setLanguage($translator, $request);
        throw new \Exception($translator->trans('error.should_not_be_reached'));
    }

    #[Route('/user/delete', name: 'app_delete_user', methods: ['DELETE'])]
    public function deleteUser(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Retrieving the ID of the user to delete and checking its existence
        $data = json_decode($request->getContent(), true);
        $userId = $data['id'] ?? null;
        if (!$userId) {
            return new JsonResponse(['error' => $translator->trans('error.user_id_required')], Response::HTTP_BAD_REQUEST);
        }

        // Retrieving the user to delete and checking its existence
        $userToDelete = $entityManager->getRepository(User::class)->find($userId);
        if (!$userToDelete) {
            return new JsonResponse(['error' => $translator->trans('error.user_not_found')], Response::HTTP_NOT_FOUND);
        }

        // Retrieving the logged-in user
        $currentUser = $security->getUser();

        // Checking the rights of the logged-in user to delete the user
        if (in_array('ROLE_ADMIN', $currentUser->getRoles()) || $currentUser === $userToDelete) {
            $entityManager->remove($userToDelete);
            $entityManager->flush();

            return new JsonResponse(['success' => $translator->trans('success.user_deleted')]);
        } else {
            return new JsonResponse(['error' => $translator->trans('error.not_allowed_to_delete')], Response::HTTP_FORBIDDEN);
        }
    }

    #[Route('/user/vote', name: 'app_select_theme', methods: ['POST'])]
    public function select_theme(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Retrieving the logged-in user and checking if they have voted today
        $currentUser = $security->getUser();
        $today = new \DateTime('today', new \DateTimeZone('UTC'));
        $tomorrow = new \DateTime('+1 day', new \DateTimeZone('UTC'));
        if ($currentUser->getLastVoteDate() == $today) {
            return new JsonResponse(['error' => $translator->trans('error.already_voted_today')], Response::HTTP_FORBIDDEN);
        }

        // Retrieving the theme chosen by the user and checking its existence
        $data = json_decode($request->getContent(), true);
        $themeId = $data['id'] ?? null;
        if (!$themeId) {
            return new JsonResponse(['error' => $translator->trans('error.theme_id_required')], Response::HTTP_BAD_REQUEST);
        }
        $themeChosen = $entityManager->getRepository(Theme::class)->find($themeId);
        if (!$themeChosen) {
            return new JsonResponse(['error' => $translator->trans('error.theme_not_found')], Response::HTTP_NOT_FOUND);
        }

        // Checking that the chosen theme is for tomorrow
        if (!$themeChosen->getDay() || $themeChosen->getDay()->getDayDate()->format('Y-m-d') !== $tomorrow->format('Y-m-d')) {
            return new JsonResponse(['error' => $translator->trans('error.theme_not_for_tomorrow')], Response::HTTP_BAD_REQUEST);
        }

        // Adding the user's vote
        $currentUser->setLastVoteDate($today);
        $currentUser->addTheme($themeChosen);
        $themeChosen->setNbVote($themeChosen->getNbVote() + 1);
        $entityManager->flush();

        return new JsonResponse(['success' => $translator->trans('success.vote_added')]);
    }

    #[Route('/user/has_voted', name: 'app_has_voted', methods: ['GET'])]
    public function has_voted(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager, Security $security): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Retrieving the logged-in user and checking if they have voted for tomorrow today
        $currentUser = $security->getUser();
        $today = new \DateTime('today', new \DateTimeZone('UTC'));
        $tomorrow = new \DateTime('+1 day', new \DateTimeZone('UTC'));
        if ($currentUser->getLastVoteDate() == $today) {
            return new JsonResponse(['error' => $translator->trans('error.already_voted_today')], Response::HTTP_BAD_REQUEST);
        }

        // Retrieving the theme chosen by the user and checking its existence
        $dayRepository = $entityManager->getRepository(Day::class);
        $dayTomorrow = $dayRepository->findOneBy(['dayDate' => $tomorrow]);
        if (!$dayTomorrow) {
            return new JsonResponse(['error' => $translator->trans('error.no_themes_for_tomorrow')], Response::HTTP_NOT_FOUND);
        }

        // Retrieving available themes for tomorrow
        $themes = [];
        foreach ($dayTomorrow->getThemes() as $theme) {
            $title = $request->query->get('language', 'en') === 'fr' ? $theme->getTitleVf() : $theme->getTitle();
            $themes[] = [
                'id' => $theme->getId(),
                'title' => $title
            ];
        }

        // Sending available themes for tomorrow
        return new JsonResponse(['themes' => $themes]);
    }

    #[Route('/password/reset/request', name: 'app_password_reset_request', methods: ['POST'])]
    public function requestResetPassword(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Retrieving the user's email address and checking its presence
        $data = json_decode($request->getContent(), true);
        $email = $data['email'];
        if (!$email) {
            return $this->json(['error' => $translator->trans('error.email_address_required')], 400);
        }

        // Checking user existence
        $user = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            return $this->json(['error' => $translator->trans('error.email_address_not_found')], 404);
        }

        // Generating reset token
        $resetToken = bin2hex(random_bytes(32));
        $user->setResetToken($resetToken);
        $user->setResetTokenExpiresAt(new \DateTime('+1 hour'));
        $entityManager->flush();

        // Sending reset email
        $resetUrl = "https://symfony.com/doc/current/translation.html#installation" . $resetToken;
        $email = (new Email())
            ->from('contact@mydayiart.com')
            ->to($user->getEmail())
            ->subject($translator->trans('email.subject.password_reset'))
            ->html($translator->trans('email.body.password_reset', ['%reset_url%' => $resetUrl]));
        $mailer->send($email);

        return $this->json(['success' => $translator->trans('success.password_reset_email_sent')]);
    }

    #[Route('/password/reset/{token}', name: 'app_password_reset', methods: ['POST'])]
    public function resetPassword(TranslatorInterface $translator, Request $request, string $token, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Checking token validity
        $user = $entityManager->getRepository(User::class)->findOneBy(['resetToken' => $token]);
        if (!$user || $user->getResetTokenExpiresAt() < new \DateTime()) {
            return $this->json(['error' => $translator->trans('error.invalid_or_expired_token')], 400);
        }

        // Resetting password
        $data = json_decode($request->getContent(), true);
        $newPassword = $data['password'];
        $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
        $user->setResetToken(null);
        $user->setResetTokenExpiresAt(null);
        $entityManager->flush();

        return $this->json(['success' => $translator->trans('success.password_reset')]);
    }
}
