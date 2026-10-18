# Development and verification

Requirements: Node 22.12+ (tested on 24), pnpm 10.33.0, PHP 8.4+ with PDO MySQL/cURL, local MariaDB, Chromium for UI tests. Use the existing checkout; no worktree is necessary. pnpm lockfiles are authoritative.

```bash
pnpm install --frozen-lockfile
pnpm --dir frontend install --frozen-lockfile
pnpm lint
pnpm build
```

For development, copy `backend/config.sample.php` to ignored `backend/config.php`, set a local domain and disposable/local database, and initialize it with `bin/migrate.php --apply`. No Steam key is needed to view the anonymous page. Authenticated development requires Steam or the isolated test harness. `pnpm dev` starts PHP and Vite. API calls use Vite's same-origin `/api` proxy. Do not put a Steam key into any `VITE_*` variable.

## Integration and browser suite

```bash
pnpm exec playwright install chromium
CLOUDHUB_TEST_SOCKET=/workspace/.cloud-tools/test-mysql.sock CLOUDHUB_TEST_DB_USER=your_local_user pnpm test
```

The test user must authenticate through that local socket and be allowed to create/drop databases named `cloudhub_test_*`. The harness refuses to start without an explicit local socket. It generates an isolated config, creates a random temporary database and session directory, starts PHP on 8082/8083 and Vite on 5175, then removes its own database and files at shutdown. No production config is used. Synthetic sessions are made by CLI code outside the public release; there is no HTTP login bypass.

An existing Chromium can be selected with `CHROMIUM_PATH=/absolute/path/to/chromium`. For the Codex environment:

```bash
. /workspace/.cloud-tools/activate
bash scripts/start-test-db.sh # separate terminal; foreground, isolated, no TCP
CLOUDHUB_TEST_SOCKET=/workspace/.cloud-tools/test-mysql.sock CLOUDHUB_TEST_DB_USER=agent CHROMIUM_PATH=/workspace/.cloud-tools/sysroot/usr/lib/chromium/chromium pnpm test
```

The suite covers anonymous access, CSRF, origin and HTTP-method checks, input validation, skin/sticker/charm serialization, team/account isolation, knives/gloves/agents/music/pins, private/public loadouts, copy/apply/delete, forged Steam callbacks, the login page, desktop/mobile navigation, filtering, a real editor save, and visible errors without losing the editor. Screenshots and traces are written to ignored `test-results/`.

## Production assets and package

```bash
pnpm assets:prepare
pnpm release
```

Asset preparation checks out a pinned upstream revision into ignored `plugin/`, verifies its commit, and generates WebP files and an image manifest. An existing reference checkout can be supplied with `--source /path/to/cs2-WeaponPaints`. Approximately 2 GB of upstream source images are reduced to about 190 MB in the generated catalogue. Generated files are excluded from Git but included in the release. Ordinary development can fall back to original remote image URLs when no manifest is present.

`pnpm release` requires the prepared image manifest, builds both parts, excludes private runtime files, and creates `releases/cloudhub-cs2-1.0.0.tar.gz` with checksums and deployment instructions. PHP files can be checked with:

```bash
find backend bin -name '*.php' -print0 | xargs -0 -n1 php -l
```

Actual Steam acceptance and in-game synchronization cannot be proven with synthetic sessions; perform the deployment checks on your own account after the separately approved installation.

## Stage 2: explicit isolated tests

The automatic Codex preparation script does not start a database or migrate SQL. `scripts/start-test-db.sh` is a separate, manual test helper: creates only the isolated MariaDB system tables under `/workspace/.cloud-tools/test-mysql` and runs it with `--skip-networking`, `--no-defaults` and its own Unix socket. It never reads app config or production DB variables. Keep it running in another terminal while invoking `pnpm test`. Stop that process with Ctrl-C after tests. The integration harness itself only migrates and drops its own random `cloudhub_test_*` schema; the socket must be under `/workspace`.

C# tests: `dotnet run --project server/CloudHub.Core.Tests -c Release -- tests/protocol-vector.json` from the root after activating the tools. Plugin build: `dotnet build server/CloudHub.Plugin -c Release --no-restore`. The automatic setup restores existing projects in locked mode, never starts CS2 or loads the plugin. See [CORE_STAGE2.pl.md](CORE_STAGE2.pl.md) for rules, HMAC and staging/rollback boundaries.


## Stage 3: community and OWNER

At Stage 3 the same `pnpm test` command ran 134 PHP assertions (foundations, rounds, community, admin) and 19 API/browser tests; Stage 4 totals are below. The harness enables CC adjustments only in its generated disposable configuration. No production configuration is read. `tests/admin-worker.php` is CLI-only test support outside the public release; independent workers verify concurrent idempotency and balance locking. An injected audit trigger failure verifies full rollback. Browser tests cover a committed operation with a lost response and reload/retry using the same UUID.

Reports and manual staging/OWNER instructions: [COMMUNITY_STAGE3_TEST_REPORT.md](COMMUNITY_STAGE3_TEST_REPORT.md), [COMMUNITY_STAGE3.pl.md](COMMUNITY_STAGE3.pl.md). No new migration is needed for Stage 3. The production sample leaves `CLOUDHUB_ADMIN_CC_ENABLED=false`.

## Etap 4 — społeczność

Pełne `pnpm test` uruchamia także `tests/social.php` (68 asercji Codexa, rozszerzone o asercje poprawek z przeglądu PR #6) i `tests/social.spec.js`. Aktualną liczbę asercji wypisuje proces na stderr (`CloudHub social assertions passed: N`). Wymaga tej samej izolowanej MariaDB przez socket. Migracja 003 wykonywana wyłącznie w losowej bazie harnessu; flaga społeczności jest włączona w konfiguracji testowej. Dotychczasowe serwisowe testy PHP sprawdzają również fallback z wyłączoną flagą, a API/UI działają z nią włączoną.

Testy obejmują rzeczywiste równoległe procesy, rollback audytu, prywatność, role/granty, CSRF/XSS/IDOR, limity, paginację i interfejs mobilny. Poprawki z przeglądu dodają: filtr blokad w rankingu, neutralny status `unavailable`, relacje w wierszach list (UI sprawdza brak żądań `relation` na `/friends`), odznaki autorów z prywatnością, `read_all`, zakaz zgłaszania własnego komentarza, znaczniki UTC przy strefie sesji `+05:00`, 503 przy zajętym `GET_LOCK`, reset okna limitu, degradację do Etapu 3 bez wpisu migracji 003 oraz brak natywnych dialogów w UI.

Na Windows bez WSL/Dockera harness nie działa, bo wymaga MariaDB przez unix socket. Można tam uruchomić tylko `pnpm lint`, `pnpm build` i `php -l`. Pliki `*.sql` mają w `.gitattributes` `eol=lf`, więc checksumy migracji są identyczne na każdym checkoucie. Raport wyników: [COMMUNITY_STAGE4_TEST_REPORT.md](COMMUNITY_STAGE4_TEST_REPORT.md). Procedura ręcznej weryfikacji stagingu i rollback: [COMMUNITY_STAGE4.pl.md](COMMUNITY_STAGE4.pl.md). Nie uruchamiać harnessu na bazie produkcyjnej.
