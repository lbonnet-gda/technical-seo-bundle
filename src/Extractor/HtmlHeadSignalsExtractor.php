<?php

declare(strict_types=1);

namespace Lbonnet\TechnicalSeoBundle\Extractor;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Lbonnet\TechnicalSeoBundle\Model\HeadSignals;
use Lbonnet\TechnicalSeoBundle\Model\HreflangLink;

final class HtmlHeadSignalsExtractor implements HeadSignalsExtractorInterface
{
    public function extract(string $html): HeadSignals
    {
        if (trim($html) === '') {
            return new HeadSignals();
        }

        $xpath = new DOMXPath(self::parse($html));

        $canonicalHrefs = [];
        $bodyCanonicalHrefs = [];
        $hreflangLinks = [];
        $bodyHreflangLinks = [];

        foreach (self::elements($xpath, '//link[@rel]') as $element) {
            if (self::isHreflangLink($element)) {
                $link = new HreflangLink(
                    trim($element->getAttribute('hreflang')),
                    trim($element->getAttribute('href')),
                );

                if (self::hasAncestor($element, 'head')) {
                    $hreflangLinks[] = $link;
                } else {
                    $bodyHreflangLinks[] = $link;
                }

                continue;
            }

            if (strcasecmp(trim($element->getAttribute('rel')), 'canonical') !== 0) {
                continue;
            }

            $href = trim($element->getAttribute('href'));

            if (self::hasAncestor($element, 'head')) {
                $canonicalHrefs[] = $href;

                continue;
            }

            $bodyCanonicalHrefs[] = $href;
        }

        return new HeadSignals(
            canonicalHrefs: $canonicalHrefs,
            bodyCanonicalHrefs: $bodyCanonicalHrefs,
            metaRobots: $this->extractMetaRobots($xpath),
            metaRefreshUrl: $this->extractMetaRefreshUrl($xpath),
            htmlLang: $this->extractHtmlLang($xpath),
            hreflangLinks: $hreflangLinks,
            bodyHreflangLinks: $bodyHreflangLinks,
        );
    }

    private static function parse(string $html): DOMDocument
    {
        $document = new DOMDocument();
        $internalErrors = libxml_use_internal_errors(true);

        $document->loadHTML(mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8'));

        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        return $document;
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(DOMXPath $xpath, string $expression): array
    {
        $elements = [];

        foreach ($xpath->query($expression) ?: [] as $node) {
            if ($node instanceof DOMElement && !self::hasAncestor($node, 'template')) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private static function isHreflangLink(DOMElement $element): bool
    {
        if (!$element->hasAttribute('hreflang')) {
            return false;
        }

        $relTokens = preg_split('/\s+/', strtolower(trim($element->getAttribute('rel')))) ?: [];

        return in_array('alternate', $relTokens, true);
    }

    private static function hasAncestor(DOMNode $node, string $name): bool
    {
        for ($parent = $node->parentNode; $parent !== null; $parent = $parent->parentNode) {
            if (strcasecmp($parent->nodeName, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractMetaRobots(DOMXPath $xpath): array
    {
        $values = [];

        foreach (self::elements($xpath, '//meta[@name]') as $element) {
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

    private function extractMetaRefreshUrl(DOMXPath $xpath): ?string
    {
        foreach (self::elements($xpath, '//meta[@http-equiv]') as $element) {
            if (strcasecmp(trim($element->getAttribute('http-equiv')), 'refresh') !== 0) {
                continue;
            }

            if (preg_match('#url\s*=\s*[\'"]?([^\'";]+)#i', $element->getAttribute('content'), $matches) === 1) {
                $url = trim($matches[1]);

                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    private function extractHtmlLang(DOMXPath $xpath): ?string
    {
        $htmlElement = self::elements($xpath, '/html')[0] ?? null;
        $lang = $htmlElement !== null ? trim($htmlElement->getAttribute('lang')) : '';

        return $lang !== '' ? $lang : null;
    }
}
