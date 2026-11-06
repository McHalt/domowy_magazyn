# Zmiany w aplikacji po integracji z hubem

Dla właściciela: czego spodziewać się po wdrożeniu. Szczegóły techniczne dla zespołu huba: `integration.md`.

## Co zniknie

- **Hasło HTTP Basic na stronie.** Po sprawdzeniu logowania przez hub (kroki w `etc/ansible/README.md`, „Przejście na logowanie przez hub”) Basic Auth jest usuwany z vhosta. Od tego momentu stronę otwiera się kontem huba.
- **Konto `mchalt` jako właściciel aplikacji na serwerze.** Aplikacja działa na koncie `domowy_magazyn` (osobna pula PHP-FPM); stare pliki zostają do Twojej decyzji (`legacy-cleanup.yaml`).
- **Wdrożenie z kopii roboczej.** Wdrażany jest zatwierdzony commit (`git archive`), więc przed `deploy.yaml` trzeba zrobić commit.
- Plik `etc/ansible/main.yaml` i dotychczasowe `vars.yaml`/`extra.yml` (zastąpione przez `vars/magazyn.yaml`, `provision.yaml`, `deploy.yaml`, `apache.yaml`).

## Co się nie zmienia

- **Legacy API** `/api/index.php?p=...&pwd=...` działa jak dotąd (to samo hasło `API_PASSWORD`, te same odpowiedzi), także `find_stocks`. Hub z niego nie korzysta; korzystają dotychczasowi klienci.
- Baza `domowy_magazyn` i jej użytkownik, dane (produkty, grupy, historia, stany minimalne), adres strony.
- `SameOriginGuard` (ochrona przed żądaniami z innych stron) działa dalej.
- Powiadomienia OneSignal (`app:send-notifications`) zostają w kodzie; na produkcji dalej nie mają crona. Powiadomienia o ważności i stanach minimalnych zastąpią zdarzenia do huba (push z huba).

## Co dojdzie

### Dla domowników
- **Logowanie przez hub** (SSO: kto jest zalogowany w hubie, nie wpisuje hasła drugi raz) i wylogowanie (`Wyloguj` w menu odwołuje sesję magazynu, sesja huba zostaje).
- **Uprawnienia z huba**, w trzech poziomach:

  | Scope | Co daje | Domyślnie |
  |---|---|---|
  | `magazyn:items:read` | przeglądanie produktów, grup i alertów | admin, dorosły, dziecko |
  | `magazyn:items:write` | dodawanie i zdejmowanie sztuk, nowe produkty, edycja produktów | admin, dorosły, dziecko |
  | `magazyn:items:admin` | stany minimalne, grupy i ich jednostki | admin, dorosły |

  Brak jakiegokolwiek scope-u daje stronę „Brak dostępu, poproś administratora huba”.
- Powiadomienia push z huba: `magazyn.expiring` (kończy się ważność: 7, 3, 1 dzień i w dniu terminu) oraz `magazyn.stock_low` (produkt lub grupa spadła poniżej minimum). Raz dziennie o 07:00.
- Nowa strona „Brak dostępu” i komunikat „Hub niedostępny” (gdy hub nie odpowiada przy odświeżaniu logowania).

### Dla huba i aplikacji Android
- **API `/api/v1`** (14 operacji, specyfikacja `docs/hub/openapi.yaml`), uwierzytelnione tokenem huba (JWT RS256, `aud` = `urn:home-hub:module:magazyn`), błędy `problem+json`, paginacja kursorem.
- Wysyłka zdarzeń do huba (`POST <hub>/events`, podpis HMAC), przez kolejkę w bazie i timer.

### W bazie
Nowe migracje (addytywne, zgodne wstecz; przed migracją zrzut bazy w `backups/`):

- `members`: domownicy z huba (UUID, nazwa), zakładani przy pierwszym logowaniu lub wywołaniu API;
- `sessions`: sesje web;
- `hub_outbox` i `hub_alert_state`: kolejka zdarzeń i stan „poniżej minimum” do wykrywania nowych przekroczeń;
- `products_history`: nullable `added_by`, `removed_by`, `removed_at`. Stare wpisy mają `NULL`.

### W kodzie
- `GET /healthz` (używane przez wdrożenie).
- Polecenia konsoli `app:hub:scan` (skan ważności i minimów) i `app:hub:send-events` (wysyłka kolejki).
- Logi w JSON (`shared/var/log`), bez nagłówka `Authorization`, tokenów i sekretów; identyfikator korelacji `X-Correlation-Id`.
- Testy automatyczne (PHPUnit 12, ok. 160 testów, ok. minuty): walidacja tokenów na własnych kluczach RSA, logowanie i odświeżanie z rotacją, API (w tym zgodność każdej odpowiedzi z `openapi.yaml`), zdarzenia z wektorem testowym HMAC, dostęp do każdej trasy.
  Jednorazowo baza testowa: `CREATE DATABASE domowy_magazyn_test CHARACTER SET utf8mb4; GRANT ALL ON \`domowy_magazyn_test\`.* TO 'app'@'%';`, potem `docker compose exec php sh -c "APP_ENV=test php bin/console doctrine:migrations:migrate -n"`.
  Uruchomienie: `docker compose exec php php vendor/bin/phpunit -c phpunit.dist.xml`. Testy nie łączą się z żadnym hubem.

## Na serwerze (wdraża właściciel)

Kolejność, szczegóły i polecenia w `etc/ansible/README.md`:

1. `provision.yaml`: konto, katalogi, pula FPM, timery (wyłączone), sudoers, logrotate; przejmuje sekrety z konta `mchalt`.
2. W hubie: zarejestrować integrację `magazyn`, klienta OIDC `module-magazyn` (`redirect_uri` = `https://domowymagazyn.kchroscinski.pl/callback`), wygenerować klucz zdarzeń; sekret i klucz wpisać do `etc/ansible/.secrets/extra.yml` (`hub_client_secret`, `hub_event_key`).
3. `deploy.yaml` (po commicie): pierwsze wydanie, migracje, `healthz`.
4. `apache.yaml`: przełączenie vhosta na nowe konto; **wymaga wdrożonego `/healthz`**.
5. Sprawdzić logowanie przez hub; wtedy `basic_auth_enabled: false` + `apache.yaml`, oraz `timers_enabled: true` + `provision.yaml`.
6. Po kilku dniach `legacy-cleanup.yaml`.

Przejście nie wymaga przerwy w działaniu strony, ale między krokami 3 i 5 (kod z logowaniem przez hub, bez ustawionych `HUB_*`) web odpowiada `503`: kroki 2 i 3 wykonaj razem albo wyłącz stronę na ten czas.

## Otwarte

- Rozbieżności z briefem i pytania do zespołu huba: `integration.md`, sekcja 10.
- Rotacja `API_PASSWORD`: zdecydowano, że nie zmieniamy; stare logi Apache zawierają hasło do końca retencji (14 dni).
