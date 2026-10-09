<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Exception;

final class DuplicationException extends \RuntimeException
{
    public function __construct(public readonly string $translationKey, public readonly int $status = 422)
    {
        parent::__construct($translationKey);
    }
}
