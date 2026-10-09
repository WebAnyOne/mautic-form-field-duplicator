<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service;

use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Exception\DuplicationException;

final class DraftDuplicator
{
    public const SUPPORTED = ['text', 'textarea', 'number', 'date', 'datetime', 'select', 'radiogrp', 'checkboxgrp', 'hidden', 'freetext', 'freehtml', 'country', 'email', 'tel', 'url', 'password', 'file', 'companyLookup'];

    public function __construct(private AliasGenerator $aliases, private ChoiceMaterializer $choices, private AttributeRewriter $attributes)
    {
    }

    /** Pure planning: no session/entity writes. The controller renders before committing. */
    public function duplicate(array $fields, array $deleted, string $source, string $formId, string $formName, string $suffix, array $customTypes = []): array
    {
        $active = array_diff_key($fields, array_flip($deleted));
        if (!isset($active[$source])) {
            throw new DuplicationException('wao.duplicator.error.source', 404);
        }
        $children = [];
        foreach ($active as $id => $field) {
            if (!is_array($field) || (string) ($field['id'] ?? '') !== (string) $id || (string) ($field['formId'] ?? '') !== $formId) {
                throw new DuplicationException('wao.duplicator.error.draft', 409);
            }
            if (!empty($field['parent'])) {
                if (!isset($active[$field['parent']])) {
                    throw new DuplicationException('wao.duplicator.error.tree');
                }
                $children[(string) $field['parent']][] = (string) $id;
            }
        }
        // Validate the entire active graph before any recursive native Twig rendering.
        foreach (array_keys($active) as $id) {
            $seen = [];
            $cursor = (string) $id;
            while (!empty($active[$cursor]['parent'])) {
                if (isset($seen[$cursor])) {
                    throw new DuplicationException('wao.duplicator.error.tree');
                }
                $seen[$cursor] = true;
                $cursor = (string) $active[$cursor]['parent'];
            }
        }
        $group = [];
        $visit = function (string $id) use (&$visit, &$group, $children): void {
            $group[] = $id;
            foreach ($children[$id] ?? [] as $child) {
                $visit($child);
            }
        };
        $visit($source);
        $map = [];
        foreach ($group as $id) {
            $field = $active[$id];
            if (!in_array($field['type'], self::SUPPORTED, true) || !empty($field['isCustom']) || isset($customTypes[$field['type']])) {
                throw new DuplicationException('wao.duplicator.error.type');
            }
            do {
                $new = 'new'.bin2hex(random_bytes(20));
            } while (isset($fields[$new]) || in_array($new, $map, true));
            $map[$id] = $new;
        }
        $used = array_column($fields, 'alias');
        $copies = [];
        $removedMappings = false;
        foreach ($group as $id) {
            // Recursive scalar/array copy rejects unsupported object/reference contracts.
            $copy = $this->copyValue(array_diff_key($active[$id], array_flip(['form', 'changes', 'uuid', 'sessionId', 'customParameters'])));
            $removedMappings = $removedMappings || !empty($copy['mappedField']) || !empty($copy['leadField']);
            $copy = $this->choices->materialize($copy);
            $copy['mappedObject'] = $copy['mappedField'] = $copy['leadField'] = null;
            $copy['id'] = $copy['sessionId'] = $map[$id];
            $copy['formId'] = $formId;
            $copy['alias'] = $this->aliases->copyAlias((string) $copy['alias'], $used);
            $copy['parent'] = empty($copy['parent']) ? null : ($map[(string) $copy['parent']] ?? $copy['parent']);
            // Native 6.0.9 conditions contain only expr/any/values; the target is parent.
            if ($id === $source) {
                $copy['label'] = (string) $copy['label'].$suffix;
            }
            $copy['_waoSource'] = $id;
            $copies[$copy['id']] = $copy;
        }
        $copies = $this->attributes->rewrite($copies, $active, $formName);
        // Native new child creation can append a child after unrelated roots.
        // Reconstruct visual tree order before insertion so saved root order matches the editor.
        $ordered = [];
        $appendTree = function (string $id) use (&$appendTree, &$ordered, $active, $children): void {
            $ordered[$id] = $active[$id];
            foreach ($children[$id] ?? [] as $child) {
                $appendTree($child);
            }
        };
        foreach ($active as $id => $field) {
            if (empty($field['parent'])) {
                $appendTree((string) $id);
            }
        }
        $ordered += array_diff_key($fields, $active);
        $last = -1;
        foreach (array_keys($ordered) as $index => $id) {
            if (in_array((string) $id, $group, true)) {
                $last = $index;
            }
        }
        $planned = array_slice($ordered, 0, $last + 1, true) + $copies + array_slice($ordered, $last + 1, null, true);
        // Only copies receive draft order hints. Native setFields orders root entities.
        $rootOrder = 0;
        foreach ($planned as $id => $field) {
            if (!isset($active[$id]) && !isset($copies[$id])) {
                continue;
            }
            if (empty($field['parent'])) {
                ++$rootOrder;
            }
            if (isset($copies[$id])) {
                $planned[$id]['order'] = $copies[$id]['order'] = $rootOrder;
            }
        }

        return ['fields' => $planned, 'copies' => $copies, 'rootId' => $map[$source], 'removedMappings' => $removedMappings];
    }

    private function copyValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = $this->copyValue($item);
            }

            return $copy;
        }
        if (is_object($value) || is_resource($value)) {
            throw new DuplicationException('wao.duplicator.error.type');
        }

        return $value;
    }
}
