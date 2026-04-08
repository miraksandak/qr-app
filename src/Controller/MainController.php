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

        $hotelBrowser = $hotelConfigurationManager->buildAccessibleHotelBrowser(
            $user->getAccessibleHotels(),
            (string) $request->query->get('q', ''),
            (string) $request->query->get('hotel', '')
        );
        $selectedHotel = $this->resolveSelectedHotel($hotelBrowser['selectedExternalHotelId'], $user);

        return $this->render('access/index.html.twig', [
            'user' => $user,
            'permissions' => [
                'access' => $user->canManageAccess(),
                'hotelSettings' => $user->canManageHotelSettings(),
            ],
            'hotelBrowser' => $hotelBrowser,
            'selectedHotel' => $selectedHotel,
            'selectedHotelState' => $selectedHotel === null ? null : $hotelConfigurationManager->buildHotelSnapshot($selectedHotel),
        ]);
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
