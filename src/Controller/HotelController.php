<?php

namespace App\Controller;

use App\Connector\ExternalHotelAccess;
use App\Repository\HotelRepository;
use App\Security\ExternalUser;
use App\Service\HotelConfigurationManager;
use App\Service\HotelImageStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    public function listHotels(Request $request, HotelConfigurationManager $hotelConfigurationManager): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $browser = $hotelConfigurationManager->buildAccessibleHotelBrowser(
            $user->getAccessibleHotels(),
            (string) $request->query->get('q', ''),
            $request->query->has('page') ? (int) $request->query->get('page', 1) : null,
            (string) $request->query->get('hotel', '')
        );

        return new JsonResponse([
            'data' => $browser['items'],
            'meta' => $browser['pagination'],
        ]);
    }

    #[Route('/api/hotels/{externalHotelId}', name: 'api_hotel_get', methods: ['GET'])]
    #[Route('/app/api/hotels/{externalHotelId}', name: 'app_api_hotel_get', methods: ['GET'])]
    public function getHotel(
        string $externalHotelId,
        HotelConfigurationManager $hotelConfigurationManager,
        HotelRepository $repository
    ): JsonResponse
    {
        $accessibleHotel = $this->findAccessibleHotel($externalHotelId, $repository);
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
        HotelConfigurationManager $hotelConfigurationManager,
        HotelImageStorage $hotelImageStorage
    ): JsonResponse {
        $user = $this->getAuthenticatedUser();
        if (!$user->canManageHotelSettings()) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $accessibleHotel = $this->findAccessibleHotel($externalHotelId, $repository);
        if ($accessibleHotel === null) {
            return new JsonResponse(['error' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        [$payload, $payloadError] = $this->resolvePayload($request);
        if ($payloadError !== null) {
            return new JsonResponse(['error' => $payloadError], Response::HTTP_BAD_REQUEST);
        }

        $logoUpload = $request->files->get('logoUpload');
        if ($logoUpload !== null && !$logoUpload instanceof UploadedFile) {
            return new JsonResponse(['error' => 'logoUpload must be a file upload'], Response::HTTP_BAD_REQUEST);
        }

        $existingHotel = $repository->findOneByExternalHotelId($accessibleHotel->getExternalHotelId());
        $created = $existingHotel === null || $existingHotel->getConfiguration() === null;

        try {
            $hotel = $hotelConfigurationManager->updateHotelConfiguration($accessibleHotel, $payload, $logoUpload);
            $this->entityManager->flush();
            $hotelImageStorage->cleanupUnusedHotelImages($hotel);
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

    private function findAccessibleHotel(string $externalHotelId, HotelRepository $repository): ?ExternalHotelAccess
    {
        $normalizedHotelId = trim($externalHotelId);
        if ($normalizedHotelId === '') {
            return null;
        }

        $user = $this->getAuthenticatedUser();
        $hotel = $user->findAccessibleHotel($normalizedHotelId);
        if ($hotel !== null) {
            return $hotel;
        }

        if ($user->canAccessAllHotels()) {
            $storedHotel = $repository->findOneByExternalHotelId($normalizedHotelId);
            if ($storedHotel !== null) {
                return new ExternalHotelAccess(
                    $storedHotel->getExternalHotelId(),
                    $storedHotel->getName()
                );
            }
        }

        return null;
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: ?string}
     */
    private function resolvePayload(Request $request): array
    {
        if ($request->request->has('payload')) {
            $rawPayload = trim((string) $request->request->get('payload', '{}'));
        } else {
            $rawPayload = trim((string) $request->getContent());
        }

        $rawPayload = $rawPayload === '' ? '{}' : $rawPayload;

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [null, 'Invalid JSON payload'];
        }

        if (!is_array($payload)) {
            return [null, 'Payload must be a JSON object'];
        }

        return [$payload, null];
    }
}
