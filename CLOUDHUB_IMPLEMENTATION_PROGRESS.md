# CloudHub — postęp implementacji

## Stan według nowej specyfikacji (18 października 2026)

Nowa specyfikacja: [docs/CLOUDHUB_NOWA_PELNA_SPECYFIKACJA.md](docs/CLOUDHUB_NOWA_PELNA_SPECYFIKACJA.md). Połączenie ze starymi wymaganiami i rozstrzygnięcie sprzeczności: [docs/CLOUDHUB_REQUIREMENTS.pl.md](docs/CLOUDHUB_REQUIREMENTS.pl.md), sekcja „Aktualizacja wymagań”.

| Obszar | Status | Uwagi |
|---|---|---|
| Fundamenty: Steam, konta, role, migracja 001 | GOTOWE | master, PR #1–#2 |
| Core: RP, CC, rangi, antyfarm, HMAC, outbox C# | CZĘŚCIOWO GOTOWE | Brakuje MVP i przegranej rundy, komend w grze, CI dla `dotnet test`. Nagrody do ponownego balansu (§13.1). |
| Profile, ranking, OWNER CC | GOTOWE | PR #5. Profil wymaga MVP, postępu rangi i osiągnięć (§6). |
| Społeczność | GOTOWE, WYMAGA TESTÓW na stagingu | PR #6 + #7, CI zielone, czeka na zgodę na merge do master |
| Sklep CC i ekwipunek (Etap 5) | CZĘŚCIOWO GOTOWE, WYMAGA TESTÓW | Kod, migracja 004 i testy na gałęzi `cloudhub-stage5-shop` (lokalnie). Testy na bazie i UI czekają na CI. Cennik wymaga zatwierdzenia. |
| Własność w WeaponPaints (Etap 6) | NIEZROBIONE | flaga `ownership_enforcement`, migracja posiadanych skinów, zgoda OWNER |
| Redesign | NIEZROBIONE | po sklepie i ekwipunku |
| Logo | NIEZROBIONE | wymaga zatwierdzenia propozycji |
| Stopka „Designed by RUB LUD” | NIEZROBIONE | licencja MIT sprawdzona: `LICENSE` zostaje, link wizualny może zniknąć |
| CloudHub Ranks (rozbudowa Core) | CZĘŚCIOWO GOTOWE | jw. |
| Utrata rangi przy spadku RP | ZABLOKOWANE | decyzja właściciela |
| Balans i symulacje RP/CC | NIEZROBIONE | wartości startowe z §13.1 do symulacji |
| CS2UIKit | NIEZROBIONE | wymaga weryfikacji API |
| CloudHub Desktop | NIEZROBIONE | zrobiona tylko podstrona `/download`; wymagana analiza Tauri/Electron/.NET |

## Etap 5 — sklep Cloud Coins i ekwipunek (18 października 2026)

Lokalna gałąź `cloudhub-stage5-shop` z `cloudhub-stage4-social` (`f22094b`). Bez push, merge, wdrożenia i migracji. Szczegóły: [docs/SHOP_STAGE5.pl.md](docs/SHOP_STAGE5.pl.md).

