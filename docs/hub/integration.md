# Domowy magazyn jako moduł huba: integracja

Dokument dla zespołu huba. Odpowiada na brief (`docs/modules/magazyn/brief.md` w repo huba), a kontrakt API jest w `openapi.yaml` obok. Lista zmian w aplikacji dla właściciela: `zmiany-w-aplikacji.md`.

Status: dokument opisuje stan docelowy; kod w tym repozytorium powstaje na jego podstawie (gałąź `hub-module-integration`). Rozbieżność między dokumentem a kodem jest błędem w jednym z nich i proszę ją zgłaszać.

## 1. Proponowany manifest

```json
{
  "id": "magazyn",
  "name": "Magazyn",
  "description": "Domowy magazyn: stany produktów, daty ważności i stany minimalne",
  "contractVersion": 1,
  "moduleVersion": "1.0.0",
  "minClientVersion": "1.0.0",
  "icon": "inventory_2",
  "scopes": [
    { "name": "magazyn:items:read", "description": "Podgląd produktów, grup i alertów", "defaultRoles": ["admin", "adult", "child"] },
    { "name": "magazyn:items:write", "description": "Dodawanie i zdejmowanie sztuk, nowe produkty, edycja produktów", "defaultRoles": ["admin", "adult", "child"] },
    { "name": "magazyn:items:admin", "description": "Stany minimalne oraz grupy produktów i ich jednostki", "defaultRoles": ["admin", "adult"] }
  ],
  "events": [
    { "type": "magazyn.expiring", "requiredScope": "magazyn:items:read", "title": "Kończy się ważność produktów" },
    { "type": "magazyn.stock_low", "requiredScope": "magazyn:items:read", "title": "Produkty poniżej stanu minimalnego" }
  ],
  "auth": { "type": "hub_token" },
  "forwardUser": false
}
```

- `auth.type: "hub_token"` to typ, którego dziś nie ma w `docs/04-module-contract.md` (tam: `api_key`, `bearer_static`, `basic`, `none`). Według odpowiedzi zespołu huba dodaje go etap 8 huba; do tego czasu jest to **propozycja**, patrz pytanie 1.
- `forwardUser: false`: tożsamość jest w podpisanym tokenie, magazyn nie czyta `X-Home-User-*`.
- `minClientVersion` ustala projekt Android (wartość powyżej to miejsce na zajęcie).
- Dlaczego trzy scope-y: odpowiadają trzem poziomom zaufania w domu. `child` czyta oraz dodaje i zdejmuje sztuki (także zakłada nowe produkty), ale nie ustawia stanów minimalnych ani nie zarządza grupami; to decyzja właściciela z 2026-11-06.

### Wymagany scope per operacja

| Operacja | Metoda i ścieżka | Scope |
|---|---|---|
| `listProducts` | `GET /products` | `magazyn:items:read` |
| `getProduct` | `GET /products/{productId}` | `magazyn:items:read` |
| `createProduct` | `POST /products` | `magazyn:items:write` |
| `updateProduct` | `PATCH /products/{productId}` | `magazyn:items:write` |
| `addStock` | `POST /products/{productId}/stock` | `magazyn:items:write` |
| `consumeStock` | `POST /products/{productId}/consume` | `magazyn:items:write` |
| `setProductMinStock` | `PUT /products/{productId}/min-stock` | `magazyn:items:admin` |
| `listGroups` | `GET /groups` | `magazyn:items:read` |
| `getGroup` | `GET /groups/{groupId}` | `magazyn:items:read` |
| `createGroup` | `POST /groups` | `magazyn:items:admin` |
| `updateGroup` | `PATCH /groups/{groupId}` | `magazyn:items:admin` |
| `setGroupMinStock` | `PUT /groups/{groupId}/min-stock` | `magazyn:items:admin` |
| `listAlerts` | `GET /alerts` | `magazyn:items:read` |
| `lookupStock` | `GET /stocks?name=` | `magazyn:items:read` |

