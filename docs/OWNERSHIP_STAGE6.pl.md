# Etap 6: egzekwowanie własności w WeaponPaints

Status: **zaimplementowane, domyślnie wyłączone.** Bez migracji produkcyjnej, bez zmian na serwerze gry, bez włączania sprzedaży.

Dwie warstwy:

1. **API WWW** odrzuca zapis nieposiadanego przedmiotu (403).
2. **Strażnik bazy** (triggery na `wp_player_skins`, `wp_player_knife`, `wp_player_gloves`) obejmuje wszystko, co trafia do tabel, z których serwer CS2 czyta wyposażenie: zapisy z menu w grze, ręczny SQL i każdą ścieżkę spoza WWW.

## Zasada

- Zapis bazowego skina, noża lub rękawic w WeaponPaints wymaga posiadania przedmiotu. Źródła posiadania:
  - zakup w sklepie (`cloudhub_player_inventory`);
  - nadanie;
  - **stan sprzed egzekwowania** (`cloudhub_legacy_ownership`).
- **Darmowe zawsze:**
  - personalizacja: wear, seed, StatTrak, nametag, naklejki, zawieszki;
  - domyślne wykończenia (`paint 0`), domyślny nóż (`weapon_knife`, `weapon_knife_t`), domyślne rękawice;
  - agenci, muzyka i przypinki.
- **Nóż i rękawice:** posiadanie dowolnego wykończenia odblokowuje model (np. Karambit Doppler pozwala wybrać Karambit), ale nie inne wykończenia.
- **Nieznane połączenia** broni i wykończenia, spoza katalogu, są odrzucane, chyba że gracz miał je wcześniej (wtedy trafiły do snapshotu).
- Podgląd w konfiguratorze działa zawsze. Blokuje backend, nie samo ukrycie przycisku (§12).
- Własność jest tylko dodawana. Nic jej automatycznie nie odbiera i nic nie usuwa wyposażenia z `wp_*`.

## Warunki włączenia

`CloudHubOwnership::active()` zwraca prawdę dopiero przy spełnieniu wszystkich warunków:

1. `CLOUDHUB_OWNERSHIP_ENFORCED=true` (domyślnie `false` w `config.sample.php`);
2. sklep aktywny (`CLOUDHUB_SHOP_ENABLED` i migracja 004);
3. migracja `005_cloudhub_ownership.sql`;
4. co najmniej jeden snapshot OWNER w `cloudhub_ownership_snapshots`.

Brak któregokolwiek warunku oznacza dotychczasowe działanie bez blokady. Dodatkowo do logu trafia komunikat, gdy flaga jest włączona, ale czegoś brakuje.

## Sprawdzane ścieżki zapisu (WWW)

| Endpoint | Akcja | Sprawdzenie |
|---|---|---|
| `skins.php` | `save` | `(weapon_defindex, paint)` |
| `skins.php` | `gloves_save` | `(glove defindex, paint)` |
| `knife.php` | `set` | model noża |
| `loadouts.php` | `apply` | wszystkie skiny, nóż i rękawice z presetu, także skopiowanego od innego gracza |

- Odmowa: `403 {"code":"not_owned","items":[{weapon_defindex,paint_id}]}`. Nic nie zostaje zapisane.
- Bez kontroli: `reset`, `reset_team`, `reset_both`, akcje agentów, muzyki i przypinek, `copy` i `save` presetów. `copy` i `save` tylko zapisują dane, nie wyposażają; kontrola następuje przy `apply`.

## Migracja istniejących skinów (§12)

`bin/cloudhub-ownership.php`:

- `--dry-run` (domyślnie): liczy, co WeaponPaints już przechowuje. Źródła: `wp_player_skins`, `wp_player_knife`, `wp_player_gloves` oraz zawartość `wp_saved_loadouts`.
- `--apply --reason="..."`: zapisuje te przedmioty jako własność sprzed egzekwowania.
  - Operacja addytywna i powtarzalna (`INSERT IGNORE`).
  - Obejmuje graczy, którzy nigdy nie logowali się do CloudHub (tabela bez klucza obcego do `cloudhub_players`).
  - Tabele `wp_*` są tylko czytane.
- `--audit`: tylko odczyt. Wypisuje wyposażone przedmioty, których egzekwowanie by nie przepuściło, np. wybrane w grze po snapshocie.
- `--guard=install --reason="..."`, `--guard=remove`, `--guard=status`: strażnik bazy (poniżej).

W sklepie przedmioty sprzed egzekwowania:

