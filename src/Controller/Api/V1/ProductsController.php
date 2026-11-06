<?php

namespace App\Controller\Api\V1;

use App\Api\ApiProblem;
use App\Api\CatalogService;
use App\Api\Input;
use App\Api\Json;
use App\Api\ListQuery;
use App\Api\Presenter;
use App\Api\StockService;
use App\Entity\Product;
use App\Hub\Security\CurrentPrincipal;
use App\Hub\Security\RequireScope;
use App\Hub\Security\Scopes;
use App\Repository\ProductHistoryRepository;
use App\Repository\ProductRepository;
use App\Service\NameNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/products')]
final class ProductsController
{
    private const ID = ['productId' => '\d{1,18}'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly ProductHistoryRepository $history,
        private readonly Presenter $presenter,
        private readonly CatalogService $catalog,
        private readonly StockService $stock,
        private readonly NameNormalizer $normalizer,
        private readonly CurrentPrincipal $principal,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'api_v1_products_list', methods: ['GET'])]
    #[RequireScope(Scopes::READ)]
    public function list(Request $request): Response
    {
        $query = ListQuery::fromRequest($request);
        $filter = $this->filter($request);

        $rows = [];
        foreach ($this->products->findAllWithRelations() as $product) {
            if ($filter($product)) {
                $rows[] = ['key' => $this->presenter->sortKey(Presenter::productName($product), $product->getId()), 'item' => $product];
            }
        }
        usort($rows, fn(array $a, array $b) => strcmp($a['key'], $b['key']));

        $page = $query->page($rows);
        $rules = $this->presenter->loadRules();
        $page['items'] = array_map(fn(Product $p) => $this->presenter->product($p, $rules), $page['items']);

        return Json::response($page);
    }

    #[Route('', name: 'api_v1_products_create', methods: ['POST'])]
    #[RequireScope(Scopes::WRITE)]
    public function create(Request $request): Response
    {
        $input = Input::fromRequest($request);
        $input->rejectUnknown(['ean', 'name', 'producer', 'unit', 'amountPerPackage', 'groupIds']);
        $data = [
            'ean' => $input->ean(),
            'name' => $input->string('name', 'Nazwa', 255, required: true),
            'producer' => $input->string('producer', 'Producent', 255, nullable: true),
            'unit' => $input->unit(),
            'amountPerPackage' => $input->amount('amountPerPackage', 'Ilość w opakowaniu', 1_000_000),
            'groupIds' => $input->idList('groupIds', 'Identyfikatory grup') ?? [],
        ];
        $input->finish();

        $id = $this->catalog->createProduct($data);

        return Json::response($this->detail($id), Response::HTTP_CREATED, ['Location' => '/api/v1/products/' . $id]);
    }

    #[Route('/{productId}', name: 'api_v1_products_show', requirements: self::ID, methods: ['GET'])]
    #[RequireScope(Scopes::READ)]
    public function show(int $productId): Response
    {
        return Json::response($this->detail($this->find($productId)->getId()));
    }

    #[Route('/{productId}', name: 'api_v1_products_update', requirements: self::ID, methods: ['PATCH'])]
    #[RequireScope(Scopes::WRITE)]
    public function update(int $productId, Request $request): Response
    {
        $product = $this->find($productId);

        $input = Input::fromRequest($request);
        $input->rejectUnknown(['name', 'producer', 'unit', 'amountPerPackage', 'groupIds']);
        $input->requireNonEmpty();

        $changes = [];
        if ($input->has('name')) {
            $changes['name'] = $input->string('name', 'Nazwa', 255, required: true);
        }
        if ($input->has('producer')) {
            $changes['producer'] = $input->string('producer', 'Producent', 255, nullable: true);
        }
        if ($input->has('unit')) {
            $changes['unit'] = $input->unit();
        }
        if ($input->has('amountPerPackage')) {
            $changes['amountPerPackage'] = $input->amount('amountPerPackage', 'Ilość w opakowaniu', 1_000_000);
        }
        if ($input->has('groupIds')) {
            $changes['groupIds'] = $input->idList('groupIds', 'Identyfikatory grup') ?? [];
        }
        $input->finish();

        $this->catalog->updateProduct($product, $changes);

        return Json::response($this->detail($productId));
    }

    #[Route('/{productId}/stock', name: 'api_v1_products_add_stock', requirements: self::ID, methods: ['POST'])]
    #[RequireScope(Scopes::WRITE)]
    public function addStock(int $productId, Request $request): Response
    {
        $product = $this->find($productId);

        $input = Input::fromRequest($request, bodyOptional: true);
        $input->rejectUnknown(['quantity', 'costMinor', 'expirationDate']);
        $quantity = $input->integer('quantity', 'Ilość', 1, 100, 1);
        $cost = $input->integer('costMinor', 'Cena', 0, 100_000_000, 0);
        $expiration = $input->date('expirationDate', 'Data ważności');
        $input->finish();

        $created = $this->stock->add($product, $quantity, $cost, $expiration, $this->principal->memberId());
        $items = $this->presenter->items($created);

        return Json::response(['items' => $items, 'product' => $this->detail($productId)], Response::HTTP_CREATED);
    }

    #[Route('/{productId}/consume', name: 'api_v1_products_consume', requirements: self::ID, methods: ['POST'])]
    #[RequireScope(Scopes::WRITE)]
    public function consume(int $productId, Request $request): Response
    {
        $this->find($productId);

        $input = Input::fromRequest($request, bodyOptional: true);
        $input->rejectUnknown(['quantity', 'expirationDate']);
        $quantity = $input->integer('quantity', 'Ilość', 1, 100, 1);
        $onlyDate = $input->has('expirationDate') ? ['date' => $input->date('expirationDate', 'Data ważności')] : null;
        $input->finish();

        $ids = $this->stock->consume($productId, $quantity, $onlyDate, $this->principal->memberId());

        $this->em->clear();
        $removed = $this->history->findBy(['id' => $ids]);

        return Json::response(['items' => $this->presenter->items($removed), 'product' => $this->detail($productId)]);
    }

    #[Route('/{productId}/min-stock', name: 'api_v1_products_min_stock', requirements: self::ID, methods: ['PUT'])]
    #[RequireScope(Scopes::ADMIN)]
    public function minStock(int $productId, Request $request): Response
    {
        $product = $this->find($productId);

        $input = Input::fromRequest($request);
        $input->rejectUnknown(['packages', 'amount']);
        foreach (['packages' => 'Minimum opakowań', 'amount' => 'Minimum ilości'] as $field => $label) {
            if (!$input->has($field)) {
                $input->error($field, $label . ' jest wymagane (liczba albo null)');
            }
        }
        $packages = $input->nullableInteger('packages', 'Minimum opakowań', 1, 1_000_000_000);
        $amount = $input->amount('amount', 'Minimum ilości', 1_000_000_000);
        $input->finish();

        $this->catalog->setMinStock($product, $packages, $amount);

        return Json::response($this->detail($productId));
    }

    private function find(int $productId): Product
    {
        return $this->products->findByIdWithRelations($productId) ?? throw ApiProblem::notFound();
    }

    /** Świeży odczyt po zapisie, żeby odpowiedź nie zależała od stanu kolekcji w pamięci. */
    private function detail(int $productId): array
    {
        $this->em->clear();
        $product = $this->find($productId);

        return $this->presenter->productDetail($product, $this->presenter->loadRules());
    }

    /** @return callable(Product): bool */
    private function filter(Request $request): callable
    {
        $errors = [];
        $conditions = [];

        if ($request->query->has('q')) {
            $q = $request->query->get('q');
            $needle = is_string($q) && mb_strlen($q) <= 100 ? $this->normalizer->normalize($q) : '';
            if ($needle === '') {
                $errors[] = ['field' => 'q', 'message' => 'Fraza musi zawierać litery lub cyfry (najwyżej 100 znaków)'];
            } else {
                $conditions[] = fn(Product $p) => str_contains(
                    $this->normalizer->normalize(trim((string) Presenter::productProducer($p) . ' ' . (string) Presenter::productName($p))),
                    $needle,
                );
            }
        }

        if ($request->query->has('ean')) {
            $ean = $request->query->get('ean');
            if (!is_string($ean) || preg_match('/^[0-9]{1,14}$/', $ean) !== 1) {
                $errors[] = ['field' => 'ean', 'message' => 'Kod EAN musi składać się z 1–14 cyfr'];
            } else {
                $conditions[] = fn(Product $p) => ltrim($p->getEan(), '0') === ltrim($ean, '0');
            }
        }

        if ($request->query->has('groupId')) {
            $groupId = $request->query->get('groupId');
            if (!is_string($groupId) || preg_match('/^[1-9][0-9]{0,9}$/', $groupId) !== 1) {
                $errors[] = ['field' => 'groupId', 'message' => 'Identyfikator grupy musi być dodatnią liczbą całkowitą'];
            } else {
                $conditions[] = fn(Product $p) => isset($p->getGroupsMap()[(int) $groupId]);
            }
        }

        if ($request->query->has('inStock')) {
            $inStock = $request->query->get('inStock');
            if (!in_array($inStock, ['true', 'false'], true)) {
                $errors[] = ['field' => 'inStock', 'message' => 'Wartość musi być równa true albo false'];
            } else {
                $conditions[] = fn(Product $p) => ($p->getQty() > 0) === ($inStock === 'true');
            }
        }

        if ($errors !== []) {
            throw ApiProblem::validation($errors);
        }

        return function (Product $product) use ($conditions): bool {
            foreach ($conditions as $condition) {
                if (!$condition($product)) {
                    return false;
                }
            }

            return true;
        };
    }
}
