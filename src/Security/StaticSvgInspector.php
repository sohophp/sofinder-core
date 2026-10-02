<?php

declare(strict_types=1);

namespace SohoPHP\SoFinder\Security;

use SohoPHP\SoFinder\Exception\SoFinderException;

/** Accept only self-contained, non-interactive SVG artwork for public image resources. */
final class StaticSvgInspector
{
    /** @return array{width:int,height:int} */
    public function inspect(string $path): array
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new SoFinderException('SVG uploads require the PHP DOM extension.', 'unsupported_image', 415);
        }
        $source = file_get_contents($path);
        if ($source === false || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $source)) {
            throw new SoFinderException('Invalid or unsafe SVG content.', 'invalid_image', 415);
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($source, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new SoFinderException('Invalid SVG document.', 'invalid_image', 415);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $document->documentElement;
        if ($root === null || $root->localName !== 'svg' || $root->namespaceURI !== 'http://www.w3.org/2000/svg') {
            throw new SoFinderException('Invalid SVG root element.', 'invalid_image', 415);
        }
        $instructions = (new \DOMXPath($document))->query('//processing-instruction()');
        if ($instructions === false) {
            throw new SoFinderException('Unable to inspect SVG document.', 'invalid_image', 415);
        }
        foreach ($instructions as $node) {
            throw new SoFinderException('SVG contains a processing instruction.', 'unsafe_file_content', 415);
        }

        $elements = ['svg', 'g', 'defs', 'desc', 'title', 'style', 'symbol', 'use', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'textPath', 'linearGradient', 'radialGradient', 'stop', 'pattern', 'clipPath', 'mask', 'marker', 'filter', 'feBlend', 'feColorMatrix', 'feComponentTransfer', 'feComposite', 'feConvolveMatrix', 'feDiffuseLighting', 'feDisplacementMap', 'feDistantLight', 'feDropShadow', 'feFlood', 'feFuncA', 'feFuncB', 'feFuncG', 'feFuncR', 'feGaussianBlur', 'feMerge', 'feMergeNode', 'feMorphology', 'feOffset', 'fePointLight', 'feSpecularLighting', 'feSpotLight', 'feTile', 'feTurbulence', 'view'];
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->namespaceURI !== 'http://www.w3.org/2000/svg' || !in_array($element->localName, $elements, true)) {
                throw new SoFinderException('SVG contains unsupported active content.', 'unsafe_file_content', 415);
            }
            if ($element->localName === 'style') {
                $this->assertSafeStyleSheet($element->textContent);
            }
            foreach ($element->attributes as $attribute) {
                if ($attribute->namespaceURI === 'http://www.w3.org/2000/xmlns/') {
                    continue;
                }
                $name = strtolower($attribute->localName ?? $attribute->nodeName);
                $value = trim($attribute->value);
                if ($name === 'style') {
                    $this->assertSafeDeclarations($value);
                    continue;
                }
                if (str_starts_with($name, 'on') || $name === 'src'
                    || ($name === 'base' && $attribute->namespaceURI === 'http://www.w3.org/XML/1998/namespace')
                    || str_contains($value, '\\')
                    || ($name === 'href' && !preg_match('/^#[A-Za-z_][\w.:-]*$/D', $value))
                    || preg_match('/url\s*\(\s*(?!["\']?#[A-Za-z_][\w.:-]*["\']?\s*\))|(?:javascript|data|https?|file):|\/\//i', $value)) {
                    throw new SoFinderException('SVG contains an external reference or active content.', 'unsafe_file_content', 415);
                }
            }
        }

        $width = $this->dimension($root->getAttribute('width'));
        $height = $this->dimension($root->getAttribute('height'));
        if (($width === 0 || $height === 0) && preg_match('/^\s*[-\d.]+[ ,]+[-\d.]+[ ,]+([\d.]+)[ ,]+([\d.]+)\s*$/D', $root->getAttribute('viewBox'), $matches)) {
            $width = $this->dimension($matches[1]);
            $height = $this->dimension($matches[2]);
        }
        if ($width === 0 || $height === 0) {
            throw new SoFinderException('SVG requires positive width and height or a viewBox.', 'invalid_image', 415);
        }
        return ['width' => $width, 'height' => $height];
    }

    private function assertSafeStyleSheet(string $css): void
    {
        if (preg_match('/[@\\<>]|\/\*|\/\/|url\s*\(/i', $css)) {
            throw new SoFinderException('SVG contains unsafe CSS.', 'unsafe_file_content', 415);
        }
        $rest = trim($css);
        while ($rest !== '') {
            if (!preg_match('/^\s*([.#]?[A-Za-z_][\w.-]*(?:\s*,\s*[.#]?[A-Za-z_][\w.-]*)*)\s*\{([^{}]*)\}\s*/', $rest, $matches)) {
                throw new SoFinderException('SVG contains unsupported CSS.', 'unsafe_file_content', 415);
            }
            $this->assertSafeDeclarations($matches[2]);
            $rest = substr($rest, strlen($matches[0]));
        }
    }

    private function assertSafeDeclarations(string $css): void
    {
        $properties = ['fill', 'fill-rule', 'fill-opacity', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity', 'opacity', 'clip-rule', 'color', 'stop-color', 'stop-opacity', 'font-family', 'font-size', 'font-style', 'font-weight', 'text-anchor', 'display', 'visibility', 'enable-background'];
        foreach (explode(';', $css) as $declaration) {
            if (trim($declaration) === '') {
                continue;
            }
            if (!preg_match('/^\s*([a-z-]+)\s*:\s*([^;{}]+)\s*$/iD', $declaration, $matches)
                || !in_array(strtolower($matches[1]), $properties, true)
                || !preg_match('/^[\w\s#.,%()+-]+$/Du', $matches[2])
                || preg_match('/(?:url|expression|javascript|data|import)\s*\(/i', $matches[2])) {
                throw new SoFinderException('SVG contains unsafe CSS.', 'unsafe_file_content', 415);
            }
        }
    }

    private function dimension(string $value): int
    {
        return preg_match('/^\s*(\d+(?:\.\d+)?)(?:px)?\s*$/D', $value, $matches)
            ? (int) round((float) $matches[1]) : 0;
    }
}
