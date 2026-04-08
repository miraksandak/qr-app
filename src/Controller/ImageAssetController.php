<?php

namespace App\Controller;

use App\Service\HotelImageStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class ImageAssetController extends AbstractController
{
    #[Route('/asset/image/{uuid}', name: 'app_image_asset_show', methods: ['GET'], requirements: ['uuid' => '[A-Za-z0-9-]{36}'])]
    public function show(string $uuid, HotelImageStorage $hotelImageStorage): Response
    {
        $payload = $hotelImageStorage->load($uuid);
        if ($payload === null) {
            throw new NotFoundHttpException('Image not found.');
        }

        return new Response($payload['data'], Response::HTTP_OK, [
            'Content-Type' => $payload['mimeType'],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