- Migracja addytywna `004_cloudhub_shop.sql`: produkty, historia cen, zakupy, ekwipunek. `wp_*` bez zmian.
- `shop.php` (katalog, ekwipunek, historia, zakup). Zakup to jedna transakcja: blokada portfela, cena z serwera, UUID, brak ujemnego salda, wpis w ledgerze z saldem przed i po.
- OWNER w `admin.php`: lista produktów, edycja ceny i aktywności z powodem i historią, statystyki ekonomii.
- UI: `/shop`, `/inventory`, sekcja „Sklep i ekonomia” w `/admin`, etykieta zakupu w portfelu, cena CC i „Posiadasz” w konfiguratorze (informacyjnie, bez blokady).
- Decyzje OWNER (18.10.2026): cennik testowy zaakceptowany, sprzedaż niezatwierdzona; M4A4 | Howl poza sprzedażą bez ceny; limit 100 CC/h bez zmian (konfigurowalny w `cloudhub_reward_rules`).
- Flagi `CLOUDHUB_SHOP_ENABLED` (podgląd) i `CLOUDHUB_SHOP_PURCHASES_ENABLED` (zakupy), obie domyślnie `false`. Personalizacja nadal 0 CC.
- Egzekwowanie własności: NIEZROBIONE, Etap 6.
- CLI `bin/cloudhub-shop-catalog.php`: propozycja 2126 produktów według oficjalnej rzadkości i faz Doppler (snapshot ByMykel/CSGO-API, MIT). Produkty bez wiarygodnych danych trafiają do weryfikacji bez ceny. Wszystko nieaktywne do zatwierdzenia. Do tego symulacja zarobków. Wniosek: `hourly_cc_cap=100` wyrównuje CC wszystkich profili do ~66 na mecz. Decyzja o balansie należy do właściciela; wartości nie zostały zmienione.
- Weryfikacja lokalna PASS: lint, build, `php -l` (52 pliki), 49 asercji pricing, `--simulate`, `git diff --check`.
- Niezweryfikowane lokalnie: `tests/shop.php` (baza), `tests/shop.spec.js` (API/UI) oraz regresja całego `pnpm test`. Wymagają CI na Linuksie, czyli push za zgodą.

## Etap 4 — społeczność (17 października 2026)

Gałąź `cloudhub-stage4-social`, baza aktualnego `master` `783223b9` po potwierdzonym scaleniu PR #5. Zaimplementowano znajomych/zaproszenia/blokady, komentarze i zgłoszenia, prywatność friends, wbudowane motywy i nagłówki, powiadomienia oraz moderację według istniejącego `moderation.manage`. Zachowano Core, WeaponPaints, role/rangi/TOP, sezony i korekty CC.

- Addytywna migracja `003_cloudhub_social.sql`: ustawienia społecznościowe, relacje, blokady, komentarze, zgłoszenia, powiadomienia, liczniki limitów. Stare ustawienia prywatności i wyłączone komentarze zachowane.
- `CLOUDHUB_SOCIAL_ENABLED=false` domyślnie; włączenie dopiero po migracji i weryfikacji osobnego stagingu. Bez produkcyjnych operacji i Merge.
- Nowe `social.php`, `CloudHubSocial`, komponenty React i strony `/friends`, `/notifications`, `/moderation`; integracja z profilem, nawigacją i panelem OWNER.
- Transakcje i wspólna blokada zapisów społeczności; blokada usuwa relację, odblokowanie jej nie odtwarza. Backend egzekwuje prywatność, CSRF, role, whitelisty pól, limity i audyt. Brak zmian rzeczywistych ról, sald lub sekretów.
- Zweryfikowano 202 asercje PHP (w tym 68 nowych), 21/21 testów API/UI, lint/build, składnię PHP, `git diff --check` i lokalną kopię Nginx. Bez testów rzeczywistego Steam, PHP 8.5 i obciążenia produkcyjnego. C# bez zmian.
- Dokumentacja: [COMMUNITY_STAGE4.pl.md](docs/COMMUNITY_STAGE4.pl.md), [raport testów](docs/COMMUNITY_STAGE4_TEST_REPORT.md). Zawierają endpointy, uprawnienia, staging, rollback i granice weryfikacji.
- Do osobnej oceny: przepustowość globalnej blokady i retencja danych. Etap 4 przygotowany do przeglądu jako domyślnie wyłączona funkcja. Właściciel decyduje o Merge.
- Następny zakres, niewykonany: Etap 5 — sklep Cloud Coins i zatwierdzona ekonomia zakupów. Własność/WeaponPaints, CS2UIKit, wdrożenia pluginów i Desktop nadal w kolejnych etapach.

