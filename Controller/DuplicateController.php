<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Controller;

use Mautic\FormBundle\Collector\MappedObjectCollectorInterface;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Helper\FormFieldHelper;
use Mautic\FormBundle\Model\FormModel;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Exception\DuplicationException;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftAccess;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftDuplicator;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\PluginState;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DuplicateController
{
    public function __construct(private PluginState $state, private DraftAccess $access, private DraftDuplicator $duplicator, private FormModel $forms, private FormFieldHelper $fieldHelper, private MappedObjectCollectorInterface $mappedFields, private TranslatorInterface $translator, private Environment $twig)
    {
    }

    public function duplicateAction(Request $request): JsonResponse
    {
        try {
            if (!$this->state->isEnabled()) {
                throw new DuplicationException('wao.duplicator.error.disabled', 403);
            }
            [$formId, $scope, $entity, $fields, $deleted] = $this->access->check($request);
            $source = (string) $request->request->get('source', '');
            $requestId = (string) $request->request->get('requestId', '');
            if (!preg_match('/^[a-f0-9]{32}$/D', $requestId)) {
                throw new DuplicationException('wao.duplicator.error.request', 400);
            }
            if (isset($scope['requests'][$requestId])) {
                $previous = $scope['requests'][$requestId];
                if ($previous['source'] !== $source || !isset($fields[$previous['response']['rootId']]) || in_array($previous['response']['rootId'], $deleted, true)) {
                    throw new DuplicationException('wao.duplicator.error.draft', 409);
                }

                return new JsonResponse($previous['response']);
            }
            $components = $this->forms->getCustomComponents();
            $formName = $entity ? $entity->generateFormName() : 'newform';
            $plan = $this->duplicator->duplicate($fields, $deleted, $source, $formId, $formName, $this->translator->trans('wao.duplicator.copy_suffix'), $components['fields']);
            $active = array_diff_key($plan['fields'], array_flip($deleted));
            // Merge native defaults for fields created in this session; preserve all native data.
            $blank = (new Field())->convertToArray();
            foreach ($active as &$field) {
                $field = array_merge($blank, $field);
            }
            unset($field);
            $root = $active[$plan['rootId']];
            $html = $this->twig->render('@MauticForm/Builder/_field_wrapper.html.twig', [
                'isConditional' => !empty($root['parent']),
                'template' => '@MauticForm/Field/'.$root['type'].'.html.twig',
                'field' => $root,
                'viewOnlyFields' => $components['viewOnlyFields'],
                'inForm' => true,
                'id' => $root['id'],
                'formId' => $formId,
                'formName' => $formName,
                'mappedFields' => $this->mappedFields->buildCollection(),
                'inBuilder' => true,
                'fields' => $this->fieldHelper->getChoiceList($components['fields']),
                'formFields' => $active,
            ]);
            $response = [
                'success' => true,
                'rootId' => $plan['rootId'],
                'fieldHtml' => $html,
                'message' => $plan['removedMappings'] ? $this->translator->trans('wao.duplicator.mapping_removed') : '',
            ];
            $scope['requests'][$requestId] = ['source' => $source, 'response' => $response];
            $scope['requests'] = array_slice($scope['requests'], -32, null, true);
            $scope['copiedIds'] = array_merge($scope['copiedIds'] ?? [], array_keys($plan['copies']));
            // PHP's native session handler serializes concurrent requests. Commit only after render.
            $session = $request->getSession();
            $session->set('mautic.form.'.$formId.'.fields.modified', $plan['fields']);
            $session->set(DraftAccess::scopeKey($formId), $scope);

            return new JsonResponse($response);
        } catch (DuplicationException $error) {
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans($error->translationKey)], $error->status);
        } catch (\Throwable) {
            // No field content, contact or response data in logs or error payloads.
            return new JsonResponse(['success' => false, 'message' => $this->translator->trans('wao.duplicator.error.generic')], 500);
        }
    }
}
