# CloudHub — Etap 4: społeczność

Baza: `master` `783223b9d10c6771a09303c9c1025ffca6eeef62`. PR #5 potwierdzony jako scalony 17 października 2026. Gałąź `cloudhub-stage4-social`. Rozszerzenie istniejącego React/Vite, sesji Steam, PDO, ról i audytu; bez zastępowania Core, WeaponPaints ani korekt CC. Nie wykonano wdrożenia, produkcyjnej migracji ani Merge.

## Zakres i strony

- `/friends`: lista znajomych z liczbą i paginacją, otrzymane/wysłane zaproszenia, blokady, zarządzanie relacją, odsyłacz do istniejącej wyszukiwarki nick/SteamID64. Bezpośrednie sprawdzenie SteamID umożliwia kontakt z kontem, którego profil jest ukryty, jeśli przyjmuje zaproszenia.
- `/players/:steamid`: zachowane statystyki, role/rangi/TOP i sezony, dodatkowo relacja, znajomi, komentarze i edycja prywatności. Bio do 1000 znaków. Cztery wbudowane motywy i cztery nagłówki CSS; brak uploadów, zewnętrznych banerów i własnego CSS.
- `/notifications`: prywatne powiadomienia, licznik nieprzeczytanych, oznaczanie pojedynczych wpisów lub wszystkich naraz (jeden zapis), link do zaproszeń/profilu. Odświeżanie co 60 sekund w widocznej karcie; brak e-mail, push i WebSocket.
- `/moderation`: zgłoszenia, usunięcie komentarza lub zamknięcie zgłoszeń z powodem, osobna historia moderacji. Ten sam panel jest częścią `/admin`, gdy moduł jest aktywny i konto ma `moderation.manage`; korekty CC zachowują dotychczasową politykę i implementację.
- Komentarze pokazują odznaki autora: rola personelu (OWNER/ADMIN/MODERATOR) zawsze, ranga i TOP tylko wtedy, gdy profil autora jest widoczny dla oglądającego. „Zgłoś” nie jest dostępne przy własnym komentarzu (backend zwraca 422).
- Nowe elementy mają stany ładowania, pustych list i błędów. Odświeżenia po akcjach i co 60 s zachowują poprzednie dane zamiast pokazywać ponownie „Wczytywanie”. Destrukcyjne operacje wymagają potwierdzenia w aplikacyjnym `askConfirm` (`lib/dialogs.js`), bez natywnych `confirm/prompt`. Powód moderacji i zgłoszenia wpisuje się w formularzu inline z etykietą. Formularze mają etykiety, przyciski widoczny fokus i obsługę klawiatury.
- Wiersze list znajomych i zaproszeń zawierają gotową relację, więc `/friends` nie wysyła osobnego `relation` dla każdej pozycji. Informacja o module (`capabilities`) jest pobierana raz na załadowanie aplikacji.

## Flaga i kompatybilność

`CLOUDHUB_SOCIAL_ENABLED=false` domyślnie, oprócz istniejącego `CLOUDHUB_CORE_ENABLED`. Wyłączona flaga zwraca 404 w `social.php`, zachowuje stare UI edycji profilu i zachowanie Etapu 3. Włącz ją dopiero po migracji 003. Moduł jest aktywny tylko wtedy, gdy flaga jest włączona **i** `cloudhub_schema_migrations` zawiera `003_cloudhub_social.sql` (`CloudHubSocial::active`). Flaga włączona przed migracją nie psuje rankingu ani profili: działają jak w Etapie 3, `social.php` zwraca 503, a log PHP dostaje ostrzeżenie. Publiczna projekcja profilu podaje `social_enabled` według tego stanu. `community.php` zamienia nieoczekiwane wyjątki na 503 bez szczegółów.

Zachowano wartości istniejących `profile_visibility`, `activity_visibility`, `comments_policy`, `bio`, `theme`, `banner`. Brak masowego włączania komentarzy: istniejące `disabled` pozostaje wyłączone. Nowe konta mają prywatną listę znajomych oraz przyjmują zaproszenia od wszystkich. Ustawienia są odczytywane przez LEFT JOIN i wartości domyślne; nie wymagają zmiany provisioningu kont ani pluginu C#.

