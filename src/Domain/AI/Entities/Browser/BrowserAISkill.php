<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Browser;

/**
 * The names of the agent skills this package ships (`config/app/AI/Skills/Agent/Web/`), as ddd-agents names its own
 * in `AISkill::AGENT_*`. A host binds them in its `config/app/AI/skillPackages.php` and lists the sub-skills in
 * `subSkillNames` with these constants instead of the literals; the literals keep working.
 */
class BrowserAISkill
{
    /** @var string Page tools: one public page rendered to a screenshot (sub-skill a host lists in its web search skill) */
    public const string AGENT_WEB_PAGES = 'Agent.Web.Pages';

    /** @var string The interactive browser session: navigate, snapshot, click, type, tabs, screenshots */
    public const string AGENT_WEB_BROWSER = 'Agent.Web.Browser';

    /** @var string A website's design read in the browser session (sub-skill of {@see self::AGENT_WEB_BROWSER}) */
    public const string AGENT_WEB_BROWSER_DESIGN = 'Agent.Web.BrowserDesign';
}
