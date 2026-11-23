<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Entities\Origins;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The texts a model-written URL or typed text may come from without asking the operator: what the operator wrote and
 * what tool results showed in this conversation ({@see \DDD\Domain\Browser\Services\BrowserOriginRulesService}).
 */
class BrowserOriginTexts extends ValueObject
{
    /** @var string[] The operator's messages */
    public array $operatorTexts = [];

    /** @var string[] The results of the conversation's tool calls */
    public array $resultTexts = [];

    /**
     * @param string[] $operatorTexts
     * @param string[] $resultTexts
     * @return static
     */
    public static function fromTexts(array $operatorTexts, array $resultTexts): static
    {
        $browserOriginTexts = new static();
        $browserOriginTexts->operatorTexts = array_values($operatorTexts);
        $browserOriginTexts->resultTexts = array_values($resultTexts);
        return $browserOriginTexts;
    }
}
