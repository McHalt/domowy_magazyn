<?php

namespace App\Controller\Api\V1;

use App\Api\ApiProblem;
use App\Api\CatalogService;
use App\Api\Input;
use App\Api\Json;
use App\Api\ListQuery;
use App\Api\Presenter;
use App\Entity\ProductsGroup;
use App\Hub\Security\RequireScope;
use App\Hub\Security\Scopes;
use App\Repository\ProductRepository;
use App\Repository\ProductsGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/v1/groups')]
final class GroupsController
{
    private const ID = ['groupId' => '\d{1,18}'];

    public function __construct(
        private readonly ProductsGroupRepository $groups,
        private readonly ProductRepository $products,
        private readonly Presenter $presenter,
        private readonly CatalogService $catalog,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'api_v1_groups_list', methods: ['GET'])]
    #[RequireScope(Scopes::READ)]
    public function list(Request $request): Response
    {
        $query = ListQuery::fromRequest($request);

        // Produkty ze wszystkimi relacjami najpierw, żeby ilości grup nie wywoływały zapytań dla każdego produktu.
        $this->products->findAllWithRelations();
        $rows = array_map(
            fn(ProductsGroup $g) => ['key' => $this->presenter->sortKey(Presenter::text($g->getName()), $g->getId()), 'item' => $g],
            $this->groups->findAllWithProducts(),
        );
        usort($rows, fn(array $a, array $b) => strcmp($a['key'], $b['key']));

        $page = $query->page($rows);
        $rules = $this->presenter->loadRules();
        $page['items'] = array_map(fn(ProductsGroup $g) => $this->presenter->group($g, $rules), $page['items']);

        return Json::response($page);
    }

    #[Route('', name: 'api_v1_groups_create', methods: ['POST'])]
    #[RequireScope(Scopes::ADMIN)]
    public function create(Request $request): Response
    {
        $input = Input::fromRequest($request);
        $input->rejectUnknown(['name', 'unit']);
        $name = $input->string('name', 'Nazwa', 100, required: true);
        $unit = $input->unit();
        $input->finish();

        $id = $this->catalog->createGroup($name, $unit);

        return Json::response($this->detail($id), Response::HTTP_CREATED, ['Location' => '/api/v1/groups/' . $id]);
    }

    #[Route('/{groupId}', name: 'api_v1_groups_show', requirements: self::ID, methods: ['GET'])]
    #[RequireScope(Scopes::READ)]
    public function show(int $groupId): Response
    {
        return Json::response($this->detail($groupId));
    }

    #[Route('/{groupId}', name: 'api_v1_groups_update', requirements: self::ID, methods: ['PATCH'])]
    #[RequireScope(Scopes::ADMIN)]
    public function update(int $groupId, Request $request): Response
    {
        $group = $this->find($groupId);

        $input = Input::fromRequest($request);
        $input->rejectUnknown(['name', 'unit']);
        $input->requireNonEmpty();
        $changes = [];
        if ($input->has('name')) {
            $changes['name'] = $input->string('name', 'Nazwa', 100, required: true);
        }
        if ($input->has('unit')) {
            $changes['unit'] = $input->unit();
        }
        $input->finish();

        $this->catalog->updateGroup($group, $changes);

        return Json::response($this->detail($groupId));
    }

    #[Route('/{groupId}/min-stock', name: 'api_v1_groups_min_stock', requirements: self::ID, methods: ['PUT'])]
    #[RequireScope(Scopes::ADMIN)]
    public function minStock(int $groupId, Request $request): Response
    {
        $group = $this->find($groupId);

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

        $this->catalog->setMinStock($group, $packages, $amount);

        return Json::response($this->detail($groupId));
    }

    private function find(int $groupId): ProductsGroup
    {
        return $this->groups->findByIdWithProducts($groupId) ?? throw ApiProblem::notFound();
    }

    /** Świeży odczyt po zapisie, żeby odpowiedź nie zależała od stanu kolekcji w pamięci. */
    private function detail(int $groupId): array
    {
        $this->em->clear();

        return $this->presenter->groupDetail($this->find($groupId), $this->presenter->loadRules());
    }
}