### Poprawki po przeglądzie PR #6 (18 października 2026)

Lokalna gałąź `cloudhub-stage4-social-fixes`, utworzona z `cloudhub-stage4-social` (`b202a4f`). Commity Codexa bez zmian. Bez push, merge, wdrożenia i migracji.

- Moduł aktywny tylko przy fladze **i** zastosowanej migracji 003 (`CloudHubSocial::active`). Wcześniej flaga bez migracji psuła ranking i profile. `community.php` i `social.php` zamieniają nieoczekiwane błędy na 503.
- Panel OWNER nie pokazuje błędu społeczności przy wyłączonym module. `capabilities` pobierane raz na załadowanie aplikacji.
- `/friends` bez N+1: wiersze list zawierają relację, przyciski korzystają z odpowiedzi POST. Odświeżenia nie migają.
- Aplikacyjne `askConfirm` i inline powód moderacji/zgłoszenia zamiast `window.confirm/prompt`.
- Znaczniki czasu społeczności w UTC (`UTC_TIMESTAMP(6)`). Odczyt ról przy moderacji przez `LOCK IN SHARE MODE` (wystarcza samo SELECT).
- Prywatność: blokada drugiej strony widoczna jako neutralne `unavailable`. Odznaki autorów komentarzy (§15): rola zawsze, ranga/TOP zgodnie z prywatnością autora. `read_all`, brak zgłaszania własnych komentarzy.
- Testy rozszerzone (PHP i Playwright). Liczba migracji w `foundations.php` liczona z plików. `.gitattributes` wymusza LF dla `*.sql` (checksumy).
- Weryfikacja lokalna na Windows: `pnpm lint`, `pnpm build`, `php -l`, `git diff --check`. Harness `pnpm test` wymaga Linuksa, dlatego uruchamia go nowe CI (`.github/workflows/ci.yml`, `pull_request`, `contents: read`, bez sekretów).
- **CI PR #7, run `39151948726` (18 października 2026): PASS.** Job `static`: `pnpm lint`, `pnpm build`, `php -l` dla 45 plików PHP, wszystko bez błędów. Job `integration`: jednorazowa MariaDB 10.11 tylko przez socket, **224 asercje PHP** (fundamenty 31, Core 50, community 29, OWNER 24, społeczność 90) oraz **21/21 testów Playwright API/UI**, bez pominiętych i niestabilnych. Nie potwierdzono: rzeczywistego Steam OpenID, produkcyjnego FPM/HTTPS, MariaDB w wersji produkcyjnej i minimalnych grantów SQL. To zostaje na staging.
- Otwarte wymagania (§17, §21): moderacja opisów profili, blokady moderacyjne, retencja i usuwanie kont, indeks audytu/`ON DELETE` (migracja 004). Kolekcja skinów na profilu w Etapie 5. Pomiar przepustowości globalnego `GET_LOCK`.

## Etap 3 — zachowany zakres (17 października 2026)

Zaimplementowano profile, ranking WWW, niezależne odznaki ról/rang/TOP, prywatny portfel i panel OWNER na `cloudhub-stage3-profiles`, od aktualnego master `8fe59c8`. Etapy 1–2 pozostają zachowane; nie odtwarzano Core. Dokumentacja: [COMMUNITY_STAGE3.pl.md](docs/COMMUNITY_STAGE3.pl.md), wyniki: [COMMUNITY_STAGE3_TEST_REPORT.md](docs/COMMUNITY_STAGE3_TEST_REPORT.md).

