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
- `/admin`: sekcja „Sklep i ekonomia” (statystyki, wyszukiwanie, edycja ceny i aktywności z powodem).
- Linki „Sklep” i „Ekwipunek” są widoczne tylko przy aktywnym module.

## Katalog i propozycja cen — WYMAGA ZATWIERDZENIA OWNER

`bin/cloudhub-shop-catalog.php` buduje produkty z dołączonych plików `weapons.json`, `skins_en.json` i `gloves_en.json`:
- bez flag: dry run;
- `--apply`: dodaje brakujące produkty jako **nieaktywne**, istniejących cen nie zmienia;
- `--apply --activate`: dodatkowo aktywuje nowe produkty, dopiero po zatwierdzeniu cennika;
- `--simulate`: symulacja zarobków, bez bazy.

Domyślne wykończenia (paint 0) broni i rękawic pozostają darmowe. Wariant bez skina noża jest produktem.

Katalog nie zawiera rzadkości ani danych rynkowych, więc propozycja opiera się na klasie broni, modelu noża i rodzinie rękawic. Każdą cenę OWNER może zmienić ręcznie.

| Kategoria | Produkty | Propozycja |
|---|---:|---|
| Skiny broni | 1456 | 80 CC (shotguny, MG), 100–150 CC (pistolety, SMG), 150–250 CC (pozostałe karabiny, snajperki), 400 CC (AK-47, M4A4, M4A1-S), 600 CC (AWP) |
| Noże | 576 | 1500 CC (wariant bez skina), 1600 / 1800–1900 / 2200 CC (Karambit, Butterfly, M9) |
| Rękawice | 94 | 1600 CC (Hand Wraps) – 2200 CC (Sport Gloves) |

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
1. Limit `hourly_cc_cap = 100` ogranicza zarobek prawie każdego profilu do ~66 CC na 40-minutowy mecz. W praktyce CC nie zależą od umiejętności. Cele czasowe §11 są spełnione: tani skin 1–3 mecze, średni 3–8, nóż/rękawice 15–35 (23–34 mecze).
2. Przedziały 1500–4000 CC dla noży i rękawic przy tym limicie oznaczałyby nawet ~61 meczów. Dlatego propozycja zatrzymuje się na 2200 CC. Aby wykorzystać górę przedziału, trzeba zmienić limit godzinowy.
3. Wartości startowe z nowej §13.1 (MVP, przegrana runda) zmieniają RP: przeciętny 219, słaby 84 RP/mecz. Każdy profil nadal zyskuje RP. Na CC nie wpływają z powodu limitu. MVP i przegrana nie są jeszcze przesyłane przez plugin (krok CloudHub Ranks).

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
- `php tests/shop-pricing.php`: 36 asercji PASS;
- `bin/cloudhub-shop-catalog.php --simulate`: PASS;
- `git diff --check`: PASS.

Testy na bazie (`tests/shop.php`: seed, OWNER, zakupy, replay, IDOR, manipulacja ceną, rollback, współbieżność) oraz `tests/shop.spec.js` (API/UI) uruchamia harness Linux w CI. **Nie zostały jeszcze uruchomione**: wymagają push i CI po zgodzie właściciela.

Nie zweryfikowano: rzeczywistego Steam, produkcyjnej MariaDB/MySQL, grantów i obciążenia.
