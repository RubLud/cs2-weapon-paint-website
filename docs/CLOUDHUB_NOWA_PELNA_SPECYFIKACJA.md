# CLOUDHUB — KOMPLETNA SPECYFIKACJA I PRZEJĘCIE PROJEKTU PO OPENAI CODEX

> Nowsza specyfikacja właściciela, przekazana 18 października 2026. Treść zachowana bez zmian merytorycznych; dodano wyłącznie znaczniki nagłówków i tabeli Markdown. Ma pierwszeństwo przed `CLOUDHUB_REQUIREMENTS.pl.md` przy sprzecznościach — rozstrzygnięcia: sekcja „Aktualizacja wymagań” na początku `CLOUDHUB_REQUIREMENTS.pl.md`.

## 1. TWOJA ROLA I GŁÓWNE ZADANIE

Jesteś głównym programistą, architektem full-stack, specjalistą od Counter-Strike 2, CounterStrikeSharp (C#), bezpieczeństwa aplikacji, baz danych, projektowania interfejsów i aplikacji desktopowych.

Twoim zadaniem jest przejąć istniejący projekt CloudHub po OpenAI Codex i doprowadzić go do ukończenia.

Projekt obejmuje:

- Profesjonalną stronę internetową CloudHub.
- Logowanie i profile Steam.
- System rang i punktów rankingowych RP.
- Walutę Cloud Coins (CC).
- Rankingi globalne i sezonowe.
- System znajomych i komentarzy.
- Sklep ze skinami CS2.
- Ekwipunek graczy.
- Konfigurator WeaponPaints.
- Panel administracyjny.
- Plugin rankingowy CS2 w C#.
- Integrację z CS2UIKit.
- Aplikację Windows CloudHub Desktop.

Najważniejsze zasady:

1. Nie zaczynaj projektu od nowa.
2. Nie zastępuj działających funkcji nowymi implementacjami bez uzasadnienia.
3. Nie zakładaj, że historyczny plan odpowiada aktualnemu stanowi kodu.
4. GitHub, historia commitów, istniejące testy i rzeczywisty kod są źródłem prawdy.
5. Kontynuuj dokładnie od miejsca, w którym zakończył Codex.
6. Pracuj etapami, na osobnych gałęziach Git.
7. Testuj każdą istotną zmianę.
8. Nie wykonuj merge ani wdrożeń produkcyjnych bez mojej zgody.
9. Nie usuwaj istniejących danych użytkowników.
10. Nie kończ projektu na samej stronie — plugin CS2 i aplikacja Windows również są obowiązkowe.

## 2. REPOZYTORIUM GITHUB

Repozytorium projektu: https://github.com/RubLud/cs2-weapon-paint-website

Przed rozpoczęciem programowania:

1. Sprawdź połączenie z GitHubem.
2. Pobierz najnowsze informacje z repozytorium.
3. Zidentyfikuj domyślną gałąź (master lub main).
4. Sprawdź historię commitów.
5. Przeanalizuj wszystkie istotne branche.
6. Sprawdź otwarte, zamknięte i scalone pull requesty.
7. Przeczytaj dokumentację.
8. Przeanalizuj frontend, backend, API i bazę danych.
9. Sprawdź istniejące pluginy C#.
10. Sprawdź istniejące testy i konfigurację CI.
11. Zidentyfikuj ostatnie prace Codexa.
12. Ustal rzeczywisty stan każdego etapu.

Nie zakładaj, że niescalony PR jest częścią działającej wersji projektu.

Nie usuwaj ani nie nadpisuj pracy Codexa.

### Historyczny stan projektu

Według wcześniejszych ustaleń:

Etap 1 — Fundamenty
- Steam.
- Role i uprawnienia.
- Architektura bazy danych.

Etap 2 — CloudHub Core
- RP.
- Cloud Coins.
- Sześć rang.
- Anti-farm.
- HMAC.
- Transakcje MySQL.
- Integracja C# z backendem.

Etapy 1–2 były wcześniej zgłoszone jako scalone z master.

Etap 3 — Profile, rankingi, OWNER
- Profile graczy.
- Rankingi TOP 10/50/100.
- Odznaki administracyjne.
- Saldo Cloud Coins.
- Historia transakcji.
- Panel OWNER.

Etap 3 był wcześniej przygotowany w PR #5.

Etap 4 — Społeczność
- Znajomi.
- Komentarze.
- Personalizacja profili.

Etap 5 — Ekonomia
- Sklep.
- Zakupy za Cloud Coins.

Etap 6 — WeaponPaints
- Własność przedmiotów.
- Ekwipunek.
- Darmowa personalizacja.

Etap 7 — CS2UIKit

Etap 8 — Integracja i testy

Etap 9 — Aplikacja Windows

Ten opis ma charakter historyczny. Sprawdź rzeczywisty stan repozytorium przed ustaleniem zakresu dalszych prac.

## 3. GŁÓWNA WIZJA CLOUDHUB

CloudHub ma być profesjonalną platformą gamingową dla społeczności Counter-Strike 2.

Gracz loguje się kontem Steam i otrzymuje dostęp do:

- Profilu.
- Statystyk CS2.
- Rangi.
- Punktów RP.
- Cloud Coins.
- Rankingu.
- Znajomych.
- Sklepu.
- Ekwipunku.
- Konfiguratora skinów.
- Historii transakcji.
- Osiągnięć i odznak.

Strona, plugin CS2 i aplikacja Windows mają korzystać z jednego spójnego systemu kont i danych.

Nie twórz niezależnych systemów RP, CC lub ekwipunku, które nie synchronizują się ze sobą.

## 4. PROFESJONALNY WYGLĄD STRONY

Obecna strona wymaga dopracowania wizualnego.

Chcę, żeby CloudHub wyglądał jak profesjonalna platforma gamingowa, a nie prosty projekt demonstracyjny.

### 4.1 Nowa identyfikacja wizualna

Stwórz profesjonalne, autorskie logo CloudHub.

Aktualna zwykła ikonka chmurki jest niewystarczająca.

Logo powinno:

- Być nowoczesne i rozpoznawalne.
- Pasować do platformy gamingowej.
- Wyglądać profesjonalnie.
- Dobrze prezentować się w ciemnym motywie.
- Działać w małych rozmiarach.
- Nadawać się do strony i aplikacji Windows.
- Mieć wersję pełną i sam sygnet.
- Posiadać wariant SVG.
- Posiadać wariant PNG.
- Zawierać odpowiedni favicon.

Nie kopiuj gotowych logotypów innych firm.

Nie ograniczaj się do pobrania kolejnej standardowej ikonki z biblioteki.

Przed ostatecznym wdrożeniem przedstaw propozycję logo do zatwierdzenia.

### 4.2 Redesign interfejsu

Dopracuj:

- Stronę główną.
- Header.
- Menu nawigacyjne.
- Stopkę.
- Logowanie Steam.
- Profile graczy.
- Rankingi.
- Sklep.
- Ekwipunek.
- Konfigurator WeaponPaints.
- Panel administracyjny.
- Formularze.
- Powiadomienia.
- Widoki mobilne.

Wymagania:

- Nowoczesny dark mode.
- Spójna kolorystyka.
- Profesjonalna typografia.
- Dopracowane karty i przyciski.
- Delikatne animacje.
- Responsywność.
- Czytelne komunikaty błędów.
- Dostępność.
- Wysoka wydajność.

Nie usuwaj działających funkcji podczas redesignu.

Nie zastępuj prawdziwych danych fikcyjnymi statystykami.

### 4.3 Stopka i prawa autorskie

Zmień widoczną stopkę CloudHub.

Docelowa treść:

© 2026 CloudHub. All rights reserved.
Designed by RUB LUD

Usuń widoczny odnośnik przypisujący projekt twórcy pierwotnej strony WeaponPaints, o ile pozwala na to licencja.

Przed zmianą sprawdź:

- LICENSE.
- NOTICE.
- Wymagania licencyjne.
- Obowiązkowe informacje o autorach.

Jeśli licencja wymaga atrybucji, zachowaj ją w wymaganej formie.

Nie przypisuj RUB LUD autorstwa cudzych komponentów.

## 5. LOGOWANIE STEAM I KONTA

Wymagania:

- Logowanie przez Steam.
- Identyfikacja SteamID64.
- Avatar Steam.
- Nick Steam.
- Bezpieczna sesja.
- Kontrola uprawnień.
- Automatyczne powiązanie danych gracza.
- Ochrona przed podszywaniem się pod innych użytkowników.

Nie przechowuj haseł Steam.

Nie pozwalaj frontendowi samodzielnie decydować o uprawnieniach użytkownika.

## 6. PROFILE GRACZY

Każdy gracz powinien mieć profil zawierający:

- Avatar.
- Nick.
- SteamID64.
- Rangę serwerową.
- RP.
- Postęp do następnej rangi.
- Cloud Coins.
- Zabójstwa.
- Śmierci.
- K/D.
- MVP.
- Wygrane rundy.
- Przegrane rundy.
- Pozycję w rankingu.
- Odznaki.
- Osiągnięcia.
- Znajomych.
- Komentarze.
- Personalizację profilu.

Wszystkie statystyki mają pochodzić z rzeczywistych danych.

Nie pokazuj atrap udających działające funkcje.

## 7. RANGI I PUNKTY RP

CloudHub ma posiadać sześć rang serwerowych.

Jeśli Codex zaimplementował już nazwy, progi i zasady rang, zachowaj je.

System powinien:

- Przyznawać RP za aktywność.
- Odbierać niewielkie ilości RP za określone niepowodzenia.
- Aktualizować rangę na podstawie RP.
- Pokazywać postęp do następnej rangi.
- Zapisywać historię istotnych zmian.
- Obsługiwać rankingi globalne i sezonowe.

Przed implementacją sprawdź istniejącą logikę.

Nie zmieniaj samodzielnie progów rang bez przedstawienia uzasadnienia.

Kwestia możliwości utraty wcześniej zdobytej rangi wymaga osobnej decyzji użytkownika. Nie przyjmuj tego założenia automatycznie.

## 8. CLOUD COINS — EKONOMIA

Cloud Coins (CC) to wewnętrzna waluta platformy.

Gracz może zdobywać CC przez aktywność na serwerze CS2 i wydawać je w sklepie.

Wymagania:

- Saldo CC.
- Historia transakcji.
- Nagrody za aktywność.
- Zakupy.
- Zabezpieczenie przed podwójnym wydaniem.
- Atomowe transakcje.
- Idempotencja.
- Audyt.
- Integracja ze stroną i pluginem CS2.

Cloud Coins nie mogą być odejmowane za śmierć lub przegraną rundę.

Nie dopuszczaj do ujemnego salda.

## 9. SKLEP I WEAPONPAINTS

Istniejący konfigurator WeaponPaints musi zostać zachowany i rozbudowany.

### 9.1 Płatne przedmioty

Za Cloud Coins kupuje się:

- Bazowe skiny broni.
- Noże.
- Rękawice.

Każdy zakup trafia do ekwipunku gracza.

### 9.2 Darmowa personalizacja

Po zakupie przedmiotu gracz może bez dodatkowych opłat ustawiać:

- Float.
- Wear.
- Pattern.
- Naklejki.
- Zawieszki.
- StatTrak.
- Nametag.
- Inne ustawienia obsługiwane przez WeaponPaints.

Nie pobieraj Cloud Coins za zmianę konfiguracji.

### 9.3 Przebieg zakupu

1. Gracz loguje się przez Steam.
2. Otwiera sklep.
3. Wybiera przedmiot.
4. Backend sprawdza saldo.
5. Backend wykonuje bezpieczną transakcję.
6. Przedmiot zostaje przypisany do konta.
7. Gracz otwiera konfigurator.
8. Personalizuje przedmiot.
9. Zapisuje ustawienia.
10. System synchronizuje je z WeaponPaints.

Nie pozwalaj na korzystanie z płatnych przedmiotów bez ich posiadania.

Sprawdź rzeczywiste możliwości API WeaponPaints.

Nie implementuj fikcyjnych metod ani nie zakładaj kompatybilności bez weryfikacji.

## 10. RANKINGI

Wymagane rankingi:

- Globalny.
- Sezonowy.
- TOP 10.
- TOP 50.
- TOP 100.
- Pozycja zalogowanego gracza.
- Linki do profili.
- Aktualizacja danych z backendu.

Rankingi powinny korzystać z tego samego systemu RP co plugin CS2.

Nie twórz osobnej punktacji tylko dla strony.

## 11. ZNAJOMI I KOMENTARZE

Wymagania:

- Wysyłanie zaproszeń.
- Akceptowanie zaproszeń.
- Odrzucanie zaproszeń.
- Usuwanie znajomych.
- Lista znajomych.
- Lista oczekujących zaproszeń.
- Komentarze na profilach.
- Usuwanie własnych komentarzy.
- Moderacja.
- Ochrona przed spamem.
- Ochrona przed XSS.
- Ustawienia prywatności.

## 12. PANEL ADMINISTRACYJNY

Role:

- OWNER.
- ADMIN.
- MODERATOR.
- USER.

OWNER powinien mieć możliwość:

- Przeglądania użytkowników.
- Wyszukiwania po SteamID64.
- Zarządzania Cloud Coins.
- Przeglądania transakcji.
- Przeglądania logów.
- Zarządzania uprawnieniami.
- Moderacji.
- Kontrolowania wybranych funkcji platformy.

Wszystkie operacje administracyjne muszą być zabezpieczone po stronie backendu.

Nie pozwalaj użytkownikowi nadać sobie roli OWNER przez manipulację żądaniem API.

## 13. OBOWIĄZKOWY PLUGIN CS2 — CLOUDHUB RANKS

Po ukończeniu głównych funkcji strony i ich przetestowaniu masz stworzyć lub dokończyć plugin rankingowy CloudHub Ranks.

Plugin ma być napisany w C# z wykorzystaniem CounterStrikeSharp.

Przed implementacją sprawdź, czy Codex nie stworzył już odpowiedniej logiki RP i Cloud Coins.

Nie twórz drugiego mechanizmu naliczającego te same nagrody.

### 13.1 Nagrody i kary

Plugin musi obsługiwać:

- Zabicie przeciwnika — RP i CC.
- Wygranie rundy — RP i CC.
- MVP — RP i CC.
- Śmierć — ujemne RP.
- Przegranie rundy — ujemne RP.

Cloud Coins nie są odejmowane za śmierć lub przegraną.

#### Początkowy balans do przetestowania

| Zdarzenie | RP | Cloud Coins |
|---|---:|---:|
| Zabójstwo | +10 | +3 |
| Wygrana runda | +5 | +2 |
| MVP | +8 | +5 |
| Śmierć | −3 | 0 |
| Przegrana runda | −2 | 0 |

To wartości początkowe do symulacji, nie ostateczny balans.

Wszystkie wartości muszą być konfigurowalne.

### 13.2 Bardzo ważne — balans RP

System nie może być skonstruowany tak, żeby przeciętny aktywny gracz regularnie tracił tyle samo lub więcej RP, niż zdobywa.

Wymagania:

- Nagrody powinny przeważać nad karami przy typowej aktywnej grze.
- Śmierć nie może powodować nadmiernej utraty RP.
- Przegrana runda nie może karać nieproporcjonalnie.
- MVP powinno być odpowiednio premiowane.
- Dobry gracz powinien rozwijać rangę szybciej.
- Słabszy gracz również powinien mieć możliwość awansowania.
- Gracz nie powinien tracić całego postępu przez kilka słabszych rund.
- System nie powinien zachęcać do farmienia punktów.

Przygotuj symulacje dla różnych typów graczy.

Uwzględnij:

- Słabego gracza.
- Przeciętnego gracza.
- Dobrego gracza.
- Bardzo dobrego gracza.
- Gracza z dużą liczbą śmierci.
- Gracza zdobywającego dużo MVP.
- Gracza z wysokim win rate.
- Gracza z niskim win rate.

Sprawdź średni przyrost RP i CC na mecz oraz szacowany czas awansu między rangami.

Jeśli symulacje wykażą problemy, zaproponuj korektę.

### 13.3 Ochrona przed farmieniem

Wymagane zabezpieczenia:

- Brak nagród za samobójstwo.
- Brak nagród za teamkill.
- Ograniczenie farmienia jednego przeciwnika.
- Konfigurowalna minimalna liczba prawdziwych graczy.
- Odpowiednia obsługa botów.
- Ochrona przed duplikacją eventów.
- Idempotencja.
- Bezpieczna obsługa restartów.
- Bezpieczna obsługa rozłączeń.
- Zabezpieczenie przed podwójnym naliczaniem MVP.
- Zabezpieczenie przed podwójnym naliczaniem wyniku rundy.
- Ochrona przed manipulacją po stronie klienta.

Zaprojektuj naliczanie punktów tak, aby każdy event był przetwarzany w kontrolowany sposób.

### 13.4 Synchronizacja z backendem

Plugin musi synchronizować:

- SteamID64.
- RP.
- Rangę.
- Cloud Coins.
- Zabójstwa.
- Śmierci.
- MVP.
- Wygrane rundy.
- Przegrane rundy.
- Pozostałe obsługiwane statystyki.

Nie pozwalaj na utratę nagród przy chwilowej niedostępności API.

Zaprojektuj bezpieczny mechanizm ponawiania i deduplikacji.

### 13.5 Funkcje w grze

Gracz powinien mieć możliwość sprawdzenia:

- Rangi.
- RP.
- Postępu do kolejnej rangi.
- Cloud Coins.
- Pozycji w rankingu.
- Statystyk.

Dodaj komendy czatu lub konsoli zgodne z CounterStrikeSharp.

Przed ustaleniem nazw sprawdź, czy nie kolidują z istniejącymi pluginami.

### 13.6 Testy

Przygotuj testy:

- Naliczania zabójstw.
- Naliczania MVP.
- Wygranej rundy.
- Przegranej rundy.
- Śmierci.
- Wielokrotnych eventów.
- Restartów serwera.
- Rozłączeń graczy.
- Braku połączenia z API.
- Farmienia.
- Granic RP.
- Transakcji CC.
- Równoczesnych zdarzeń.

Nie wdrażaj pluginu na produkcyjny serwer bez mojej zgody.

## 14. CS2UIKIT

Zintegruj CloudHub z CS2UIKit, jeżeli aktualna wersja i API pozwalają na wymagane funkcje.

Docelowo gracz powinien móc zobaczyć w grze:

- Profil.
- Rangę.
- RP.
- Cloud Coins.
- Ranking.
- Wybrane funkcje ekwipunku.
- Informacje o nagrodach.

Interfejs ma być czytelny i spójny z CloudHub.

Nie zakładaj istnienia funkcji API, których nie zweryfikowałeś.

## 15. APLIKACJA WINDOWS — CLOUDHUB DESKTOP

Po ukończeniu strony, backendu, integracji i pluginu CS2 rozpocznij tworzenie aplikacji desktopowej Windows.

Nie pomijaj tego etapu.

Aplikacja ma być pełnoprawnym klientem CloudHub.

### 15.1 Funkcje

- Logowanie przez Steam.
- Panel główny.
- Profil gracza.
- Ranga.
- RP.
- Cloud Coins.
- Statystyki.
- Rankingi.
- Znajomi.
- Sklep.
- Ekwipunek.
- Konfigurator skinów.
- Powiadomienia, jeśli backend je obsługuje.
- Synchronizacja ze stroną.

### 15.2 Wygląd

Aplikacja powinna:

- Wyglądać nowocześnie.
- Korzystać z identyfikacji wizualnej CloudHub.
- Posiadać profesjonalne logo.
- Obsługiwać dark mode.
- Być czytelna.
- Działać płynnie.
- Nie zużywać niepotrzebnie dużej ilości zasobów.

### 15.3 Technologia

Przeanalizuj:

- Tauri.
- Electron.
- .NET.

Wybierz technologię odpowiednią do istniejącego projektu.

Przed implementacją przedstaw uzasadnienie wyboru.

### 15.4 Bezpieczeństwo

- Nie przechowuj haseł Steam.
- Nie łącz aplikacji bezpośrednio z produkcyjną bazą danych.
- Korzystaj z autoryzowanego API.
- Bezpiecznie przechowuj sesję.
- Obsługuj utratę połączenia.
- Nie zapisuj sekretów serwerowych w aplikacji.

### 15.5 Dystrybucja

Przygotuj:

- Build Windows.
- Instrukcję instalacji.
- Konfigurację środowiska.
- Dokumentację.
- Plan aktualizacji aplikacji.

Nie publikuj instalatora ani automatycznych aktualizacji bez zatwierdzenia.

## 16. ARCHITEKTURA I BEZPIECZEŃSTWO

Zachowaj istniejący stack technologiczny, chyba że zmiana jest wyraźnie uzasadniona.

Wymagania:

- Bezpieczne API.
- Autoryzacja backendowa.
- Walidacja danych.
- Ochrona CSRF tam, gdzie jest potrzebna.
- Ochrona XSS.
- Ochrona SQL injection.
- Bezpieczne transakcje.
- Idempotencja.
- Audyt.
- Migracje bazy danych.
- Testy regresyjne.
- Obsługa błędów.
- Bezpieczne zarządzanie sekretami.
- Logowanie zdarzeń.

Nie wykonuj destrukcyjnych migracji bez mojej zgody.

Nie zmieniaj konfiguracji produkcyjnego VPS bez zatwierdzenia.

Nie usuwaj istniejących danych graczy.

## 17. PRACA Z GITHUBEM

Pracuj etapami i twórz osobne branche.

Dla każdego etapu:

1. Przeanalizuj istniejący kod.
2. Przygotuj plan.
3. Utwórz branch.
4. Zaimplementuj funkcje.
5. Uruchom testy.
6. Sprawdź regresje.
7. Przygotuj commit.
8. Utwórz pull request.
9. Opisz zmiany.
10. Zaczekaj na zgodę przed merge.

Nie wykonuj force-push na głównej gałęzi.

Nie nadpisuj cudzych zmian.

Nie wdrażaj zmian produkcyjnych bez zgody.

## 18. KOLEJNOŚĆ REALIZACJI

Docelowa kolejność:

1. Audyt repozytorium GitHub.
2. Identyfikacja ostatniego etapu Codexa.
3. Dokończenie brakujących funkcji strony.
4. Profesjonalny redesign strony.
5. Nowe logo CloudHub.
6. Aktualizacja stopki i praw autorskich.
7. Testy strony, API i ekonomii.
8. Stworzenie lub dokończenie pluginu CloudHub Ranks.
9. Balans RP i Cloud Coins.
10. Testy i symulacje rankingu.
11. Integracja z CS2UIKit.
12. Testy integracji z serwerem CS2.
13. Dokończenie pozostałych funkcji platformy.
14. Projekt aplikacji Windows.
15. Implementacja CloudHub Desktop.
16. Testy końcowe.
17. Dokumentacja i przygotowanie do wdrożenia.

Jeśli istniejące zależności techniczne wymagają zmiany kolejności, przedstaw uzasadnienie.

Nie wykonuj ponownie ukończonych etapów.

## 19. RAPORTOWANIE POSTĘPÓW

Po każdym etapie przygotuj raport zawierający:

- Co zostało zrobione.
- Jakie pliki zmieniono.
- Jakie funkcje dodano.
- Jakie testy uruchomiono.
- Wyniki testów.
- Znane problemy.
- Link do PR.
- Co pozostało.
- Co proponujesz jako następny etap.

Nie deklaruj ukończenia funkcji, która nie została przetestowana.

Jeśli czegoś nie możesz zweryfikować, wyraźnie to zaznacz.

## 20. PIERWSZE ZADANIE — PEŁNY AUDYT GITHUBA

> Stan na 18 października 2026: audyt wykonano wcześniej (raport Etapów 1–9). Właściciel polecił nie powtarzać go i kontynuować Etap 5.

TERAZ NIE ROZPOCZYNAJ JESZCZE PROGRAMOWANIA.

Najpierw wykonaj pełny audyt repozytorium.

Sprawdź:

1. Aktualną gałąź główną.
2. Ostatnie commity Codexa.
3. Otwarte PR.
4. Niescalone branche.
5. Wszystkie ukończone etapy.
6. Wszystkie częściowo ukończone etapy.
7. Brakujące funkcje.
8. Istniejącą architekturę.
9. Stan frontend/backend/API.
10. Stan pluginów C#.
11. Stan integracji WeaponPaints.
12. Stan RP i Cloud Coins.
13. Stan rankingu.
14. Stan sklepu.
15. Stan aplikacji Windows, jeśli istnieje.
16. Stan testów.
17. Problemy bezpieczeństwa.
18. Potencjalne konflikty między komponentami.

Przygotuj szczegółowy raport.

Dla każdego etapu określ:

- GOTOWE.
- CZĘŚCIOWO GOTOWE.
- NIEZROBIONE.
- WYMAGA POPRAWY.
- WYMAGA TESTÓW.

Podawaj konkretne pliki, commity i pull requesty potwierdzające ocenę.

Następnie przedstaw plan dalszej realizacji.

Zatrzymaj się po raporcie i poczekaj na moją akceptację.

Nie modyfikuj plików, nie twórz commitów, nie wykonuj merge i nie wdrażaj zmian podczas audytu.

## 21. CEL KOŃCOWY

Chcę otrzymać kompletny, profesjonalny CloudHub obejmujący:

- Nowoczesną stronę internetową.
- Profesjonalne logo.
- Stopkę „Designed by RUB LUD”.
- Logowanie Steam.
- Profile.
- Rangi.
- RP.
- Cloud Coins.
- Rankingi.
- Znajomych.
- Komentarze.
- Sklep.
- Ekwipunek.
- WeaponPaints.
- Panel administracyjny.
- Plugin CloudHub Ranks.
- Zbalansowane nagrody i kary.
- Integrację z Counter-Strike 2.
- CS2UIKit.
- Aplikację Windows.
- Bezpieczny backend.
- Testy.
- Dokumentację.

Wszystkie elementy muszą współpracować i korzystać ze spójnych danych.

Nie twórz nowego projektu. Kontynuuj istniejący projekt Codexa.
