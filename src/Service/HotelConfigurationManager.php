<?php

namespace App\Service;

use App\Connector\ExternalHotelAccess;
use App\Entity\Hotel;
use App\Entity\HotelConfiguration;
use App\Repository\HotelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class HotelConfigurationManager
{
    public const DEFAULT_PAGE_SIZE = 50;

    private const ALLOWED_AUTH_MODES = ['roomSurname', 'accessCode'];
    private const ALLOWED_DEVICE_TYPES = ['android', 'ios', 'generic'];
    private const ALLOWED_PMS_CREDENTIAL_FIELDS = ['roomNumber', 'surname', 'firstName', 'checkinNumber', 'password'];
    private const ALLOWED_SSID_USAGES = ['pms', 'ac', 'free'];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private HotelRepository $hotelRepository,
        private HotelImageStorage $hotelImageStorage
    ) {
    }

    /**
     * @param list<ExternalHotelAccess> $accessibleHotels
     *
     * @return list<array<string, mixed>>
     */
    public function buildAccessibleHotelList(array $accessibleHotels): array
    {
        $hotelsByExternalId = [];
        foreach ($this->hotelRepository->findByExternalHotelIds(array_map(
            static fn (ExternalHotelAccess $hotel): string => $hotel->getExternalHotelId(),
            $accessibleHotels
        )) as $hotelEntity) {
            $hotelsByExternalId[$hotelEntity->getExternalHotelId()] = $hotelEntity;
        }

        $result = [];
        foreach ($accessibleHotels as $accessibleHotel) {
            $hotelEntity = $hotelsByExternalId[$accessibleHotel->getExternalHotelId()] ?? null;

            $result[] = [
                'externalHotelId' => $accessibleHotel->getExternalHotelId(),
                'name' => $hotelEntity?->getName() ?? $accessibleHotel->getName(),
                'displayName' => $hotelEntity?->getName() ?? $accessibleHotel->getDisplayName(),
                'externalName' => $accessibleHotel->getName(),
                'configurationExists' => $hotelEntity?->getConfiguration() !== null,
                'attributes' => $accessibleHotel->getAttributes(),
            ];
        }

        usort($result, static function (array $left, array $right): int {
            $leftKey = mb_strtolower((string) ($left['displayName'] ?? $left['externalHotelId'] ?? ''));
            $rightKey = mb_strtolower((string) ($right['displayName'] ?? $right['externalHotelId'] ?? ''));

            return $leftKey <=> $rightKey;
        });

        return $result;
    }

    /**
     * @param list<ExternalHotelAccess> $accessibleHotels
     *
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array<string, int>,
     *     query: string,
     *     selectedExternalHotelId: ?string
     * }
     */
    public function buildAccessibleHotelBrowser(
        array $accessibleHotels,
        string $query = '',
        ?int $page = null,
        ?string $selectedExternalHotelId = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE
    ): array {
        $allHotels = $this->buildAccessibleHotelList($accessibleHotels);
        $normalizedQuery = mb_strtolower(trim($query));

        if ($normalizedQuery !== '') {
            $allHotels = array_values(array_filter($allHotels, static function (array $hotel) use ($normalizedQuery): bool {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) ($hotel['displayName'] ?? ''),
                    (string) ($hotel['name'] ?? ''),
                    (string) ($hotel['externalHotelId'] ?? ''),
                    (string) ($hotel['externalName'] ?? ''),
                ])));

                return str_contains($haystack, $normalizedQuery);
            }));
        }

        $pageSize = max(1, min(self::DEFAULT_PAGE_SIZE, $pageSize));
        $total = count($allHotels);
        $pageCount = max(1, (int) ceil($total / $pageSize));

        $selectedIndex = null;
        if (is_string($selectedExternalHotelId) && $selectedExternalHotelId !== '') {
            foreach ($allHotels as $index => $hotel) {
                if (($hotel['externalHotelId'] ?? null) === $selectedExternalHotelId) {
                    $selectedIndex = $index;
                    break;
                }
            }
        }

        if ($page === null && $selectedIndex !== null) {
            $page = (int) floor($selectedIndex / $pageSize) + 1;
        }

        $page ??= 1;
        $page = max(1, min($pageCount, $page));
        $offset = ($page - 1) * $pageSize;
        $items = array_slice($allHotels, $offset, $pageSize);

        $resolvedSelectedExternalHotelId = null;
        $selectedIsOnCurrentPage = $selectedIndex !== null
            && $selectedIndex >= $offset
            && $selectedIndex < ($offset + count($items));

        if ($selectedIsOnCurrentPage) {
            $resolvedSelectedExternalHotelId = $selectedExternalHotelId;
        } elseif ($items !== []) {
            $resolvedSelectedExternalHotelId = $items[0]['externalHotelId'] ?? null;
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'pageSize' => $pageSize,
                'pageCount' => $pageCount,
                'total' => $total,
            ],
            'query' => trim($query),
            'selectedExternalHotelId' => $resolvedSelectedExternalHotelId,
        ];
    }

    public function buildHotelSnapshot(ExternalHotelAccess $accessibleHotel): array
    {
        $hotel = $this->hotelRepository->findOneByExternalHotelId($accessibleHotel->getExternalHotelId());
        if ($hotel !== null && $hotel->getConfiguration() !== null) {
            return $this->serializeHotel($hotel, true);
        }

        $preview = $this->createDefaultConfigurationPreview();

        return [
            'hotel' => [
                'id' => $hotel?->getId(),
                'externalHotelId' => $accessibleHotel->getExternalHotelId(),
                'name' => $hotel?->getName() ?? $accessibleHotel->getName(),
                'createdAt' => $hotel?->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $hotel?->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'configuration' => $this->serializeConfiguration($preview),
            'meta' => [
                'persisted' => false,
                'configurationComplete' => $this->isConfigurationComplete($preview),
                'accessCodeAllowed' => $this->isAccessCodeAllowed($preview),
                'missingConfigurationFields' => $this->findMissingConfigurationFields($preview),
            ],
        ];
    }

    public function ensureHotelWithConfiguration(ExternalHotelAccess $accessibleHotel): Hotel
    {
        $hotel = $this->hotelRepository->findOneByExternalHotelId($accessibleHotel->getExternalHotelId());
        if ($hotel === null) {
            $hotel = new Hotel(
                $accessibleHotel->getExternalHotelId(),
                $accessibleHotel->getName() ?? 'Hotel Guest'
            );
            $this->entityManager->persist($hotel);
        } elseif ($hotel->getName() === null && $accessibleHotel->getName() !== null) {
            $hotel->setName($accessibleHotel->getName());
        }

        if ($hotel->getConfiguration() === null) {
            $configuration = new HotelConfiguration($hotel);
            $this->applyDefaultConfiguration($configuration);
            $hotel->setConfiguration($configuration);

            $this->entityManager->persist($configuration);
        }

        return $hotel;
    }

    public function updateHotelConfiguration(
        ExternalHotelAccess $accessibleHotel,
        array $payload,
        ?UploadedFile $logoUpload = null
    ): Hotel {
        $hotel = $this->ensureHotelWithConfiguration($accessibleHotel);

        if (array_key_exists('name', $payload)) {
            $hotel->setName(
                $this->normalizeNullableString($payload['name'])
                ?? $accessibleHotel->getName()
                ?? 'Hotel Guest'
            );
        }

        if (isset($payload['configuration'])) {
            if (!is_array($payload['configuration'])) {
                throw new \InvalidArgumentException('configuration must be an object');
            }

            $this->applyConfigurationPatch($hotel, $payload['configuration'], $logoUpload);
        }

        return $hotel;
    }

    public function serializeHotel(Hotel $hotel, bool $persisted = true): array
    {
        $configuration = $hotel->getConfiguration();

        return [
            'hotel' => [
                'id' => $hotel->getId(),
                'externalHotelId' => $hotel->getExternalHotelId(),
                'name' => $hotel->getName(),
                'createdAt' => $hotel->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'updatedAt' => $hotel->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            ],
            'configuration' => $this->serializeConfiguration($configuration),
            'meta' => [
                'persisted' => $persisted,
                'configurationComplete' => $this->isConfigurationComplete($configuration),
                'accessCodeAllowed' => $this->isAccessCodeAllowed($configuration),
                'missingConfigurationFields' => $this->findMissingConfigurationFields($configuration),
            ],
        ];
    }

    public function buildManualHotelPayload(Hotel $hotel): array
    {
        $configuration = $hotel->getConfiguration();
        if ($configuration === null) {
            return [];
        }

        return [
            'hotel' => [
                'name' => $hotel->getName() ?? 'Hotel Guest',
                'supportText' => $configuration->getSupportText(),
                'footerText' => $configuration->getFooterText(),
                'logoUrl' => $this->hotelImageStorage->resolveLogoUrl(
                    $configuration->getLogoImageUuid(),
                    $configuration->getLogoUrl()
                ),
                'logoImageUuid' => $configuration->getLogoImageUuid(),
                'portalUrl' => $configuration->getPortalUrl(),
                'ssids' => $configuration->getSsids(),
                'upgrade' => [
                    'enabled' => $configuration->isUpgradeEnabled(),
                    'url' => $configuration->getUpgradeUrl(),
                ],
            ],
            'device' => [
                'default' => $configuration->getDefaultDevice(),
                'available' => $configuration->getAvailableDevices(),
            ],
            'options' => [
                'mode' => $configuration->getPrimaryAuthMode(),
                'pms' => [
                    'provider' => $configuration->getPmsProvider(),
                    'fields' => $configuration->getPmsCredentialFields(),
                ],
            ],
        ];
    }

    public function buildManualAuthPayload(HotelConfiguration $configuration, array $options): array
    {
        $mode = $this->normalizePrimaryAuthMode($options['mode'] ?? $configuration->getPrimaryAuthMode());
        $freeAccess = $options['freeAccess'] ?? false;
        $payload = [
            'mode' => $mode,
            'freeAccess' => [
                'enabled' => $this->normalizeBoolean(
                    is_array($freeAccess) ? ($freeAccess['enabled'] ?? false) : $freeAccess
                ),
            ],
        ];

        if ($mode === 'accessCode') {
            $accessCode = $options['accessCode'] ?? [];
            $legacyAccessCode = $options['ac'] ?? [];
            $code = $this->normalizeNullableString(
                (is_array($accessCode) ? ($accessCode['code'] ?? null) : $accessCode)
                ?? (is_array($legacyAccessCode) ? ($legacyAccessCode['code'] ?? null) : $legacyAccessCode)
                ?? $options['accessCode']
                ?? null
            );
            if ($code === null) {
                throw new \InvalidArgumentException('Access code is required.');
            }

            $payload['accessCode'] = [
                'code' => $code,
            ];
            $payload['ac'] = $payload['accessCode'];
            $payload['fields'] = [
                ['key' => 'accessCode', 'value' => $code],
            ];

            return $payload;
        }

        $rawPms = $options['pms'] ?? $options['roomSurname'] ?? [];
        if (!is_array($rawPms)) {
            throw new \InvalidArgumentException('PMS fields must be an object.');
        }

        $fields = [];
        $pmsValues = [];
        foreach ($configuration->getPmsCredentialFields() as $fieldName) {
            $value = $this->normalizeNullableString(
                $rawPms[$fieldName]
                ?? ($fieldName === 'roomNumber' ? ($rawPms['room'] ?? null) : null)
                ?? null
            );

            if ($value === null) {
                throw new \InvalidArgumentException(sprintf('PMS field "%s" is required.', $fieldName));
            }

            $pmsValues[$fieldName] = $value;
            $fields[] = [
                'key' => $fieldName,
                'value' => $value,
            ];
        }

        $payload['pms'] = $pmsValues + ['provider' => $configuration->getPmsProvider()];
        $payload['roomSurname'] = [
            'room' => $pmsValues['roomNumber'] ?? '',
            'roomNumber' => $pmsValues['roomNumber'] ?? '',
            'surname' => $pmsValues['surname'] ?? '',
        ];
        $payload['fields'] = $fields;

        return $payload;
    }

    public function isConfigurationComplete(HotelConfiguration|HotelConfigurationPreview|null $configuration): bool
    {
        return $this->findMissingConfigurationFields($configuration) === [];
    }

    public function isAccessCodeAllowed(HotelConfiguration|HotelConfigurationPreview|null $configuration): bool
    {
        if (!$this->isConfigurationComplete($configuration)) {
            return false;
        }

        if ($configuration === null) {
            return false;
        }

        foreach ($configuration->getSsids() as $ssid) {
            if (($ssid['usage'] ?? null) === 'ac') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function findMissingConfigurationFields(HotelConfiguration|HotelConfigurationPreview|null $configuration): array
    {
        if ($configuration === null) {
            return ['portalUrl', 'pmsProvider', 'ssids', 'pmsCredentialFields'];
        }

        $missing = [];

        if ($this->normalizeNullableString($configuration->getPortalUrl()) === null) {
            $missing[] = 'portalUrl';
        }

        if ($this->normalizeNullableString($configuration->getPmsProvider()) === null) {
            $missing[] = 'pmsProvider';
        }

        if (count($configuration->getPmsCredentialFields()) < 2) {
            $missing[] = 'pmsCredentialFields';
        }

        if ($configuration->getSsids() === []) {
            $missing[] = 'ssids';
        }

        return $missing;
    }

    private function createDefaultConfigurationPreview(): HotelConfigurationPreview
    {
        return new HotelConfigurationPreview(
            'Need help? Contact reception.',
            null,
            '/media/mik-logo.png',
            null,
            null,
            null,
            null,
            null,
            ['roomNumber', 'surname'],
            'roomSurname',
            'generic',
            ['android', 'ios', 'generic'],
            [],
            false,
            null
        );
    }

    private function applyDefaultConfiguration(HotelConfiguration $configuration): void
    {
        $configuration->setSupportText('Need help? Contact reception.');
        $configuration->setLogoUrl('/media/mik-logo.png');
        $configuration->setLogoImageUuid(null);
        $configuration->setFooterText(null);
        $configuration->setPortalUrl(null);
        $configuration->setProxyApiBaseUrl(null);
        $configuration->setDatacenterId(null);
        $configuration->setPmsProvider(null);
        $configuration->setPmsCredentialFields(['roomNumber', 'surname']);
        $configuration->setPrimaryAuthMode('roomSurname');
        $configuration->setDefaultDevice('generic');
        $configuration->setAvailableDevices(['android', 'ios', 'generic']);
        $configuration->setSsids([]);
        $configuration->setUpgradeEnabled(false);
        $configuration->setUpgradeUrl(null);
    }

    private function serializeConfiguration(HotelConfiguration|HotelConfigurationPreview|null $configuration): ?array
    {
        if ($configuration === null) {
            return null;
        }

        return [
            'id' => $configuration->getId(),
            'supportText' => $configuration->getSupportText(),
            'footerText' => $configuration->getFooterText(),
            'logoUrl' => $this->hotelImageStorage->resolveLogoUrl(
                $configuration->getLogoImageUuid(),
                $configuration->getLogoUrl()
            ),
            'logoSourceUrl' => $configuration->getLogoUrl(),
            'logoImageUuid' => $configuration->getLogoImageUuid(),
            'portalUrl' => $configuration->getPortalUrl(),
            'proxyApiBaseUrl' => $configuration->getProxyApiBaseUrl(),
            'datacenterId' => $configuration->getDatacenterId(),
            'pmsProvider' => $configuration->getPmsProvider(),
            'pmsCredentialFields' => $configuration->getPmsCredentialFields(),
            'primaryAuthMode' => $configuration->getPrimaryAuthMode(),
            'device' => [
                'default' => $configuration->getDefaultDevice(),
                'available' => $configuration->getAvailableDevices(),
            ],
            'ssids' => $configuration->getSsids(),
            'upgrade' => [
                'enabled' => $configuration->isUpgradeEnabled(),
                'url' => $configuration->getUpgradeUrl(),
            ],
            'createdAt' => $configuration->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $configuration->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
        ];
    }

    private function applyConfigurationPatch(
        Hotel $hotel,
        array $payload,
        ?UploadedFile $logoUpload = null
    ): void {
        $configuration = $hotel->getConfiguration();
        if ($configuration === null) {
            throw new \InvalidArgumentException('Hotel configuration is missing.');
        }

        if (array_key_exists('supportText', $payload)) {
            $configuration->setSupportText($this->normalizeNullableString($payload['supportText']));
        }

        if (array_key_exists('footerText', $payload)) {
            $configuration->setFooterText($this->normalizeNullableString($payload['footerText']));
        }

        $removeLogo = $this->normalizeBoolean($payload['removeLogo'] ?? false);
        if ($logoUpload !== null) {
            $asset = $this->hotelImageStorage->storeUploadedLogo($hotel, $logoUpload);
            $configuration->setLogoImageUuid($asset->getUuid());
            $configuration->setLogoUrl(null);
        } elseif ($removeLogo) {
            $configuration->setLogoImageUuid(null);
            $configuration->setLogoUrl(null);
        } elseif (array_key_exists('logoUrl', $payload)) {
            $logoUrl = $this->normalizeNullableString($payload['logoUrl']);
            $configuration->setLogoUrl($logoUrl);
            if ($logoUrl !== null) {
                $configuration->setLogoImageUuid(null);
            }
        }

        if (array_key_exists('portalUrl', $payload)) {
            $configuration->setPortalUrl($this->normalizeNullableString($payload['portalUrl']));
        }

        if (array_key_exists('proxyApiBaseUrl', $payload)) {
            $configuration->setProxyApiBaseUrl($this->normalizeNullableString($payload['proxyApiBaseUrl']));
        }

        if (array_key_exists('datacenterId', $payload)) {
            $configuration->setDatacenterId($this->normalizeNullableString($payload['datacenterId']));
        }

        if (array_key_exists('pmsProvider', $payload)) {
            $configuration->setPmsProvider($this->normalizeNullableString($payload['pmsProvider']));
        }

        if (array_key_exists('pmsCredentialFields', $payload)) {
            $configuration->setPmsCredentialFields($this->normalizePmsCredentialFields($payload['pmsCredentialFields']));
        }

        if (array_key_exists('primaryAuthMode', $payload)) {
            $configuration->setPrimaryAuthMode($this->normalizePrimaryAuthMode($payload['primaryAuthMode']));
        }

        if (array_key_exists('device', $payload)) {
            if (!is_array($payload['device'])) {
                throw new \InvalidArgumentException('device must be an object');
            }

            $this->applyDevicePatch($configuration, $payload['device']);
        }

        if (array_key_exists('ssids', $payload)) {
            $configuration->setSsids($this->normalizeSsids($payload['ssids']));
        }

        if (array_key_exists('upgrade', $payload)) {
            if (!is_array($payload['upgrade'])) {
                throw new \InvalidArgumentException('upgrade must be an object');
            }

            $this->applyUpgradePatch($configuration, $payload['upgrade']);
        }
    }

    private function applyDevicePatch(HotelConfiguration $configuration, array $payload): void
    {
        if (array_key_exists('available', $payload)) {
            $availableDevices = $this->normalizeAvailableDevices($payload['available']);
            $configuration->setAvailableDevices($availableDevices);

            if (!in_array($configuration->getDefaultDevice(), $availableDevices, true)) {
                $configuration->setDefaultDevice($availableDevices[0]);
            }
        }

        if (array_key_exists('default', $payload)) {
            $defaultDevice = $this->normalizeDeviceType($payload['default']);
            $availableDevices = $configuration->getAvailableDevices();

            if (!in_array($defaultDevice, $availableDevices, true)) {
                throw new \InvalidArgumentException('device.default must be one of the available devices');
            }

            $configuration->setDefaultDevice($defaultDevice);
        }
    }

    private function applyUpgradePatch(HotelConfiguration $configuration, array $payload): void
    {
        if (array_key_exists('enabled', $payload)) {
            $configuration->setUpgradeEnabled($this->normalizeBoolean($payload['enabled']));
        }

        if (array_key_exists('url', $payload)) {
            $configuration->setUpgradeUrl($this->normalizeNullableString($payload['url']));
        }
    }

    private function normalizePrimaryAuthMode(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_AUTH_MODES, true)) {
            throw new \InvalidArgumentException('primaryAuthMode must be one of: roomSurname, accessCode');
        }

        return $value;
    }

    private function normalizeDeviceType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, self::ALLOWED_DEVICE_TYPES, true)) {
            throw new \InvalidArgumentException('device type must be one of: android, ios, generic');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function normalizeAvailableDevices(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('device.available must be an array');
        }

        $devices = [];
        foreach ($value as $device) {
            $devices[] = $this->normalizeDeviceType($device);
        }

        $devices = array_values(array_unique($devices));
        if ($devices === []) {
            throw new \InvalidArgumentException('device.available must not be empty');
        }

        return $devices;
    }

    /**
     * @return list<array{name: string, usage: string}>
     */
    private function normalizeSsids(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('ssids must be an array');
        }

        $ssids = [];
        $seen = [];
        foreach ($value as $index => $ssid) {
            if (!is_array($ssid)) {
                throw new \InvalidArgumentException(sprintf('ssids[%d] must be an object', $index));
            }

            $name = $this->normalizeNullableString($ssid['name'] ?? null);
            if ($name === null) {
                throw new \InvalidArgumentException(sprintf('ssids[%d].name is required', $index));
            }

            $usages = $ssid['usages'] ?? [$ssid['usage'] ?? null];
            if (!is_array($usages)) {
                throw new \InvalidArgumentException(sprintf('ssids[%d].usages must be an array', $index));
            }

            foreach ($usages as $usage) {
                if (!is_string($usage) || !in_array($usage, self::ALLOWED_SSID_USAGES, true)) {
                    throw new \InvalidArgumentException(sprintf('ssids[%d].usage must be one of: pms, ac, free', $index));
                }

                $signature = mb_strtolower($name) . ':' . $usage;
                if (isset($seen[$signature])) {
                    continue;
                }

                $seen[$signature] = true;
                $ssids[] = [
                    'name' => $name,
                    'usage' => $usage,
                ];
            }
        }

        return $ssids;
    }

    /**
     * @return list<string>
     */
    private function normalizePmsCredentialFields(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \InvalidArgumentException('pmsCredentialFields must be an array');
        }

        $fields = ['roomNumber'];
        foreach ($value as $fieldName) {
            if (!is_string($fieldName) || !in_array($fieldName, self::ALLOWED_PMS_CREDENTIAL_FIELDS, true)) {
                throw new \InvalidArgumentException('Unsupported PMS field selection.');
            }

            $fields[] = $fieldName;
        }

        $fields = array_values(array_unique($fields));
        if (count($fields) < 2) {
            throw new \InvalidArgumentException('At least roomNumber and one additional PMS field are required.');
        }

        return $fields;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw new \InvalidArgumentException('Expected a string value');
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }
}
