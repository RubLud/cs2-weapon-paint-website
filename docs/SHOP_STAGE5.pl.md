# Etap 5 — sklep Cloud Coins i ekwipunek

Gałąź `cloudhub-stage5-shop`, utworzona z `cloudhub-stage4-social` (`f22094b`). Etap jest domyślnie wyłączony. Bez push, merge, wdrożenia i migracji produkcyjnej.

## Zakres

- Addytywna migracja `004_cloudhub_shop.sql`:
  - `cloudhub_shop_products`: produkt = broń/nóż/rękawice + paint, cena, aktywność;
  - `cloudhub_shop_price_history`: każda zmiana ceny i aktywności z autorem i powodem;
  - `cloudhub_shop_purchases`: zakup, cena z chwili zakupu, UUID żądania, powiązana transakcja portfela;
  - `cloudhub_player_inventory`: trwała własność (`purchase`, przygotowane `grant`/`migration`).
- Tabele `wp_*` WeaponPaints i zapisane skiny graczy są nietknięte. Test porównuje `wp_player_skins` przed zakupami i po nich.
- Zakup kosztuje wyłącznie bazowy przedmiot. Float, wear, pattern, naklejki, zawieszki, StatTrak, nametag i presety pozostają w istniejącym konfiguratorze i kosztują 0 CC. Test API zapisuje pełną personalizację kupionego skina i sprawdza, że saldo się nie zmienia.
- **Egzekwowanie własności nie jest częścią tego etapu.** Konfigurator i plugin nadal działają jak dotąd (`ownership_enforcement:false`). Etap 6 wymaga osobnej flagi, migracji posiadanych skinów i zgody OWNER (§12 starego dokumentu).

## API

`backend/shop.php` (moduł działa przy `CLOUDHUB_CORE_ENABLED`, `CLOUDHUB_SHOP_ENABLED` i zastosowanej migracji 004; inaczej 404/503):

| Akcja | Metoda | Dostęp | Opis |
|---|---|---|---|
| `catalog` | GET | każdy | Aktywne produkty; filtry `kind`, `weapon`, `search`, `sort`, `page` (48 na stronę); `owned` dla zalogowanego |
| `inventory` | GET | sesja | Własny ekwipunek, także przedmioty później wyłączone ze sprzedaży |
| `purchases` | GET | sesja | Własna historia zakupów (kursor `before`) |
| `buy` | POST + CSRF | sesja | `product_id`, `request_id` (UUID v4), opcjonalnie `expected_price` |

Zakup w jednej transakcji:
1. blokada portfela gracza (`FOR UPDATE`);
2. sprawdzenie `request_id`: powtórka zwraca `duplicate` bez obciążenia, inny produkt z tym samym UUID to 409;
3. odczyt produktu (`LOCK IN SHARE MODE`), cena wyłącznie z bazy;
4. `expected_price` różna od ceny serwera → 409, gracz nie płaci innej ceny niż widział;
5. posiadany przedmiot → 409, brak środków → 409;
6. zapis salda, transakcji `source='shop'` z saldem przed i po, zakupu i ekwipunku.

Saldo nie może spaść poniżej zera (sprawdzenie w kodzie i `CHECK` w bazie). Pola spoza listy (np. `price`, `steamid`) dają 422; tożsamość pochodzi tylko z sesji.

Zakupy wymagają dodatkowo `CLOUDHUB_SHOP_PURCHASES_ENABLED`. Bez tej flagi sklep działa jako podgląd (§12: „tryb podglądu sklepu bez aktywnej blokady”). `cloudhub.php` zwraca `features.shop` i `features.shop_purchases`.

OWNER (`backend/admin.php`):
- `shop_products`: lista z filtrami, także nieaktywne, z liczbą właścicieli;
- `shop_product` (POST + CSRF): cena 1–1 000 000 CC, aktywność, powód, UUID. Idempotentne dla pary (OWNER, UUID). Zapis w historii cen i w dzienniku administracyjnym `shop.product`;
- `shop_history`: historia cen produktu;
- `shop_stats`: CC w obiegu, liczba zakupów, wydane CC, przychody/wydatki według źródła, CC z rund w ciągu 7 dni i średnia na gracza (§11).

