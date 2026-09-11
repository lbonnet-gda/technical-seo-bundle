<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Tests\Http;

use Lbonnet\TechnicalSeoBundle\Http\HeaderFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

final class HeaderFetcherTest extends TestCase
{
    public function testItReturnsTheStatusAndHeaders(): void
    {
        $httpClient = new MockHttpClient(static fn(): MockResponse => new MockResponse('<html></html>', [
            'response_headers' => ['content-type' => 'text/html', 'x-robots-tag' => 'noindex'],
        ]));

        $response = (new HeaderFetcher($httpClient))->fetch('https://example.com/a');

        $this->assertNotNull($response);
        $this->assertSame('https://example.com/a', $response->url);
        $this->assertSame(Response::HTTP_OK, $response->statusCode);
        $this->assertTrue($response->isSuccessful());
        $this->assertTrue($response->isHtml());
        $this->assertTrue($response->headerRobotsDirectives()->hasNoindex());
        $this->assertNull($response->html);
    }

    public function testItReportsARedirectInsteadOfFollowingIt(): void
    {
        $httpClient = new MockHttpClient(static fn(): MockResponse => new MockResponse('', [
            'http_code' => Response::HTTP_MOVED_PERMANENTLY,
            'response_headers' => ['location' => 'https://example.com/b'],
        ]));

        $response = (new HeaderFetcher($httpClient))->fetch('https://example.com/a');

        $this->assertNotNull($response);
        $this->assertTrue($response->isRedirect());
        $this->assertSame(['https://example.com/b'], $response->headers['location']);
    }

    public function testItReturnsNullWhenTheUrlCannotBeReached(): void
    {
        $httpClient = new MockHttpClient(
            static fn(): MockResponse => new MockResponse('', ['error' => 'Connection refused'])
        );

        $this->assertNull((new HeaderFetcher($httpClient))->fetch('https://example.com/a'));
    }

    public function testAnErrorStatusIsStillAResult(): void
    {
        $httpClient = new MockHttpClient(
            static fn(): MockResponse => new MockResponse('', ['http_code' => Response::HTTP_NOT_FOUND])
        );

        $response = (new HeaderFetcher($httpClient))->fetch('https://example.com/a');

        $this->assertNotNull($response);
        $this->assertTrue($response->isError());
        $this->assertSame(Response::HTTP_NOT_FOUND, $response->statusCode);
    }
}
