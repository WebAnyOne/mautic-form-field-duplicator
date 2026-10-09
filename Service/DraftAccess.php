<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Exception\DuplicationException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class DraftAccess
{
    public function __construct(private CorePermissions $permissions, private UserHelper $users, private FormModel $forms, private CsrfTokenManagerInterface $csrf)
    {
    }

    public static function scopeKey(string $formId): string
    {
        return 'wao.duplicator.'.$formId;
    }

    public static function csrfId(string $formId, string $generation): string
    {
        return 'wao.duplicator.'.$formId.'.'.$generation;
    }

    public function check(Request $request): array
    {
        if (!$request->isMethod('POST') || !$request->isXmlHttpRequest()) {
            throw new DuplicationException('wao.duplicator.error.request', 405);
        }
        $formId = (string) $request->request->get('formId', '');
        if (!preg_match('/^(?:[1-9][0-9]*|mautic_[a-f0-9]{40})$/D', $formId)) {
            throw new DuplicationException('wao.duplicator.error.draft', 409);
        }
        $session = $request->getSession();
        $scope = $session->get(self::scopeKey($formId));
        $generation = (string) $request->request->get('generation', '');
        if (!is_array($scope) || !hash_equals($scope['generation'], $generation) || $scope['userId'] !== $this->users->getUser()->getId()
            || !$session->has('mautic.form.'.$formId.'.fields.modified')) {
            throw new DuplicationException('wao.duplicator.error.draft', 409);
        }
        if (!$this->csrf->isTokenValid(new CsrfToken(self::csrfId($formId, $generation), (string) $request->request->get('_token', '')))) {
            throw new DuplicationException('wao.duplicator.error.csrf', 403);
        }
        $entity = null;
        if ($scope['entityId']) {
            if ($formId !== (string) $scope['entityId'] || !$entity = $this->forms->getEntity($scope['entityId'])) {
                throw new DuplicationException('wao.duplicator.error.source', 404);
            }
            if (!$this->permissions->hasEntityAccess('form:forms:editown', 'form:forms:editother', $entity->getCreatedBy())) {
                throw new DuplicationException('wao.duplicator.error.permission', 403);
            }
            if ($this->forms->isLocked($entity)) {
                throw new DuplicationException('wao.duplicator.error.lock', 423);
            }
        } elseif (!$this->permissions->isGranted('form:forms:create')) {
            throw new DuplicationException('wao.duplicator.error.permission', 403);
        }
        $fields = $session->get('mautic.form.'.$formId.'.fields.modified');
        $deleted = $session->get('mautic.form.'.$formId.'.fields.deleted', []);
        $persistedIds = $entity ? array_map(static fn ($field): string => (string) $field->getId(), $entity->getFields()->toArray()) : [];
        foreach ($fields as $id => $field) {
            if ((string) ($field['formId'] ?? '') !== $formId || (string) ($field['id'] ?? '') !== (string) $id) {
                throw new DuplicationException('wao.duplicator.error.source', 404);
            }
            if (!preg_match('/^new[a-f0-9]{40}$/D', (string) $id) && !in_array((string) $id, $persistedIds, true)) {
                throw new DuplicationException('wao.duplicator.error.source', 404);
            }
        }

        return [$formId, $scope, $entity, $fields, $deleted];
    }
}