Wszystkie ścieżki są pod `/api/v1`. Adres bazowy dla huba: `https://domowymagazyn.kchroscinski.pl/api/v1`. Operacje `POST` nie są idempotentne (`idempotent: false` w adapterze); `GET` i `PUT` można ponawiać.

### Zdarzenia

Magazyn zgłasza dwa typy, oba **zbiorcze** (jedno zdarzenie na typ na jeden skan, a skan jest raz dziennie o 07:00 `Europe/Warsaw`). Zdarzenia nie mają `actorUserId`, bo wynikają z upływu czasu, nie z działania domownika. „Ktoś dodał produkt” nie jest deklarowane celowo: przy zakupach byłby to szum.

| `type` | Kiedy | `payload` |
|---|---|---|
| `magazyn.expiring` | Skan znajduje sztuki, którym do końca ważności zostało dokładnie 7, 3 lub 1 dzień, albo które wygasają dziś. Jedno zdarzenie na skan. | `title`: „Kończy się ważność produktów”, `message`: po jednej linii na dzień („Dziś: …”, „Jutro: …”, „Za 3 dni: …”, „Za 7 dni: …”), rozdzielone znakiem nowej linii, `count`: liczba sztuk |
| `magazyn.stock_low` | Skan znajduje produkty lub grupy, które **od poprzedniego skanu** spadły poniżej stanu minimalnego (powtórne przypomnienia nie są wysyłane, dopóki stan nie wróci powyżej minimum). Jedno zdarzenie na skan. | `title`: „Produkty poniżej stanu minimalnego”, `message`: po jednej linii na pozycję („Makaron: 800 g z 1000 g”, „Mleko: 1 op. z 2 op.”), `count`: liczba pozycji |

- `message` jest obcinany do 500 znaków z dopiskiem „… i N więcej”; `payload` mieści się w 2048 bajtach. Tytuły i treści są po polsku, bez znaków sterujących.
- `id`: `expiring-YYYYMMDD` (data skanu, więc powtórzony skan tego samego dnia nie tworzy drugiego zdarzenia) oraz `stock_low-YYYYMMDDTHHMMSSZ-xxxxxx` (UTC, początek skanu i 6 znaków szesnastkowych). Ponowne wysłanie tego samego zdarzenia używa tego samego `id` i tej samej treści.
- Dla `expiring` kolejne dni tej samej sztuki dają osobne zdarzenia (7, 3, 1 dzień i dziś); sztuki dodane później niż 7 dni przed terminem dostaną tylko pozostałe przypomnienia. Skan, który się nie odbył (awaria), jest nadrabiany po restarcie (timer `Persistent`), ale tylko jeden raz.

## 2. Walidacja tokenu huba w API

Każde żądanie pod `/api/v1` jest uwierzytelniane wyłącznie nagłówkiem `Authorization: Bearer <JWT>`. Ciasteczka sesji web nie działają w API i odwrotnie (token API nie loguje do webu).

Kolejność sprawdzeń (pierwsze niepowodzenie rozstrzyga):

1. Nagłówek `Authorization` ma postać `Bearer <token>`; token ma 3 segmenty i ≤ 8 KB.
2. Nagłówek JWT: `alg` dokładnie `RS256` (w tym odrzucone `none`, `HS256` i inne), `typ` dokładnie `at+jwt` (bez rozróżniania wielkości liter, zgodnie z RFC 9068), `kid` obecny.
3. Klucz z JWKS po `kid`. Nieznany `kid`: jedno ponowne pobranie JWKS (nie częściej niż co 30 s, żeby sfałszowane `kid` nie obciążały huba), potem odrzucenie. Klucze inne niż RSA (`kty` ≠ `RSA`) są ignorowane. Nieudane pobranie też liczy się jako próba: kolejna nie wcześniej niż po 30 s, a w tym czasie obowiązują ostatnie znane klucze.
4. Podpis RS256.
5. Claimy: `iss` dokładnie równe `HUB_ISSUER`; `aud` dokładnie `urn:home-hub:module:magazyn` (jeśli `aud` jest tablicą, musi ją zawierać; token z logowania z `aud` = `module-magazyn` jest odrzucany); `exp` wymagany i niewygasły; `nbf` i `iat` (jeśli są) nie z przyszłości; tolerancja zegara 30 s; `sub` musi być UUID (to klucz lokalnego rekordu domownika); `scope` ciąg (może być pusty).
6. Wymagany scope operacji jest w `scope` (porównanie po rozdzieleniu spacją, dokładne).

