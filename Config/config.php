<?php

declare(strict_types=1);

use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Controller\DuplicateController;

return [
    'name' => 'WebAnyOne Form Field Duplicator',
    'description' => 'Duplicate native form fields and their conditional children in the editor draft.',
    'version' => '1.0.0',
    'author' => 'WebAnyOne',
    'routes' => [
        'main' => [
            'wao_form_field_duplicate' => [
                'path' => '/wao-form-field-duplicator/duplicate',
                'controller' => DuplicateController::class.'::duplicateAction',
                'method' => 'POST',
            ],
        ],
    ],
];
