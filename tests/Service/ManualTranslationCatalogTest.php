<?php

namespace App\Tests\Service;

use App\Service\ManualTranslationCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

class ManualTranslationCatalogTest extends TestCase
{
    public function testBuildAllReturnsFrontendFriendlyCatalogs(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'ui.language' => 'Language',
            'ui.noStepsAvailable' => 'No steps available for this device.',
            'device.android' => 'Android',
            'device.generic' => 'Other',
            'instruction.portal.android.2' => 'Connect to {ssid}.',
            'instruction.portal.android.1' => 'Open Wi-Fi settings on your phone.',
        ], 'en', 'manual');
        $translator->addResource('array', [
            'ui.language' => 'Jazyk',
            'device.android' => 'Android',
            'instruction.free.generic.1' => 'Jste online.',
        ], 'cs', 'manual');

        $catalog = (new ManualTranslationCatalog($translator))->buildAll();

        self::assertSame('Language', $catalog['translations']['en']['language']);
        self::assertSame('Jazyk', $catalog['translations']['cs']['language']);
        self::assertSame('Android', $catalog['deviceNames']['en']['android']);
        self::assertSame([
            'Open Wi-Fi settings on your phone.',
            'Connect to {ssid}.',
        ], $catalog['instructionSets']['en']['portal']['android']);
        self::assertSame([
            'Jste online.',
        ], $catalog['instructionSets']['cs']['free']['generic']);
        self::assertSame([], $catalog['instructionSets']['en']['free']['ios']);
    }
}