Błędy: kroki 1–5 dają `401 invalid_token` z `WWW-Authenticate: Bearer error="invalid_token"`; krok 6 daje `403 insufficient_scope` (z polem `requiredScope`). Przyczyna odrzucenia trafia do logu (poziom `warning`, bez tokenu), nie do odpowiedzi.

- Sprawdzenie `jti` i odwołania nie jest wykonywane (zgodnie z briefem): o odwołaniu decyduje 60 s życia tokenu.
- Przy pierwszym wywołaniu z nowym `sub` magazyn zakłada lokalny rekord domownika (just-in-time), a przy kolejnych aktualizuje nazwę z claimu `name`. Polskie znaki w `name` są zachowywane (UTF-8).
- JWKS: pobierany z `HUB_INTERNAL_URL` (jeśli ustawiony) albo `HUB_ISSUER`, ścieżka `/.well-known/jwks.json`; cache 10 min w `shared/var/share/cache` (wspólny dla web i API, przeżywa wdrożenia). Jeśli hub jest niedostępny, a klucz jest w cache, API działa dalej; jeśli klucza nie ma, API odpowiada `401` (nie `5xx`, bo nie da się zweryfikować tokenu), a błąd pobrania trafia do logu.

## 3. Reguły domenowe (poza scope-ami)

- Nie ma reguł „tylko autor”: każdy, kto ma `magazyn:items:write`, może zdjąć dowolną sztukę. Autor dodania i zdjęcia jest zapisywany (`addedBy` w sztuce, w bazie także `removed_by` i `removed_at`).
- Kod EAN jest unikalny (`409 ean_taken`).
- Jednostka produktu i jednostka grupy muszą być zgodne (ten sam wymiar: `g`/`kg`, `ml`/`l`, `szt`), o ile obie są ustawione; naruszenie to `422` z polem `unit` albo `groupIds`.
- Minimum ilości wymaga jednostki (produktu: `unit` + `amountPerPackage`; grupy: `unit`). Zmiana jednostki na niezgodny wymiar usuwa minimum ilości.
- Zdjęcie większej liczby sztuk, niż jest na stanie, nie zdejmuje nic i daje `409 insufficient_stock`. Zdjęcia i dodania tego samego produktu są serializowane blokadą bazy, więc dwa równoległe `consume` nie zdejmą tej samej sztuki.
- Zasoby nie są usuwane przez API: nie ma `DELETE` produktu ani grupy (aplikacja też tego dziś nie umie). Sztuki „zdjęte” zostają w historii.

## 4. Czasy, limity, paginacja

