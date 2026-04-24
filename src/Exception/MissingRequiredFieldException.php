<?php

declare(strict_types=1);

namespace BetterData\Exception;

final class MissingRequiredFieldException extends DataObjectException
{
    public static function for(string $dataObjectClass, string $fieldName): self
    {
        return new self(
            sprintf(
                'Missing required field "%s" when hydrating %s.%s',
                $fieldName,
                $dataObjectClass,
                self::hintForOptionalBeforeRequired($dataObjectClass, $fieldName),
            ),
            $dataObjectClass,
            $fieldName,
        );
    }

    /**
     * PHP's "optional-before-required" rule silently demotes a
     * default-bearing param to required when any LATER param has no
     * default. Reflection agrees with PHP, so the generic message is
     * technically accurate but misleading for a developer staring at a
     * `$field = 0` default in the source. If the class file has a
     * literal `= ...` after the named param but reflection still marks
     * it required, surface a hint pointing to the real cause.
     */
    private static function hintForOptionalBeforeRequired(string $dataObjectClass, string $fieldName): string
    {
        try {
            $reflection = new \ReflectionClass($dataObjectClass);
        } catch (\ReflectionException) {
            return '';
        }

        $file = $reflection->getFileName();
        if ($file === false || !is_readable($file)) {
            return '';
        }
        $source = @file_get_contents($file);
        if ($source === false) {
            return '';
        }

        // Look for "$fieldName = " pattern inside the constructor signature.
        // False positive risk is low — worst case we emit a stray hint.
        $pattern = '/\$' . preg_quote($fieldName, '/') . '\s*=\s*[^,)]+/';
        if (preg_match($pattern, $source) !== 1) {
            return '';
        }

        return sprintf(
            "\nHint: the source declares a default for \"%s\" but PHP is treating it as required — "
            . 'a LATER constructor parameter has no default, which silently demotes every earlier '
            . "default (PHP's optional-before-required rule). Give every trailing constructor "
            . 'parameter a default value.',
            $fieldName,
        );
    }
}
