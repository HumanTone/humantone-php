<?php

declare(strict_types=1);

namespace HumanTone\Internal;

use HumanTone\Enums\HumanizationLevel;
use HumanTone\Enums\OutputFormat;

final class RequestBodyBuilder
{
    /**
     * Builds the JSON body for POST /v1/humanize.
     *
     * Performs the SDK→API renames per §4.3 / §6.4:
     *   - text parameter → "content" field
     *   - HumanizationLevel enum → "humanization_level" string
     *   - OutputFormat enum → "output_format" string (SDK default Text overrides
     *     the API default Html — text is always sent on the wire when default)
     *   - customInstructions → "custom_instructions"; omitted entirely when null
     *
     * @return array<string, string>
     */
    public static function humanize(
        string $text,
        HumanizationLevel $level,
        OutputFormat $outputFormat,
        ?string $customInstructions,
    ): array {
        $body = [
            'content' => $text,
            'humanization_level' => $level->value,
            'output_format' => $outputFormat->value,
        ];
        if ($customInstructions !== null) {
            $body['custom_instructions'] = $customInstructions;
        }
        return $body;
    }

    /**
     * Builds the JSON body for POST /v1/detect.
     *
     * Same field rename: SDK $text parameter → "content" field per §4.4.
     *
     * @return array<string, string>
     */
    public static function detect(string $text): array
    {
        return ['content' => $text];
    }
}