- Listy (`/products`, `/groups`, `/alerts`, `/stocks`) wczytują cały katalog (kilkadziesiąt zapytań, rząd setek produktów) i filtrują, sortują oraz dzielą na strony w pamięci PHP. Pojedyncze zasoby i zapisy to kilka zapytań. Pomiary w sekcji „Pomiary”; limit huba (5 s) zostawia ogromny zapas.
- Rozmiar: 50 produktów to ok. 15 KB, 200 produktów ok. 45 KB, szczegóły produktu z kilkunastoma sztukami poniżej 1 KB; limit 2 MB jest poza zasięgiem.
- Paginacja kursorem we wszystkich listach (`/products`, `/groups`, `/alerts`): `limit` 1–200 (domyślnie 50), `cursor` nieprzezroczysty, `nextCursor` zawsze obecny (`null` na ostatniej stronie). Kursor kodowany jest z klucza sortowania ostatniego elementu, więc dodanie lub usunięcie elementów między stronami nie powoduje duplikatów ani pominięć elementów, które nie ruszały się w zakresie; niepoprawny kursor to `422`.
- Sortowanie jest stałe (opisane w `openapi.yaml` przy każdej liście) i nie można go zmienić.
- `GET /stocks` nie jest stronicowane (opis w specyfikacji).

### Pomiary

Zmierzone w środowisku deweloperskim (Docker na Windowsie: PHP-FPM 8.3 + MySQL 8, wolniejsze dyski niż serwer produkcyjny), po rozgrzaniu, 25 powtórzeń na operację, z syntetycznymi danymi większymi niż produkcyjne: 150 produktów, 30 grup, ok. 900 wpisów historii, minima dla 40 produktów i 10 grup. Czas obejmuje całą obsługę żądania w aplikacji (token, baza, odpowiedź), bez sieci i bez startu procesu PHP.

| Operacja | p50 | p95 | Rozmiar odpowiedzi |
|---|---|---|---|
| `GET /products` (50) | 148 ms | 183 ms | 15 KB |
| `GET /products?limit=200` | 156 ms | 169 ms | 44 KB |
| `GET /products?q=` | 152 ms | 232 ms | 3 KB |
| `GET /products/{id}` | 11 ms | 14 ms | 0,6 KB |
| `GET /groups` (50) | 152 ms | 241 ms | 4,5 KB |
| `GET /groups/{id}` | 14 ms | 17 ms | 0,7 KB |
| `GET /alerts` | 262 ms | 396 ms | 7 KB |
| `GET /stocks?name=` | 148 ms | 179 ms | 0,1 KB |
| `POST /products/{id}/stock` | 17 ms | 21 ms | 4 KB |
| `POST /products/{id}/consume` | 26 ms | 30 ms | 1 KB |

Najwolniejszy przypadek w serii to ok. 0,5 s (`/alerts`); do tego dochodzi start PHP-FPM i połączenie z bazą (kilkadziesiąt ms). Pierwsze żądanie po wygaśnięciu cache JWKS (co 10 min) dolicza jedno wywołanie huba (limit 5 s, zwykle dziesiątki ms).

## 5. Logowanie przez hub i sesja

Zachowanie zgodne z briefem (sekcja 3); poniżej tylko doprecyzowania.

| Element | Wartość |
|---|---|
| Klient | poufny, `client_id` = `module-magazyn` (`HUB_CLIENT_ID`), uwierzytelnienie `client_secret_basic` |
| `redirect_uri` | `<APP_PUBLIC_URL>/callback`: produkcja `https://domowymagazyn.kchroscinski.pl/callback`, dev `http://localhost:8080/callback` |
| Przepływ | Authorization Code + PKCE `S256`, `scope=openid profile email`, `state`, `nonce` |
| Trasy | `GET /login` (start), `GET /callback`, `POST /logout`, `GET /logged-out` (potwierdzenie wylogowania), `GET /login/denied` (strona „Brak dostępu”) |
| Ciasteczko sesji | `magazyn_session`, `HttpOnly`, `Secure` (poza dev po HTTP), `SameSite=Lax` |
| Przechowywanie sesji | tabela `sessions` w bazie (`PdoSessionHandler`, blokada `LOCK_ADVISORY`: równoległe żądania tej samej sesji są wykonywane po kolei) |
| Co jest w sesji | `sub`, `name`, `role`, scope-y, czas wygaśnięcia access tokenu, **refresh token** (token dostępu i ID token nie są zapisywane) |