Stary POST `community.php?action=edit` nadal przyjmuje tylko bio i dwa stare pola prywatności; gdy społeczność działa, zapis przechodzi przez ten sam mechanizm transakcyjny. Dodatkowe pola są odrzucane. Nowa pełna edycja jest w `social.php?action=settings`.

## Migracja 003

`migrations/003_cloudhub_social.sql`, addytywna, powtarzalna po przerwaniu, zarządzana istniejącym CLI/checksumami. Nie zmienia 001/002 ani tabel `wp_*`.

| Tabela | Przeznaczenie i ograniczenia |
|---|---|
| `cloudhub_social_settings` | Nowa prywatność listy i zaproszeń; PK/FK SteamID64 |
| `cloudhub_friendships` | Jedna kanoniczna para `low_id < high_id`, PK pary, nadawca należący do pary, pending/accepted |
| `cloudhub_blocks` | Kierunkowy PK actor/target, zakaz blokady siebie |
| `cloudhub_profile_comments` | Tekst, autor, profil, daty i miękkie usunięcie; indeks profilu/id |
| `cloudhub_comment_reports` | Powód, zgłaszający, zamknięcie; UNIQUE komentarz/zgłaszający |
| `cloudhub_notifications` | Odbiorca, typ, odsyłacz, przeczytanie; indeksy odbiorcy/id i nieprzeczytanych |
| `cloudhub_social_limits` | Stała liczba liczników na konto/akcję, niezależnych od IP i sesji |

Wszystkie relacje do graczy/komentarzy mają FK. Zapisy administracyjne trafiają do istniejącego `cloudhub_admin_audit_logs`, z akcjami `social.comment.delete`, `social.comment.moderate`, `social.report.resolve`. Audyt społeczności nie udostępnia operacji CC.

## Współbieżność i limity

Każdy zapis społecznościowy uzyskuje `GET_LOCK` z nazwą zależną od bazy, następnie rozpoczyna transakcję InnoDB. Zapis ustawień, blokada, relacja, komentarz, powiadomienie i audyt są serializowane względem siebie. Blokada jest zwalniana w `finally`; błąd dowolnego zapisu powoduje rollback. Timeout blokady daje 503. Odczyty API korzystają ze spójnego obrazu transakcyjnego. Wszystkie znaczniki czasu zapisów społeczności (komentarze, zgłoszenia, powiadomienia, relacje, blokady, audyt `social.*`) są jawnie ustawiane na `UTC_TIMESTAMP(6)`, niezależnie od strefy sesji DB; frontend interpretuje je jako UTC.

To świadomy kompromis dla obecnej skali portalu: prostsze dowodzenie poprawności kosztem globalnej kolejki zapisów społeczności. Nie blokuje kolejki Core i portfeli, które mają własne transakcje. Przed dużą skalą potrzebne pomiary i ewentualne blokady par/kont w stałej kolejności, z zachowaniem testów wyścigów.

- Maksymalnie 120 skutecznych zapisów społecznościowych/konto/godzinę, również ustawień i oznaczeń przeczytania.
- Zaproszenia: 20/h i co najmniej 3 sekundy odstępu.
- Komentarze: 30/h i co najmniej 15 sekund odstępu, 1–1000 znaków Unicode.
- Zgłoszenia: 20/h i co najmniej 3 sekundy odstępu, powód 1–500 znaków.
- Liczniki w bazie nie resetują się przy zmianie IP/sesji ani usunięciu komentarza. Dodatkowo działa istniejący limiter HTTP. Odrzucone transakcje nie zużywają liczników sukcesów; próby błędnych żądań ogranicza ogólny limiter HTTP.
- Odpowiedź 429 zawiera komunikat i orientacyjny Retry-After. Okno godzinowe może wymagać dłuższego oczekiwania.
- Powtórzone zaproszenie daje 409, nie duplikat. Krzyżowe zaproszenia wymagają świadomej akceptacji już istniejącego zaproszenia. Ponowne block/unblock/read jest bezpieczne. Duplikat zgłoszenia nie tworzy kolejnego rekordu.

