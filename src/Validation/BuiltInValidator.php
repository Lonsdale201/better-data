<?php

declare(strict_types=1);

namespace BetterData\Validation;

use BetterData\DataObject;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Default validation engine.
 *
 * Walks the DataObject constructor parameter list, collects every
 * attribute that implements `Rule`, runs each against the currently-held
 * property value, and aggregates results.
 *
 * Recurses into nested DataObject values (including lists of DataObjects),
 * prefixing error paths with `parentField.childField` /
 * `parentField[index].childField` dot-notation.
 *
 * Short-circuit: rules are run in declaration order, but a field's
 * remaining rules are skipped once one fails, to avoid cascading noise
 * (e.g. a Required failure shouldn't also produce an Email failure).
 */
final class BuiltInValidator implements ValidationEngineInterface
{
    public function validate(DataObject $subject): ValidationResult
    {
        $errors = [];
        $this->collect($subject, '', $errors);

        return new ValidationResult($errors);
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function collect(DataObject $subject, string $prefix, array &$errors): void
    {
        $reflection = new ReflectionClass($subject);
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $parameter) {
            $fieldName = $parameter->getName();
            $fieldPath = $prefix === '' ? $fieldName : "{$prefix}.{$fieldName}";

            $value = $subject->{$fieldName} ?? null;

            $attrs = $parameter->getAttributes(Rule::class, ReflectionAttribute::IS_INSTANCEOF);
            foreach ($attrs as $attr) {
                /** @var Rule $rule */
                $rule = $attr->newInstance();
                $message = $rule->check($value, $fieldName, $subject);
                if ($message !== null) {
                    $errors[$fieldPath][] = $message;
                    break;
                }
            }

            $this->recurse($value, $fieldPath, $errors);
        }
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function recurse(mixed $value, string $parentPath, array &$errors): void
    {
        if ($value instanceof DataObject) {
            $this->collect($value, $parentPath, $errors);

            return;
        }

        if (is_array($value)) {
            foreach ($value as $index => $item) {
                if ($item instanceof DataObject) {
                    $this->collect($item, "{$parentPath}[{$index}]", $errors);
                }
            }
        }
    }
}
