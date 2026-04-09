<?php

namespace App\Controller;

use App\Security\ExternalUser;
use App\Service\HotelConfigurationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class MainController extends AbstractController
{
    #[Route('/', name: 'app_access', methods: ['GET'])]
    public function index(Request $request, HotelConfigurationManager $hotelConfigurationManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof ExternalUser) {
            throw $this->createAccessDeniedException('External user is required.');
        }

        if (!$user->canManageAccess()) {
            if ($user->canManageHotelSettings()) {
                return $this->redirectToRoute('app_hotel_settings', $request->query->all());
            }

            throw $this->createAccessDeniedException('You do not have access to access documents.');
        }

        $query = (string) $request->query->get('q', '');
        $selectedExternalHotelId = (string) $request->query->get('hotel', '');
        $requestedHotelError = $this->buildRequestedHotelError($selectedExternalHotelId, $user);
        $hotelBrowser = trim($query) === ''
            ? $hotelConfigurationManager->buildInitialAccessibleHotelBrowser(
                $user->getAccessibleHotels(),
                $query,
                $selectedExternalHotelId
            )
            : $hotelConfigurationManager->buildAccessibleHotelBrowser(
                $user->getAccessibleHotels(),
                $query,
                $selectedExternalHotelId
            );
        $selectedHotel = $this->resolveSelectedHotel($hotelBrowser['selectedExternalHotelId'], $user);

        return $this->render('access/index.html.twig', [
            'user' => $user,
            'permissions' => [
                'access' => $user->canManageAccess(),
                'hotelSettings' => $user->canManageHotelSettings(),
            ],
            'requestedHotelError' => $requestedHotelError,
            'hotelBrowser' => $hotelBrowser,
            'selectedHotel' => $selectedHotel,
            'selectedHotelState' => $selectedHotel === null ? null : $hotelConfigurationManager->buildHotelSnapshot($selectedHotel),
        ]);
    }

    private function buildRequestedHotelError(string $requestedExternalHotelId, ExternalUser $user): ?string
    {
        $requestedExternalHotelId = trim($requestedExternalHotelId);
        if ($requestedExternalHotelId === '') {
            return null;
        }

        if ($user->findAccessibleHotel($requestedExternalHotelId) !== null) {
            return null;
        }

        return sprintf(
            'Hotel "%s" was not found or you do not have access to it. Showing the first available hotel instead.',
            $requestedExternalHotelId
        );
    }

    private function resolveSelectedHotel(?string $selectedExternalHotelId, ExternalUser $user): ?\App\Connector\ExternalHotelAccess
    {
        if (is_string($selectedExternalHotelId) && trim($selectedExternalHotelId) !== '') {
            $selectedHotel = $user->findAccessibleHotel(trim($selectedExternalHotelId));
            if ($selectedHotel !== null) {
                return $selectedHotel;
            }
        }

        return $user->getAccessibleHotels()[0] ?? null;
    }
}
