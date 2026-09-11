<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Http;

use Lbonnet\TechnicalSeoBundle\Model\PageResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final class HeaderFetcher
{
    public const DEFAULT_USER_AGENT = 'Mozilla/5.0 (compatible; TechnicalSeoBundle/1.0; +https://github.com/lbonnet-gda/technical-seo-bundle)';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly int $timeout = 10,
        private readonly string $userAgent = self::DEFAULT_USER_AGENT,
    ) {
    }

    public function fetch(string $url): ?PageResponse
    {
        try {
            $response = $this->httpClient->request(Request::METHOD_GET, $url, [
                'timeout' => $this->timeout,
                'max_redirects' => 0,
                'headers' => [
                    'User-Agent' => $this->userAgent,
                ],
            ]);

            $statusCode = $response->getStatusCode();
            /** @var array<string, list<string>> $headers */
            $headers = $response->getHeaders(false);
            $redirectLocation = $response->getInfo('redirect_url');
            $response->cancel();
        } catch (Throwable) {
            return null;
        }

        return new PageResponse(
            url: $url,
            statusCode: $statusCode,
            headers: $headers,
            redirectLocation: is_string($redirectLocation) ? $redirectLocation : null,
        );
    }
}