- mają etykietę „Posiadasz”;
- są w ekwipunku jako „Posiadany przed uruchomieniem sklepu”;
- nie da się ich kupić drugi raz.

## Strażnik bazy: wyposażenie przekazywane na serwer CS2

Plugin WeaponPaints nie pyta CloudHub o zgodę. Przy wejściu gracza i przy `!wp` czyta `wp_*` bezpośrednio, a menu w grze zapisują tam wybór. Dlatego kontrola jest na poziomie bazy.

- `--guard=install`:
  - wymaga aktywnego egzekwowania WWW (flaga i sklep);
  - w tym samym przebiegu robi świeży snapshot, więc nic, co gracze mają w chwili instalacji, nie staje się „nieposiadane”;
  - zakłada 6 triggerów `BEFORE INSERT/UPDATE`.
- **Nowy wybór nieposiadanego przedmiotu** zapisuje się jako domyślny: wykończenie `paint 0`, nóż `weapon_knife`, rękawice `0`. Zapis nie kończy się błędem, więc plugin dalej działa.
- **Aktualizacja, która nie zmienia przedmiotu** (licznik StatTrak, wear, seed) przechodzi zawsze. Strażnik niczego nie odbiera i nie zmienia już zapisanych wierszy.
- **Bez kontroli:** agenci, muzyka i przypinki.
- **Działa niezależnie od klienta:** ta sama reguła obowiązuje WWW, menu w grze i ręczny SQL.
- **`--guard=remove`** usuwa triggery i nie zmienia zapisanych danych.
- CLI ostrzega, gdy strażnik jest zainstalowany, a egzekwowanie WWW wyłączone, np. po wyłączeniu sklepu. Wtedy trzeba wykonać `--guard=remove`.
- **Wymagania produkcyjne (do sprawdzenia przed wdrożeniem):**
  - uprawnienie `TRIGGER` na tabelach `wp_*`;
  - przy włączonym binlogu MariaDB: `SUPER` albo `log_bin_trust_function_creators=1`.
- Plugin nie wykonuje `ALTER`, `DROP` ani `TRUNCATE` na tych tabelach (sprawdzone w `Utility.cs`), więc triggery pozostają po jego restartach.
- **Ograniczenie:** menu w grze nakłada wybór natychmiast w pamięci pluginu. Do końca sesji gracz widzi skin u siebie, ale w bazie zostaje domyślny. Po ponownym wejściu lub `!wp` dostaje stan z bazy. Pełne zamknięcie tej luki wymaga wyłączenia menu w konfiguracji pluginu (poniżej).

## Plugin WeaponPaints (zweryfikowane w kodzie, bez zmian na serwerze)

Sprawdzono tylko do odczytu Nereziel/cs2-WeaponPaints w commicie `6b1039d63042b0bbadbf142054c86f79284fdb6a`, tym samym co w `docs/COMPATIBILITY.md`: `Commands.cs`, `Config.cs`, `WeaponPaints.cs`.

- Menu w grze zapisują wybór bezpośrednio do bazy (`WeaponSync.Sync*ToDatabase`), z pominięciem strony:
  - `!skins` (`CommandSkinSelection`);
  - `!knife` (`CommandKnife`);
  - `!gloves` (`CommandGlove`).
- Agenci, muzyka i przypinki również, ale one pozostają darmowe.
- Plugin nakłada to, co jest w bazie. Egzekwowanie po stronie WWW nie zatrzymuje więc wyboru z menu w grze. Zatrzymuje go strażnik bazy (powyżej).
- **Rekomendacja (wymaga zatwierdzenia i weryfikacji na wdrożonej wersji pluginu):**
  - w konfiguracji WeaponPaints ustawić puste listy: `"CommandSkinSelection": []`, `"CommandKnife": []`, `"CommandGlove": []`;
  - opcji `SkinEnabled`, `KnifeEnabled` i `GloveEnabled` nie wyłączać, bo sterują też nakładaniem skinów;
  - `!wp` i `!ws` (odświeżanie i informacja) zostają.
- Zanim menu zostaną wyłączone, strażnik bazy pilnuje zapisu, a `--audit` wykrywa obejście.
- Nie wykonano żadnej zmiany konfiguracji ani restartu serwera CS2.

## Menu w grze: natychmiastowe nałożenie (analiza kodu, 18.10.2026)

Sprawdzone tylko do odczytu:

- **WeaponPaints:** Nereziel/cs2-WeaponPaints `6b1039d` = aktualny `main` = release `build-466` (04.10.2026), `VERSION` **3.3a**, licencja **GPL-3.0**. Zależności: CounterStrikeSharp.API 1.0.376, .NET 10.
- **MenuManager:** interfejs `IMenuApi` (NickFox007/MenuManagerCS2).
- **Wersja na produkcyjnym serwerze nie została potwierdzona.** Przed jakąkolwiek zmianą trzeba ją odczytać, np. z `css_plugins list` albo z pliku `VERSION` przy pluginie.

### Co robi plugin

`Commands.cs`: handlery menu `!skins` (`handleSkinSelection`), `!knife` (`handleGive`) i `!gloves` (`handleGloveSelection`) działają w tej kolejności:

1. zmiana stanu w pamięci (`GPlayerWeaponsInfo`, `GPlayersKnife`, `GPlayersGlove`);
2. natychmiastowe nałożenie (`RefreshWeapons`, `GivePlayerGloves`);
3. dopiero potem asynchroniczny zapis do bazy (`Task.Run(... Sync*ToDatabase)`).

Strażnik bazy działa dopiero w kroku 3. Dlatego gracz widzi wybrany przedmiot do ponownego wczytania stanu z bazy (`OnClientFullConnect` lub `!wp`, które wywołują `GetPlayerData`).

### Czy da się to zatrzymać bez forka? Nie, w sposób bezpieczny

- **API pluginu:** WeaponPaints nie rejestruje żadnej `PluginCapability`, nie ma zdarzeń ani metod publicznych. Stan i handlery są `internal` albo prywatnymi lambdami. Plugin tylko *konsumuje* `PluginCapability<IMenuApi>("menu:nfcore")`.
- **MenuManager:** `IMenuApi` udostępnia wyłącznie `GetMenu`, `GetMenuForcetype`, `CloseMenu`, `GetMenuType`, `HasOpenedMenu`. Brak zdarzenia wyboru opcji i brak sposobu na opakowanie cudzego menu.
- **CounterStrikeSharp:**
  - `AddCommandListener` może przechwycić tylko polecenie (np. `css_skins`), czyli otwarcie menu. Wybór opcji obsługuje MenuManager, więc z tego poziomu da się menu jedynie całkowicie zablokować. To jest sprzeczne z wymaganiem zachowania menu.
  - Hooki `VirtualFunction` dotyczą funkcji silnika gry, nie metod innej wtyczki.
- **Odrzucone obejścia:**
  - **Łatanie w czasie działania (Harmony, refleksja do `internal static` stanu):** w praktyce nieoficjalny fork, kruchy przy każdej aktualizacji i niewspierany przez CounterStrikeSharp.
  - **Korekta po fakcie (zdejmowanie atrybutów broni):** konflikt z WeaponPaints, które ponownie nakłada skin, i ryzyko błędów entity.

### Bezpieczny wariant: minimalny fork + kontrakt w pluginie CloudHub

1. **Kontrakt (osobna mała biblioteka `CloudHub.Ownership.Contracts`, MIT):**
   - `interface ICloudHubOwnership { bool IsReady(ulong steamId); bool CanUse(ulong steamId, int defindex, int paint); bool CanUseKnife(ulong steamId, string knife); bool CanUseGlove(ulong steamId, int defindex, int paint); void Refresh(ulong steamId); }`
   - Udostępniany przez plugin CloudHub jako `PluginCapability<ICloudHubOwnership>("cloudhub:ownership")`. Ten sam mechanizm, którego WeaponPaints już używa dla MenuManager.
2. **Plugin CloudHub (nasz kod):**
   - pamięć podręczna posiadania per gracz, ładowana asynchronicznie przy wejściu i odświeżana przy otwarciu menu;
   - odpowiedzi z bazy ładowane w tle, nigdy blokująco na wątku gry;
   - źródło: ta sama reguła co strażnik bazy (zakup, snapshot, darmowe przedmioty domyślne, model noża lub rękawic przez dowolne posiadane wykończenie). Najprościej przez widok SQL tylko do odczytu (np. `cloudhub_owned_items`, przyszła migracja) albo endpoint HMAC tylko do odczytu.
