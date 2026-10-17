# Etap 3 — raport testów, 17 października 2026

Baza: aktualny `master` `8fe59c8`, gałąź `cloudhub-stage3-profiles`. Wyłącznie lokalna MariaDB po Unix socket, bez TCP, i losowe bazy `cloudhub_test_*` tworzone/usuwane przez istniejący harness. Bez danych produkcyjnych. Testy wprowadzają syntetyczne konta wyłącznie do tych baz.

| Kontrola | Wynik końcowy |
|---|---|
| Fundamenty PHP | 31/31 asercji |
| Rozliczenia Core PHP | 50/50 asercji |
| Profile/ranking PHP | 29/29 asercji |
| OWNER/CC PHP | 24/24 asercje |
| Playwright API i UI | 19/19 testów, 23,3 s |
| `pnpm lint` | PASS |
| `pnpm build` | PASS |
| Składnia wszystkich PHP w backend/bin/tests | PASS |
| `git diff --check` | PASS |
| Lokalna kopia Nginx `nginx -t` | PASS |

Łącznie **134 asercje PHP**. Brak pominiętych testów i oczekiwanych porażek. Nowe testy dołączono do istniejącego harnessu, który sprawdza również nienaruszenie tabel i zapisanych ustawień WeaponPaints oraz idempotencję/checksumy migracji 001/002.

## Co zostało sprawdzone

- Jednoznaczny remis RP/kills przez SteamID, niezależne odznaki OWNER/ranga/TOP, zachowanie pozycji po wyszukiwaniu, rankingi sezonowe i historyczne `final_position`.
- Prawdziwa paginacja 110 dodatkowych kont testowych: po 10, 50 i 100 wyników, bez duplikatów między stronami; wszystkie dozwolone sortowania. Brak ujawnienia planowanego sezonu. Puste wyniki, zero zgonów i limity wejścia.
- Prywatny profil niewidoczny publicznie, własny dostęp, brak obejścia prywatności opisu przez OWNER, aktywność prywatna domyślnie, brak CC w publicznej projekcji.
- Edycja wyłącznie własnego opisu/prywatności; odrzucenie dodatkowego SteamID i RP, ustawień friends, niepoprawnych parametrów, prób wstrzyknięcia sortowania/SQL. Opis zawierający HTML renderowany jako tekst.
- Autoryzacja OWNER zarówno w API, jak i serwisie; PLAYER/ADMIN nie mogą przyznawać CC; odebranie OWNER działa natychmiast; flaga wyłączająca korekty blokuje także OWNER.
- Metoda POST, CSRF, obcy Origin, obowiązkowy powód, granice kwot, zerowe/ułamkowe kwoty, brak ujemnego salda.
- Idempotentne powtórki, konflikt zmienionej treści, poprawne aktor/kwota/saldo w księdze i audycie.
- Trigger błędu audytu dowodzi rollbacku salda i księgi. Dwa niezależne procesy PHP: ten sam UUID daje applied+duplicate; dwie różne wypłaty przekraczające wspólne saldo dają applied+conflict.
- UI OWNER po utracie odpowiedzi już wykonanej operacji: blokada zmiany celu, odświeżenie strony, odtworzenie UUID z sessionStorage, bezpieczne ponowienie i dokładnie jeden wpis audytu.
- Publiczny ranking/profil, wyszukiwarka i stan pusty; widok 390 px bez poziomego przepełnienia strony (tabela ma własny przewijany obszar); edycja profilu, portfel, panel OWNER i brak zakładki dla zwykłego gracza.
- Istniejące testy logowania, skinów, loadoutów, obu drużyn, edytora, błędów zapisu, mobilnej nawigacji, HMAC i prywatnej księgi nadal przechodzą.

## Polecenia

```bash
. /workspace/.cloud-tools/activate
bash scripts/start-test-db.sh # osobny proces, tylko lokalna baza testowa
CLOUDHUB_TEST_SOCKET=/workspace/.cloud-tools/test-mysql.sock \
CLOUDHUB_TEST_DB_USER=agent \
CHROMIUM_PATH=/workspace/.cloud-tools/sysroot/usr/lib/chromium/chromium \
pnpm test
pnpm lint
pnpm build
find backend bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
```

Nginx sprawdzono na kopii z lokalnymi ścieżkami logów/cache i jednorazowym certyfikatem localhost. Nie uruchomiono Nginx ani nie zmieniono VPS. Lokalny sandbox wymagał rozszerzonego wykonania dla socketów testowych i dostępu Git przez istniejące proxy; po jego udostępnieniu fetch/push i testy zakończyły się poprawnie. W pierwszym przebiegu rozszerzonego testu utraty odpowiedzi selektor `role=status` obejmował również równoległe komunikaty ładowania; ograniczono go do formularza korekty i cały zestaw powtórzono.

## Ograniczenia

PHP 8.4.26, MariaDB 11.8.6, Chromium i Node 24 w Codexie. Nie wykonano logowania rzeczywistym kontem Steam, testu PHP 8.5 ani produkcyjnej konfiguracji HTTPS/FPM, rzeczywistego CS2 i obciążenia produkcyjnego. Nowe operacje nie zmieniają pluginu C#, dlatego nie przebudowywano go w tym etapie. Build zgłasza istniejące ostrzeżenie rozmiaru opcjonalnego modułu 3D (~663 kB); brak błędów budowania.

Edycja rang/punktacji pozostaje świadomie wyłączona. Rzeczywisty OWNER wymaga potwierdzonego SteamID i ręcznej istniejącej procedury CLI. Korekty CC są domyślnie wyłączone. Procedura stagingu, minimalne uprawnienia i ryzyka: [COMMUNITY_STAGE3.pl.md](COMMUNITY_STAGE3.pl.md).