## Relacje i prywatność

- Profil, lista znajomych i aktywność: `public/friends/private`. Komentarze i zaproszenia: `everyone/friends/disabled`. Właściciel zawsze widzi własne dane i może komentować własny profil.
- „Znajomi” to zaakceptowana relacja CloudHub; brak synchronizacji Steam. Tylko odbiorca akceptuje/odrzuca, tylko nadawca anuluje, obie strony mogą usunąć znajomość.
- „Tylko znajomi” dla zaproszeń faktycznie zamyka nowe znajomości: obcy użytkownik nie może wysłać zaproszenia. UI wyjaśnia tę semantykę.
- Blokada w dowolną stronę uniemożliwia wzajemne zaproszenia, dostęp do profilu, listy i nowe komentarze/zgłoszenia względem drugiej osoby. Usuwa istniejącą znajomość/zaproszenie oraz powiadomienia pomiędzy parą. Odblokowanie usuwa tylko własną blokadę i nie odtwarza relacji. `relation` zwraca `blocked` tylko dla własnej blokady. Blokada ustawiona przez drugą stronę daje `unavailable`, identycznie jak konto, które nie przyjmuje zaproszeń od oglądającego; zablokowany nie dowiaduje się więc o blokadzie.
- Wcześniejsze komentarze blokowanego autora są ukryte dla osoby blokującej (również na cudzych profilach), lecz zachowane dla innych uprawnionych odbiorców. Blokada nie usuwa globalnie historii wypowiedzi. Autor nadal może usunąć swój komentarz; właściciel może porządkować swój profil, niezależnie od blokady.
- Profil `friends` jest dostępny znajomym przez URL; publiczna wyszukiwarka i ranking nadal pokazują tylko profile publiczne. Blokady dodatkowo filtrują ranking zalogowanego odbiorcy. Pozycje TOP wylicza się przed tym filtrem, jak w Etapie 3.
- Udostępniona lista znajomych pokazuje podstawową tożsamość (nick/avatar/SteamID), także gdy znajomy ma prywatną zawartość profilu. Nie udostępnia statystyk, opisu ani aktywności tego znajomego. Właściciel listy decyduje o widoczności własnej relacji.
- OWNER zachowuje dostęp do statystyk prywatnego profilu z Etapu 3, lecz nie omija prywatności bio/nagłówka/motywu ani aktywności; blokada ukrywa również ten widok. Moderacja jest osobnym uprawnionym widokiem zgłoszonych treści, bez portfela.
- Publiczne komentarze zawierają tożsamość autora i datę, ale nie jego prywatną aktywność/profil. Ukrycie własnego profilu nie anonimizuje wcześniejszych wypowiedzi na cudzych profilach.
- Powiadomienia nie kopiują komentarza, bio, salda ani tożsamości moderatora. Kliknięcie odsyłacza ponownie sprawdza bieżącą prywatność. Wpisy oznacza tylko ich odbiorca; podanie cudzego ID nie zmienia jego danych.

## Uprawnienia i bezpieczeństwo

Istniejący `CloudHubAccounts::can(actor,'moderation.manage')`: OWNER ma dostęp, ADMIN i MODERATOR tylko poprzez skonfigurowany grant (oba mają taki grant w migracji 001). Nie otrzymują uprawnień OWNER, korekt CC, ról ani konfiguracji ekonomii. Kontrola moderacji przy zapisie używa aktualnych odczytów ról i grantów z blokadą współdzieloną (`LOCK IN SHARE MODE`), a nie ról z sesji lub wcześniejszego obrazu transakcji. Równoczesne odebranie roli/grantu czeka na koniec transakcji moderacyjnej. Blokada współdzielona wymaga tylko uprawnienia SELECT. Cofnięcie grantu obowiązuje dla następnej operacji.

Usunięcie własnego komentarza i komentarza na własnym profilu nie wymaga uprawnień moderatora, ale także zapisuje audyt. Moderacja cudzego komentarza i zamknięcie zgłoszeń wymagają powodu oraz uprawnienia. Błąd audytu cofa usunięcie, zamknięcie zgłoszeń i powiadomienia. Usunięcie oznacza `deleted_at`, nie kasuje materiału historycznego.

