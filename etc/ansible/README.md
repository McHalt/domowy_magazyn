# Deploy na serwer (`kchroscinski-prod`)

Strona **https://domowymagazyn.kchroscinski.pl/**, serwer `kchroscinski.pl` (alias `kchroscinski-prod`). Układ jak
w wdrożeniu huba (`home-app/deploy/ansible/`), które działa na tym samym serwerze: Ansible + ansistrano, bez Dockera,
osobne konto systemowe `domowy_magazyn`, wdrażany jest zatwierdzony commit, zadania cykliczne jako timery systemd.

Na tym samym Apache stoją inne strony (mod_php 8.1), dlatego aplikacja ma własną pulę **PHP 8.3 FPM**, a `/usr/bin/php`
zostaje przy 8.1.

## Uruchamianie

Ansible działa w kontenerze (wersje przypięte w `docker/Dockerfile` i `requirements.yml`), repo jest montowane tylko
do odczytu, zapis tylko w `etc/ansible/` (log `ansible.log`):

```powershell
powershell -ExecutionPolicy Bypass -File etc\ansible\run.ps1 provision.yaml --ask-become-pass --check --diff
$env:SSH_KEY = 'haltcore_ed25519'   # klucz z ~/.ssh, domyślnie id_ed25519
```

Playbooki z hasłem sudo (`provision`, `apache`, `legacy-cleanup`) odpala właściciel, najpierw z `--check --diff`.
`deploy.yaml` i `rollback.yaml` łączą się jako `domowy_magazyn` i nie potrzebują hasła.

Sekrety zewnętrzne: `.secrets/extra.yml` (wzór: `extra.yml.example`), tylko dane z huba (`hub_client_secret`,
`hub_event_key`). Sekrety wewnętrzne (`APP_SECRET`, dane bazy, `API_PASSWORD`, OneSignal) zostają w `shared/.env.local`
na serwerze.

## Przejście z konta `mchalt` (jednorazowo)

Nowe miejsce powstaje obok starego, więc strona działa przez cały czas, a przełącza je jedno przeładowanie Apache.

1. `provision.yaml`: konto i katalogi, nowa pula FPM, timery, sudoers (tylko reload FPM), logrotate. Przejmuje
   `shared/.env.local` i `.htpasswd` z `/var/www/mchalt/domains/<domena>/shared/` (te same sekrety, ta sama baza).
   Z `DATABASE_URL` powstaje `shared/secrets/my.cnf` dla `mysql` i `mysqldump`.
2. `deploy.yaml`: pierwsze wydanie w `/var/www/domowy_magazyn/...`. Domena dalej obsługuje stary katalog.
3. `apache.yaml`: vhost na nowy `DocumentRoot`, `AssignUserId domowy_magazyn`, nowe gniazdo FPM. Porównuje odpowiedzi
   pozostałych stron przed zmianą i po niej (`configtest`, przywrócenie przy błędzie).
4. Po kilku dniach `legacy-cleanup.yaml`: wyłącza starą pulę FPM i starą regułę sudoers (pliki zostają z sufiksem
   `.disabled-<data>`). Stary katalog `/var/www/mchalt/domains/<domena>/` (w tym `html/` ze starą aplikacją) zostaje do
   decyzji właściciela.

## Codzienna praca

```powershell
powershell -ExecutionPolicy Bypass -File etc\ansible\run.ps1 deploy.yaml [-e commit=<sha|gałąź|tag>]
```

Wdrażany jest commit (domyślnie `HEAD`), nie kopia robocza: `git archive` pomija pliki ignorowane i niezacommitowane,
a `.gitattributes` (`export-ignore`) wyklucza `old/`, `docker/`, `etc/`. Na serwerze leci `composer install --no-dev`
z `composer.lock` commita, sprawdzenie bazy i migracje; `current` przeskakuje dopiero po nich, a błąd wcześniej usuwa
nowe wydanie i nie dotyka działającego. Trzymane są 3 ostatnie wydania. Po przełączeniu deploy przeładowuje FPM
(`sudo -n`) i sprawdza `/healthz`.

Cofnięcie do poprzedniego wydania: `rollback.yaml` (przeładowuje FPM, migracji nie cofa).

## Co gdzie leży na serwerze

```
/var/www/domowy_magazyn/                          0750 domowy_magazyn
└── domains/domowymagazyn.kchroscinski.pl/
    ├── current -> releases/<UTC>-<commit>        DocumentRoot = current/public
    ├── releases/                                 3 ostatnie wydania; .env.prod z APP_VERSION
    ├── shared/
    │   ├── .env.local                            0600, sekrety wewnętrzne i DATABASE_URL (przejęte)
    │   ├── .env.prod.local                       0600, konfiguracja i sekrety huba (każdy deploy)
    │   ├── .htpasswd                             Basic Auth (dopóki basic_auth_enabled)
    │   ├── secrets/my.cnf                        0600, dane bazy dla mysql i mysqldump
    │   └── var/{log,lock,share}/                 logi, blokady (flock), cache współdzielony między wydaniami
    ├── backups/                                  zrzuty bazy sprzed migracji (5 ostatnich)
    └── tools/composer.phar

/etc/apache2/sites-available/domowymagazyn.kchroscinski.pl.conf       apache.yaml (kopie *~ obok)
/etc/php/8.3/fpm/pool.d/domowy_magazyn.conf
/etc/systemd/system/domowymagazyn-{outbox,scan}.{service,timer}
/etc/sudoers.d/domowy_magazyn                     tylko systemctl reload php8.3-fpm
```