3. **Fork WeaponPaints (`RubLud/cs2-WeaponPaints`, GPL-3.0 zachowana, autorstwo upstream zachowane, źródła publiczne):**
   - około 4 miejsc w `Commands.cs`: na początku `handleSkinSelection`, `handleGive` i `handleGloveSelection`, *przed* zmianą pamięci, wywołanie `CanUse…`;
   - przy odmowie: komunikat „Kup w sklepie CloudHub” i `return`, bez zmiany pamięci, bez `RefreshWeapons` i bez zapisu do bazy;
   - opcjonalnie: lista skinów w podmenu filtrowana per gracz albo oznaczona kłódką. Podmenu skinów jest budowane per gracz w `handleWeaponSelection`, więc to możliwe. Menu noży i rękawic jest globalne, dlatego tam działa kontrola w handlerze;
   - nowa opcja konfiguracji `CloudHubOwnership` (domyślnie `false`): bez niej fork działa identycznie jak upstream;
   - gdy `true`, a capability nie jest dostępne lub dane gracza nie są jeszcze wczytane: **fail-closed** dla wykończeń płatnych; przedmioty domyślne zawsze działają;
   - personalizacja (`!stattrak`), agenci, muzyka i przypinki bez zmian;
   - `GiveRandomSkin` i `GiveRandomKnife` pozostają `false`. Przy włączeniu fork musi je objąć tą samą kontrolą.
4. **Utrzymanie:**
   - fork jako mała łatka nad tagiem upstream (`build-466`), z plikiem `CLOUDHUB_PATCH.md` opisującym zmiany;
   - przy każdym nowym buildzie upstream: rebase, build w GitHub Actions (`dotnet build`, testy jednostkowe reguł w kontrakcie);
   - przed wdrożeniem test na serwerze testowym, nie produkcyjnym.
5. **Warstwy pozostają:** API WWW (403) i strażnik bazy (triggery) działają niezależnie od forka. Gdy fork zawiedzie, w bazie i tak zostaje stan domyślny.

### Etap 6B: implementacja lokalna (19.10.2026)

Zrobione lokalnie, bez push i bez zmian na serwerze CS2. Wszystkie elementy są domyślnie wyłączone.

- **Backend:** `POST /api/ownership.php`, tylko do odczytu.
  - Uwierzytelnienie kluczem serwera z ingest (`CLOUDHUB_INGEST_KEYS`). Flaga `CLOUDHUB_OWNERSHIP_API_ENABLED` (domyślnie `false`).
  - W `nginx.conf` endpoint zablokowany (404). Staging: blok w `nginx-ingest.conf`, izolowana pula ingest.
  - Odpowiedź: posiadane przedmioty z zakupów i snapshotu, modele noży i rękawic, ceny z bazy. Każdy pytany gracz dostaje wpis.
  - „Nie egzekwowane” to zawsze jawna odpowiedź. Odpowiedzi są podpisane prefiksem `r1`, więc nigdy nie przejdą jako żądanie.
- **Kontrakt** `server/CloudHub.Ownership.Contracts` (MIT): `ICloudHubOwnership`, capability `cloudhub:ownership`.
  - Statusy `Free`, `Owned`, `Locked` (z ceną CC) i `Pending`.
  - Sprawdzone w CounterStrikeSharp 1.0.376 (refleksja nad DLL): `Capabilities.RegisterPluginCapability`, `PluginCapability<T>.Get()` oraz obsługa współdzielonych assembly (`SharedPath`, `LoadSharedLibraries`).
- **CloudHub.Core:** protokół, reguły i serwis. Odpowiedzi z pamięci, bez blokowania wątku gry, jedna pętla w tle, do 64 graczy na żądanie.
- **Plugin CloudHub 0.3.0:** `OwnershipEnabled` (domyślnie `false`) rejestruje capability i odświeża gracza po autoryzacji Steam.
- **Fork WeaponPaints** (`D:\CloudHub\cs2-WeaponPaints-cloudhub`, gałąź `cloudhub-ownership`, lokalnie): opis zmian w `CLOUDHUB_PATCH.md`. Flaga `Additional.CloudHubOwnership` (domyślnie `false` = upstream).
  - Menu pokazują wszystko. Nieposiadane przedmioty mają etykietę `🔒 nazwa - N CC`. Wybór takiej pozycji wyświetla tylko komunikat.
  - Handlery sprawdzają własność przed jakąkolwiek zmianą w pamięci.
  - `GiveRandomSkin` i `GiveRandomKnife` są wymuszone na `false`.

**Zachowanie awaryjne (fail-closed):** płatne przedmioty pozostają zablokowane, gdy brakuje któregokolwiek elementu:

- pluginu CloudHub, capability albo DLL kontraktu;
- backendu, poprawnego podpisu albo danych gracza.

Domyślne przedmioty działają zawsze. Posiadane przedmioty działają z ostatniej poprawnej odpowiedzi przez maksymalnie 6 godzin. „Nie egzekwowane” obowiązuje najwyżej 2 minuty od podpisanej odpowiedzi. Strażnik bazy działa niezależnie.

**Licencje:**

