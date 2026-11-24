<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser\Files;

use DDD\Domain\Base\Entities\ValueObject;

/** Whose files these are: the conversation, agent space, account and workspace a sink files them under */
class BrowserFileSinkContext extends ValueObject
{
    public ?int $conversationId = null;

    public ?int $agentSpaceId = null;

    public ?int $accountId = null;

    /** @var string|null The sandbox workspace of the call, when the host has one */
    public ?string $workspaceKey = null;

    /** @var string|null The tool that produced the file */
    public ?string $toolName = null;
}
