<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service;

use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Integration\FormFieldDuplicatorIntegration;

final class PluginState
{
    public function __construct(private IntegrationHelper $integrations)
    {
    }

    public function isEnabled(): bool
    {
        $integration = $this->integrations->getIntegrationObject(FormFieldDuplicatorIntegration::NAME);

        return $integration && $integration->getIntegrationSettings()->getIsPublished();
    }
}
