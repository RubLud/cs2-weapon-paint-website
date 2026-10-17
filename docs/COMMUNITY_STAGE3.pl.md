# CloudHub — Etap 3: profile, ranking, portfel i OWNER

Rozszerzenie istniejącego React/Vite + PHP/MySQL na bazie `master` `8fe59c8` (PR #4). Konfigurator WeaponPaints, Steam OpenID i `/download` pozostają dostępne. Brak wdrożenia na VPS, zmian pluginu, resetowania statystyk i automatycznych migracji. Kod nie tworzy danych demonstracyjnych poza izolowanymi testami.

## Audyt i wykorzystane fundamenty

Przeczytano `CLOUDHUB_IMPLEMENTATION_PROGRESS.md`, `CORE_STAGE2.pl.md`, `CORE_STAGE2_TEST_REPORT.md`, migracje 001/002, `CloudHubAccounts`, `CloudHubRounds`, SteamOpenID/callback, zabezpieczenia sesji/CSRF, routing i konfigurator React. Istnieją już wszystkie wymagane tabele. **Etap 3 nie wymaga nowej migracji SQL** i nie zmienia checksum migracji 001/002. Nie odtwarza Core ani drugiej ekonomii.

- Konto i rola: `CloudHubAccounts`, SteamID64 jako kanoniczny ciąg, świeży odczyt ról z bazy.
- RP i statystyki: `cloudhub_player_stats` i `cloudhub_player_season_stats`; ranga z `cloudhub_rank_config`.
- CC: `cloudhub_wallets` i nieusuwalna w eksploatacji księga `cloudhub_wallet_transactions`.
- OWNER: istniejący bootstrap CLI; audyt w `cloudhub_admin_audit_logs`.

## Strony i API

| Strona | Zachowanie |
|---|---|
| `/ranking` | Publiczny ranking globalny i aktywnych/zamkniętych sezonów; wyszukiwanie, sortowanie, strony po 10/50/100 graczy |
| `/players` | Wyszukiwarka profili w tym samym rankingu |
| `/players/:steamid` | Profil, odznaki, statystyki, historia sezonów, opis i ustawienia prywatności |
| `/wallet` | Własne saldo, prywatne transakcje, nagrody za rundy |
| `/admin` | Tylko OWNER: gracze, salda, korekty CC, audyt, podgląd reguł |

`community.php` udostępnia GET `leaderboard`, `seasons`, `profile`; POST `edit` przyjmuje wyłącznie `bio`, `profile_visibility`, `activity_visibility`. ID edytowanego konta pochodzi tylko z sesji, dodatkowe pola (w tym SteamID, role, RP, CC) są odrzucane. Opis jest zwykłym tekstem, maksymalnie 1000 znaków Unicode; React nie interpretuje HTML.

`admin.php` udostępnia GET `players`, `audit`, `config` oraz POST `adjust`. Wymaga OWNER na backendzie także przy bezpośrednim wejściu na adres. Samo ukrycie zakładki nie stanowi autoryzacji. ADMIN/MODERATOR nie mają dostępu do korekt CC ani audytu.

Istniejące `cloudhub.php` i `action=history` nadal zwracają wyłącznie konto/księgę zalogowanej osoby. Podany z zewnątrz SteamID nie zmienia właściciela historii. OWNER widzi salda innych osób oraz audyt korekt, ale nie otrzymuje dodatkowego endpointu ich pełnej księgi.

Odpowiedzi mają `Cache-Control: no-store`; publiczne odczyty też korzystają z istniejącego limitera. Zapytania używają parametrów PDO; nazwy sortowań wybierane są wyłącznie z listy, limit 10/50/100, numer strony 1–10000, wyszukiwanie do 128 bajtów. `%` i `_` w wyszukiwaniu są znakami literalnymi. Księga i audyt: 50 wpisów na stronę, kursor BIGINT jako ciąg. Pola BIGINT używane przez UI nie przechodzą przez zmiennoprzecinkowy JS.

## Ranking i odznaki

Jedyna definicja pozycji TOP: **RP malejąco → zabójstwa malejąco → SteamID64 rosnąco**. `ROW_NUMBER()` jest liczone dla całego zbioru globalnego lub danego sezonu, przed wyszukiwaniem, sortowaniem tabeli, filtrem prywatności i paginacją. W rezultacie nie ma współdzielonych miejsc i odznaka TOP nie zmienia się od wybranej kolumny sortowania. Publiczny ranking ukrywa konta prywatne, ale nie przesuwa pozycji innych graczy — przerwy w numeracji są zamierzone i opisane w UI.

Role OWNER/ADMIN/MODERATOR, ranga Bronze/Silver/Gold/Platinum/Diamond/Master i TOP są oddzielnymi odznakami. Ranga w rankingu sezonowym jest wyliczana z sezonowego RP. Historia pokazuje `final_position` tylko wtedy, gdy zostało faktycznie zapisane; nie rekonstruuje historycznego TOP z obecnych danych. Nazwa rangi w historii korzysta z obecnych progów, ponieważ Core nie zapisuje wersji progów na zakończenie sezonu.

K/D = kills / max(deaths, 1), do dwóch miejsc. Win rate dotyczy **rund**: rounds_won / rounds_played; bez rozegranych rund pokazuje „—”. Czas gry oznacza zapisane przez Core sekundy aktywności kwalifikowanych rund, nie cały czas połączenia z serwerem. Ostatnia aktywność konta pochodzi z `last_seen_at`, aktualizowanego przez istniejącą rejestrację/logowanie; nie jest statusem online w grze. Match System i statystyki zwycięstw całych meczów pozostają poza tym etapem.

## Prywatność

Domyślne ustawienia z Etapu 1 pozostają zachowane. Publiczny profil ujawnia nick, avatar, statystyki i odznaki, ale nigdy saldo ani księgę. `profile_visibility=private` ukrywa publiczny profil i wpis w wyszukiwarce/rankingu. Właściciel konta nadal widzi własny profil. OWNER może sprawdzić statystyki konta prywatnego, ale nie omija prywatności opisu ani ostatniej aktywności. Ostatnia aktywność ma oddzielne ustawienie.

Istniejąca wartość `friends` jest traktowana tak samo jak prywatna; UI udostępnia tylko public/private. Nie wprowadzono znajomych, komentarzy, motywów, banerów ani ustawień kolekcji. Zmiana prywatności profilu nie zmienia istniejącej osobnej funkcji udostępniania loadoutów WeaponPaints.

## Bezpieczne przypisanie rzeczywistego OWNER

SteamID właściciela nie został podany i nie został odgadnięty. Nie nadano roli żadnemu rzeczywistemu użytkownikowi. Testowe ID i role istnieją tylko w jednorazowych bazach testowych.

Procedura dla administratora hosta, dopiero po osobnej zgodzie na staging/wdrożenie:

1. Potwierdź tożsamość właściciela i jego kanoniczny 17-cyfrowy SteamID64. Nie korzystaj z nicku ani kolejności rejestracji.
2. Na zatwierdzonym środowisku z migracjami 001/002 i `CLOUDHUB_CORE_ENABLED=true` właściciel przechodzi rzeczywiste logowanie Steam OpenID. Zweryfikowany callback zapisuje `steam_verified_at`.
3. Administrator hosta porównuje SteamID konta z potwierdzonym ID właściciela. Następnie ręcznie uruchamia istniejący CLI, dwukrotnie wpisując ten sam rzeczywisty identyfikator:

   ```bash
   CLOUDHUB_CONFIG=/prywatna/sciezka/staging.php php bin/cloudhub-owner.php \
     --steamid=STEAMID64 --confirm-steamid=STEAMID64 \
     --reason='Potwierdzone przypisanie właściciela CloudHub'
   ```

4. CLI wymaga wcześniejszego zweryfikowanego logowania, stosuje blokadę i transakcję, zapisuje audyt. Gdy OWNER już istnieje, bootstrap odmawia kolejnego nadania. Nie zmienia RP ani CC. Nie jest endpointem WWW.
5. Po odświeżeniu strony konto widzi zakładkę Administracja i odznakę OWNER na swoim profilu. Zmiana SteamID właściciela w przyszłości wymaga oddzielnej, audytowanej procedury — nie dodano publicznego zarządzania rolami.

## Korekty Cloud Coins

Domyślnie `CLOUDHUB_ADMIN_CC_ENABLED=false`, także gdy Core jest włączony. Panel i podgląd sald działają bez włączania zapisu. Po zatwierdzeniu stagingu operator może ręcznie włączyć tę flagę w prywatnej konfiguracji. Nie ustawiono jej na produkcji.

Żądanie `adjust`: docelowy SteamID64, całkowita kwota od -1 000 000 do 1 000 000 (bez zera), powód 1–500 bajtów UTF-8, UUID v4 `request_id`. Powód jest normalizowany przez trim. Wszystkie zapisy wymagają POST, poprawnego CSRF i same-origin zgodnie z istniejącym `wp_require_post`.

Jedna transakcja InnoDB:

1. Świeża kontrola OWNER i blokada jego wpisu roli `FOR UPDATE` — odebranie roli nie może przejść między kontrolą a commitem.
2. Odczyt idempotencji z istniejącego unikatowego `operation_key=admin:<actor>:<UUID>`. Identyczna powtórka zwraca poprzedni wynik; zmiana kwoty, celu lub powodu pod tym samym kluczem daje 409. Klucz jest związany z administratorem z sesji.
3. Blokada portfela `FOR UPDATE`; kontrola nieujemnego salda i przepełnienia signed BIGINT.
4. Aktualizacja salda, wpis księgi z administratorem i powodem, wpis audytu z ID transakcji, kwotą i saldami przed/po. Błąd dowolnego zapisu wycofuje całość.

Równoległe żądania tego samego OWNER są serializowane blokadą jego roli. Różni właściciele i ingestion współdzielą blokadę portfela. Deadlock/awaria może zwrócić błąd 500 po rollbacku; ponowienie identycznego UUID pozostaje bezpieczne. Rekordów idempotencji/księgi nie wolno usuwać podczas eksploatacji.

UI zapisuje oczekujący payload i UUID w `sessionStorage` przed wysyłką. Przy utracie odpowiedzi blokuje zmianę kwoty/celu i pozwala ponowić tę samą operację, również po odświeżeniu lub powrocie do panelu w tej samej karcie. Nie zapisuje haseł ani tokenów w tym magazynie. Zamknięcie karty może usunąć stan — przed nową korektą po utracie tego stanu należy sprawdzić audyt. Sukces powtórki zwraca saldo po historycznej operacji, a świeże saldo tabeli jest pobierane osobno.

## Przygotowanie późniejszego stagingu (nie wykonano)

- Wykorzystaj istniejącą bazę stagingową i konfigurację; nie uruchamiaj migracji produkcyjnych. Brak migracji 003. Jeżeli fundamenty nie są zainstalowane, migracje 001/002 uruchamia administrator ręcznie po osobnej akceptacji.
- Przykładowy Nginx ma rozszerzoną listę dozwolonych endpointów o `community.php` i `admin.php`. Sam commit nie zmienia konfiguracji działającego hosta. Przed zatwierdzonym reloadem wymagane `nginx -t` w docelowym środowisku.
- Dla WWW potrzebne SELECT na tabelach community, rangach, sezonach, rolach, portfelach i audycie. Edycja profilu wymaga UPDATE kolumn bio/profile_visibility/activity_visibility. Włączenie korekt wymaga UPDATE salda, INSERT do księgi i audytu oraz uprawnień wymaganych przez blokujące odczyty InnoDB. To poszerza dotychczasowe uprawnienia WWW z Etapu 2 — nadać je świadomie tylko na zatwierdzonym środowisku, bez DDL/DELETE/GRANT i bez uprawnień do edycji rang, punktacji i ról.
- Kod nie potrzebuje UPDATE ról, ale dokładne uprawnienia blokujących odczytów należy zweryfikować dla docelowej wersji MySQL/MariaDB. Jeśli wymagałaby nieakceptowalnego poszerzenia uprawnień, nie włączać korekt przed wydzieleniem konta/puli do operacji administracyjnych.
- Istniejące potrzeby provisioningu konta i WeaponPaints pozostają takie jak wcześniej. Ingestion nadal używa osobnej puli i uprawnień. Nie udostępniać jego sekretów puli WWW.
- Sprawdź prawdziwy Steam callback i właściciela, prywatność, uprawnienia SQL, integrację Nginx/FPM i liczbę graczy. Rollback funkcji zapisujących: `CLOUDHUB_ADMIN_CC_ENABLED=false`; zachować całą księgę i audyt.

## Świadomie niewykonane

Edycja progów i punktacji WWW jest **wyłączona**: wymaga wersjonowania reguł, decyzji o oczekujących zdarzeniach, spójnego momentu aktywacji i osobnych testów z ingestion. Panel daje tylko podgląd. Bez znajomych, komentarzy, sklepu CC, egzekwowania własności w WeaponPaints, CS2UIKit, uruchamiania pluginu na VPS i aplikacji Windows. Przyszła ekonomia nadal dotyczy wyłącznie bazowych skinów/noży/rękawic; personalizacja pozostaje darmowa.

Ranking korzysta z funkcji okienkowych MySQL 8+/MariaDB 10.2+. Nie wykonano testu obciążenia na skali produkcyjnej; wyliczanie TOP skanuje zbiór statystyk i może wymagać materializowanej projekcji przy dużej liczbie kont. Nie zastępować tego cachem ujawniającym prywatne profile. Nie potwierdzono działania w produkcyjnym PHP 8.5 ani rzeczywistego logowania Steam w tym środowisku.
