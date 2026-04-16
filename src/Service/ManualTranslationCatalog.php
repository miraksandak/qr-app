<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Translation\TranslatorBagInterface;

class ManualTranslationCatalog
{
    private const DOMAIN = 'manual';
    private const SUPPORTED_LOCALES = ['en', 'cs'];
    private const STEP_GROUPS = ['portal', 'free'];
    private const STEP_DEVICES = ['android', 'ios', 'generic'];

    public function __construct(
        #[Autowire(service: 'translator.default')]
        private TranslatorBagInterface $translatorBag
    ) {
    }

    /**
     * @return array{
     *     translations: array<string, array<string, string>>,
     *     deviceNames: array<string, array<string, string>>,
     *     instructionSets: array<string, array<string, array<string, list<string>>>>
     * }
     */
    public function buildAll(): array
    {
        $translations = [];
        $deviceNames = [];
        $instructionSets = [];

        foreach (self::SUPPORTED_LOCALES as $locale) {
            $catalog = $this->buildLocaleCatalog($locale);
            $translations[$locale] = $catalog['translations'];
            $deviceNames[$locale] = $catalog['deviceNames'];
            $instructionSets[$locale] = $catalog['instructionSets'];
        }

        return [
            'translations' => $translations,
            'deviceNames' => $deviceNames,
            'instructionSets' => $instructionSets,
        ];
    }

    /**
     * @return list<string>
     */
    public function getSupportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    /**
     * @return array{
     *     translations: array<string, string>,
     *     deviceNames: array<string, string>,
     *     instructionSets: array<string, array<string, list<string>>>
     * }
     */
    private function buildLocaleCatalog(string $locale): array
    {
        $messages = $this->translatorBag->getCatalogue($locale)->all(self::DOMAIN);
        $translations = [];
        $deviceNames = [];
        $instructionSets = $this->createEmptyInstructionSets();

        foreach ($messages as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'ui.')) {
                $translations[substr($key, 3)] = $value;
                continue;
            }

            if (str_starts_with($key, 'device.')) {
                $deviceNames[substr($key, 7)] = $value;
                continue;
            }

            if (!preg_match('/^instruction\.(portal|free)\.(android|ios|generic)\.(\d+)$/', $key, $matches)) {
                continue;
            }

            $group = $matches[1];
            $device = $matches[2];
            $position = (int) $matches[3];
            $instructionSets[$group][$device][$position] = $value;
        }

        foreach ($instructionSets as $group => $devices) {
            foreach ($devices as $device => $steps) {
                ksort($steps);
                $instructionSets[$group][$device] = array_values($steps);
            }
        }

        return [
            'translations' => $translations,
            'deviceNames' => $deviceNames,
            'instructionSets' => $instructionSets,
        ];
    }

    /**
     * @return array<string, array<string, array<int, string>>>
     */
    private function createEmptyInstructionSets(): array
    {
        $sets = [];

        foreach (self::STEP_GROUPS as $group) {
            foreach (self::STEP_DEVICES as $device) {
                $sets[$group][$device] = [];
            }
        }

        return $sets;
    }
}
