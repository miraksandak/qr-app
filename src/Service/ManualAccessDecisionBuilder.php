<?php

namespace App\Service;

class ManualAccessDecisionBuilder
{
    public const TYPE_FREE = 'free';
    public const TYPE_ACCESS_CODE = 'access_code';
    public const TYPE_PMS_ACCESS = 'pms_access';

    public function build(array $payload): ?array
    {
        $options = is_array($payload['options'] ?? null) ? $payload['options'] : [];
        $mode = $this->normalizeMode($options['mode'] ?? null);

        if ($mode === 'access_code') {
            return $this->buildAccessCodeDecision($options)
                ?? $this->buildPmsDecision($options)
                ?? $this->buildFreeDecision($options);
        }

        if ($mode === 'pms_access') {
            return $this->buildPmsDecision($options)
                ?? $this->buildAccessCodeDecision($options)
                ?? $this->buildFreeDecision($options);
        }

        if ($mode === 'free') {
            return $this->buildFreeDecision($options)
                ?? $this->buildPmsDecision($options)
                ?? $this->buildAccessCodeDecision($options);
        }

        return $this->buildPmsDecision($options)
            ?? $this->buildAccessCodeDecision($options)
            ?? $this->buildFreeDecision($options);
    }

    private function buildAccessCodeDecision(array $options): ?array
    {
        $code = $this->normalizeString(
            $options['accessCode']['code']
            ?? $options['ac']['code']
            ?? $options['accessCode']
            ?? $options['ac']
            ?? null
        );

        return $code === null ? null : [
            'type' => self::TYPE_ACCESS_CODE,
            'code' => $code,
        ];
    }

    private function buildPmsDecision(array $options): ?array
    {
        $pms = $this->normalizePmsValues($options['pms'] ?? $options['roomSurname'] ?? []);
        if ($pms === []) {
            return null;
        }

        return [
            'type' => self::TYPE_PMS_ACCESS,
            'pms' => $pms,
            'secondaryAuth' => [],
        ];
    }

    private function buildFreeDecision(array $options): ?array
    {
        $freeAccess = $options['freeAccess'] ?? false;
        $enabled = is_array($freeAccess) ? ($freeAccess['enabled'] ?? false) : $freeAccess;

        return $this->normalizeBoolean($enabled) ? ['type' => self::TYPE_FREE] : null;
    }

    private function normalizePmsValues(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        $values = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || in_array($key, ['provider', 'fields', 'url'], true)) {
                continue;
            }

            $value = $this->normalizeString($value);
            if ($value !== null) {
                $values[$key] = $value;
            }
        }

        if (isset($values['room']) && !isset($values['roomNumber'])) {
            $values['roomNumber'] = $values['room'];
        }
        unset($values['room']);

        return $values;
    }

    private function normalizeMode(mixed $value): ?string
    {
        $value = $this->normalizeString($value);
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?? '';
        if (in_array($normalized, ['ac', 'access', 'accesscode', 'code'], true)) {
            return 'access_code';
        }

        if (in_array($normalized, ['pms', 'room', 'roomsurname', 'roomlogin'], true)) {
            return 'pms_access';
        }

        if (in_array($normalized, ['free', 'freeaccess', 'guest', 'open'], true)) {
            return 'free';
        }

        return null;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }
}
