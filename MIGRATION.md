# Migracja do Symfony 7.4

## Cel
- Obecny kod (custom PHP MVC) → `old/`
- Symfony 7.4 jako główna struktura repo
- Docker lokalnie (PHP-FPM 8.3 + Nginx + MySQL 8), prod bez Dockera
- API kompatybilne 1:1, URL-e frontendowe mogą się zmienić

---

## Status zadań

| # | Zadanie | Status |
|---|---------|--------|
| 1 | Przenieść obecny kod do `old/` | ✅ DONE |
| 2 | Zebrać schemat bazy danych | ✅ DONE |
| 3 | Utworzyć szkielet Symfony 7.4 (composer.json, Kernel, public/index.php, bin/console) | ✅ DONE |
| 4 | Skonfigurować Docker (docker-compose.yml + Dockerfile + nginx) | ✅ DONE |
| 5 | Plik .env + config/packages/ (framework, doctrine, twig, security) | ✅ DONE |
| 6 | Encje Doctrine (Product, ProductHistory, Feature, ProductToFeature, ProductsGroup, MinStockRule) | ✅ DONE |
| 7 | Repozytoria Doctrine (Product, ProductsGroup, Feature, ProductHistory, MinStockRule) | ✅ DONE |
| 8 | Kontrolery frontendowe (Main, Product, Group) | ✅ DONE |
| 9 | API Controller (kompatybilność 1:1 z api/index.php?p=...) | ✅ DONE |
| 10 | Szablony Twig (wszystkie strony) | ✅ DONE |
| 11 | Pliki statyczne (CSS/JS/Imgs) → public/files/ | ✅ DONE |
| 12 | Komenda cron (app:send-notifications) | ✅ DONE |
| 13 | Uruchomienie Docker + composer install | ✅ DONE |
| 14 | Wygenerowanie migracji Doctrine (doctrine:migrations:diff) | ✅ DONE |
| 15 | Import danych produkcyjnych do Docker DB | ⏳ NASTĘPNE |
| 16 | Testy manualne (frontend + API) | ✅ DONE (lokalnie z pustą DB) |
| 17 | Instrukcja deploy na prod (bez Dockera) | ✅ DONE |

---

## Struktura nowego projektu

```
.
├── bin/console
├── config/
│   ├── bundles.php
│   ├── routes.yaml
│   ├── services.yaml
│   └── packages/
│       ├── doctrine.yaml
│       ├── doctrine_migrations.yaml
│       ├── framework.yaml
│       ├── security.yaml
│       └── twig.yaml
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile
├── migrations/           ← generowane przez Doctrine
├── old/                  ← stary kod (nienaruszony)
├── public/
│   ├── index.php         ← front controller Symfony
│   └── files/            ← CSS, JS, Imgs (skopiowane z old/Files/)
├── src/
│   ├── Command/SendNotificationsCommand.php
│   ├── Controller/
│   │   ├── Api/ApiController.php     ← /api/index.php?p=...
│   │   ├── GroupController.php
│   │   ├── MainController.php
│   │   └── ProductController.php
│   ├── Entity/
│   │   ├── Feature.php
│   │   ├── MinStockBasis.php         ← enum ITEMS/QUANTITY
│   │   ├── MinStockRule.php
│   │   ├── Product.php
│   │   ├── ProductHistory.php
│   │   ├── ProductToFeature.php      ← M:N z polem value
│   │   └── ProductsGroup.php
│   ├── Kernel.php
│   └── Repository/
│       ├── FeatureRepository.php
│       ├── MinStockRuleRepository.php
│       ├── ProductHistoryRepository.php
│       ├── ProductRepository.php
│       └── ProductsGroupRepository.php
├── templates/
│   ├── base.html.twig
│   ├── errors/404.html.twig
│   ├── group/ (add, list, view)
│   ├── main/index.html.twig
│   └── product/ (edit, ean_exists, edit_existing, insert_ean, list, remove, remove_insert_ean, view)
├── .env
├── .env.prod.example
├── .gitignore
├── composer.json
├── docker-compose.yml
└── MIGRATION.md
```

---

## Mapowanie URL (stare → nowe)

| Stare URL | Nowe URL | Kontroler |
|-----------|----------|-----------|
| `/?p=Main` (lub `/`) | `GET /` | `MainController::index` |
| `/?p=Products` | `GET /products` | `ProductController::list` |
| `/?p=ViewProduct&id=X` | `GET /products/{id}` | `ProductController::view` |
| `/?p=EditProduct` | `GET/POST /products/edit` | `ProductController::edit` |
| `/?p=RemoveProduct` | `GET /products/remove` | `ProductController::remove` |
| `/?p=ProductsGroups` | `GET /groups` | `GroupController::list` |
| `/?p=ViewGroup&id=X` | `GET /groups/{id}` | `GroupController::view` |
| `/?p=AddGroup` | `GET/POST /groups/add` | `GroupController::add` |
| `/api/index.php?p=X&pwd=Y` | `/api/index.php?p=X&pwd=Y` | `ApiController` (**1:1 zachowane**) |