- **Walidacja ID tokenu i access tokenu** jak w briefie: `RS256`, `kid`, `iss`, `aud` = `module-magazyn`, `exp`, tolerancja 30 s; ID token dodatkowo `nonce`; access token dodatkowo `typ` = `at+jwt`. Access token z logowania **nie** jest przyjmowany przez API, a token API nie loguje do webu.
- **Uprawnienia** w webie wynikają ze `scope` access tokenu, a rola służy tylko do wyświetlenia. Mapowanie tras na scope-y jest w tabeli poniżej.
- **Odświeżanie:** przy pierwszym żądaniu po wygaśnięciu access tokenu (z zapasem 30 s) magazyn wymienia refresh token na nowy zestaw. Odświeżenie jest serializowane: blokada sesji bazy, a dodatkowo blokada `symfony/lock` (`flock`, katalog `shared/var/lock`) z kluczem sesji. Blokada sesji w bazie sama wyklucza dwa równoległe odświeżenia tej samej sesji, a blokada `flock` to dodatkowe zabezpieczenie. Nowy refresh token zastępuje stary w tej samej operacji zapisu sesji. Scope-y w sesji są aktualizowane z odpowiedzi.
- `invalid_grant` i `invalid_client` kończą sesję i przekierowują na `/login`. Błąd sieci, timeout albo `5xx` huba **nie** kończą sesji: domownik widzi stronę „Hub niedostępny, spróbuj za chwilę” (`503`) i może odświeżyć.
- **Wylogowanie** (`POST /logout`): `POST /oauth/revoke` na refresh token (błąd odwołania jest tylko logowany), potem zniszczenie sesji. Sesja huba zostaje (SSO).
- **Błędy logowania:** `access_denied` albo brak jakiegokolwiek scope-u `magazyn:` w access tokenie → strona „Brak dostępu, poproś administratora huba” (`403`), bez automatycznego powrotu do `/login`. Niezgodny `state`, wygasły kod i błąd huba → krótki komunikat i link „zaloguj ponownie”; szczegóły tylko w logu.
- Po zalogowaniu domownik wraca na stronę, od której zaczął (tylko ścieżka względna, zweryfikowana: zaczyna się od jednego `/`).
- **CSRF:** bez tokenów; żądania zmieniające dane są sprawdzane po `Sec-Fetch-Site` i `Origin`/`Referer` (`SameOriginGuard`). To istniejący mechanizm aplikacji; ciasteczko `SameSite=Lax` jest dodatkową warstwą.

### Uprawnienia w webie (po scope-ach)

| Strony | Scope |
|---|---|
| `/`, `/products`, `/products/{id}`, `/groups`, `/groups/{id}` | `magazyn:items:read` |
| `/products/edit` (formularze i zapis), `/products/remove` | `magazyn:items:write` |
| `POST /products/{id}/min-stock`, `POST /groups/{id}/min-stock`, `POST /groups/{id}/unit`, `/groups/add` | `magazyn:items:admin` |

Dopóki hub nie jest skonfigurowany (brak `HUB_*`), web odpowiada `503` (stan „fail closed”), a nie wpuszcza bez logowania.

## 6. Zdarzenia: mechanika

