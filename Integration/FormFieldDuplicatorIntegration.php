<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Integration;

use Mautic\PluginBundle\Integration\AbstractIntegration;

final class FormFieldDuplicatorIntegration extends AbstractIntegration
{
    public const NAME = 'FormFieldDuplicator';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return 'WebAnyOne Form Field Duplicator';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    public function getRequiredKeyFields(): array
    {
        return [];
    }
}
