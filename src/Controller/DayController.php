<?php

namespace App\Controller;

use App\Entity\Day;
use App\Entity\Theme;
use Doctrine\ORM\EntityManagerInterface;
use OpenAI;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class DayController extends AbstractController
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

    private function getDayOrError(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager): Day|JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Check if a day exists for today
        $today = new \DateTime('now', new \DateTimeZone('UTC'));
        $dayRepository = $entityManager->getRepository(Day::class);
        $day = $dayRepository->findOneBy(['dayDate' => $today]);
        if (!$day) {
            return new JsonResponse([
                'error' => $translator->trans('error.no_day'),
            ], 404);
        }
        return $day;
    }

    #[Route('/today', name: 'app_today')]
    public function today(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Retrieve the day for today
        $day = $this->getDayOrError($translator, $request, $entityManager);
        if ($day instanceof JsonResponse) {
            return $day;
        }

        // Check if an image exists for this day
        if ($day->getImageUrl() == null) {
            // Retrieve the most voted theme for today
            $themeRepository = $entityManager->getRepository(Theme::class);
            $themes = $themeRepository->findBy(['day' => $day], ['nbVote' => 'DESC']);
            if (empty($themes)) {
                return new JsonResponse([
                    'error' => $translator->trans('error.no_theme'),
                ], 404);
            }
            $maxVotes = $themes[0]->getNbVote();
            $topThemes = array_filter($themes, function ($theme) use ($maxVotes) {
                return $theme->getNbVote() === $maxVotes;
            });
            $selectedTheme = $topThemes[array_rand($topThemes)];

            // Create an image with the chosen theme
            $myApiKey = $_ENV['OPENAI_API_KEY'];
            $client = OpenAI::client($myApiKey);
            $response = $client->images()->create([
                'model' => 'dall-e-3',
                'prompt' => $selectedTheme->getTitle(),
                'n' => 1,
                'size' => '1024x1024',
                'response_format' => 'url',
            ]);

            // Save the image locally
            $imageContent = file_get_contents($response->data[0]->url);
            $imageDirectoryName = __DIR__ . '/../../public/images';
            if (!is_dir($imageDirectoryName)) {
                mkdir($imageDirectoryName, 0777, true);
            }
            $imageFileName = $imageDirectoryName . '/image_' . $day->getDayDate()->format('d-m-Y') . '.png';
            file_put_contents($imageFileName, $imageContent);

            // Update the day with the image URL
            $day->setImageUrl('/images/image_' . $day->getDayDate()->format('d-m-Y') . '.png');
        }

        // Update the view count of the day's image
        $day->setNbView($day->getNbView() + 1);
        $entityManager->persist($day);
        $entityManager->flush();

        // Return the URL of the day's image
        return new JsonResponse([
            'image_url' => $day->getImageUrl(),
        ]);
    }

    #[Route('/finished', name: 'app_finished')]
    public function finished(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Retrieve the day for today
        $day = $this->getDayOrError($translator, $request, $entityManager);
        if ($day instanceof JsonResponse) {
            return $day;
        }

        // Update the finish count of the day's image
        $day->setNbFinish($day->getNbFinish() + 1);
        $entityManager->persist($day);
        $entityManager->flush();

        return $this->json([
            'success' => $translator->trans('success.finish_added'),
        ]);
    }

    #[Route('/instagram', name: 'app_instagram')]
    public function instagram(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Retrieve the day for today
        $day = $this->getDayOrError($translator, $request, $entityManager);
        if ($day instanceof JsonResponse) {
            return $day;
        }

        // Update the Instagram post count of the day's image
        $day->setNbPostInstagram($day->getNbPostInstagram() + 1);
        $entityManager->persist($day);
        $entityManager->flush();

        return $this->json([
            'success' => $translator->trans('success.instagram_post_added'),
        ]);
    }

    #[Route('/api/add_days', name: 'app_add_days', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Only admin can access this endpoint')]
    public function addDays(TranslatorInterface $translator, Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->setLanguage($translator, $request);

        // Check if themes have been provided
        $data = json_decode($request->getContent(), true);
        $themesData = $data['themes'] ?? [];
        if (empty($themesData)) {
            throw new HttpException(400, $translator->trans('error.no_themes_provided'));
        }

        // Count usable themes
        $countThemes = count($themesData);
        $validCount = intdiv($countThemes, 3) * 3;
        $themesData = array_slice($themesData, 0, $validCount);

        // Retrieve the last recorded day
        $dayRepository = $entityManager->getRepository(Day::class);
        $lastDay = $dayRepository->findOneBy([], ['dayDate' => 'DESC']);
        $startDay = $lastDay ? $lastDay->getDayDate() : new \DateTime('today', new \DateTimeZone('UTC'));

        // Add days with provided themes
        for ($i = 0; $i < $validCount; $i += 3) {
            $day = new Day();
            $day->setDayDate((clone $startDay)->modify('+1 day'));
            $startDay = $day->getDayDate();

            for ($j = 0; $j < 3; $j++) {
                $theme = new Theme();
                $theme->setTitle($themesData[$i + $j]['theme']);
                $day->addTheme($theme);
                $entityManager->persist($theme);
            }
            $entityManager->persist($day);
        }
        $entityManager->flush();

        return new JsonResponse(['success' => $translator->trans('success.add_days')]);
    }
}
