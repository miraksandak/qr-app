<?php

namespace App\Controller;

use App\Connector\ExternalHotelAccess;
use App\Repository\HotelRepository;
use App\Security\ExternalUser;
use App\Service\HotelConfigurationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HotelController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {
    }

    #[Route('/api/hotels', name: 'api_hotels_list', methods: ['GET'])]
    #[Route('/app/api/hotels', name: 'app_api_hotels_list', methods: ['GET'])]
    public function listHotels(HotelConfigurationManager $hotelConfigurationManager): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        return new JsonResponse([
            'data' => $hotelConfigurationManager->buildAccessibleHotelList($user->getAccessibleHotels()),
        ]);
    }

    #[Route('/api/hotels/{externalHotelId}', name: 'api_hotel_get', methods: ['GET'])]
    #[Route('/app/api/hotels/{externalHotelId}', name: 'app_api_hotel_get', methods: ['GET'])]
    public function getHotel(string $externalHotelId, HotelConfigurationManager $hotelConfigurationManager): JsonResponse
    {
        $accessibleHotel = $this->findAccessibleHotel($externalHotelId);
        if ($accessibleHotel === null) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($hotelConfigurationManager->buildHotelSnapshot($accessibleHotel));
    }

    #[Route('/api/hotels/{externalHotelId}', name: 'api_hotel_upsert', methods: ['PUT'])]
    #[Route('/app/api/hotels/{externalHotelId}', name: 'app_api_hotel_upsert', methods: ['PUT'])]
    public function upsertHotel(
        string $externalHotelId,
        Request $request,
        HotelRepository $repository,
        HotelConfigurationManager $hotelConfigurationManager
    ): JsonResponse {
        $accessibleHotel = $this->findAccessibleHotel($externalHotelId);
        if ($accessibleHotel === null) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $rawContent = trim((string) $request->getContent());
        $rawContent = $rawContent === '' ? '{}' : $rawContent;

        try {
            $payload = json_decode($rawContent, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            return new JsonResponse(['error' => 'Invalid JSON payload'], Response::HTTP_BAD_REQUEST);
        }

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload must be a JSON object'], Response::HTTP_BAD_REQUEST);
        }

        $existingHotel = $repository->findOneByExternalHotelId($accessibleHotel->getExternalHotelId());
        $created = $existingHotel === null || $existingHotel->getConfiguration() === null;

        try {
            $hotel = $hotelConfigurationManager->updateHotelConfiguration($accessibleHotel, $payload);
            $this->entityManager->flush();
        } catch (\InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(
            $hotelConfigurationManager->serializeHotel($hotel),
            $created ? Response::HTTP_CREATED : Response::HTTP_OK
        );
    }

    private function getAuthenticatedUser(): ExternalUser
    {
        $user = $this->getUser();
        if (!$user instanceof ExternalUser) {
            throw $this->createAccessDeniedException('External user is required.');
        }

        return $user;
    }

    private function findAccessibleHotel(string $externalHotelId): ?ExternalHotelAccess
    {
        $normalizedHotelId = trim($externalHotelId);
        if ($normalizedHotelId === '') {
            return null;
        }

        $hotel = $this->getAuthenticatedUser()->findAccessibleHotel($normalizedHotelId);
        return $hotel;
    }
}
