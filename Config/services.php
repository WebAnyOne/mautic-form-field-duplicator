<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Integration\FormFieldDuplicatorIntegration;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()->defaults()->autowire()->autoconfigure()->public();
    $services->load('MauticPlugin\\WebAnyOneFormFieldDuplicatorBundle\\', '../')
        ->exclude('../{Config,DependencyInjection,Exception,Tests,Resources,Assets,Translations}');
    $services->set(FormFieldDuplicatorIntegration::class)->tag('mautic.integration');
    $services->alias('mautic.integration.formfieldduplicator', FormFieldDuplicatorIntegration::class)->public();
};
