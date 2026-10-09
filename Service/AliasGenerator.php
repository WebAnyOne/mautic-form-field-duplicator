<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service;

use Mautic\FormBundle\Model\FieldModel;

final class AliasGenerator
{
    public function __construct(private FieldModel $fields)
    {
    }

    public function copyAlias(string $source, array &$used): string
    {
        // Native normalization, SQL reserved words and internal HTML reserved IDs.
        $none = [];
        $base = $this->fields->generateAlias($source, $none);
        for ($number = 1; ; ++$number) {
            $suffix = 1 === $number ? '_copy' : '_copy_'.$number;
            $candidate = rtrim(substr($base, 0, 25 - strlen($suffix)), '_').$suffix;
            if (!in_array($candidate, $used, true)) {
                $used[] = $candidate;

                return $candidate;
            }
        }
    }
}
