<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\EventListener;

use Mautic\FormBundle\Event\FormEvent;
use Mautic\FormBundle\FormEvents;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftAccess;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class PersistedIndexSubscriber implements EventSubscriberInterface
{
    public function __construct(private RequestStack $requests)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // After the core conditional subscriber resolves parents; before saveEntity generates HTML.
        return [FormEvents::FORM_POST_SAVE => ['indexSavedFields', -128]];
    }

    public function indexSavedFields(FormEvent $event): void
    {
        $request = $this->requests->getCurrentRequest();
        $formId = (string) ($request?->request->all()['mauticform']['sessionId'] ?? '');
        if (!$formId || !$request->hasSession()) {
            return;
        }
        $scope = $request->getSession()->get(DraftAccess::scopeKey($formId), []);
        $roots = $scope['copiedIds'] ?? array_column(array_column($scope['requests'] ?? [], 'response'), 'rootId');
        if (!$roots) {
            return;
        }
        $fields = $event->getForm()->getFields();
        $affected = false;
        foreach ($fields as $field) {
            if (in_array($field->getSessionId(), $roots, true)) {
                $affected = true;
                break;
            }
        }
        if (!$affected) {
            return;
        }
        // This is the inverse collection, with no orphanRemoval in 6.0.9.
        // Rekeying the SAME objects does not delete or recreate entities/results.
        foreach ($fields->toArray() as $key => $field) {
            if ($field->getId() && (string) $key !== (string) $field->getId()) {
                $fields->remove($key);
                $fields->set($field->getId(), $field);
            }
        }
    }
}