Sesja Steam i tożsamość aktora są brane z backendu. Zmiany wyłącznie POST + istniejący CSRF/same-origin. Whitelist pól, walidacja SteamID64 i dodatnich ID/kursorów, prepared statements, ograniczona paginacja. Bio i komentarze są zwykłym tekstem React; brak `dangerouslySetInnerHTML`. Motyw/nagłówek tylko ze stałych list. Brak zapisu RP/CC/rang/ról przez nowe API. Odpowiedzi `no-store`. Błędy DB nie ujawniają zapytań ani konfiguracji.

## API

Wszystkie nowe akcje: `/api/social.php?action=...`. Listy kursorowe zwracają `items,next_before`, po 20 wpisów; `before` jest dodatnim BIGINT jako ciąg. Listy znajomości mają `items,total,page`, po 20 wpisów, page 1–10000. Nie ma nieograniczonego eksportu.

| Metoda/akcja | Parametry | Dostęp |
|---|---|---|
| GET friends | target, page | Prywatność profilu i listy; własna lista zawiera `relation` w wierszach |
| GET comments | target, before | Prywatność profilu i blokady; wiersze mają `badges`, `can_delete`, `can_report` |
| GET relation | target | Zalogowany; tylko własna relacja: `none/sent/received/friends/blocked/unavailable` |
| GET connections | kind=received/sent/blocked, page | Własne listy, wiersze zawierają `relation` |
| GET settings | — | Własne ustawienia |
| GET capabilities | — | Własne uprawnienie moderate |
| GET notifications | before | Własne wpisy + unread |
| GET moderation / audit | before | moderation.manage |
| POST invite/accept/reject/cancel/remove/block/unblock | target | Stan relacji + polityka/blokady |
| POST comment | target, body | Polityka komentarzy i profilu |
| POST delete | id, reason dla moderacji | Autor, właściciel profilu lub moderacja |
| POST report | id, reason | Zalogowany z dostępem do cudzego komentarza |
| POST resolve | id komentarza, reason | moderation.manage; zamyka jego zgłoszenia |
| POST read | id powiadomienia | Odbiorca |
| POST read_all | — | Odbiorca; jeden zapis dla wszystkich nieprzeczytanych |
| POST settings | bio, profile_visibility, activity_visibility, friends_visibility, invites_policy, comments_policy, theme, banner | Właściciel, dozwolone pola |

401 brak sesji, 403 brak dostępu/polityka/CSRF, 404 niedostępny profil/komentarz/moduł, 405 metoda, 409 konflikt relacji, 422 walidacja, 429 limit, 503 zajętość/awaria/brak migracji 003. Brakujące konto wymaga ponownego zweryfikowanego logowania Steam z włączonym Core.

## Instrukcja stagingu (nie wykonano)

1. Potwierdź oddzielny staging oraz bazę bez danych produkcyjnych. Zrób backup stagingowej bazy i zachowaj dotychczasową konfigurację. Zbuduj frontend zgodnie z `docs/TESTING.md`.
2. Pozostaw `CLOUDHUB_SOCIAL_ENABLED=false`. Administrator sprawdza docelowy host/nazwę bazy w prywatnej konfiguracji. Użyj istniejącego CLI, wyłącznie na tym stagingu:
   `CLOUDHUB_CONFIG=/prywatna/sciezka/staging-migration.php php bin/cloudhub-migrate.php --apply`.