Timery uruchamiają polecenia z bieżącego wydania (`app:hub:send-events` co minutę, `app:hub:scan` o 07:00
Europe/Warsaw z nadrabianiem po restarcie), więc deploy niczego nie restartuje. Polecenia powstają razem z integracją
z hubem, więc `timers_enabled` w `vars/magazyn.yaml` jest na `false` (jednostki są zainstalowane, timery wyłączone).
Po wdrożeniu poleceń ustaw `true` i puść `provision.yaml`; logi: `journalctl -u domowymagazyn-outbox`.

## Przejście na logowanie przez hub

Dopóki aplikacja nie ma logowania przez hub, web chroni Basic Auth z vhosta (`basic_auth_enabled: true` w
`vars/magazyn.yaml`). Po wdrożeniu logowania i sprawdzeniu go:

1. w `vars/magazyn.yaml` ustaw `basic_auth_enabled: false`,
2. `apache.yaml` (usuwa `<Location />` z Basic Auth),
3. `.htpasswd` w `shared/` można usunąć.

Legacy API (`/api/index.php?p=...&pwd=...`) zostaje z własnym hasłem i poza Basic Auth.

## Baza danych

Baza `domowy_magazyn` i użytkownik `domowymagazyn` pochodzą ze starej aplikacji i się nie zmieniają. Każdy deploy
z nowymi migracjami robi zrzut do `backups/<baza>-<wydanie>.sql.gz` i uruchamia `doctrine:migrations:migrate`
**przed** przełączeniem `current`. Przez chwilę stary kod działa na nowym schemacie, więc migracje muszą być zgodne
wstecz (np. nowe kolumny nullowe). Rollback kodu nie cofa migracji.

## Pułapki

- **`Too many authentication failures`**: SSH proponuje wszystkie klucze z agenta. Kontener używa jednego klucza
  (`SSH_KEY`, `IdentitiesOnly yes`).
- **Domyślny vhost SSL.** Vhost magazynu jest pierwszy alfabetycznie z `:443`, więc odbiera żądania `https://` do stron
  bez własnego vhosta SSL. Dotąd zatrzymywał je Basic Auth (401), teraz odpowiada im 404 (reguła na `Host` w
  `templates/vhost.conf.j2`). Porównanie stron w `apache.yaml` tę różnicę pomija.
- **`www` przekierowuje na domenę bez `www`** (`301`), bo `redirect_uri` logowania i ciasteczko sesji są przypisane do
  jednego hosta. `/api/` na `http://` nie jest przekierowywane: klienci API wołają stare adresy, a 301 zamieniłby ich
  POST-y w GET-y.
- **`CGIPassAuth On`**: bez niego Apache 2.4.52 z `mod_proxy_fcgi` nie przekazuje do PHP nagłówka `Authorization`,
  więc token Bearer z huba nie dotarłby do API.
- **Logi Apache bez zapytania.** Format zapisuje samą ścieżkę, bez `?pwd=`, `code` i `state` z logowania, `Referer`
  i `Location`. Logi sprzed tej zmiany (rotacja 14 dni) zawierają hasło API.
- **`Referrer-Policy: same-origin`**, a nie `no-referrer`: przy `no-referrer` przeglądarka wysyła `Origin: null`, co
  osłabia `SameOriginGuard` w starszych przeglądarkach bez `Sec-Fetch-Site`.
- **`apt` i `unattended-upgrades`** potrafią trzymać blokadę dpkg (stąd `lock_timeout`), a klucz PPA ondrej jest pobierany
  tylko gdy brakuje pliku (serwer kluczy odrzuca HEAD z `--check`).

## Awaryjny powrót do poprzedniej instalacji

Do czasu `legacy-cleanup.yaml` stara pula FPM i stary katalog działają. Przywrócenie vhosta sprzed `apache.yaml`:

```bash
ssh -t kchroscinski-prod 'ls -t /etc/apache2/sites-available/ | grep domowymagazyn'
# wskaż kopię z najnowszej daty przed przełączeniem, potem:
ssh -t kchroscinski-prod 'sudo cp /etc/apache2/sites-available/<kopia> /etc/apache2/sites-available/domowymagazyn.kchroscinski.pl.conf && sudo apache2ctl configtest && sudo systemctl reload apache2'
```