- Skan (`app:hub:scan`, timer systemd codziennie o 07:00 `Europe/Warsaw`) zapisuje zdarzenia do tabeli `hub_outbox` w jednej transakcji ze stanem „poniżej minimum” (`hub_alert_state`). Wysyłka (`app:hub:send-events`, timer co minutę) pobiera zaległe wiersze i wysyła `POST <hub>/events`, więc nic nie blokuje żądań użytkownika.
- Podpis: HMAC-SHA256 hex z `<X-Timestamp>.<surowe bajty treści>`, kluczem jest tekst `HUB_EVENT_KEY` (bez dekodowania base64). Treść wysyłana jest tymi samymi bajtami, które podpisano. Test: wektor z briefu.
- Ponawianie: ten sam `id`, nowy `X-Timestamp` i podpis przy każdej próbie; błąd sieci, `429` (z poszanowaniem `Retry-After`) i `5xx` → odstępy 1 s, 10 s, 1 min, potem co minutę, razem najwyżej godzina od utworzenia zdarzenia; inne `4xx` kończą próby (wiersz oznaczony jako nieudany, błąd w logu bez klucza i bez treści).
- `HUB_EVENT_KEY` pusty: zdarzenia nie są wysyłane, skan i tak zapisuje zdarzenia do kolejki, więc po ustawieniu klucza najstarsze nie zostaną odrzucone, dopóki nie miną godziny.

## 7. Migracja istniejących danych

Dziś w bazie jest kilkadziesiąt grup i ~130 produktów (w tym historia sztuk) **bez żadnego właściciela**: aplikacja nigdy nie miała kont. Dlatego nie ma czego przypisywać domownikom i **nic nie wymaga mapowania**.

- Migracje Doctrine są addytywne i zgodne wstecz: tabele `members`, `sessions`, `hub_outbox`, `hub_alert_state` oraz nullable kolumny `added_by`, `removed_by`, `removed_at` w `products_history`. Dotychczasowe wiersze mają tam `NULL` (w API `addedBy: null`, czyli „sprzed integracji”).
- Wdrożenie wykonuje migracje **przed** przełączeniem wydania, a poprzedni kod działa na nowym schemacie (dodane kolumny są nullowe), więc wdrożenie i ewentualny rollback kodu nie przerywają działania. Przed migracją jest zrzut bazy.
- Atrybucja zaczyna się od pierwszych akcji zalogowanych domowników (web i API). Legacy API `?pwd=` nie ma domownika, więc jego wpisy mają `NULL`.

## 8. Konfiguracja i zmiany na serwerze

Zmienne środowiskowe (pliki `shared/.env.local` — sekrety wewnętrzne, `shared/.env.prod.local` — konfiguracja huba, tworzone przez Ansible):

| Zmienna | Opis |
|---|---|
| `APP_PUBLIC_URL` | publiczny adres aplikacji (`https://domowymagazyn.kchroscinski.pl`); `redirect_uri` = `<APP_PUBLIC_URL>/callback` |
| `HUB_ISSUER` | publiczny adres huba (`iss`, `/oauth/authorize`) |
| `HUB_INTERNAL_URL` | opcjonalny adres huba dla wywołań serwer–serwer (token, JWKS, revoke, events); pusty = `HUB_ISSUER` |
| `HUB_CLIENT_ID` | `module-magazyn` |
| `HUB_CLIENT_SECRET` | sekret klienta z huba (pokazywany raz) |
| `HUB_EVENT_KEY` | klucz zdarzeń z huba (43 znaki) |
| `LOCK_DSN` | magazyn blokad (`flock://%kernel.project_dir%/var/lock`; w produkcji katalog w `shared/`) |
| `APP_SHARE_DIR` | katalog współdzielony między wydaniami (cache JWKS), w produkcji `shared/var/share` |

Pozostałe zmienne (`APP_SECRET`, `DATABASE_URL`, `API_PASSWORD`, `ONESIGNAL_*`) bez zmian. Sekrety nie trafiają do repo ani do logów; filtr logów usuwa nagłówek `Authorization`, wartości `HUB_*`, tokeny i hasła.

Zmiany na serwerze (opisane w `etc/ansible/`, wdraża właściciel; nic nie jest uruchamiane z tego repo):

