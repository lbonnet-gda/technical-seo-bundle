<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Extractor;

use DOMElement;
use DOMNode;
use Lbonnet\TechnicalSeoBundle\Model\HeadSignals;
use Symfony\Component\DomCrawler\Crawler;

final class HtmlHeadSignalsExtractor implements HeadSignalsExtractorInterface
{
    public function extract(string $html): HeadSignals
    {
        if (trim($html) === '') {
            return new HeadSignals();
        }

        $crawler = new Crawler($html);

        $canonicalHrefs = [];
        $bodyCanonicalHrefs = [];

        foreach ($crawler->filter('link[rel]') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            if (strcasecmp(trim($element->getAttribute('rel')), 'canonical') !== 0) {
                continue;
            }

            $href = trim($element->getAttribute('href'));

            if (self::isInsideHead($element)) {
                $canonicalHrefs[] = $href;

                continue;
            }

            $bodyCanonicalHrefs[] = $href;
        }

        return new HeadSignals(
            canonicalHrefs: $canonicalHrefs,
            bodyCanonicalHrefs: $bodyCanonicalHrefs,
            metaRobots: $this->extractMetaRobots($crawler),
            metaRefreshUrl: $this->extractMetaRefreshUrl($crawler),
            htmlLang: $this->extractHtmlLang($crawler),
        );
    }

    private static function isInsideHead(DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if (strcasecmp($parent->nodeName, 'head') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractMetaRobots(Crawler $crawler): array
    {
        $values = [];

        foreach ($crawler->filter('meta[name]') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            if (strcasecmp(trim($element->getAttribute('name')), 'robots') !== 0) {
                continue;
            }

            $content = trim($element->getAttribute('content'));

            if ($content === '') {
                continue;
            }

            $values[] = $content;
        }

        return $values;
    }

    private function extractMetaRefreshUrl(Crawler $crawler): ?string
    {
        foreach ($crawler->filter('meta[http-equiv]') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            if (strcasecmp(trim($element->getAttribute('http-equiv')), 'refresh') !== 0) {
                continue;
            }

            $content = $element->getAttribute('content');

            if (preg_match('#url\s*=\s*[\'"]?([^\'";]+)#i', $content, $matches) === 1) {
                $url = trim($matches[1]);

                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    private function extractHtmlLang(Crawler $crawler): ?string
    {
        $htmlNode = $crawler->filter('html');

        if ($htmlNode->count() === 0) {
            return null;
        }

        $lang = trim((string)$htmlNode->first()->attr('lang'));

        return $lang !== '' ? $lang : null;
    }
}
