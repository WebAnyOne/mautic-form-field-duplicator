<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service;

use Mautic\CoreBundle\Helper\AbstractFormFieldHelper;
use Mautic\FormBundle\Collector\FieldCollectorInterface;
use Mautic\LeadBundle\Helper\FormFieldHelper;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Exception\DuplicationException;

final class ChoiceMaterializer
{
    public function __construct(private FieldCollectorInterface $fields)
    {
    }

    public function materialize(array $field): array
    {
        if (empty($field['properties']['syncList'])) {
            return $field;
        }
        if (!in_array($field['type'], ['select', 'radiogrp', 'checkboxgrp', 'country'], true)) {
            throw new DuplicationException('wao.duplicator.error.choices');
        }
        $mapped = $this->fields->getFields((string) ($field['mappedObject'] ?? ''))
            ->getFieldByKey((string) ($field['mappedField'] ?? ''), false);
        if (!$mapped) {
            throw new DuplicationException('wao.duplicator.error.choices');
        }
        $properties = $mapped->getProperties();
        $choices = match ($mapped->getType()) {
            'country' => FormFieldHelper::getCountryChoices(),
            'region' => FormFieldHelper::getRegionChoices(),
            'timezone' => FormFieldHelper::getTimezonesChoices(),
            'locale' => FormFieldHelper::getLocaleChoices(),
            // Explicit rows preserve numerical values (0/1) through native list parsing.
            'boolean' => [['value' => '0', 'label' => $properties['no'] ?? 'No'], ['value' => '1', 'label' => $properties['yes'] ?? 'Yes']],
            default => $properties['list'] ?? [],
        };
        $choices = AbstractFormFieldHelper::parseList($choices);
        if (!$choices) {
            // The renderer falls back to local choices if the CRM list is empty.
            $local = $field['properties']['optionlist']['list'] ?? $field['properties']['list']['list'] ?? [];
            $choices = AbstractFormFieldHelper::parseList($local);
        }
        if (!$choices) {
            throw new DuplicationException('wao.duplicator.error.choices');
        }
        $rows = [];
        foreach ($choices as $value => $label) {
            if (!is_scalar($label)) {
                throw new DuplicationException('wao.duplicator.error.choices');
            }
            $rows[] = ['value' => (string) $value, 'label' => (string) $label];
        }
        $key = in_array($field['type'], ['radiogrp', 'checkboxgrp'], true) ? 'optionlist' : 'list';
        $field['properties'][$key] = ['list' => $rows];
        // Remove legacy alternative lists which could override the newly materialized list.
        unset($field['properties']['optionList']);
        $field['properties']['syncList'] = false;

        return $field;
    }
}
