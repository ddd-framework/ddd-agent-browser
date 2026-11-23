<?php

declare(strict_types=1);

namespace DDD\Domain\Browser\Repo\DB\Sessions;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'BrowserSessions')]
class DBBrowserSessionModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'BrowserSession';

	public const string TABLE_NAME = 'BrowserSessions';

	public const string ENTITY_CLASS = 'DDD\Domain\Browser\Entities\Sessions\BrowserSession';

	#[ORM\Column(type: 'integer')]
	public ?int $conversationId;

	#[ORM\Column(type: 'integer')]
	public ?int $agentSpaceId;

	#[ORM\Column(type: 'integer')]
	public ?int $accountId;

	#[ORM\Column(type: 'string')]
	public ?string $sessionKey;

	#[ORM\Column(type: 'string')]
	public string $status = 'OPEN';

	#[ORM\Column(type: 'string')]
	public ?string $currentUrl;

	#[ORM\Column(type: 'string')]
	public ?string $currentTitle;

	#[ORM\Column(type: 'integer')]
	public ?int $navigationCount = 0;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $lastActionAt;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $closedAt;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}