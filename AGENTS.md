# mgamadeus/ddd-browser

Browser sessions and website analysis for mgamadeus/ddd. Namespace `DDD\` (`src/`), module class
`DDD\Modules\Browser\BrowserModule`. Skill: `.claude/skills/ddd-module-browser-specialist` — load it before changing
the module.

## Layout

| Path | Owner | Content |
|---|---|---|
| `src/Domain/Browser/` | PHP | sessions (entities, DB repos), renderer contract and Argus repos, services, file sink port |
| `src/Domain/Browser/Services/Agents/`, `src/Presentation/Mcp/Client/Browser/` | PHP | the agent part: only loaded beside ddd-agents (`getNamespacesRequiringClasses`) |
| `src/Symfony/Commands/Browser/` | PHP | `app:browser:check-renderer`, `app:browser:purge-sessions` |
| `config/app/Browser/`, `config/app/AI/Skills/Browser/` | PHP | config defaults, agent skills |
| `renderer/` | renderer | the Node service (Patchright, Chrome); its own README and tests. PHP changes never touch it |

Both sides push to `main`: `git fetch && git rebase origin/main` before every push; commit only your own paths.

## Rules

- **The contract is one place:** README › "Renderer contract" and `BrowserRendererContract` (version constant,
  routes, reasons). A contract change lands in the renderer first, then in PHP; within a major it only grows. The
  release workflow fails when `CONTRACT_VERSION` differs from the release version.
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
