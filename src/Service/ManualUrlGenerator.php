<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

class ManualUrlGenerator
{
    public function __construct(
        private RequestStack $requestStack,
        #[Autowire('%env(default::BASE_VIEWER_URL)%')]
        private ?string $configuredViewerBaseUrl = null,
        #[Autowire('%env(default::BASE_UPGRADE_URL)%')]
        private ?string $configuredUpgradeBaseUrl = null
    ) {
    }

    public function getViewerBaseUrl(): string
    {
        return $this->resolveBaseUrl($this->configuredViewerBaseUrl);
    }

    public function getUpgradeBaseUrl(): string
    {
        $configuredUpgradeBaseUrl = is_string($this->configuredUpgradeBaseUrl)
            ? trim($this->configuredUpgradeBaseUrl)
            : '';

        if ($configuredUpgradeBaseUrl !== '') {
            return $this->assertAbsoluteUrl($configuredUpgradeBaseUrl);
        }

        return $this->getViewerBaseUrl() . '/upgrade';
    }

    public function buildViewerUrl(string $id): string
    {
        return $this->getViewerBaseUrl() . '/' . strtoupper($id);
    }

    public function buildJsonUrl(string $id): string
    {
        return $this->getViewerBaseUrl() . '/json/' . strtoupper($id);
    }

    public function buildAccessDecisionUrl(string $id): string
    {
        return $this->getViewerBaseUrl() . '/access-decision/' . strtoupper($id);
    }

    public function buildPrintUrl(string $id): string
    {
        return $this->getViewerBaseUrl() . '/print/' . strtoupper($id);
    }

    public function buildUpgradeUrl(string $id): string
    {
        return $this->getUpgradeBaseUrl() . '/' . strtoupper($id);
    }

    private function resolveBaseUrl(?string $configuredUrl): string
    {
        $configuredUrl = is_string($configuredUrl) ? trim($configuredUrl) : '';
        if ($configuredUrl !== '') {
            return $this->assertAbsoluteUrl($configuredUrl);
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            throw new \RuntimeException('Unable to resolve the public viewer URL without a current request.');
        }

        return rtrim($request->getSchemeAndHttpHost() . $request->getBaseUrl(), '/');
    }

    private function assertAbsoluteUrl(string $url): string
    {
        $parsedUrl = parse_url($url);
        if (!is_array($parsedUrl) || !isset($parsedUrl['scheme'], $parsedUrl['host'])) {
            throw new \RuntimeException(sprintf('Configured public URL "%s" is not a valid absolute URL.', $url));
        }

        return rtrim($url, '/');
    }
}
