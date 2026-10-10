<?php

namespace justinholtweb\airwave\fields;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use craft\helpers\Json;
use justinholtweb\airwave\elements\Source;
use justinholtweb\airwave\models\DisplayOptions;
use justinholtweb\airwave\models\FeedFieldValue;
use justinholtweb\airwave\Plugin;
use yii\db\Schema;

/**
 * A field for choosing which feeds appear on this entry.
 *
 * Not a relation field, despite feeds being elements. A relation field would bring the whole
 * element-selection modal, relation tables and eager-loading machinery to solve "pick from the
 * eight feeds this site has", and would still not answer the question the field is really for,
 * which is *how* they should appear here. Storing ids in the field's own column costs one JSON
 * decode and keeps the display options next to the choice they apply to.
 */
class FeedField extends Field
{
    /** Whether an author may pick more than one feed. */
    public bool $allowMultiple = true;

    /** Display options applied when this field renders. */
    public array $displayOptions = [];

    public static function displayName(): string
    {
        return Craft::t('airwave', 'Feed');
    }

    public static function icon(): string
    {
        return 'rss';
    }

    public static function phpType(): string
    {
        return FeedFieldValue::class;
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_TEXT;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof FeedFieldValue) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $value = Json::decodeIfJson($value);
        }

        $ids = [];

        foreach ((array)$value as $id) {
            if (is_numeric($id) && (int)$id > 0) {
                $ids[] = (int)$id;
            }
        }

        return new FeedFieldValue(array_values(array_unique($ids)), $this->displayOptions);
    }

    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if ($value instanceof FeedFieldValue) {
            $value = $value->getSourceIds();
        }

        $ids = array_values(array_filter(array_map('intval', (array)$value)));

        return $ids ? Json::encode($ids) : null;
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element = null, bool $inline = false): string
    {
        $sources = Plugin::getInstance()->sources->getAllSources(true);

        if (!$sources) {
            return Cp::renderTemplate('airwave/_field/empty', []);
        }

        $selected = $value instanceof FeedFieldValue ? $value->getSourceIds() : [];
        $options = [];

        foreach ($sources as $source) {
            $options[] = [
                'label' => $source->getUiLabel(),
                'value' => (string)$source->id,
                // A disabled feed still renders whatever it collected before it was switched off,
                // so it is worth being able to pick, and worth being told about.
                'labelHtml' => sprintf(
                    '%s%s',
                    Craft::$app->getFormatter()->asText($source->getUiLabel()),
                    $source->enabled ? '' : ' <span class="light">(' . Craft::t('airwave', 'disabled') . ')</span>',
                ),
            ];
        }

        return Craft::$app->getView()->renderTemplate('airwave/_field/input', [
            'name' => $this->handle,
            'options' => $options,
            'values' => array_map('strval', $selected),
            'allowMultiple' => $this->allowMultiple,
            'id' => $this->getInputId(),
        ]);
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('airwave/_field/settings', [
            'field' => $this,
            'options' => DisplayOptions::fromArray($this->displayOptions),
            'layouts' => DisplayOptions::LAYOUTS,
            'contentModes' => DisplayOptions::CONTENT_MODES,
        ]);
    }

    public function getElementValidationRules(): array
    {
        return [
            [
                'validateSelection',
                'on' => [Element::SCENARIO_LIVE, Element::SCENARIO_DEFAULT],
            ],
        ];
    }

    public function validateSelection(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->handle);

        if ($value instanceof FeedFieldValue && !$this->allowMultiple && count($value) > 1) {
            $element->addError($this->handle, Craft::t('airwave', 'Only one feed can be chosen here.'));
        }
    }

    protected function searchKeywords(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof FeedFieldValue) {
            return '';
        }

        return implode(' ', array_map(fn(Source $source) => $source->getUiLabel(), $value->getSources()));
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof FeedFieldValue || $value->isEmpty()) {
            return '';
        }

        return Craft::$app->getFormatter()->asText(implode(', ', array_map(
            fn(Source $source) => $source->getUiLabel(),
            $value->getSources(),
        )));
    }
}