- Publiczne profile SteamID64 i edycja wyłącznie własnego opisu/prywatności; statystyki globalne i historia sezonów.
- Ranking globalny/sezonowy, TOP 10/50/100 z paginacją, wyszukiwaniem i sortowaniem; deterministyczny remis RP → kills → SteamID.
- Oddzielne odznaki OWNER/ADMIN/MODERATOR, Bronze–Master i TOP. Profil prywatny znika z publicznej wyszukiwarki, zachowując rzeczywiste pozycje pozostałych osób.
- Prywatne saldo, historia transakcji i nagrody z istniejącej księgi Etapu 2.
- OWNER: wyszukiwanie graczy, statystyki, salda, atomowe korekty CC z powodem, CSRF, idempotencją, audytem i blokadą ujemnego salda. UI zachowuje niepotwierdzoną operację po odświeżeniu karty.
- Progi rang i punktacja dostępne tylko do odczytu; edycja WWW wyłączona do opracowania wersjonowania reguł i ich aktywacji.
- Nie jest potrzebna nowa migracja. Nie zmieniano migracji 001/002, danych produkcji, konfiguratora ani pluginów. Bez wdrożenia i bez automatycznego merge.
- `CLOUDHUB_ADMIN_CC_ENABLED=false` domyślnie. SteamID rzeczywistego właściciela nie jest znany; nie nadano mu roli. Udokumentowano istniejący bootstrap CLI po zweryfikowanym logowaniu Steam.
- Weryfikacja: **134 asercje PHP, 19 testów API/UI**, frontend lint/build, PHP lint i lokalny Nginx syntax PASS. Nie testowano produkcyjnego obciążenia, PHP 8.5 ani prawdziwego logowania Steam.
- Moduły zapisane i wypchnięte kolejno: `a85cdb3` profile/ranking, `e4b2a90` OWNER/CC, `7b0864a` interfejs i regresja; osobny commit dokumentacji.

Kolejne etapy według aktualnego zlecenia: 4 — znajomi/komentarze, 5 — sklep CC, 6 — egzekwowanie własności WeaponPaints, 7 — CS2UIKit, 8 — osobno zatwierdzone wdrożenie pluginu, 9 — Windows. Poniższe sekcje zachowują historię wcześniejszego planu; ich dawna numeracja nie rozszerza zakresu Etapu 3.

## Historia — Etap 1

Etap 1: audyt i fundamenty, gałąź `cloudhub-foundations`, na bazie połączonego PR #1 (`6b7e432`). Oryginalna wersja CloudHub CS2 (`f02bbf1`) jest zachowana. Pełne wymagania: [docs/CLOUDHUB_REQUIREMENTS.pl.md](docs/CLOUDHUB_REQUIREMENTS.pl.md).

## Wykonano

- Audyt React/Vite, PHP, przepływu Steam OpenID i PDO/WeaponPaints. Bez dostępu do produkcji.
- Addytywna migracja 001 z wersjonowaniem/checksumą, blokadą współbieżności i bez wywołania migratora `wp_*`.
- Konta po SteamID64, profil i ustawienia prywatności, role i uprawnienia, portfel oraz statystyki inicjalizowane atomowo.
- Integracja konta po poprawnym callbacku Steam, za flagą domyślnie false; zachowanie starego logowania w przypadku awarii Core.
- Prywatne API konta, z saldem i RP tylko aktualnej sesji, odczytem ról z bazy i wyliczaniem rangi.
- Jawne nadanie pierwszego OWNER przez CLI po zweryfikowanym logowaniu Steam, z powodem, blokadą i audytem. Nie nadano rzeczywistemu użytkownikowi żadnej roli; SteamID właściciela nie jest znany.
- Fundament tabel statystyk, sezonów, rang, portfeli, ledgeru i idempotencji zdarzeń. Bez naliczania nagród, pobierania CC i blokowania skinów.
- Testy regresji, bezpieczeństwa API, migracji, zachowania wyposażenia i kont. Skrypt produkcyjnego pakowania uwzględnia katalog migracji poza publicznym rootem.

## Schemat i konfiguracja

Szczegóły tabel, decyzji, instalacji testowej, uprawnień DB i rollbacku: [docs/FOUNDATIONS.pl.md](docs/FOUNDATIONS.pl.md).

