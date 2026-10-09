<?php

declare(strict_types=1);

namespace MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Tests\Unit;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\ORM\EntityManager;
use Mautic\FormBundle\Collection\FieldCollection;
use Mautic\FormBundle\Collector\FieldCollectorInterface;
use Mautic\FormBundle\Crate\FieldCrate;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Model\FieldModel;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Exception\DuplicationException;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\AliasGenerator;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\AttributeRewriter;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\ChoiceMaterializer;
use MauticPlugin\WebAnyOneFormFieldDuplicatorBundle\Service\DraftDuplicator;
use PHPUnit\Framework\TestCase;

final class DraftDuplicatorTest extends TestCase
{
    private function service(array $crm = []): DraftDuplicator
    {
        // Exercise native cleanAlias/generateAlias with the real MySQL keyword list.
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $em = $this->createMock(EntityManager::class);
        $em->method('getConnection')->willReturn($connection);
        $model = (new \ReflectionClass(FieldModel::class))->newInstanceWithoutConstructor();
        $property = new \ReflectionProperty(FieldModel::class, 'em');
        $property->setAccessible(true);
        $property->setValue($model, $em);
        $collector = $this->createMock(FieldCollectorInterface::class);
        $collector->method('getFields')->willReturnCallback(static fn (string $object): FieldCollection => new FieldCollection($crm[$object] ?? []));

        return new DraftDuplicator(new AliasGenerator($model), new ChoiceMaterializer($collector), new AttributeRewriter());
    }

    private function field(string $id, string $type = 'select', ?string $parent = null): array
    {
        return array_merge((new Field())->convertToArray(), [
            'id' => $id, 'formId' => '12', 'alias' => 'question_'.$id, 'label' => 'Question '.$id,
            'type' => $type, 'parent' => $parent, 'order' => 1,
            'properties' => ['list' => ['list' => [['label' => 'Un', 'value' => '1'], ['label' => 'Deux', 'value' => '2']]], 'placeholder' => 'Choisir', 'multiple' => true],
            'validation' => ['type' => 'regexp', 'pattern' => '[12]'], 'validationMessage' => 'Choix obligatoire',
            'isRequired' => true, 'saveResult' => true, 'helpMessage' => 'Aide', 'defaultValue' => '1',
            'conditions' => $parent ? ['expr' => 'in', 'any' => false, 'values' => ['1']] : [],
        ]);
    }

    private function runCopy(array $fields, string $source = '1', array $deleted = [], array $crm = [], array $custom = []): array
    {
        return $this->service($crm)->duplicate($fields, $deleted, $source, '12', 'survey', ' — copie', $custom);
    }

    public function testChoicesValidationAndIndependentEditing(): void
    {
        $original = $this->field('1');
        $result = $this->runCopy(['1' => $original]);
        $id = $result['rootId'];
        $copy = $result['fields'][$id];
        self::assertSame('Question 1 — copie', $copy['label']);
        self::assertSame($original['properties'], $copy['properties']);
        self::assertSame($original['validation'], $copy['validation']);
        foreach (['showLabel', 'isRequired', 'saveResult', 'validationMessage', 'helpMessage', 'defaultValue'] as $key) self::assertSame($original[$key], $copy[$key]);
        $result['fields'][$id]['properties']['list']['list'][0]['label'] = 'Autre';
        self::assertSame('Un', $result['fields'][1]['properties']['list']['list'][0]['label']);
        self::assertSame($original, $result['fields'][1]);
        self::assertArrayNotHasKey('uuid', $copy);
    }

    public function testConditionalTreeRemapsParentsAndKeepsExternalParent(): void
    {
        $fields = [1 => $this->field('1'), 2 => $this->field('2', 'text', '1'), 3 => $this->field('3', 'number', '2'), 4 => $this->field('4', 'text', '1')];
        foreach ($fields as &$field) { $field['mappedObject'] = 'company'; $field['mappedField'] = 'companyname'; $field['leadField'] = 'legacy'; }
        unset($field);
        $result = $this->runCopy($fields);
        $copies = array_values($result['copies']);
        self::assertCount(4, $copies);
        self::assertSame($copies[0]['id'], $copies[1]['parent']);
        self::assertSame($copies[1]['id'], $copies[2]['parent']);
        self::assertSame('Question 2', $copies[1]['label']);
        self::assertSame($fields[2]['conditions'], $copies[1]['conditions']);
        foreach ($copies as $copy) { self::assertNull($copy['mappedObject']); self::assertNull($copy['mappedField']); self::assertNull($copy['leadField']); }
        self::assertTrue($result['removedMappings']);
        $childResult = $this->runCopy($fields, '2');
        self::assertCount(2, $childResult['copies']);
        self::assertSame('1', $childResult['copies'][$childResult['rootId']]['parent']);
        self::assertSame([1, 2, 3, ...array_keys($childResult['copies']), 4], array_keys($childResult['fields']));
    }

    public function testAliasesAndTemporaryIdsAcrossRepeatedDraftCopies(): void
    {
        $field = $this->field('new'.str_repeat('a', 40));
        $field['alias'] = 'Satisfaction accueil';
        $fields = [$field['id'] => $field];
        $ids = [];
        for ($i = 1; $i <= 12; ++$i) {
            $result = $this->runCopy($fields, $field['id']);
            $copy = $result['copies'][$result['rootId']];
            if (1 === $i) self::assertSame('satisfaction_accueil_copy', $copy['alias']);
            else self::assertStringEndsWith('_copy_'.$i, $copy['alias']);
            self::assertLessThanOrEqual(25, strlen($copy['alias']));
            self::assertMatchesRegularExpression('/^new[a-f0-9]{40}$/', $copy['id']);
            $ids[] = $copy['id'];
            $fields = $result['fields'];
        }
        self::assertCount(12, array_unique($ids));
        self::assertCount(13, array_unique(array_column($fields, 'alias')));
    }