1. **Osobne konto systemowe** `domowy_magazyn`, własna pula PHP-FPM 8.3 i wdrożenia przez Ansible + ansistrano, analogicznie do wdrożenia huba (`etc/ansible/README.md`).
2. **Vhost Apache:**
   - Basic Auth usunięty z całej witryny po uruchomieniu logowania przez hub (zmienna `basic_auth_enabled: false` i `apache.yaml`); do tego czasu zostaje.
   - `/api/v1/` bez Basic Auth (autoryzacja tokenem), `/api/index.php` (legacy z `?pwd=`) bez zmian.
   - `CGIPassAuth On`: bez tego Apache z `mod_proxy_fcgi` nie przekazuje do PHP nagłówka `Authorization`.
   - Log dostępu bez zapytania (`?pwd=`, `code`, `state` nie trafiają do logów), `Referrer-Policy: same-origin`.
   - `www` przekierowuje na adres bez `www` (`redirect_uri` i ciasteczko są przypisane do jednego hosta).
   - Nazwy hostów spoza witryny na `:443` dostają `404`, bo ten vhost jest pierwszym alfabetycznie z TLS.
3. **Timery systemd** (zamiast crona): `domowymagazyn-outbox.timer` (co minutę), `domowymagazyn-scan.timer` (07:00 `Europe/Warsaw`, `Persistent`). Włączane po wdrożeniu poleceń (`timers_enabled: true`).
4. **`GET /healthz`** (`{"status":"ok"}`, `503` gdy baza niedostępna) bez uwierzytelnienia: sprawdza je wdrożenie.
5. **Po stronie huba (do zrobienia przez zespół huba):** integracja `magazyn` w rejestrze (manifest powyżej), klient OIDC `module-magazyn` z `redirect_uri`, klucz zdarzeń, adres bazowy API, ewentualnie adres w `MODULES_ALLOWED_NETWORKS`, jeśli hub woła magazyn po sieci prywatnej.

## 9. Znane ograniczenia

- **Tabele produktów są MyISAM** (bez transakcji i kluczy obcych). Serializację zdjęć i dodań realizuje blokada nazwana na produkt (`GET_LOCK`) i dotyczy tylko API `/api/v1`; równoległy `RemoveProduct` z legacy API albo z webu może się z nim zderzyć (praktycznie nieosiągalne w domu).
- Brak `Idempotency-Key` i `ETag`/`If-Match`: równoległa edycja tego samego produktu kończy się „ostatni zapis wygrywa”; `409` występuje tylko dla `ean_taken` i `insufficient_stock`.
- Kod EAN jest w bazie liczbą (`BIGINT`), więc zera wiodące giną (`0012345678905` jest zapisane i zwracane jako `12345678905`); filtr `ean` ignoruje zera wiodące po obu stronach.
- Minimum ilości jest w bazie liczbą całkowitą w jednostce bazowej (`g`, `ml`, `szt`), więc `amount` podany z częścią ułamkową w `g`/`ml`/`szt` jest zaokrąglany do całości (np. `1.5` g → `2` g), a w `kg`/`l` zachowuje trzy miejsca (`1.5` kg = 1500 g).
- Nazwy i producenci zapisani przez stare ścieżki są zapisani z encjami HTML (np. `&amp;`). API `/api/v1` zwraca je zdekodowane, a zapisuje czysty tekst.
- Zdarzenia pochodzą ze skanu raz dziennie: „poniżej minimum” nie jest zgłaszane w chwili zdjęcia sztuki, tylko następnego ranka.
- Brak wylogowania inicjowanego z huba: dezaktywowane konto traci dostęp do webu w ciągu ≤ 10 min (następne odświeżenie), do API w ciągu ≤ 60 s.
- Refresh token jest w tabeli `sessions` bez dodatkowego szyfrowania (dostęp do bazy daje i tak dostęp do wszystkich danych magazynu).
- Strony `GET /products/edit` i `GET /products/remove` zmieniają dane przy `GET` (zachowanie z poprzednich wersji, potrzebne starym linkom i formularzom); chroni je `SameOriginGuard`, a nie token CSRF.
- Wyszukiwanie i sortowanie list odbywa się w pamięci PHP po wczytaniu wszystkich produktów: przy rzędzie tysięcy produktów wymagałoby to przejścia na zapytania SQL.
- Legacy API `?pwd=` działa dalej, obok `/api/v1`, dla dotychczasowych klientów (m.in. asystenta głosowego). Hub z niego nie korzysta.
- Obsługiwany jest jeden hub (jeden `iss`).
- Zdjęć produktów aplikacja nie przechowuje, więc API ich nie zwraca.