Nowe: `cloudhub_players`, `cloudhub_profiles`, `cloudhub_roles`, `cloudhub_player_roles`, `cloudhub_role_permissions`, `cloudhub_rank_config`, `cloudhub_seasons`, `cloudhub_player_stats`, `cloudhub_player_season_stats`, `cloudhub_wallets`, `cloudhub_wallet_transactions`, `cloudhub_processed_events`, `cloudhub_admin_audit_logs`, `cloudhub_schema_migrations`.

`CLOUDHUB_CORE_ENABLED=false` domyślnie. Najpierw osobno autoryzowana migracja, potem włączenie Core i ponowne logowanie Steam, następnie kontrolowany bootstrap OWNER. Sekrety i konfiguracja DB pozostają poza repozytorium. Sklep i egzekwowanie własności nie są włączalne w tym etapie. Personalizacja ma pozostać darmowa również po wdrożeniu sklepu.

## Weryfikacja — 16 października 2026

- 9/9 testów Playwright (API i przeglądarka), w izolowanej lokalnej MariaDB.
- 31 sprawdzeń fundamentów w PHP: migracje i checksumy, zachowanie `wp_*` oraz starego skina, inicjalizacja kont, trwałość danych, uprawnienia, bootstrap OWNER, nieujemne saldo i arytmetyka ledgeru.
- Lint frontendu bez błędów. Build poprawny; istniejące ostrzeżenie o opcjonalnym module 3D >500 kB pozostaje.
- Kontrola składni PHP poprawna. Bez realnego logowania Steam, gry CS2 i produkcyjnego PHP 8.5.
- Testy nie dowodzą jeszcze współbieżnych zakupów, wydajności leaderboardu ani odporności pluginu na zmiany map — te usługi nie są zaimplementowane.

## Ryzyka i możliwość połączenia

Etap nadaje się do przeglądu i połączenia jako wyłączony domyślnie fundament. Sam merge nie uruchamia migracji ani nowych funkcji na VPS. Włączenie Core bez migracji daje niedostępne API konta, ale nie powinno blokować starego logowania. Nie wdrażać jako ukończonego portalu ekonomicznego. Nie nadpisywać starego archiwum CloudHub 1.0.0 bez nadania nowej wersji przy przyszłym pakowaniu.

## Pozostałe etapy

2. CloudHub Core C#: źródła/kontrakt Match System, audyt kolizji, zaufane zdarzenia i uczestnictwo rund, antyfarm, kolejka, retry, RP/CC, testy; bez instalacji na produkcji.
3. Backend: transakcyjny ledger i zakupy, kontrole idempotencji/replay, profile, leaderboard, sezony, odznaki TOP/historyczne, role/admin, znajomi, blokowanie i komentarze.
4. Frontend: rozszerzenie aktualnej nawigacji o ranking, profile, portfel, społeczność, sklep i administrację; brak drugiej niezależnej strony.
5. Ekonomia: katalog i propozycje cen, symulacja, zatwierdzenie przez OWNER, własność bazowych skinów, bezpłatne presety/personalizacja, dry-run migracji wyposażenia. Kontrola własności także we wszystkich ścieżkach pluginu WeaponPaints, nie tylko w WWW. Żadnego automatycznego odbierania wyposażenia.
6. CS2UIKit: audyt API `v2.0.0-preview.1`, lokalny prototyp, integracja GUI i fallback, weryfikacja Workshop 3807024759 / MultiAddonManager / `mm_client_extra_addons`. Bez aktualizacji CSS/Metamod/gamedata.
7. Pełne testy systemowe, bezpieczeństwa, współbieżności, wydajności, awarii DB, zmian map i restartów w środowisku testowym. Wdrożenie, migracja produkcji i restart CS2 wymagają osobnej zgody właściciela.

## Plan zapisany po Etapie 1 (wykonany w odtworzonym Etapie 2)