    public function testLongAliasesReserveSpaceForCopySuffix(): void
    {
        $field = $this->field('1');
        $field['alias'] = str_repeat('a', 25);
        $result = $this->runCopy([1 => $field]);
        self::assertSame(str_repeat('a', 20).'_copy', $result['copies'][$result['rootId']]['alias']);
    }

    public function testMaterializesContactAndCompanyListsIncludingBooleanZero(): void
    {
        foreach (['contact', 'company'] as $object) {
            $field = $this->field('1', 'radiogrp');
            $field['mappedObject'] = $object;
            $field['mappedField'] = 'rating';
            $field['properties']['syncList'] = true;
            $crm = [$object => [new FieldCrate('rating', 'Rating', 'boolean', ['no' => 'Non', 'yes' => 'Oui'])]];
            $result = $this->runCopy([1 => $field], '1', [], $crm);
            $copy = $result['copies'][$result['rootId']];
            self::assertSame([['value' => '0', 'label' => 'Non'], ['value' => '1', 'label' => 'Oui']], $copy['properties']['optionlist']['list']);
            self::assertFalse($copy['properties']['syncList']);
            self::assertNull($copy['mappedField']);
        }
    }

    public function testCrmLookupPreservesLabelsValuesAndOrder(): void
    {
        $field = $this->field('1');
        $field['mappedObject'] = 'contact'; $field['mappedField'] = 'rating'; $field['properties']['syncList'] = true;
        $list = [['value' => 'b', 'label' => 'Deux'], ['value' => 'a', 'label' => 'Un']];
        $result = $this->runCopy([1 => $field], '1', [], ['contact' => [new FieldCrate('rating', 'Rating', 'lookup', ['list' => $list])]]);
        self::assertSame($list, $result['copies'][$result['rootId']]['properties']['list']['list']);
    }

    public function testCustomHtmlIdsAndInternalReferences(): void
    {
        $field = $this->field('1');
        $field['inputAttributes'] = 'id="rating" aria-describedby="hint external" class="keep" style="color:red" onclick="run(\'rating\')"';
        $field['labelAttributes'] = 'id="hint" for="rating"';
        $result = $this->runCopy([1 => $field]);
        $copy = $result['copies'][$result['rootId']];
        self::assertMatchesRegularExpression('/id="rating_copy_[a-f0-9]{8}"/', $copy['inputAttributes']);
        self::assertMatchesRegularExpression('/aria-describedby="hint_copy_[a-f0-9]{8} external"/', $copy['inputAttributes']);
        self::assertStringContainsString('class="keep" style="color:red"', $copy['inputAttributes']);
        self::assertStringContainsString('run(&apos;rating&apos;)', $copy['inputAttributes']);
        self::assertSame($field, $result['fields'][1]);
    }

    public function testDeletedChildIsNotCopied(): void
    {
        $fields = [1 => $this->field('1'), 2 => $this->field('2', 'text', '1')];
        $result = $this->runCopy($fields, '1', ['2']);
        self::assertCount(1, $result['copies']);
    }

    public function testInterleavedNativeDraftKeepsVisualAndPersistedOrder(): void
    {
        $fields = [1 => $this->field('1'), 2 => $this->field('2', 'text'), 3 => $this->field('3', 'text', '1'), 4 => $this->field('4', 'button')];
        $result = $this->runCopy($fields);
        self::assertSame([1, 3, ...array_keys($result['copies']), 2, 4], array_keys($result['fields']));
        foreach ($fields as $id => $original) self::assertSame($original, $result['fields'][$id]);
    }

    /** @dataProvider unsupportedTypes */
    public function testUnsupportedDescendantRejectsWholeGroup(string $type): void
    {
        $fields = [1 => $this->field('1'), 2 => $this->field('2', $type, '1')];
        $before = $fields;
        try { $this->runCopy($fields); self::fail('Expected atomic refusal'); }
        catch (DuplicationException $error) { self::assertSame('wao.duplicator.error.type', $error->translationKey); }
        self::assertSame($before, $fields);
    }

    public function unsupportedTypes(): array
    {
        return array_map(static fn (string $type): array => [$type], ['button', 'captcha', 'sociallogin', 'pagebreak', 'unknown_plugin']);
    }

    public function testNativeTypeOverriddenByPluginIsRefused(): void
    {
        $this->expectException(DuplicationException::class);
        $this->runCopy([1 => $this->field('1')], '1', [], [], ['select' => ['template' => 'custom']]);
    }

    /** @dataProvider invalidTrees */
    public function testInvalidTreeIsRefused(array $parents): void
    {
        $fields = [];
        foreach ($parents as $id => $parent) $fields[$id] = $this->field((string) $id, 'select', $parent);
        $this->expectException(DuplicationException::class);
        $this->runCopy($fields);
    }

    public function invalidTrees(): array
    {
        return [[[1 => '2', 2 => '1']], [[1 => '1']], [[1 => '99']]];
    }

    public function testSourceFromOtherFormIsRefused(): void
    {
        $field = $this->field('1'); $field['formId'] = '99';
        $this->expectException(DuplicationException::class);
        $this->runCopy([1 => $field]);
    }
}
