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
#[ORM\Table(name: 'BrowserSessionEvents')]
class DBBrowserSessionEventModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'BrowserSessionEvent';

	public const string TABLE_NAME = 'BrowserSessionEvents';

	public const string ENTITY_CLASS = 'DDD\Domain\Browser\Entities\Sessions\BrowserSessionEvent';

	#[ORM\Column(type: 'integer')]
	public int $browserSessionId;

	#[ORM\Column(type: 'string')]
	public string $eventType;

	#[ORM\Column(type: 'string')]
	public ?string $toolName;

	#[ORM\Column(type: 'string')]
	public ?string $url;

	#[ORM\Column(type: 'string')]
	public ?string $detail;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?DateTime $updated;

}