Rozpocząć etap 2 od odczytu API CounterStrikeSharp i CS2UIKit oraz kontraktu z CloudHub Match System. Brakujące informacje produkcyjne opisano w instrukcji audytu; nie zgadywać wyników meczów, wersji schematu WeaponPaints 3.3a, SteamID właściciela ani istniejących komend. Nadal można lokalnie projektować i testować logikę kolejki, antyfarm i rozliczeń niezależnie od gry.

## Przyszły moduł — CloudHub Desktop (dodano 17 października 2026)

Po ukończeniu strony i backendu zaplanowano aplikację Windows, najlepiej współdzielącą obecny frontend. Tauri jest kandydatem do oceny, nie zatwierdzoną nową zależnością.

- Te same konta Steam, profile, znajomi, ranking, Cloud Coins, sklep i konfigurator WeaponPaints; wspólny backend i postęp, bez drugiej ekonomii.
- Docelowo uruchamianie/dołączanie do serwera CS2, aktualizacje aplikacji i powiadomienia.
- Na teraz wyłącznie publiczna podstrona `/download` z komunikatem „Aplikacja CloudHub — wkrótce” oraz przycisk „Pobierz CloudHub” na ekranie logowania i w panelu gracza.
- Brak instalatora, nieczynnego linku pobierania, projektu Tauri, desktopowego logowania i aktualizatora. Nie rozpoczynano implementacji aplikacji i nie zmieniano zakresu Etapu 2.

### Stan środowiska przy tym dodatku

Po odtworzeniu środowiska dostępny jest zapisany na GitHubie etap 1 (`ee256ce`, PR #2). Lokalne niewypchnięte pliki Etapu 2 z poprzedniej instancji nie zostały odtworzone i gałąź `cloudhub-core` nie istnieje na zdalnym repozytorium. Nie oznaczamy tego kodu jako zapisanego na GitHubie ani nie zastępujemy go inną implementacją. Ten stan został następnie zamknięty decyzją użytkownika o ponownej implementacji; odtworzony Etap 2 opisano poniżej. Dodatek Desktop jest odrębną gałęzią opartą na potwierdzonym `master` i nie zawiera zmian backendu, migracji ani pluginu.


## Etap 2 — ponowna implementacja zakończona do stagingu (17 października 2026)

Na podstawie master `14b1391` odtworzono CloudHub Core na `cloudhub-core-stage2`. To nowa implementacja na zlecenie użytkownika, nie odzyskane źródła. Fundamenty Etapu 1 i CloudHub Desktop zachowane.

- Plugin CSS 1.0.376 / .NET 10: obserwacja statystyk i wyników rund, asynchroniczny outbox na dysku, podpisy HMAC, ponawianie i kwarantanna.
- Backend: addytywna migracja 002, konfigurowalne RP/CC, sześć istniejących rang, antyfarm par i godzinowe limity, atomowe rozliczenia, deduplikacja zdarzenia i rundy, sezony, prywatna historia portfela.
- Automatyczna konfiguracja Codexa bez SQL i bez uruchamiania bazy/gry; pełna treść w `docs/codex-setup.sh`, zatwierdzenie/publikacja przez ustawienia środowiska. Testy używają osobnego lokalnego helpera i jednorazowej bazy.
- Moduły zapisywane i wypychane kolejno: `00ade2d` (setup), `369f41f` (backend), `85b92f5` (plugin), `0333624` (historia i staging), następnie raport końcowy.
- Wyniki: 39 asercji C#, 31 fundamentów PHP, 50 rozliczeń PHP, 13 testów API/UI; build/lint i lokalna składnia Nginx/FPM poprawne. Szczegóły: `docs/CORE_STAGE2_TEST_REPORT.md`.
- Plugin i ingestion wyłączone domyślnie. Brak zmian produkcji. Następny krok to przegląd PR i osobno uzgodniony staging zgodnie z `docs/CORE_STAGE2.pl.md`. Match System pozostaje niewpiętym adapterem, bez naliczania wyników/nagród całych meczów.