## Interfejs

- `/shop`: katalog z obrazami z tego samego katalogu WeaponPaints, filtry, cena, „Posiadasz”, zakup z potwierdzeniem w aplikacji (bez natywnych dialogów). UUID niepotwierdzonego zakupu jest zapisywany w `sessionStorage`; „Ponów ten sam zakup” nie obciąży dwa razy.
- `/inventory`: posiadane przedmioty z odnośnikiem „Personalizuj za 0 CC” oraz historia zakupów.
- `/wallet`: transakcje sklepu jako „Zakup w sklepie”.
- **Konfigurator WeaponPaints**: na liście skinów etykieta z ceną CC albo „Posiadasz”.
  - Dane pochodzą z `shop.php?action=prices`, jednego żądania na załadowanie strony; własność jest pokazywana tylko dla zalogowanego gracza.
  - Etykieta jest wyłącznie informacyjna. Wybór, podgląd, zapis i reset działają jak dotąd, bez blokady (egzekwowanie własności to Etap 6).
  - Przy wyłączonym sklepie etykiet nie ma.
- `/admin`: sekcja „Sklep i ekonomia” (statystyki, wyszukiwanie, edycja ceny i aktywności z powodem).
- Linki „Sklep” i „Ekwipunek” są widoczne tylko przy aktywnym module.

## Katalog i propozycja cen — WYMAGA ZATWIERDZENIA OWNER

`bin/cloudhub-shop-catalog.php` buduje produkty z dołączonych plików `weapons.json`, `skins_en.json`, `gloves_en.json` i `shop_rarity.json`:
- bez flag: dry run z tabelą kategorii;
- `--apply`: dodaje brakujące produkty jako **nieaktywne**, istniejących cen nie zmienia;
- `--apply --activate`: dodatkowo aktywuje nowe produkty, z wyjątkiem oczekujących na weryfikację. Tylko po zatwierdzeniu cennika;
- `--simulate [--cc-cap=N]`: symulacja zarobków, bez bazy. `--cc-cap` sprawdza wpływ innego limitu.

Domyślne wykończenia (paint 0) broni i rękawic pozostają darmowe. Wariant bez skina noża jest produktem.

### Research rzadkości i wartości (18 października 2026)

