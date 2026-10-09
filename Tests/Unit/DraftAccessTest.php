<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Tests\Unit;

use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Model\FormModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Exception\DuplicationException;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftAccess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class DraftAccessTest extends TestCase
{
    private function make(bool $permitted = true, bool $locked = false, bool $existing = true): array
    {
        $user = new User();
        $id = new \ReflectionProperty(User::class, 'id'); $id->setAccessible(true); $id->setValue($user, 8);
        $users = $this->createMock(UserHelper::class); $users->method('getUser')->willReturn($user);
        $permissions = $this->createMock(CorePermissions::class);
        $permissions->method('isGranted')->willReturn($permitted);
        $permissions->method('hasEntityAccess')->willReturn($permitted);
        $form = new Form();
        $formIdProperty = new \ReflectionProperty(Form::class, 'id'); $formIdProperty->setAccessible(true); $formIdProperty->setValue($form, 12);
        $field = new Field();
        $fieldIdProperty = new \ReflectionProperty(Field::class, 'id'); $fieldIdProperty->setAccessible(true); $fieldIdProperty->setValue($field, 3);
        $form->addField(3, $field);
        $forms = $this->createMock(FormModel::class);
        $forms->method('getEntity')->willReturn($form);
        $forms->method('isLocked')->willReturn($locked);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn ($token): bool => 'valid' === $token->getValue());
        $service = new DraftAccess($permissions, $users, $forms, $csrf);
        $formId = $existing ? '12' : 'mautic_'.str_repeat('b', 40);
        $request = Request::create('/s/forms/field/wao-duplicate', 'POST', ['formId' => $formId, 'generation' => 'generation', '_token' => 'valid']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $session = new Session(new MockArraySessionStorage()); $request->setSession($session);
        $session->set(DraftAccess::scopeKey($formId), ['generation' => 'generation', 'entityId' => $existing ? 12 : null, 'userId' => 8]);
        $fieldId = $existing ? '3' : 'new'.str_repeat('c', 40);
        $session->set('mautic.form.'.$formId.'.fields.modified', [$fieldId => ['id' => $fieldId, 'formId' => $formId]]);

        return [$service, $request, $session, $formId];
    }

    public function testExistingAndNewDraftsAuthorized(): void
    {
        foreach ([true, false] as $existing) {
            [$service, $request, , $formId] = $this->make(true, false, $existing);
            self::assertSame($formId, $service->check($request)[0]);
        }
    }

    /** @dataProvider refusedRequests */
    public function testDeniedRequestsCannotWriteDraft(string $scenario, int $expectedStatus): void
    {
        [$service, $request, $session, $formId] = $this->make('permission' !== $scenario, 'locked' === $scenario, 'create' !== $scenario);
        if ('create' === $scenario) [$service, $request, $session, $formId] = $this->make(false, false, false);
        $key = 'mautic.form.'.$formId.'.fields.modified';
        if ('csrf' === $scenario) $request->request->set('_token', 'invalid');
        if ('generation' === $scenario) $request->request->set('generation', 'stale');
        if ('other-form' === $scenario) $session->set($key, [3 => ['id' => 3, 'formId' => '99']]);
        if ('foreign-entity' === $scenario) $session->set($key, [99 => ['id' => 99, 'formId' => $formId]]);
        if ('cancelled' === $scenario) $session->remove($key);
        $before = $session->all();
        try { $service->check($request); self::fail('Request should be refused'); }
        catch (DuplicationException $error) { self::assertSame($expectedStatus, $error->status); }
        self::assertSame($before, $session->all());
    }

    public function refusedRequests(): array
    {
        return [['permission', 403], ['create', 403], ['locked', 423], ['csrf', 403], ['generation', 409], ['other-form', 404], ['foreign-entity', 404], ['cancelled', 409]];
    }
}