## 10. Pytania do zespołu huba

Każde z propozycją; bez odpowiedzi przyjmuję propozycję.

1. **Typ uwierzytelnienia w manifeście.** Propozycja: `auth: {"type": "hub_token"}` i `forwardUser: false`, token z `aud` = `urn:home-hub:module:magazyn`, `typ` = `at+jwt`, ważny 60 s, ponownie używany przez hub w tym czasie. Czy ten typ trafi do schematu manifestu w etapie 8 i czy nazwa jest ostateczna?
2. **Test end-to-end API przed adapterem.** Dev nie wystawi tokenu API bez adaptera. Propozycja: polecenie `bin/console` w hubie, które drukuje podpisany token `at+jwt` dla wskazanego domownika i scope-ów (dev), albo wskazanie istniejącego mechanizmu. Wtedy sprawdzę magazyn na prawdziwym JWKS dev.
3. **Rotacja kluczy.** Zakładam, że w JWKS stary klucz zostaje co najmniej tak długo, jak życie tokenu (10 min) po rotacji. Potwierdzenie?
4. **Wartości w polach.** Identyfikatory zasobów to liczby całkowite (`id`), kwoty to grosze w polach z sufiksem `Minor` (np. `costMinor`), a daty kalendarzowe to `YYYY-MM-DD` bez strefy. Czy adapter i aplikacja Android mogą na tym polegać (nie ma w kontrakcie ogólnej konwencji kwot)?
5. **Skanowanie kodów.** Propozycja: `GET /products?ean=<kod>` zwraca najwyżej jeden element (lub pustą listę); do dodania produktu po zeskanowaniu aplikacja używa `POST /products/{id}/stock`, a nowy produkt zakłada `POST /products`.
6. **Format `X-Correlation-Id`.** Przyjmuję dowolny ciąg do 64 znaków `[A-Za-z0-9._-]` (tak samo jak hub); inny jest ignorowany i zastępowany wygenerowanym UUID. Zgadza się z tym, co hub wysyła?
7. **`405` zamiast `404`.** API zwraca `405 method_not_allowed` z `Allow` dla niedozwolonej metody (hub mapuje takie przypadki na `404`). Adapter nie powinien takich wywołań generować; czy to pasuje?
8. **Zdarzenia.** Czy dzienne, zbiorcze `magazyn.expiring` (07:00) oraz `magazyn.stock_low` bez `actorUserId` są akceptowalne dla modelu preferencji powiadomień? Jeśli hub woli osobne zdarzenie na produkt, wymaga to zmiany w magazynie (dziś celowo zbiorcze: mniej powiadomień).
9. **Adres sieciowy.** Hub i magazyn stoją na tym samym serwerze. Czy hub woła magazyn publicznym `https://` (propozycja, bez zmian w `MODULES_ALLOWED_NETWORKS`), czy wewnętrznie?

## 11. Pytania do właściciela

1. Rotacja `API_PASSWORD`: zdecydowano, że **nie**. Logi Apache sprzed zmiany formatu (rotacja 14 dni) zawierają to hasło; po upływie retencji problem znika bez działań. Do ponownej decyzji tylko, jeśli logi były udostępniane.
2. Termin wyłączenia Basic Auth po sprawdzeniu logowania przez hub (kroki w `etc/ansible/README.md`).
3. Czas życia sesji web: propozycja 30 dni (koniec sesji i tak następuje przy `invalid_grant`).
