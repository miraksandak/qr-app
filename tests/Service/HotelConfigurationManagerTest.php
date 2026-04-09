<?php

namespace App\Tests\Service;

use App\Connector\ExternalHotelAccess;
use App\Repository\HotelRepository;
use App\Service\HotelConfigurationManager;
use App\Service\HotelImageStorage;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class HotelConfigurationManagerTest extends TestCase
{
    public function testBuildAccessibleHotelBrowserIncludesHotelsBeyondFormerLocalPageLimit(): void
    {
        $manager = $this->createManager();

        $browser = $manager->buildAccessibleHotelBrowser(
            $this->createAccessibleHotels(60),
            '',
            'hotel-060'
        );

        self::assertCount(60, $browser['items']);
        self::assertSame(60, $browser['pagination']['total']);
        self::assertSame(1, $browser['pagination']['pageCount']);
        self::assertSame('hotel-060', $browser['selectedExternalHotelId']);
    }

    public function testBuildAccessibleHotelBrowserFiltersAgainstEntireAccessibleList(): void
    {
        $manager = $this->createManager();

        $browser = $manager->buildAccessibleHotelBrowser(
            $this->createAccessibleHotels(60),
            'Hotel 060'
        );

        self::assertCount(1, $browser['items']);
        self::assertSame('hotel-060', $browser['items'][0]['externalHotelId']);
        self::assertSame('hotel-060', $browser['selectedExternalHotelId']);
    }

    public function testBuildInitialAccessibleHotelBrowserLoadsOnlySelectedHotel(): void
    {
        $manager = $this->createManager();

        $browser = $manager->buildInitialAccessibleHotelBrowser(
            $this->createAccessibleHotels(60),
            '',
            'hotel-060'
        );

        self::assertCount(1, $browser['items']);
        self::assertSame('hotel-060', $browser['items'][0]['externalHotelId']);
        self::assertSame(60, $browser['pagination']['total']);
        self::assertSame('hotel-060', $browser['selectedExternalHotelId']);
    }

    private function createManager(): HotelConfigurationManager
    {
        $hotelRepository = $this->createMock(HotelRepository::class);
        $hotelRepository
            ->method('findByExternalHotelIds')
            ->willReturn([]);

        return new HotelConfigurationManager(
            $this->createMock(EntityManagerInterface::class),
            $hotelRepository,
            $this->createMock(HotelImageStorage::class)
        );
    }

    /**
     * @return list<ExternalHotelAccess>
     */
    private function createAccessibleHotels(int $count): array
    {
        $hotels = [];

        for ($index = 1; $index <= $count; ++$index) {
            $hotels[] = new ExternalHotelAccess(
                sprintf('hotel-%03d', $index),
                sprintf('Hotel %03d', $index)
            );
        }

        return $hotels;
    }
}
