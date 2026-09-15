<?php

namespace App\Services\Council;

/*
 * Strict output schemas for the two chairman calls, derived from the prompts
 * in CouncilService (framingSystem, verdictSystem) and from what
 * normalizeHypotheses and assembleVerdict read. Every object requires all of
 * its properties and forbids extra ones, which is what OpenAI-family strict
 * mode demands. Arrays may be empty because the narration contract allows
 * it: a verdict with no findings is honest, not invalid. Non-empty
 * hypotheses stay a local check in normalizeHypotheses, since minItems is
 * outside the strict-mode subset on some deployments.
 */
final class CouncilSchemas
{
    public const FRAME_KEYS = ['claims', 'hypotheses', 'open_questions'];

    public const VERDICT_KEYS = ['accepted', 'confidence', 'summary', 'recommended_plan', 'findings', 'supported_hypotheses', 'weak_hypotheses', 'defeated', 'dissents', 'proposed_discriminators', 'risks'];

    public const CONFIDENCE = ['high', 'medium', 'low', 'none'];

    /**
     * @return array<string, mixed>
     */
    public static function frame(): array
    {
        return self::object([
            'claims' => ['type' => 'array', 'items' => self::object(['id' => ['type' => 'string'], 'text' => ['type' => 'string']])],
            'hypotheses' => ['type' => 'array', 'items' => self::object([
                'id' => ['type' => 'string'],
                'statement' => ['type' => 'string'],
                'kill_conditions' => self::strings(),
            ])],
            'open_questions' => self::strings(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function verdict(): array
    {
        return self::object([
            'accepted' => ['type' => 'boolean'],
            'confidence' => ['type' => 'string', 'enum' => self::CONFIDENCE],
            'summary' => ['type' => 'string'],
            'recommended_plan' => self::strings(),
            'findings' => self::strings(),
            'supported_hypotheses' => self::strings(),
            'weak_hypotheses' => self::strings(),
            'defeated' => self::strings(),
            'dissents' => self::strings(),
            'proposed_discriminators' => self::strings(),
            'risks' => self::strings(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @return array<string, mixed>
     */
    private static function object(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function strings(): array
    {
        return ['type' => 'array', 'items' => ['type' => 'string']];
    }
}
