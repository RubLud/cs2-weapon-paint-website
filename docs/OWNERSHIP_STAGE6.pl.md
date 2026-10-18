# Etap 6: egzekwowanie własności w WeaponPaints

Status: **przygotowane, domyślnie wyłączone.** Bez migracji produkcyjnej, bez zmian na serwerze gry, bez włączania sprzedaży.

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

W sklepie przedmioty sprzed egzekwowania:

- mają etykietę „Posiadasz”;
- są w ekwipunku jako „Posiadany przed uruchomieniem sklepu”;
- nie da się ich kupić drugi raz.

## Plugin WeaponPaints (zweryfikowane w kodzie, bez zmian na serwerze)

Sprawdzono tylko do odczytu Nereziel/cs2-WeaponPaints w commicie `6b1039d63042b0bbadbf142054c86f79284fdb6a`, tym samym co w `docs/COMPATIBILITY.md`: `Commands.cs`, `Config.cs`, `WeaponPaints.cs`.

- Menu w grze zapisują wybór bezpośrednio do bazy (`WeaponSync.Sync*ToDatabase`), z pominięciem strony:
  - `!skins` (`CommandSkinSelection`);
  - `!knife` (`CommandKnife`);
  - `!gloves` (`CommandGlove`).
- Agenci, muzyka i przypinki również, ale one pozostają darmowe.
- Plugin nakłada to, co jest w bazie. Egzekwowanie po stronie WWW nie zatrzymuje więc wyboru z menu w grze.
- **Rekomendacja (wymaga zatwierdzenia i weryfikacji na wdrożonej wersji pluginu):**
  - w konfiguracji WeaponPaints ustawić puste listy: `"CommandSkinSelection": []`, `"CommandKnife": []`, `"CommandGlove": []`;
  - opcji `SkinEnabled`, `KnifeEnabled` i `GloveEnabled` nie wyłączać, bo sterują też nakładaniem skinów;
  - `!wp` i `!ws` (odświeżanie i informacja) zostają.
- Zanim menu zostaną wyłączone, `--audit` wykrywa obejście.
- Nie wykonano żadnej zmiany konfiguracji ani restartu serwera CS2.

## Kolejność wdrożenia (każdy krok za zgodą OWNER)

1. Kopia zapasowa bazy.
2. Migracja 005 (`bin/cloudhub-migrate.php --apply`).
3. Katalog produktów z Etapu 5 (`bin/cloudhub-shop-catalog.php --apply`) i zatwierdzony cennik.
4. Zmiana konfiguracji pluginu (powyżej) i przeładowanie pluginu.
5. `bin/cloudhub-ownership.php --dry-run`, przegląd liczb.
6. Tuż przed włączeniem: `--apply --reason="..."`.
7. `CLOUDHUB_OWNERSHIP_ENFORCED=true`. Ma sens dopiero przy włączonym sklepie i aktywnych produktach.
8. Okresowo `--audit`.

**Wycofanie:** `CLOUDHUB_OWNERSHIP_ENFORCED=false` działa natychmiast. Tabele z migracji 005 można zostawić, bo nie wpływają na działanie.

## Testy

- `tests/ownership.php` (testowa baza), z naciskiem na:
  - warunki włączenia i dry-run;
  - snapshot: wymagany powód, idempotencja, brak zmian w `wp_*`;
  - zachowanie stanu (gracz bez konta CloudHub, presety, nieznane wykończenia);
  - reguły: darmowe domyślne, modele noży i rękawic, nieznany nóż;
  - zakupy;
  - widoczność w sklepie i brak podwójnego zakupu;
  - audyt tylko do odczytu;
  - brak migracji 005.
- `tests/ownership.spec.js`: drugi backend (port 8084) z włączoną flagą na tej samej bazie i sesjach. Sprawdza:
  - flagę w `cloudhub.php`;
  - odmowę zapisu bez zapisu do bazy;
  - darmowe domyślne;
  - model noża;
  - rękawice po zakupie i darmową personalizację;
  - preset zawierający nieposiadany skin (`apply` → 403);
  - niezmienione działanie bez flagi;
  - konfigurator z etykietą „Kup: N CC” i komunikatem odmowy.
