# Etap 4 — raport testów

17 października 2026. Baza `master` `783223b9` po scalonym PR #5. Gałąź `cloudhub-stage4-social`. Testy wyłącznie lokalne: MariaDB przez Unix socket z `--skip-networking`, losowe bazy `cloudhub_test_*`, sztuczne konta i sesje. Nie używano produkcyjnego config.php, kont Steam ani danych VPS.

## Rzeczywiście wykonane kontrole

| Kontrola | Wynik |
|---|---|
| Fundamenty PHP | 31 asercji PASS |
| Core/rozliczenia PHP | 50 asercji PASS |
| Profile/ranking Etapu 3 PHP | 29 asercji PASS |
| OWNER/CC PHP | 24 asercje PASS |
| Społeczność PHP | 68 asercji PASS |
| Playwright API/UI | 21/21 PASS, 20,8 s |
| `pnpm lint` | PASS |
| `pnpm build` | PASS, istniejące ostrzeżenie modułu 3D ~663 kB |
| Składnia PHP backend/bin/tests | PASS |
| `git diff --check` | PASS |
| Lokalna kopia konfiguracji `nginx -t` | PASS |

Łącznie **202 asercje PHP**. Brak pominiętych testów lub oczekiwanych porażek w końcowym przebiegu. Testy PHP są uruchamiane przez harness przed testami API/UI. Stare testy serwisów PHP obejmują również wyłączoną flagę społeczności; nowy osobny proces PHP i cały przebieg API/UI działają z włączoną flagą. Migracja 003 wykonywana jest tylko w losowej bazie; fundamenty nadal sprawdzają ponowne wykonanie/checksumy i nienaruszenie WeaponPaints.

## Zakres nowych testów PHP

`tests/social.php`, z dodatkowymi niezależnymi procesami `tests/social-worker.php`:

- Wszystkie stany i kierunki zaproszeń; tylko odbiorca akceptuje/odrzuca, tylko nadawca anuluje. Odrzucenie samo-zaproszenia, nieistniejącego gracza i akceptacji przez trzecią osobę.
- Usuwanie znajomości, blokada zaakceptowanej relacji i oczekującego zaproszenia, odblokowanie bez odtwarzania relacji, brak możliwości usunięcia cudzej blokady.
- Dwa rzeczywiste połączenia/procesy PHP: duplikowane zaproszenia, krzyżowe zaproszenia, zaproszenie kontra blokada, dwie równoczesne publikacje komentarza. Odpowiednio jedna relacja, blokada końcowa i limit jednej publikacji.
- Profile i aktywność tylko dla znajomych, ukrycie przed obcymi i anonimowymi, prywatna lista znajomych, liczba znajomych, blokowanie profili i wcześniejszych komentarzy, polityki zaproszeń everyone/friends/disabled.
- Zachowanie domyślnego comments_policy=disabled. Komentarze everyone/friends/disabled, limit długości i częstotliwości, tekst zawierający HTML.
- Usuwanie przez autora, właściciela profilu i moderatora; odrzucenie trzeciej osoby; wymagany powód moderacji.
- Unikalność zgłoszeń, podgląd OWNER, zamknięcie zgłoszenia bez usunięcia komentarza, zamknięcie przy usunięciu, historia i powiadomienie o moderacji.
- Istniejące granty ADMIN/MODERATOR, brak uprawnień CC, natychmiastowe odebranie roli/grantu. Kontrola zapisów moderacyjnych używa blokujących odczytów uprawnień.
- Trigger awarii zapisu audytu: usunięcie komentarza zostaje wycofane, treść nadal dostępna.
- Oznaczenie własnego powiadomienia; obcy ID nie zmienia danych odbiorcy. Paginacja komentarzy i powiadomień bez duplikatów między stronami; brak bio/treści komentarza w payloadzie powiadomienia.
- Nieprawidłowe kursory/strony, odrzucenie RP i zewnętrznych motywów/banerów, zapis dozwolonej palety, limit 20 zaproszeń/h.

## API i interfejs

Dwa nowe rozbudowane scenariusze w `tests/social.spec.js` wraz z 19 wcześniejszymi testami:

- Uwierzytelnienie, GET/POST, CSRF, obcy Origin, próba edycji cudzego SteamID, API znajomości/prywatności, limit publikacji, zgłoszenie i moderacja OWNER, nieautoryzowany audyt, IDOR powiadomień, blokowanie i odblokowanie, nieprawidłowy parametr tablicowy.
- Prawdziwa przeglądarka: wysłanie zaproszenia, akceptacja drugim kontem, stan znajomości, komentarz ze znacznikiem script renderowany literalnie (brak wykonania JS), powiadomienia i oznaczanie przeczytania, usunięcie znajomego po potwierdzeniu, zapis motywu/nagłówka.
- UI moderatora: zgłoszony komentarz, powód usunięcia w natywnym dialogu, zamknięcie zgłoszenia oraz zapis widoczny w historii.
- Widok 390 px bez poziomego przepełnienia dla profilu z długą nazwą i komentarzem. Obejrzano zrzut profilu. Zrzuty w ignorowanym `test-results/`: `cloudhub-stage4-profile-mobile.png`, `cloudhub-stage4-friends-mobile.png`, `cloudhub-stage4-moderation.png`.
- Regresja: Steam callback, CSRF, skiny i loadouty, obie drużyny, konfigurator, błędy zapisu, ranking/odznaki, portfel, korekty OWNER i retry utraconej odpowiedzi, ingestion/HMAC.

Nowe konta społeczności rozszerzają pulę sesji. Istniejący test historii portfela sprawdza teraz jawnie dwóch uczestników swojej rundy (`users.slice(0,2)`), zamiast zakładać, że każde konto w harnessie uczestniczyło w niej. Nie zmieniono oczekiwań księgi ani naliczania CC.

## Polecenia

```bash
. /workspace/.cloud-tools/activate
bash scripts/start-test-db.sh # osobny proces lokalnej bazy testowej, bez TCP
CLOUDHUB_TEST_SOCKET=/workspace/.cloud-tools/test-mysql.sock \
CLOUDHUB_TEST_DB_USER=agent \
CHROMIUM_PATH=/workspace/.cloud-tools/sysroot/usr/lib/chromium/chromium pnpm test
pnpm lint
pnpm build
find backend bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check
```

Nginx: lokalna kopia `deployment/nginx.conf`, tymczasowy certyfikat localhost, ścieżki logów/temp/root ustawione pod `/workspace/work/nginx-stage4`. Wykonano wyłącznie `nginx -t`, bez uruchomienia usługi, reloadu ani zmian VPS.

## Problemy znalezione i poprawione

- Początkowy sandbox nie pozwalał na lokalne sockety i dostęp Git przez proxy. Rozszerzone wykonanie testów/fetch zostało zaakceptowane automatycznie; uruchomiono wyłącznie lokalną testową MariaDB. Nie zmieniano polityki sieci ani sekretów.
- W przygotowaniu nowych fixture poprawiono operator konkatenacji PHP.
- Test mobilny wykrył przepełnienie listy znajomych przez długi nick/SteamID: poprawiono min-width, zawijanie i układ identyfikatora.
- Test selektora nagłówka wykazał niejednoznaczną etykietę select; dodano jawne `aria-label`.
- Przegląd kontroli moderacji wykazał ryzyko użycia wcześniejszego obrazu ról w transakcji. Autoryzacja zapisu korzysta teraz z wyników aktualnych `FOR UPDATE`, a nie wtórnego odczytu snapshotu.

Po poprawkach wykonano cały zestaw ponownie, z wynikiem podanym w tabeli.

## Granice weryfikacji

PHP 8.4, MariaDB 11.8, Chromium, Node 24 w środowisku testowym. Nie potwierdzono PHP 8.5, prawdziwego Steam OpenID, produkcyjnego HTTPS/FPM, obciążenia produkcyjnego ani działania gry CS2. C# nie zmieniono; ponowne testy pluginu nie były wymagane przez zakres tego etapu. Stare testy PHP/API nadal weryfikują Core i naliczanie, ale nie stanowią testu serwera gry.

Współbieżność udowodniona dla wskazanych par operacji; nie jest to test przepustowości lub wszystkich możliwych awarii. Globalna blokada zapisów społeczności i brak automatycznej retencji wymagają oceny przed większą skalą. Funkcje pozostają domyślnie wyłączone; włączenie wymaga migracji 003 oraz osobno zatwierdzonego stagingu. Instrukcja i rollback: `COMMUNITY_STAGE4.pl.md`.
