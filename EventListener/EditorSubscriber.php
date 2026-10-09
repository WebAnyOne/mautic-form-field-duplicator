<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomAssetsEvent;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\FormBundle\Entity\Form;
use Mautic\InstallBundle\Install\InstallService;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftAccess;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftDuplicator;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\PluginState;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class EditorSubscriber implements EventSubscriberInterface
{
    public function __construct(private PluginState $state, private InstallService $installer, private RequestStack $requests, private UserHelper $users, private CsrfTokenManagerInterface $csrf, private UrlGeneratorInterface $router, private TranslatorInterface $translator)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CoreEvents::VIEW_INJECT_CUSTOM_ASSETS => 'assets', CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => 'content'];
    }

    public function assets(CustomAssetsEvent $event): void
    {
        $request = $this->requests->getCurrentRequest();
        if ($request && str_starts_with($request->getPathInfo(), '/s/') && $this->installer->checkIfInstalled() && $this->state->isEnabled()) {
            // Outside Assets/js so the plugin is not unconditionally bundled into app.js.
            // Loaded on admin pages to support AJAX navigation into the editor.
            $event->addScript('plugins/WebAnyOneFormFieldDuplicatorBundle/Assets/library/js/duplicator.js', 'bodyClose');
        }
    }

    public function content(CustomContentEvent $event): void
    {
        if ('page.header.right' !== $event->getContext() || !$this->state->isEnabled()) {
            return;
        }
        $vars = $event->getVars();
        $vars = $vars['mauticTemplateVars'] ?? $vars;
        $view = $vars['form'] ?? null;
        $entity = $vars['activeForm'] ?? null;
        if (!$view instanceof FormView || !$entity instanceof Form || empty($vars['inBuilder']) || !isset($view['sessionId'])) {
            return;
        }
        $formId = (string) $view['sessionId']->vars['data'];
        $generation = bin2hex(random_bytes(16));
        $request = $this->requests->getCurrentRequest();
        $request->getSession()->set(DraftAccess::scopeKey($formId), ['generation' => $generation, 'entityId' => $entity->getId(), 'userId' => $this->users->getUser()->getId(), 'requests' => []]);
        $messages = [];
        foreach (['duplicate', 'mapping_removed', 'modal_open', 'error.generic'] as $key) {
            $messages[$key] = $this->translator->trans('wao.duplicator.'.$key);
        }
        $event->addTemplate('@WebAnyOneFormFieldDuplicator/editor.html.twig', ['waoConfig' => [
            'url' => $this->router->generate('wao_form_field_duplicate'),
            'formId' => $formId,
            'generation' => $generation,
            'token' => $this->csrf->getToken(DraftAccess::csrfId($formId, $generation))->getValue(),
            'supported' => array_values(array_diff(DraftDuplicator::SUPPORTED, array_keys($vars['fieldSettings'] ?? []))),
            'messages' => $messages,
        ]]);
    }
}
