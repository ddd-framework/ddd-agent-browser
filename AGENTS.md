# mgamadeus/ddd-agent-browser

Browser sessions, page tools and website analysis for mgamadeus/ddd (formerly `mgamadeus/ddd-browser`). Namespace
`DDD\` (`src/`), PHP in the AI domain, module class `DDD\Modules\AgentBrowser\AgentBrowserModule`. Skill:
`.claude/skills/ddd-module-agent-browser-specialist` — load it before changing
the module.

## Layout

| Path | Owner | Content |
|---|---|---|
| `src/Domain/AI/{Entities,Repo,Services}/Browser/` | PHP | sessions (entities, DB repos), page screenshots, renderer contract and Argus repos, services, file sink port |
| `src/Domain/AI/Entities/Browser/Agents/`, `src/Domain/AI/Services/Browser/Agents/`, `src/Presentation/Mcp/Client/Browser/` | PHP | the agent part: only loaded beside ddd-agents (`getNamespacesRequiringClasses`) |
| `src/Domain/AI/Services/Browser/PageText/` | PHP | the page reader of the web research: only loaded beside ddd-agents ≥ 0.3.1 (its `WebPageTextSource` port) |
| `src/Symfony/Commands/Browser/` | PHP | `app:agent-browser:check-renderer`, `app:agent-browser:purge-sessions` (aliases `app:browser:*`) |
| `config/app/AI/Browser/`, `config/app/AI/Skills/Agent/Web/` | PHP | config defaults (`AI.Browser.*`), agent skills `Agent.Web.Browser`, `.BrowserDesign`, `.Pages` |
| `renderer/` | renderer | the Node service (Patchright, Chrome); its own README and tests. PHP changes never touch it |

Both sides push to `main`: `git fetch && git rebase origin/main` before every push; commit only your own paths.

## Rules

- **The contract is one place:** README › "Renderer contract" and `BrowserRendererContract` (version constant,
  routes, reasons). A contract change lands in the renderer first, then in PHP; within a major it only grows. The
  release workflow fails when `CONTRACT_VERSION` differs from the release version.
- **One version everywhere:** `composer.json`, `web/package.json`, `renderer/package.json` (private, name
  `ddd-agent-browser-renderer`) and `CONTRACT_VERSION` carry the release version; the release workflow refuses a tag
  where one differs.
- **Untrusted input** (Core skill `ddd-untrusted-input-specialist`): every page string is third-party content (wrap it
  in untrusted marks in tool answers); images only through `RasterImageDecoder`; foreign file bytes only through the
  host's `BrowserFileSink` with the type from the bytes; no navigation to a non-public host; no code from the model
  (analyses are fixed renderer scripts addressed by name). `tests/UntrustedSinkScanTest.php` enforces the sink rule.
- **Agent safety:** model-written URLs and typed texts need a known origin or the operator's consent; submissions run
  only after the operator's yes, once, with the renderer's release token; interactive browsing is for staff only.
- House rules of mgamadeus/ddd: no `private`/`final`, services from the container, typed value objects instead of
  struct arrays, generated `DB*Model` files are never edited by hand, English only, no internal names in this public
  repository.

## Tests

`composer install && vendor/bin/phpunit` (no database; fakes in `tests/Fakes/`).

## Contributing: versions and commit messages

- **A pull request never sets a version.** Leave `version` in `composer.json` and in `package.json` as it is, and add
  no version heading to `CHANGELOG.md`. Write the changelog entry under `## Unreleased` at the top of the changelog
  (add the heading when it is missing).
- **The maintainer assigns the version at release:** bumps `composer.json` / `package.json`, renames `## Unreleased` to
  the new version and tags it. Versions are regular only (no pre-release suffixes).
- **Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):** `feat: …`, `fix: …`,
  `docs: …`, `refactor: …`, `test: …`, `chore: …`, optionally with a scope (`fix(chat): …`); a breaking change carries
  `!` after the type or a `BREAKING CHANGE:` footer.
- **Merging a pull request that still carries a version:** keep the version fields of `main`, move its changelog entry
  under `## Unreleased`, then release as usual.
- **Rebase on the current `main` before the merge,** so that only real code conflicts remain.
- **A breaking change is named.** Its changelog entry says what an application must change. The release makes it a
  major version (a package at 1.0 or above) or a minor version (a package still at 0.x).
- **No application terms in a package:** no application's name, and no logic that only one application has (its own
  posts, its own media screens, its own country rules). A package offers hooks; the application individualises
  through them. Review checks this.
- **Tags are never moved or deleted.** A mistake in a release is fixed by a new patch version.
