# Deploy na serwer (`kchroscinski-prod`)

Wdrożenie na `mchalt@kchroscinski.pl` (alias `kchroscinski-prod` w `~/.ssh/config`),
strona **https://domowymagazyn.kchroscinski.pl/**. Układ wzorowany na
`etc/ansible/` z projektu hafciareczka: Ansible + ansistrano, deploy odpalany
ręcznie z laptopa, bez CI i bez zdalnego gita.

W przeciwieństwie do hafciareczki **bez Dockera** — serwer ma natywnie Apache
(mpm_itk + mod_php 8.1) i MySQL 8. Na tym samym Apache stoją inne strony
(kchroscinski.pl, cookbook, przeprowadzka, szpaki, slubkrzysiainatalki, mc),
dlatego aplikacja dostaje własną pulę **PHP 8.3 FPM**, a reszta zostaje na
mod_php 8.1.

## Jednorazowe przygotowanie

1. Role Ansible (jeśli ich jeszcze nie ma — są wspólne z hafciareczką):

   ```bash
   cd etc/ansible
   ansible-galaxy install -r requirements.yml
   ```

2. Sekrety:

   ```bash
   cp .secrets/extra.yml.example .secrets/extra.yml
   $EDITOR .secrets/extra.yml
   ```

   Login i hasło Basic Auth (`basic_auth_*`) wymyślasz sam. Pozostałe wartości są w starej aplikacji na serwerze
   (`/var/www/mchalt/domains/domowymagazyn.kchroscinski.pl/html/`):
   hasło bazy w `Configs/db.conf`, hasło API w `api/pwd.php`, klucze OneSignal
   w `Configs/send_notifs.conf`.

3. Przygotowanie serwera — PHP 8.3 FPM z pulą aplikacji, moduły Apache,
   Composer, katalogi, wpis w sudoers pozwalający deployowi przeładować FPM:

   ```bash
   ansible-playbook provision.yaml --ask-become-pass
   ```

4. Pierwsze wdrożenie kodu:

   ```bash
   ansible-playbook deploy.yaml
   ```

   Do tego momentu strona dalej serwuje starą aplikację z `html/`.

5. Przełączenie vhosta na nową aplikację i certyfikat Let's Encrypt:

   ```bash
   ansible-playbook apache.yaml --ask-become-pass
   ```

Punkty 3–5 naraz: `ansible-playbook main.yaml --ask-become-pass`.

## Codzienna praca

```bash
cd etc/ansible
ansible-playbook deploy.yaml
```

Rsync pcha working tree z laptopa (nie commit — to, co masz na dysku) do nowego
katalogu w `releases/`. Na serwerze leci `composer install` i test połączenia
z bazą; `current` przeskakuje dopiero, gdy to przejdzie. Trzymane są 3 ostatnie
wydania.

Cofnięcie do poprzedniego wydania:

```bash
ansible-playbook rollback.yaml
```

`apache.yaml` uruchamiasz ponownie tylko po zmianie `templates/vhost.conf.j2`.

## Co gdzie leży na serwerze

```
/var/www/mchalt/domains/domowymagazyn.kchroscinski.pl/
├── current -> releases/...     aktywne wydanie, DocumentRoot = current/public
├── releases/                   3 ostatnie wydania
├── shared/
│   ├── .env.local              generowany z .secrets/extra.yml przy każdym deployu
│   ├── .htpasswd               Basic Auth, generowany przez apache.yaml
│   └── var/log/                logi Symfony i php-fpm.log
├── tools/composer.phar         Composer z apt (2.2.6) jest za stary na PHP 8.3
└── html/                       STARA aplikacja sprzed migracji — patrz niżej

/etc/apache2/sites-available/domowymagazyn.kchroscinski.pl.conf
                                generowany przez apache.yaml; oryginał w *.pre-ansible
/etc/php/8.3/fpm/pool.d/domowymagazyn.conf
/etc/sudoers.d/domowymagazyn-deploy
```

## Baza danych

Aplikacja działa na **istniejącej bazie produkcyjnej bez zmian** (`domowy_magazyn`,
użytkownik `domowymagazyn`) — sprawdzone lokalnie na dumpie z 2026-09-25:
wszystkie strony produktów i grup oraz API zwracają 200.

Deploy **nie uruchamia migracji Doctrine**. Pierwsza migracja
(`Version20260223135128`) robi `CREATE TABLE` i na bazie produkcyjnej by się
wywaliła. Schemat różni się od mapowania tylko indeksami, kluczami obcymi i
dwiema nieużywanymi tabelami (`categories`, `products_to_categories`) — widać
to w `bin/console doctrine:schema:validate`.

## Pułapki

- **`Too many authentication failures`** przy `ssh mchalt@kchroscinski.pl` —
  SSH proponuje wszystkie klucze z agenta i serwer zrywa połączenie przed
  właściwym. Łącz się przez alias `kchroscinski-prod` (`IdentitiesOnly yes`).
- **`/usr/bin/php` zostaje na 8.1.** Instalacja `php8.3-cli` przestawiłaby
  alternatywę na 8.3 i zmieniła PHP pozostałym projektom na serwerze —
  `provision.yaml` przypina ją z powrotem. W tej aplikacji zawsze
  `php8.3 bin/console ...`.
- **Basic Auth na wszystkim poza `/api/`.** API ma własne hasło (`?pwd=`), a
  klienci nie obsługują Basic Auth. `.htpasswd` leży w `shared/` i należy do
  `mchalt` (grupa `www-data`), bo mpm_itk czyta go już jako `mchalt`. Zmiana
  loginu lub hasła: popraw `.secrets/extra.yml` i puść `apache.yaml`.
- **`/api/` nie jest przekierowywane na HTTPS.** Klienci API wołają stare
  adresy `http://.../api/index.php?p=...&pwd=...`, a przekierowanie 301
  zamieniłoby ich POST-y w GET-y. Reszta strony przekierowuje na HTTPS.
- **Przeładowanie FPM po każdym deployu.** Opcache i realpath cache trzymają
  ścieżki poprzedniego wydania za dowiązaniem `current`; bez przeładowania
  strona serwowałaby stary kod. Robi to `deploy/after-symlink.yaml` przez
  `sudo -n` (reguła z `provision.yaml`).

## Awaryjny powrót do starej aplikacji

`html/` zostaje nietknięte. Powrót vhosta do stanu sprzed wdrożenia:

```bash
ssh -t kchroscinski-prod 'sudo cp /etc/apache2/sites-available/domowymagazyn.kchroscinski.pl.conf.pre-ansible /etc/apache2/sites-available/domowymagazyn.kchroscinski.pl.conf && sudo apache2ctl configtest && sudo systemctl reload apache2'
```

Stara aplikacja działa wtedy po HTTP na mod_php 8.1, jak przed migracją.