---

## API (1:1 kompatybilne)

Endpointy `GET/POST /api/index.php?p=PAGE&pwd=HASLO`:

| `?p=` | Opis | Odpowiedź |
|-------|------|-----------|
| `ViewProduct` | Pobierz produkt wg `?id=` lub `?ean=` | JSON z danymi produktu |
| `EditProduct` | Zapisz/dodaj produkt | `{"status":"ok","id":X}` |
| `RemoveProduct` | Zdejmij produkt ze stanu | `Not implemented` (stare zachowanie) |
| `Main` | Lista produktów z alertami ważności | `{"outdated":...,"shortExpirationDates":...}` |
| `Products` | Lista wszystkich produktów | JSON array |

Hasło API: zmienna `API_PASSWORD` w `.env` (wcześniej stała `PWD` w `api/pwd.php`)

---

## Schemat bazy danych (przeanalizowany)

```sql
CREATE TABLE `products` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ean` bigint NOT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `ean` (`ean`)
) ENGINE=MyISAM;

CREATE TABLE `products_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `products_id` int NOT NULL,
  `cost` int NOT NULL,      -- grosze (x100)
  `active` int NOT NULL,    -- 0/1
  `date_added` date NOT NULL,
  `expiration_date` date DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM;

CREATE TABLE `features` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL,      -- klucz EN (np. 'producer')
  `name_pl` varchar(200) NOT NULL,   -- etykieta PL
  PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM;

CREATE TABLE `products_groups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

CREATE TABLE `products_to_features` (
  `products_id` int NOT NULL,
  `features_id` int NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`products_id`,`features_id`)
) ENGINE=MyISAM;

CREATE TABLE `products_to_products_groups` (
  `product_id` int NOT NULL,
  `products_group_id` int NOT NULL,
  PRIMARY KEY (`product_id`,`products_group_id`)
) ENGINE=InnoDB;

CREATE TABLE `min_stock_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `product_id` int DEFAULT NULL,
  `group_id` int DEFAULT NULL,
  `min_stock` int NOT NULL,
  `min_stock_basis` enum('ITEMS','QUANTITY') NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB;

-- Nieużywane (stary kod): categories, products_to_categories
```

---

## Uruchamianie lokalnie (Docker)

```bash
# Pierwsze uruchomienie
docker compose up --build

# Gdy kontenery działają — migracje DB
docker compose exec php php bin/console doctrine:migrations:migrate

# Import danych z produkcji (opcjonalnie)
docker compose exec -T db mysql -u app -papp domowy_magazyn < dump_produkcja.sql

# Cron (lokalnie, ręcznie)
docker compose exec php php bin/console app:send-notifications
```

App dostępna pod: http://localhost:8080

---

## Deploy na prod (bez Dockera)

```bash
# Wymagania: PHP 8.2+, Composer, MySQL 8
git pull
composer install --no-dev --optimize-autoloader
APP_ENV=prod php bin/console cache:clear
php bin/console doctrine:migrations:migrate
```

Plik `.env.local` na serwerze (wzór z `.env.prod.example`):
- `DATABASE_URL` — połączenie do lokalnej bazy MySQL
- `API_PASSWORD` — hasło do API
- `ONESIGNAL_*` — klucze OneSignal

Cron na prod:
```cron
0 8 * * * /usr/bin/php /var/www/domowy_magazyn/bin/console app:send-notifications >> /var/log/magazyn_notifs.log 2>&1
```

---

## Znane do zrobienia / potencjalne problemy

1. **`categories` / `products_to_categories`** — tabele istnieją w DB ale nie są używane. Nie migrowane do Doctrine na razie. Można dodać encję `Category` jeśli potrzeba.
2. **`RemoveProduct` API** — stara implementacja zwracała `"Not implemented"`. Zachowane dla zgodności. Można zmienić na JSON gdy klienty API zostaną zaktualizowane.
3. **Silnik MyISAM** — tabele `products`, `products_history`, `features`, `products_to_features` używają MyISAM. Doctrine generuje InnoDB. Przy `doctrine:migrations:diff` poczekaj na różnicę ENGINE — do decyzji czy migrować.
4. **Pliki statyczne** — skopiowane do `public/files/`. Na prod można zostawić tam lub użyć symlinku do `old/Files/`.
5. **`edit_product.js`** — JS ze starego projektu, oczekuje `groups` jako globalną zmienną. Należy sprawdzić czy działa poprawnie po migracji URL-i.
6. **`deactivateByExpirationDate()`** w `Product::` modyfikuje kolekcję in-memory ale zmiany są flush-owane przez EntityManager. Wymaga testu.
