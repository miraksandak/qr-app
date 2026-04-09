<?php

namespace App\Service;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class ManualPayloadViewBuilder
{
    private PngWriter $qrWriter;

    public function __construct()
    {
        $this->qrWriter = new PngWriter();
    }

    public function build(array $payload): array
    {
        $catalog = $this->buildSsidCatalog($payload);
        $preferredMode = $this->resolvePrimaryMode($payload);
        $authVariants = $this->buildAuthVariants($payload, $catalog, $preferredMode);
        $freeVariant = $this->buildFreeVariant($payload, $catalog);

        $variants = $authVariants;
        if ($freeVariant !== null) {
            $variants[] = $freeVariant;
        }

        $payload['manual'] = [
            'primaryMode' => $preferredMode,
            'activeVariantId' => $authVariants[0]['id'] ?? $freeVariant['id'] ?? null,
            'hasMultipleAuthVariants' => count($authVariants) > 1,
            'variants' => $variants,
        ];

        return $payload;
    }

    /**
     * @return array{pms: list<string>, ac: list<string>, free: list<string>}
     */
    private function buildSsidCatalog(array $payload): array
    {
        $catalog = [
            'pms' => [],
            'ac' => [],
            'free' => [],
        ];

        $rawList = is_array($payload['hotel']['ssids'] ?? null) ? $payload['hotel']['ssids'] : [];
        foreach ($rawList as $item) {
            if (is_string($item)) {
                $name = trim($item);
                if ($name !== '') {
                    $catalog['pms'][] = $name;
                }

                continue;
            }

            if (!is_array($item)) {
                continue;
            }

            $name = $this->normalizeNullableString(
                $item['name']
                ?? $item['ssid']
                ?? null
            );
            if ($name === null) {
                continue;
            }

            $usages = $item['usages'] ?? [$item['usage'] ?? $item['purpose'] ?? $item['type'] ?? null];
            if (!is_array($usages)) {
                $usages = [$usages];
            }

            foreach ($usages as $usage) {
                $normalizedUsage = $this->normalizeSsidUsage($usage);
                if ($normalizedUsage === null) {
                    continue;
                }

                $catalog[$normalizedUsage][] = $name;
            }
        }

        foreach ($catalog as $usage => $names) {
            $catalog[$usage] = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
        }

        $legacySsid = $this->normalizeNullableString($payload['hotel']['ssid'] ?? null);
        if ($legacySsid !== null && $catalog['pms'] === [] && $catalog['ac'] === [] && $catalog['free'] === []) {
            $catalog['pms'][] = $legacySsid;
        }

        return $catalog;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildAuthVariants(array $payload, array $catalog, string $preferredMode): array
    {
        $orderedModes = $preferredMode === 'accessCode'
            ? ['accessCode', 'roomSurname']
            : ['roomSurname', 'accessCode'];

        $variants = [];
        foreach ($orderedModes as $mode) {
            $variant = $this->buildAuthVariant($payload, $catalog, $mode, count($variants) + 1);
            if ($variant === null) {
                continue;
            }

            $variant['isPrimary'] = $mode === $preferredMode || $variants === [];
            $variants[] = $variant;
        }

        return $variants;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildAuthVariant(array $payload, array $catalog, string $mode, int $optionNumber): ?array
    {
        if ($mode === 'accessCode') {
            $code = $this->normalizeNullableString(
                $payload['options']['accessCode']['code']
                ?? $payload['options']['ac']['code']
                ?? null
            );
            if ($code === null) {
                return null;
            }

            $url = $this->normalizeNullableString(
                $payload['options']['accessCode']['url']
                ?? $payload['options']['ac']['url']
                ?? null
            );
            $qrPayload = $url ?? $code;

            return [
                'id' => 'accessCode',
                'type' => 'auth',
                'mode' => 'accessCode',
                'titleKey' => 'accessCode',
                'optionNumber' => $optionNumber,
                'usage' => 'ac',
                'ssids' => $catalog['ac'],
                'fields' => [
                    ['key' => 'accessCode', 'value' => $code],
                ],
                'url' => $url,
                'qrDataUrl' => $this->createQrDataUri($qrPayload),
            ];
        }

        $fields = $this->buildPmsFields($payload);
        if ($fields === []) {
            return null;
        }

        $url = $this->normalizeNullableString(
            $payload['options']['roomSurname']['url']
            ?? $payload['options']['pms']['url']
            ?? null
        );
        $fallback = implode(' ', array_values(array_filter(array_map(
            static fn (array $field): string => trim((string) ($field['value'] ?? '')),
            $fields
        ))));

        return [
            'id' => 'roomSurname',
            'type' => 'auth',
            'mode' => 'roomSurname',
            'titleKey' => 'roomSurname',
            'optionNumber' => $optionNumber,
            'usage' => 'pms',
            'ssids' => $catalog['pms'],
            'fields' => $fields,
            'url' => $url,
            'qrDataUrl' => $this->createQrDataUri($url ?? $fallback),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildFreeVariant(array $payload, array $catalog): ?array
    {
        $enabled = $this->normalizeBoolean(
            $payload['options']['freeAccess']['enabled']
            ?? $payload['options']['freeAccess']
            ?? false
        );

        if (!$enabled && $catalog['free'] === []) {
            return null;
        }

        return [
            'id' => 'freeAccess',
            'type' => 'free',
            'mode' => 'freeAccess',
            'titleKey' => 'freeAccess',
            'optionNumber' => 3,
            'usage' => 'free',
            'ssids' => $catalog['free'],
            'fields' => [],
            'url' => null,
            'qrDataUrl' => null,
        ];
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function buildPmsFields(array $payload): array
    {
        $orderedFields = is_array($payload['options']['pms']['fields'] ?? null)
            ? $payload['options']['pms']['fields']
            : [];
        $source = is_array($payload['options']['pms'] ?? null)
            ? $payload['options']['pms']
            : (is_array($payload['options']['roomSurname'] ?? null) ? $payload['options']['roomSurname'] : []);

        $fields = [];
        $seen = [];

        foreach ($orderedFields as $fieldName) {
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            $value = $this->normalizeNullableString(
                $source[$fieldName]
                ?? ($fieldName === 'roomNumber' ? ($source['room'] ?? null) : null)
                ?? null
            );
            if ($value === null) {
                continue;
            }

            $seen[$fieldName] = true;
            $fields[] = [
                'key' => $fieldName,
                'value' => $value,
            ];
        }

        foreach ($source as $fieldName => $value) {
            if (!is_string($fieldName) || isset($seen[$fieldName]) || in_array($fieldName, ['provider', 'fields', 'url'], true)) {
                continue;
            }

            $normalizedValue = $this->normalizeNullableString($value);
            if ($normalizedValue === null) {
                continue;
            }

            $fields[] = [
                'key' => $fieldName,
                'value' => $normalizedValue,
            ];
        }

        return $fields;
    }

    private function resolvePrimaryMode(array $payload): string
    {
        $preferredMode = $this->normalizeMode($payload['options']['mode'] ?? null);
        $hasPmsVariant = $this->buildPmsFields($payload) !== [];
        $hasAccessCodeVariant = $this->normalizeNullableString(
            $payload['options']['accessCode']['code']
            ?? $payload['options']['ac']['code']
            ?? null
        ) !== null;

        if ($preferredMode === 'accessCode' && $hasAccessCodeVariant) {
            return 'accessCode';
        }

        if ($hasPmsVariant) {
            return 'roomSurname';
        }

        if ($hasAccessCodeVariant) {
            return 'accessCode';
        }

        return 'roomSurname';
    }

    private function createQrDataUri(?string $data): ?string
    {
        $normalizedData = $this->normalizeNullableString($data);
        if ($normalizedData === null) {
            return null;
        }

        $qrCode = new QrCode(
            data: $normalizedData,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 240,
            margin: 12,
            roundBlockSizeMode: RoundBlockSizeMode::Margin
        );

        return $this->qrWriter->write($qrCode)->getDataUri();
    }

    private function normalizeSsidUsage(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', trim($value)) ?? '');
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['pms', 'room', 'roomsurname', 'roomlogin'], true)) {
            return 'pms';
        }

        if (in_array($normalized, ['ac', 'access', 'accesscode', 'code'], true)) {
            return 'ac';
        }

        if (in_array($normalized, ['free', 'freeaccess', 'guest', 'open'], true)) {
            return 'free';
        }

        return null;
    }

    private function normalizeMode(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(preg_replace('/[^a-z0-9]+/', '', trim($value)) ?? '');
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['pms', 'room', 'roomsurname', 'roomlogin'], true)) {
            return 'roomSurname';
        }

        if (in_array($normalized, ['ac', 'access', 'accesscode', 'code'], true)) {
            return 'accessCode';
        }

        return null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }
}
