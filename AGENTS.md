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
