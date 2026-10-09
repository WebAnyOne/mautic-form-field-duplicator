<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service;

use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Exception\DuplicationException;

final class AttributeRewriter
{
    private const PATHS = ['labelAttributes', 'inputAttributes', 'containerAttributes'];
    private const REFERENCES = ['for', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-activedescendant', 'headers', 'list'];

    public function rewrite(array $copies, array $originals, string $formName): array
    {
        $used = [];
        foreach ($originals as $field) {
            foreach ($this->attributeStrings($field) as $text) {
                // Keep legacy malformed strings outside the copied group untouched.
                if (preg_match('/\bid\s*=\s*([\x22\x27])(.*?)\1/i', $text, $match)) {
                    $used[html_entity_decode($match[2], ENT_QUOTES)] = true;
                }
            }
        }
        $map = [];
        $parsed = [];
        foreach ($copies as $id => $field) {
            foreach ($this->attributeStrings($field) as $path => $text) {
                $attrs = $this->parse($text);
                $parsed[$id][$path] = $attrs;
                if (!empty($attrs['id'])) {
                    $old = $attrs['id'];
                    if (isset($map[$old])) {
                        throw new DuplicationException('wao.duplicator.error.attributes');
                    }
                    do {
                        $new = $old.'_copy_'.bin2hex(random_bytes(4));
                    } while (isset($used[$new]));
                    $map[$old] = $new;
                    $used[$new] = true;
                }
            }
        }
        // Native aliases generate the public IDs. Map only exact, known IDs.
        foreach ($copies as $id => $field) {
            $old = $originals[$field['_waoSource']]['alias'];
            foreach (['input', 'label', ''] as $part) {
                $prefix = 'mauticform'.('' === $part ? '' : '_'.$part).'_'.$formName.'_';
                $map[$prefix.$old] = $prefix.$field['alias'];
            }
        }
        foreach ($copies as $id => &$field) {
            foreach ($parsed[$id] ?? [] as $path => $attrs) {
                foreach ($attrs as $key => &$value) {
                    if ('id' === $key) {
                        $value = $map[$value] ?? $value;
                    } elseif (in_array($key, self::REFERENCES, true)) {
                        $value = implode(' ', array_map(static fn (string $token): string => $map[$token] ?? $token, preg_split('/\s+/', trim($value))));
                    } elseif (in_array($key, ['href', 'data-target'], true) && str_starts_with($value, '#')) {
                        $target = substr($value, 1);
                        $value = isset($map[$target]) ? '#'.$map[$target] : $value;
                    }
                }
                unset($value);
                $text = implode(' ', array_map(static fn (string $name, string $value): string => $name.'="'.htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8').'"', array_keys($attrs), array_values($attrs)));
                if (strlen($text) > 191 && strlen($text) > strlen($this->attributeStrings($field)[$path])) {
                    throw new DuplicationException('wao.duplicator.error.attributes');
                }
                if ('properties.labelAttributes' === $path) {
                    $field['properties']['labelAttributes'] = $text;
                } else {
                    $field[$path] = $text;
                }
            }
            unset($field['_waoSource']);
        }
        unset($field);

        return $copies;
    }

    private function attributeStrings(array $field): array
    {
        $strings = [];
        foreach (self::PATHS as $key) {
            if (!empty($field[$key])) {
                $strings[$key] = (string) $field[$key];
            }
        }
        if (!empty($field['properties']['labelAttributes'])) {
            $strings['properties.labelAttributes'] = (string) $field['properties']['labelAttributes'];
        }

        return $strings;
    }

    private function parse(string $text): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string('<element '.$text.' />', \SimpleXMLElement::class, LIBXML_NONET);
            if (false === $xml) {
                throw new DuplicationException('wao.duplicator.error.attributes');
            }
            $attributes = [];
            foreach ($xml->attributes() as $name => $value) {
                $attributes[$name] = (string) $value;
            }

            return $attributes;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
