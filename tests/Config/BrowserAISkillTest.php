<?php

declare(strict_types=1);

namespace DDD\Tests\Browser\Config;

use DDD\Domain\AI\Entities\Browser\BrowserAISkill;
use DDD\Domain\AI\Services\Skills\AISkillSourcesService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/** Every skill name constant of BrowserAISkill names a skill file the package ships, and every shipped skill has one */
class BrowserAISkillTest extends TestCase
{
    public function testEveryConstantNamesAShippedSkillFile(): void
    {
        $skillRootDirectory = dirname(__DIR__, 2) . '/config/app/AI/Skills';
        $shippedSkillNames = (new AISkillSourcesService())->findDottedNamesInFolder($skillRootDirectory);
        sort($shippedSkillNames);
        $constantSkillNames = array_values((new ReflectionClass(BrowserAISkill::class))->getConstants());
        sort($constantSkillNames);
        self::assertSame($shippedSkillNames, $constantSkillNames, 'one constant per shipped skill file');
        foreach ($constantSkillNames as $constantSkillName) {
            $skillFilePath = $skillRootDirectory . '/' . str_replace('.', '/', $constantSkillName) . '.md';
            self::assertFileExists($skillFilePath, $constantSkillName . ' has its skill file');
        }
    }
}
