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

class DayController extends AbstractController
{

    private function getDayOrError(EntityManagerInterface $entityManager): Day|JsonResponse
    {
        $today = new \DateTime('now', new \DateTimeZone('UTC'));
        $dayRepository = $entityManager->getRepository(Day::class);
        $day = $dayRepository->findOneBy(['dayDate' => $today]);
        if (!$day) {
            return new JsonResponse([
                'error' => 'No day found for today : ' . $today->format('d/m/Y'),
            ], 404);
        }
        return $day;
    }

    #[Route('/today', name: 'app_today')]
    public function today(EntityManagerInterface $entityManager): JsonResponse
    {
        $day = $this->getDayOrError($entityManager);
        if ($day instanceof JsonResponse) {
            return $day;
        }

        if ($day->getImageUrl() == null) {
            $themeRepository = $entityManager->getRepository(Theme::class);
            $themes = $themeRepository->findBy(['day' => $day], ['nbVote' => 'DESC']);

            if (empty($themes)) {
                return new JsonResponse([
                    'error' => 'No theme for today : ' . $day->getDayDate()->format('d/m/Y'),
                ], 404);
            }

            $maxVotes = $themes[0]->getNbVote();
            $topThemes = array_filter($themes, function ($theme) use ($maxVotes) {
                return $theme->getNbVote() === $maxVotes;
            });
            $selectedTheme = $topThemes[array_rand($topThemes)];

            // Générer une image avec OpenAI DALL-E
            $myApiKey = $_ENV['OPENAI_API_KEY'];
            $client = OpenAI::client($myApiKey);

            $response = $client->images()->create([
                'model' => 'dall-e-3',
                'prompt' => $selectedTheme->getTitle(),
                'n' => 1,
                'size' => '1024x1024',
                'response_format' => 'url',
            ]);

            $imageContent = file_get_contents($response->data[0]->url);
            $imageDirectoryName = __DIR__ . '/../../public/images';
            if (!is_dir($imageDirectoryName)) {
                mkdir($imageDirectoryName, 0777, true);
            }
            $imageFileName = $imageDirectoryName . '/image_' . $day->getDayDate()->format('d-m-Y') . '.png';
            file_put_contents($imageFileName, $imageContent);

            $day->setImageUrl('/images/image_' . $day->getDayDate()->format('d-m-Y') . '.png');

        }
        $day->setNbView($day->getNbView() + 1);
        $entityManager->persist($day);
        $entityManager->flush();
        return new JsonResponse([
            'id' => $day->getId(),
            'day_date' => $day->getDayDate()->format('Y-m-d'),
            'image_url' => $day->getImageUrl(),
        ]);
    }

    #[Route('/finished', name: 'app_finished')]
    public function finished(EntityManagerInterface $entityManager): JsonResponse
    {
        $day = $this->getDayOrError($entityManager);
        if ($day instanceof JsonResponse) {
            return $day;
        }

        $day->setNbFinish($day->getNbFinish() + 1);
        $entityManager->persist($day);
        $entityManager->flush();

        return $this->json([
            'nbFinish' => $day->getNbFinish(),
        ]);
    }

    #[Route('/instagram', name: 'app_instagram')]
    public function instagram(EntityManagerInterface $entityManager): JsonResponse
    {
        $day = $this->getDayOrError($entityManager);
        if ($day instanceof JsonResponse) {
            return $day;
        }

        $day->setNbPostInstagram($day->getNbPostInstagram() + 1);
        $entityManager->persist($day);
        $entityManager->flush();

        return $this->json([
            'nbPostInstagram' => $day->getNbPostInstagram(),
        ]);
    }

    #[Route('/api/add_days', name: 'app_add_days', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN', message: 'Only admin can access this endpoint')]
    public function addDays(Request $request, EntityManagerInterface $entityManager): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $themesData = $data['themes'] ?? [];

        if (empty($themesData)) {
            throw new HttpException(400, 'No themes provided');
        }

        $countThemes = count($themesData);
        $validCount = intdiv($countThemes, 3) * 3;
        $themesData = array_slice($themesData, 0, $validCount);

        $dayRepository = $entityManager->getRepository(Day::class);
        $lastDay = $dayRepository->findOneBy([], ['dayDate' => 'DESC']);
        $startDay = $lastDay ? $lastDay->getDayDate() : new \DateTime();

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
        return new JsonResponse(['message' => 'Days added successfully'], 200);
    }
}