| Źródło | Co daje | Automatyzacja | Wniosek |
|---|---|---|---|
| [ByMykel/CSGO-API](https://github.com/ByMykel/CSGO-API) (`public/api/en/skins.json`) | Dane z plików gry CS2: rzadkość konkretnego wykończenia, `paint_index`, faza Doppler/Gamma Doppler, nazwa wykończenia, min/max float, StatTrak, Souvenir, kolekcje i skrzynki | Tak. Licencja MIT, plik statyczny bez klucza API, możliwość przypięcia do commitu | **Wybrane.** Snapshot w repozytorium, atrybucja w [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) |
| [SteamDB — fazy Doppler](https://steamdb.com/en/articles/cs2-doppler-phases-guide) | Fazy to osobne paint indexy: Doppler 415 Ruby, 416 Sapphire, 417 Black Pearl, 418–421 Phase 1–4; Gamma Doppler 568 Emerald, 569–572 Phase 1–4 (Glock-18: 1119–1123). Faza nie zależy od pattern seed | Odczyt kontrolny | Zgodne z danymi ByMykel; potwierdzone testem (Karambit 415/417/418/419/568) |
| [SteamDB — szanse ze skrzynek](https://steamdb.com/en/articles/cs2-case-opening-odds-explained), [prosettings.net](https://prosettings.net/blog/cs2-skin-rarity-explained/), [csdb.gg](https://csdb.gg/case/csgo-weapon-case/) | Ujawnione przez Valve w 2017 (Perfect World, Chiny): Mil-Spec 79,92%, Restricted 15,98%, Classified 3,20%, Covert 0,64%, nóż/rękawice 0,26% | — | Każdy wyższy poziom jest ok. 5× rzadszy. Uzasadnia kolejność cen, nie ich mnożnik |
| CSFloat | Ceny ofert, float, wzory | API wymaga klucza; regulaminu nie udało się odczytać; klienci API są nieoficjalni ([csfloat-rs](https://docs.rs/csfloat-rs)), endpoint wycen nieudokumentowany | **Nie używać automatycznie** bez potwierdzenia warunków |
| Steam Web API ([warunki](https://steamcommunity.com/dev/apiterms)) | Dane Steam | Licencja na dane dla użytkowników „for their personal use”, limit 100 000 wywołań dziennie; ceny rynku nie są oficjalnie udokumentowane | Nie budować cennika na scrapowaniu Steam Market |
| [CSPriceAPI](https://cspriceapi.com/csfloat-api) | Komercyjny agregator cen CSFloat (w tym faz Doppler) | Płatne API | Możliwe w przyszłości po decyzji i akceptacji warunków; nieużyte |
| SkinsMonkey, CSDB | Przeglądarki cen i skrzynek | Bez publicznego, udokumentowanego API do pobierania całego katalogu | Tylko do orientacyjnego porównania ręcznego |

Wnioski:
- **Rzadkość da się pobrać legalnie i automatycznie dla całego katalogu.** Dane ByMykel pokrywają 100% produktów: 2106/2106 malowanych skinów i rękawic plus 20 noży bez skina, dopasowanie po `weapon_defindex` + `paint_index`, bez duplikatów.
- **Cen rynkowych nie pobieramy automatycznie.** Warunki CSFloat i Steam nie dają jasnej zgody na wykorzystanie w cenniku, a spec zabrania przeliczania prawdziwych pieniędzy na CC. Ceny rynkowe są wyłącznie ręcznym punktem odniesienia dla OWNER.
- **Pattern seed, float, StatTrak, Souvenir i naklejki nie wpływają na cenę.**
  - Blue Gem (Case Hardened), procent Fade, Fire & Ice (Marble Fade), układ Crimson Web i float wybiera się za darmo w konfiguratorze (§10: „rzadki float lub wyjątkowy pattern również nie wymaga dopłaty”). Są tym samym produktem co zwykły egzemplarz wykończenia.
  - Pole `finish` w produkcie pozwala OWNER odszukać wykończenia (np. Fade, Lore) i ręcznie skorygować ich ceny.
  - Souvenir i kolekcje nie są w WeaponPaints osobnymi przedmiotami, więc nie tworzą produktów.
- **Fazy Doppler/Gamma Doppler są osobnymi produktami** (osobne paint indexy) z własnymi cenami.

### Automatyczne przypisanie kategorii i ceny

1. `node scripts/sync-shop-rarity.mjs` pobiera przypięty commit ByMykel (`72f9320…`), weryfikuje sha256 i zapisuje `frontend/public/data/shop_rarity.json`. Plik zawiera rzadkość, fazę, wykończenie, StatTrak/Souvenir, źródło, licencję i sumę kontrolną. Po aktualizacji CS2 commit i sumę zmienia się świadomie.
2. `CloudHubShopPricing::propose`, deterministycznie:
   - **Skin broni:** cena bazowa według poziomu rzadkości tego wykończenia (nie modelu broni). ×1,2 dla 7 najczęściej używanych broni (AK-47, M4A4, M4A1-S, AWP, Desert Eagle, USP-S, Glock-18), ×1,5 dla fazy Emerald.

     | Poziom | Cena bazowa |
     |---|---:|
     | Consumer | 50 |
     | Industrial | 80 |
     | Mil-Spec | 120 |
     | Restricted | 250 |
     | Classified | 500 |
     | Covert | 1000 |

   - **Nóż:** cena modelu (1600–2200 CC; wariant bez skina 1500 CC) plus premia fazy. Limit 5000 CC.

     | Faza | Premia |
     |---|---:|
     | Phase 1 / 3 | +200 |
     | Phase 2 / 4 | +400 |
     | Black Pearl | +1000 |
     | Ruby / Sapphire | +1400 |
     | Emerald | +1600 |

   - **Rękawice:** wszystkie mają poziom Extraordinary. Cena według rodziny, 1600–2200 CC.
3. **Weryfikacja zamiast zgadywania** (`needs_review=1` z powodem w produkcie):
   - brak danych o rzadkości → **brak ceny** (`price_cc = NULL`);
   - nieznana faza lub rodzina rękawic albo nietypowy poziom → brak ceny;
   - Contraband (M4A4 | Howl) → **wyłączony ze sprzedaży, bez ceny** (decyzja OWNER z 18.10.2026); pozostaje „Do weryfikacji”.

   `CHECK` w bazie nie pozwala aktywować produktu bez ceny ani oczekującego na weryfikację. `--activate` je pomija. Jawna cena zapisana przez OWNER kończy weryfikację.
4. `/admin` pokazuje rzadkość, fazę i status „Do weryfikacji”, z filtrem i ręczną zmianą każdej ceny (historia i powód).

Wszystkie produkty pozostają **nieaktywne** do zatwierdzenia przez właściciela.

### Wynik dla katalogu (`php bin/cloudhub-shop-catalog.php`)

Liczba meczów przy ~66 CC na 40-minutowy mecz (limit 100 CC/h):

| Kategoria / rzadkość | Produkty | Do weryfikacji | Cena CC | Mecze |
|---|---:|---:|---:|---:|
| Skin broni / Consumer | 200 | 0 | 50 | 1 |
| Skin broni / Industrial | 204 | 0 | 80–100 | 2 |
| Skin broni / Mil-Spec | 446 | 0 | 120–140 | 2–3 |
| Skin broni / Restricted | 311 | 0 | 250–300 | 4–5 |
| Skin broni / Classified | 183 | 0 | 500–600 | 8–10 |
| Skin broni / Covert | 111 | 0 | 1000–1800 | 16–28 |
| Skin broni / Contraband | 1 | 1 | brak (poza sprzedażą) | — |
| Noże / Covert | 576 | 0 | 1500–3800 | 23–58 |
| Rękawice / Extraordinary | 94 | 0 | 1600–2200 | 25–34 |

Na tle §9 i §11 starego dokumentu:
- tanie skiny: 50–150 CC, 1–3 mecze;
- popularne: 250–600 CC, 4–10 meczów;
- rzadkie i bardzo wartościowe: 1000–1800 CC, 16–28 meczów;
- noże i rękawice: 1500–3800 CC, 23–58 meczów; zwykłe wykończenia 23–34, zgodnie z celem 15–35;
- maksimum 5000 CC.

Mnożniki między poziomami (~2×) są celowo mniejsze niż różnice szans ze skrzynek (~5×), żeby wyższe poziomy pozostały osiągalne. Popularności rynkowej nie mierzymy automatycznie (brak wiarygodnego, dozwolonego źródła). Korekty za popularność wykończeń wprowadza OWNER.

### Symulacja zarobków (`--simulate`)

Mecz to 24 rundy w około 40 minut, profile z nowej §13.2. Limity godzinowe są uwzględnione. Pary zabójca–ofiara przyjęto jako zróżnicowane.

Reguły z migracji 002:

| Profil | RP/mecz | CC/mecz | Mecze do 100 CC | do 400 CC | do 2000 CC |
|---|---:|---:|---:|---:|---:|
| Słaby | 132 | 65 | 2 | 7 | 31 |
| Przeciętny | 244 | 66 | 2 | 7 | 31 |
| Dobry | 331 | 66 | 2 | 7 | 31 |
| Bardzo dobry | 333 | 66 | 2 | 7 | 31 |
| Dużo śmierci | 181 | 66 | 2 | 7 | 31 |
| Dużo MVP | 304 | 66 | 2 | 7 | 31 |
| Wysoki win rate | 287 | 66 | 2 | 7 | 31 |
| Niski win rate | 204 | 66 | 2 | 7 | 31 |

Wnioski do decyzji właściciela (wartości nagród **nie zostały zmienione**):
1. Limit `hourly_cc_cap = 100` (decyzja OWNER: na razie bez zmian) ogranicza zarobek prawie każdego profilu do ~66 CC na 40-minutowy mecz. W praktyce CC nie zależą od umiejętności. Cele czasowe §11 są spełnione: tani skin 1–3 mecze, średni 3–8, nóż/rękawice 15–35 (23–34 mecze).
2. Przy tym limicie zwykłe noże i rękawice (1500–2200 CC) to 23–34 mecze. Rzadkie fazy Doppler (do 3800 CC) to do 58 meczów, a 5000 CC to 76 meczów. Rzadkie fazy mają być ambitnym celem; krótszy czas wymagałby zmiany limitu godzinowego.
3. Wartości startowe z nowej §13.1 (MVP, przegrana runda) zmieniają RP: przeciętny 219, słaby 84 RP/mecz. Każdy profil nadal zyskuje RP. Na CC nie wpływają z powodu limitu. MVP i przegrana nie są jeszcze przesyłane przez plugin (krok CloudHub Ranks).

### Status cennika (decyzja OWNER, 18.10.2026)

- Cennik zaakceptowany **jako wersja testowa**. Sprzedaż dla graczy **niezatwierdzona**: produkty nieaktywne, `CLOUDHUB_SHOP_PURCHASES_ENABLED=false`.
- Różnice według rzadkości i faz (Doppler Ruby, Sapphire, Black Pearl, Gamma Doppler) pozostają.
- Rzadkie patterny (Blue Gem, procent Fade, Fire & Ice): pattern seed to darmowa personalizacja (§10), więc w danym wykończeniu nie ma osobnej ceny za seed. Różnicę cen niosą wykończenie i faza. Ewentualne dopłaty za wykończenia (np. Case Hardened) OWNER ustawia ręcznie po polu `finish`.
- Przedmioty bez wiarygodnej identyfikacji pozostają „Do weryfikacji”.
- Limit CC: `hourly_cc_cap` to wiersz w `cloudhub_reward_rules` (migracja 002). Backend czyta go przy każdym rozliczeniu rundy i waliduje w zakresie 0–100000, więc zmiana nie wymaga kodu ani nowej migracji. Edycja z poziomu WWW pozostaje wyłączona (decyzja z Etapu 3: wersjonowanie reguł). Zmiana wymaga osobnej, zatwierdzonej operacji na bazie. Symulację innego limitu daje `--cc-cap=N`.

## Wdrożenie (tylko za zgodą)

1. Kopia bazy, potem `bin/cloudhub-migrate.php --apply` na zatwierdzonym stagingu.
2. `bin/cloudhub-shop-catalog.php` (dry run), następnie `--apply`.
3. Zatwierdzenie cennika przez OWNER: ręcznie w `/admin` albo `--apply --activate` dla całej propozycji.
4. `CLOUDHUB_SHOP_ENABLED=true` (podgląd), testy, potem `CLOUDHUB_SHOP_PURCHASES_ENABLED=true`.
5. Granty konta WWW: SELECT/INSERT/UPDATE na nowych tabelach (DELETE nie jest wymagany).

Rollback: wyłączenie flag ukrywa sklep. Dane zakupów i ledger pozostają; nie usuwać ich bez decyzji właściciela. Migracja 004 nie zmienia istniejących tabel.

## Weryfikacja

Lokalnie na Windows:
- `pnpm lint` i `pnpm build`: PASS;
- `php -l` dla 52 plików PHP: PASS;
- `php tests/shop-pricing.php`: 48 asercji PASS;
- `node scripts/sync-shop-rarity.mjs`: 2126 wpisów, suma sha256 zgodna z przypiętym commitem;
- `bin/cloudhub-shop-catalog.php --simulate`: PASS;
- `git diff --check`: PASS.

Testy na bazie (`tests/shop.php`: seed, OWNER, zakupy, replay, IDOR, manipulacja ceną, rollback, współbieżność) oraz `tests/shop.spec.js` (API/UI) uruchamia harness Linux w CI. **Nie zostały jeszcze uruchomione**: wymagają push i CI po zgodzie właściciela.

Nie zweryfikowano: rzeczywistego Steam, produkcyjnej MariaDB/MySQL, grantów i obciążenia.
