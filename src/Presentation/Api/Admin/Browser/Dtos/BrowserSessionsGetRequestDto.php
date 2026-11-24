<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Browser\Dtos;

use DDD\Domain\AI\Entities\Browser\Sessions\BrowserSession;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Choice;

/** A page of the browser sessions, newest first; a filter left out does not narrow */
class BrowserSessionsGetRequestDto extends RequestDto
{
    /** @var string|null Only sessions in this status */
    #[Parameter(in: Parameter::QUERY, required: false)]
    #[Choice(choices: [BrowserSession::STATUS_OPEN, BrowserSession::STATUS_CLOSED, BrowserSession::STATUS_GONE])]
    public ?string $status = null;

    /** @var int|null Only the sessions of this conversation (the root of a delegation tree) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $conversationId = null;

    /** @var int|null Only the sessions run for this account */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $accountId = null;

    /** @var int|null Sessions of the page (default 50, at most 200) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $limit = null;

    /** @var int|null Sessions skipped before the page (default 0) */
    #[Parameter(in: Parameter::QUERY, required: false)]
    public ?int $offset = null;
}