3. Sprawdź zapis checksum migracji 003, klucze i indeksy, oraz zachowanie istniejącej prywatności. Migrator nie jest uruchamiany automatycznie przez WWW ani setup. MariaDB DDL auto-commit: po przerwaniu uruchom ponownie niezmienioną migrację.
4. Konto WWW: SELECT na społeczności i istniejących profilach/graczach/rolach; INSERT/UPDATE social_settings i social_limits; INSERT/UPDATE/DELETE friendships, blocks, notifications; INSERT/UPDATE profile_comments i comment_reports; UPDATE tylko bio/theme/banner/profile_visibility/activity_visibility/comments_policy w profiles; INSERT audytu; SELECT na `cloudhub_schema_migrations` (kontrola gotowości modułu). Odczyty ról/grantów z `LOCK IN SHARE MODE` wymagają wyłącznie SELECT. Nie nadawaj UPDATE na tabelach ról. Zachowanie blokady współdzielonej i GET_LOCK sprawdź na docelowej wersji DB z minimalnymi uprawnieniami. Nie nadawaj DDL/GRANT ani nowych praw do portfeli, statystyk i ról.
5. Zaktualizowana przykładowa konfiguracja Nginx dopuszcza `social.php`. Dopiero w osobno zatwierdzonym stagingu sprawdź `nginx -t`, konfigurację FPM, HTTPS i sesje. Nie kopiuj sekretów ingestion do puli WWW. Włącz `CLOUDHUB_SOCIAL_ENABLED=true` w prywatnej konfiguracji stagingu.
6. Dwoma testowymi kontami sprawdź zaproszenia w obie strony, blokady i prywatność. Kontem z istniejącym potwierdzonym uprawnieniem sprawdź zgłoszenie/usunięcie/audyt. Nie przypisuj rzeczywistego OWNER automatycznie. Zweryfikuj Steam OpenID, mobilny UI, limity oraz rzeczywiste uprawnienia DB. Testy lokalne opisano w raporcie.

## Rollback

Wyłącz `CLOUDHUB_SOCIAL_ENABLED`, zachowaj migrację 003, dane społeczności i audyt. Przywróć poprzedni artefakt aplikacji oraz konfigurację Nginx tylko w zatwierdzonym środowisku. Etap 3 ignoruje addytywne tabele i traktuje `friends` jako prywatne, co nie ujawnia ich publicznie. Stary edytor profilu zna tylko `public/private`: jeśli gracz z ustawieniem `friends` zapisze profil podczas rollbacku, ustawienie zmieni się na `private`. To bezpieczne dla prywatności; po ponownym włączeniu gracz może przywrócić `friends`. Nie wykonuj DROP nowych tabel, nie usuwaj rekordu/checksumy migracji ani historii moderacji. Nie resetuj CC/statystyk i nie cofaj 001/002. Ponowne włączenie używa zachowanych relacji i ustawień.

## Ograniczenia i dalsze prace

Brak pomiarów na skali produkcyjnej, rzeczywistego Steam, PHP 8.5 i Nginx/FPM na VPS. Globalna blokada zapisów wymaga pomiaru przed zwiększeniem skali. Limiter HTTP pozostaje dotychczasowym mechanizmem plikowym; wiele hostów wymaga wspólnej strategii brzegowej. Limitery sukcesów społeczności są wspólne przez bazę.

Nie dodano edycji komentarzy, automatycznej retencji komentarzy/zgłoszeń/powiadomień ani narzędzia usuwania kont. Retencja wymaga osobnej polityki i audytowanej procedury, szczególnie dla miękko usuniętych treści. Paginacja list przez offset może zmieniać skład podczas edycji znajomości; komentarze/powiadomienia używają stabilnego kursora ID. Brak gwarancji exactly-once publikacji komentarza po utracie odpowiedzi; limit odstępu ogranicza szybki duplikat, lecz przed późniejszym ponowieniem należy sprawdzić listę.

Otwarte wymagania specyfikacji (§17, §21), świadomie poza tym etapem: moderacja publicznych opisów profili przez personel, blokady moderacyjne (czasowy zakaz komentowania/zapraszania), polityka retencji i usuwania kont/danych, indeks `cloudhub_admin_audit_logs.action` oraz `ON DELETE` dla FK. Wymagają osobnej migracji 004 i decyzji właściciela. Migracja 003 pozostaje niezmieniona, bo zmiana pliku zmienia checksum.

Etap 5 pozostaje niezrealizowany: katalog sklepu CC, zatwierdzona ekonomia i transakcyjne zakupy. Własność/WeaponPaints, CS2UIKit, wdrożenie pluginów i Desktop pozostają w kolejnych osobno zatwierdzonych etapach. Nie zmieniono sald ani ról rzeczywistych kont.