- WeaponPaints i CounterStrikeSharp: GPL-3.0. Zmiany w forku są oznaczone (`CloudHub modification`, README, `CLOUDHUB_PATCH.md`), a autorstwo upstream zachowane. Źródła forka muszą być dostępne razem z buildem.
- Kontrakt: MIT, zgodny z GPL-3.0.
- `MenuManagerApi.dll`: bez podanej licencji w repo upstream. Do sprawdzenia przed publikacją.

**Przed wdrożeniem (osobna zgoda):**

1. Potwierdzić wersję WeaponPaints na serwerze (`css_plugins list`, oczekiwane 3.3a / build-466).
2. Sprawdzić na serwerze testowym katalog `shared/CloudHub.Ownership.Contracts/`.
3. Przetestować tam scenariusze z `CLOUDHUB_PATCH.md`.

### Plan do czasu decyzji

- Komendy `!skins`, `!knife` i `!gloves` na produkcji pozostają bez zmian (decyzja OWNER).
- Po włączeniu Etapu 6 bez forka luka ogranicza się do jednej sesji. Zapis i każde ponowne wczytanie dają stan domyślny, a `--audit` wykrywa przypadki.

## Kolejność wdrożenia (każdy krok za zgodą OWNER)

1. Kopia zapasowa bazy.
2. Migracja 005 (`bin/cloudhub-migrate.php --apply`).
3. Katalog produktów z Etapu 5 (`bin/cloudhub-shop-catalog.php --apply`) i zatwierdzony cennik.
4. Zmiana konfiguracji pluginu (powyżej) i przeładowanie pluginu.
5. `bin/cloudhub-ownership.php --dry-run`, przegląd liczb.
6. Tuż przed włączeniem: `--apply --reason="..."`.
7. `CLOUDHUB_OWNERSHIP_ENFORCED=true`. Ma sens dopiero przy włączonym sklepie i aktywnych produktach.
8. `--guard=install --reason="..."`. To zmiana schematu produkcyjnej bazy (triggery na `wp_*`) i wymaga osobnej zgody.
9. Okresowo `--audit`.

**Wycofanie:** `--guard=remove` oraz `CLOUDHUB_OWNERSHIP_ENFORCED=false`. Oba działają natychmiast i nie zmieniają zapisanego wyposażenia. Tabele z migracji 005 można zostawić, bo nie wpływają na działanie.

**Sklep wyłączony:** egzekwowanie WWW wyłącza się automatycznie i WeaponPaints działa jak przed Etapem 6. Strażnik bazy trzeba wtedy zdjąć (`--guard=remove`); CLI ostrzega o niespójności.

## Testy

- `tests/ownership.php` (testowa baza), z naciskiem na:
  - warunki włączenia i dry-run;
  - snapshot: wymagany powód, idempotencja, brak zmian w `wp_*`;
  - zachowanie stanu (gracz bez konta CloudHub, presety, nieznane wykończenia);
  - reguły: darmowe domyślne, modele noży i rękawic, nieznany nóż;
  - zakupy;
  - widoczność w sklepie i brak podwójnego zakupu;
  - audyt tylko do odczytu;
  - brak migracji 005;
  - strażnik bazy, sprawdzany na prawdziwym SQL pluginu:
    - wybór nieposiadanego skina, noża i rękawic z menu w grze;
    - zmiana na nieposiadany przedmiot przez `UPDATE` i `ON DUPLICATE KEY UPDATE`;
    - nieznany nóż;
    - darmowa personalizacja;
    - aktualizacja StatTrak wiersza sprzed strażnika;
    - odmowa instalacji przy wyłączonym sklepie;
    - usunięcie strażnika bez zmiany danych.
- `tests/ownership.spec.js`: drugi backend (port 8084) z włączoną flagą na tej samej bazie i sesjach. Sprawdza:
  - flagę w `cloudhub.php`;
  - odmowę zapisu bez zapisu do bazy;
  - darmowe domyślne;
  - model noża;
  - rękawice po zakupie i darmową personalizację;
  - preset zawierający nieposiadany skin (`apply` → 403);
  - niezmienione działanie bez flagi;
  - konfigurator z etykietą „Kup: N CC” i komunikatem odmowy;
  - bezpieczeństwo:
    - cudzy SteamID w żądaniu;
    - brak sesji, CSRF, metoda GET;
    - nieznane i błędne wartości;
    - preset skopiowany od innego gracza;
    - ponowny zakup bez drugiego obciążenia;
  - sklep wyłączony (port 8085, flaga włączona): WeaponPaints działa jak dotąd.
