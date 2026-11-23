<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Config;

use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillSourcesService;
use PHPUnit\Framework\TestCase;

/** The module's agent skill files pass the ddd-agents skill-file lint (frontmatter parses, known keys, list shapes) */
class BrowserSkillFilesLintTest extends TestCase
{
    public function testTheSkillFilesPassTheLint(): void
    {
        $skillRootDirectory = dirname(__DIR__, 2) . '/config/app/AI/Skills';
        $skillNames = (new AISkillSourcesService())->findDottedNamesInFolder($skillRootDirectory);
        sort($skillNames);
        self::assertSame(['Browser.Design', 'Browser.Session'], $skillNames, 'the lint sees both skills');
        $skillFileProblems = (new AISkillFilesLintService())->findSkillFileProblems([$skillRootDirectory]);
        self::assertSame([], $skillFileProblems);
    }
}
