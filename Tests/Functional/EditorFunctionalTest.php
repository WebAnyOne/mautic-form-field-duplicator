<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Entity\Form;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Model\FormModel;
use Mautic\PluginBundle\Facade\ReloadFacade;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftAccess;
use Symfony\Component\DomCrawler\Crawler;

final class EditorFunctionalTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(ReloadFacade::class)->reloadPlugins();
        $integration = static::getContainer()->get(IntegrationHelper::class)->getIntegrationObject('FormFieldDuplicator');
        self::assertNotNull($integration);
        $settings = $integration->getIntegrationSettings();
        $settings->setIsPublished(true);
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function editor(int $id = 0): array
    {
        $crawler = $this->client->request('GET', $id ? '/s/forms/edit/'.$id : '/s/forms/new');
        self::assertTrue($this->client->getResponse()->isOk());
        self::assertCount(1, $crawler->filter('#wao-duplicator-config'), 'Native custom content hook must publish the editor scope');
        $config = json_decode($crawler->filter('#wao-duplicator-config')->attr('data-config'), true);

        return [$crawler, $config];
    }

    private function field(string $id, string $type, string $alias, ?string $parent = null): array
    {
        $field = array_merge((new Field())->convertToArray(), [
            'id' => $id, 'type' => $type, 'alias' => $alias, 'label' => ucfirst($alias),
            'parent' => $parent, 'order' => 1, 'properties' => ['list' => ['list' => [['label' => 'Un', 'value' => '1'], ['label' => 'Deux', 'value' => '2']]]],
            'conditions' => $parent ? ['expr' => 'in', 'any' => false, 'values' => ['1']] : [],
        ]);
        unset($field['form']);

        return $field;
    }

    private function fixture(string $type): Form
    {
        $form = new Form();
        $form->setName('Duplicator '.$type); $form->setAlias('duplicator');
        $form->setFormType($type); $form->setIsPublished(true); $form->setInKioskMode(true);
        $form->setPostAction('message'); $form->setPostActionProperty('Merci');
        $ids = array_map(static fn (int $i): string => 'new'.str_repeat((string) $i, 40), [1, 2, 3]);
        $fields = [
            $ids[0] => $this->field($ids[0], 'select', 'rating'),
            $ids[1] => $this->field($ids[1], 'text', 'detail', $ids[0]),
            $ids[2] => $this->field($ids[2], 'button', 'submit'),
        ];
        $model = static::getContainer()->get(FormModel::class);
        $model->setFields($form, $fields);
        $model->saveEntity($form);
        $id = $form->getId();
        $this->em->clear();

        return $this->em->find(Form::class, $id);
    }

    private function requestCopy(array $config, string $source, array $override = []): array
    {
        $this->setCsrfHeader();
        $data = array_merge(['formId' => $config['formId'], 'generation' => $config['generation'], '_token' => $config['token'], 'source' => $source, 'requestId' => bin2hex(random_bytes(16))], $override);
        $this->client->request('POST', $config['url'], $data, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('success', $response, substr($this->client->getResponse()->getContent(), 0, 1500));

        return $response;
    }

    private function publicSubmit(int $id, array $values): void
    {
        $crawler = $this->client->request('GET', '/form/'.$id);
        self::assertTrue($this->client->getResponse()->isOk());
        $htmlForm = $crawler->filter('form[data-mautic-form]')->form();
        $htmlForm->setValues($values);
        $this->client->submit($htmlForm);
        self::assertTrue($this->client->getResponse()->isOk());
    }

    /** @dataProvider formTypes */
    public function testSaveReopenIndependentSubmissionsAndHistoricalResults(string $type): void
    {
        $form = $this->fixture($type);
        $id = $form->getId();
        $source = (string) $form->getFields()->first()->getId();
        $this->publicSubmit($id, ['mauticform[rating]' => '1', 'mauticform[detail]' => 'Historique']);
        $rowsBefore = $this->em->getRepository(Submission::class)->findBy(['form' => $id]);
        self::assertCount(1, $rowsBefore);
        $historicId = $rowsBefore[0]->getId();
        $historic = $this->em->getRepository(Submission::class)->getEntity($historicId)->getResults();
        self::assertSame('Historique', $historic['detail']);
        [$crawler, $config] = $this->editor($id);
        $result = $this->requestCopy($config, $source);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($result));
        self::assertStringContainsString('rating_copy', $result['fieldHtml']);
        self::assertStringContainsString('detail_copy', $result['fieldHtml']);
        self::assertCount(5, $this->client->getRequest()->getSession()->get('mautic.form.'.$id.'.fields.modified'));
        self::assertCount(3, $this->em->getRepository(Field::class)->findBy(['form' => $id]), 'Copies must remain unpersisted');
        $nativeForm = $crawler->selectButton('mauticform_buttons_save')->form();
        $savedResponse = $this->client->submit($nativeForm);
        self::assertTrue($this->client->getResponse()->isOk());
        self::assertCount(5, $this->em->getRepository(Field::class)->findBy(['form' => $id]), $savedResponse->filter('.has-error, .alert')->each(static fn ($node) => $node->text()) ? implode(' / ', $savedResponse->filter('.has-error, .alert')->each(static fn ($node) => $node->text())) : json_encode($this->client->getRequest()->request->all()));
        [$reopened] = $this->editor($id);
        self::assertStringContainsString('rating_copy', $reopened->html());
        $this->em->clear();
        $saved = $this->em->find(Form::class, $id);
        $savedFields = array_values($saved->getFields()->toArray());
        self::assertCount(5, $savedFields);
        $byAlias = []; foreach ($savedFields as $field) $byAlias[$field->getAlias()] = $field;
        self::assertSame((string) $byAlias['rating_copy']->getId(), $byAlias['detail_copy']->getParent());
        self::assertNull($byAlias['rating_copy']->getMappedField());
        $public = $this->client->request('GET', '/form/'.$id);
        self::assertCount(1, $public->filter('[data-mautic-form-show-on="rating_copy:1"]'), implode(' / ', $public->filter('[data-mautic-form-show-on]')->each(static fn ($node) => $node->attr('data-mautic-form-show-on'))));
        $this->publicSubmit($id, ['mauticform[rating]' => '2', 'mauticform[detail]' => '', 'mauticform[rating_copy]' => '1', 'mauticform[detail_copy]' => 'Nouvelle réponse']);
        $this->em->clear();
        $submissions = $this->em->getRepository(Submission::class)->findBy(['form' => $id], ['id' => 'ASC']);
        self::assertCount(2, $submissions);
        foreach ($submissions as &$submission) $submission = $this->em->getRepository(Submission::class)->getEntity($submission->getId());
        unset($submission);
        self::assertSame($historicId, $submissions[0]->getId());
        // New columns are null on old rows; every historical answer stays intact.
        foreach ($historic as $key => $value) self::assertSame($value, $submissions[0]->getResults()[$key]);
        self::assertSame('2', $submissions[1]->getResults()['rating']);
        self::assertSame('1', $submissions[1]->getResults()['rating_copy']);
        self::assertSame('Nouvelle réponse', $submissions[1]->getResults()['detail_copy']);
        $integration = static::getContainer()->get(IntegrationHelper::class)->getIntegrationObject('FormFieldDuplicator');
        $integration->getIntegrationSettings()->setIsPublished(false);
        $settings = $this->em->find(Integration::class, $integration->getIntegrationSettings()->getId());
        $settings->setIsPublished(false);
        $this->em->persist($settings); $this->em->flush();
        $disabledEditor = $this->client->request('GET', '/s/forms/edit/'.$id);
        self::assertCount(0, $disabledEditor->filter('#wao-duplicator-config'));
        $this->publicSubmit($id, ['mauticform[rating]' => '1', 'mauticform[detail]' => 'Source', 'mauticform[rating_copy]' => '2', 'mauticform[detail_copy]' => '']);
        self::assertCount(3, $this->em->getRepository(Submission::class)->findBy(['form' => $id]));
    }

    public function formTypes(): array
    {
        return [['standalone'], ['campaign']];
    }

    public function testNewDraftNativeFieldCreationRetryAndCancel(): void
    {
        [$crawler, $config] = $this->editor();
        $url = $this->router->generate('mautic_formfield_action', ['objectAction' => 'new', 'type' => 'text', 'formId' => $config['formId']]);
        $modal = $this->client->request('GET', $url, [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $json = json_decode($this->client->getResponse()->getContent(), true);
        $modal = new Crawler($json['newContent'], 'https://localhost');
        $fieldForm = $modal->selectButton('formfield_buttons_save')->form();
        $fieldForm['formfield[label]'] = 'Question brouillon';
        $this->setCsrfHeader();
        $this->client->submit($fieldForm, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $created = json_decode($this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('success', $created, substr($this->client->getResponse()->getContent(), 0, 1500));
        self::assertSame(1, $created['success']);
        $requestId = bin2hex(random_bytes(16));
        $first = $this->requestCopy($config, $created['fieldId'], ['requestId' => $requestId]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($first));
        $again = $this->requestCopy($config, $created['fieldId'], ['requestId' => $requestId]);
        self::assertSame($first['rootId'], $again['rootId']);
        $cancel = $crawler->selectButton('mauticform_buttons_cancel')->form();
        $this->client->submit($cancel);
        self::assertSame([], $this->em->getRepository(Form::class)->findAll());
        $this->requestCopy($config, $created['fieldId']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
    }

    public function testRealContactAndCompanyCollectorsMaterializeChoicesAndRemoveMappings(): void
    {
        $lists = [
            'contact' => [['label' => 'Accueil', 'value' => 'a'], ['label' => 'Salle', 'value' => 's']],
            'company' => [['label' => 'Petit', 'value' => 'p'], ['label' => 'Grand', 'value' => 'g']],
        ];
        foreach ($lists as $object => $list) {
            $crm = new \Mautic\LeadBundle\Entity\LeadField();
            $crm->setLabel('Liste '.$object); $crm->setAlias('wao_'.$object);
            $crm->setType('select'); $crm->setObject('contact' === $object ? 'lead' : 'company');
            $crm->setProperties(['list' => $list]); $crm->setIsPublished(true);
            static::getContainer()->get('mautic.lead.model.field')->saveEntity($crm);
        }
        $form = new Form();
        $form->setName('CRM choices'); $form->setAlias('crmchoices');
        $form->setFormType('standalone'); $form->setIsPublished(true);
        $form->setPostAction('message'); $form->setPostActionProperty('Merci');
        $root = 'new'.sha1('contact'); $child = 'new'.sha1('company');
        $draft = [];
        foreach (['contact' => $root, 'company' => $child] as $object => $id) {
            $field = $this->field($id, 'select', 'contact' === $object ? 'rating' : 'detail', 'company' === $object ? $root : null);
            $field['mappedObject'] = $object; $field['mappedField'] = 'wao_'.$object;
            $field['properties']['syncList'] = true;
            $draft[$id] = $field;
        }
        $model = static::getContainer()->get(FormModel::class);
        $model->setFields($form, $draft); $model->saveEntity($form);
        $formId = $form->getId(); $this->em->clear();
        $saved = $this->em->find(Form::class, $formId);
        $source = (string) $saved->getFields()->first()->getId();
        [$crawler, $config] = $this->editor($formId);
        $response = $this->requestCopy($config, $source);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), json_encode($response));
        self::assertNotEmpty($response['message']);
        $fields = $this->client->getRequest()->getSession()->get('mautic.form.'.$formId.'.fields.modified');
        $copies = array_values(array_filter($fields, static fn (array $f): bool => str_ends_with($f['alias'], '_copy')));
        self::assertCount(2, $copies);
        foreach ($copies as $i => $copy) {
            self::assertNull($copy['mappedObject']); self::assertNull($copy['mappedField']);
            self::assertFalse($copy['properties']['syncList']);
            self::assertSame(array_column(array_values($lists)[$i], 'label'), array_column($copy['properties']['list']['list'], 'label'));
            self::assertSame(array_column(array_values($lists)[$i], 'value'), array_column($copy['properties']['list']['list'], 'value'));
        }
        self::assertSame('wao_contact', $fields[$source]['mappedField']);
        $this->client->submit($crawler->selectButton('mauticform_buttons_save')->form());
        self::assertTrue($this->client->getResponse()->isOk());
        $public = $this->client->request('GET', '/form/'.$formId);
        self::assertTrue($this->client->getResponse()->isOk());
        self::assertSame(['', 'a', 's'], $public->filter('select[name="mauticform[rating_copy]"] option')->each(static fn ($n) => $n->attr('value')));
        self::assertSame(['', 'p', 'g'], $public->filter('select[name="mauticform[detail_copy]"] option')->each(static fn ($n) => $n->attr('value')));
    }

    public function testEverySupportedNativeTypeRendersSavesAndReopens(): void
    {
        $form = new Form();
        $form->setName('Native types'); $form->setAlias('nativetypes');
        $form->setFormType('standalone'); $form->setIsPublished(true); $form->setInKioskMode(true);
        $form->setPostAction('message'); $form->setPostActionProperty('Merci');
        $draft = [];
        foreach (\MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftDuplicator::SUPPORTED as $i => $type) {
            $id = 'new'.sha1($type);
            $field = $this->field($id, $type, 'q'.($i + 1));
            $field['order'] = $i + 1;
            $field['properties']['optionlist'] = $field['properties']['list'];
            $field['properties']['text'] = '<p>Description native</p>';
            $draft[$id] = $field;
        }
        $id = 'new'.sha1('submit');
        $draft[$id] = $this->field($id, 'button', 'submit');
        $model = static::getContainer()->get(FormModel::class);
        $model->setFields($form, $draft); $model->saveEntity($form);
        $formId = $form->getId(); $this->em->clear();
        $saved = $this->em->find(Form::class, $formId);
        $sources = [];
        foreach ($saved->getFields() as $field) {
            if ('button' !== $field->getType()) $sources[$field->getType()] = (string) $field->getId();
        }
        [$crawler, $config] = $this->editor($formId);
        foreach ($sources as $type => $source) {
            $response = $this->requestCopy($config, $source);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), $type.': '.json_encode($response));
            self::assertStringContainsString('form-field-wrapper', $response['fieldHtml']);
        }
        $this->client->submit($crawler->selectButton('mauticform_buttons_save')->form());
        self::assertTrue($this->client->getResponse()->isOk());
        $this->em->clear();
        $saved = $this->em->find(Form::class, $formId);
        self::assertCount(37, $saved->getFields());
        $aliases = [];
        foreach ($saved->getFields() as $field) $aliases[] = $field->getAlias();
        self::assertCount(37, array_unique($aliases));
        [$reopened] = $this->editor($formId);
        self::assertCount(37, $reopened->filter('.form-field-wrapper'));
        $public = $this->client->request('GET', '/form/'.$formId);
        self::assertTrue($this->client->getResponse()->isOk());
        self::assertCount(1, $public->filter('form[data-mautic-form]'));
    }

    public function testCsrfWrongSourceOldTabAndExcludedType(): void
    {
        $form = $this->fixture('standalone');
        [$crawler, $config] = $this->editor($form->getId());
        $source = (string) $form->getFields()->first()->getId();
        $this->requestCopy($config, $source, ['_token' => 'invalid']);
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->requestCopy($config, '9999999');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
        $button = (string) $form->getFields()->last()->getId();
        $this->requestCopy($config, $button);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $this->editor($form->getId());
        $this->requestCopy($config, $source);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertCount(3, $this->em->getRepository(Field::class)->findBy(['form' => $form->getId()]));
    }
}
