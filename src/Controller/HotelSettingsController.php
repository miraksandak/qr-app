<?php

namespace App\Controller;

use App\Security\ExternalUser;
use App\Service\HotelConfigurationManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class HotelSettingsController extends AbstractController
{
    #[Route('/settings/hotels', name: 'app_hotel_settings', methods: ['GET'])]
    public function index(Request $request, HotelConfigurationManager $hotelConfigurationManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof ExternalUser) {
            throw $this->createAccessDeniedException('External user is required.');
        }

        $selectedHotel = $this->resolveSelectedHotel($request, $user);

        return $this->render('hotel/settings.html.twig', [
            'user' => $user,
            'accessibleHotels' => $hotelConfigurationManager->buildAccessibleHotelList($user->getAccessibleHotels()),
            'selectedHotel' => $selectedHotel,
            'selectedHotelState' => $selectedHotel === null ? null : $hotelConfigurationManager->buildHotelSnapshot($selectedHotel),
        ]);
    }

    private function resolveSelectedHotel(Request $request, ExternalUser $user): ?\App\Connector\ExternalHotelAccess
    {
        $requestedExternalHotelId = trim((string) $request->query->get('hotel', ''));
        if ($requestedExternalHotelId !== '') {
            $selectedHotel = $user->findAccessibleHotel($requestedExternalHotelId);
            if ($selectedHotel !== null) {
                return $selectedHotel;
            }
        }

        return $user->getAccessibleHotels()[0] ?? null;
    }
}
