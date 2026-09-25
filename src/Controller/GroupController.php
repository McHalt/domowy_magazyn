<?php

namespace App\Controller;

use App\Entity\ProductsGroup;
use App\Entity\Unit;
use App\Repository\ProductsGroupRepository;
use App\Service\MinStockManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GroupController extends AbstractController
{
    #[Route('/groups', name: 'groups_list')]
    public function list(ProductsGroupRepository $groupRepo): Response
    {
        return $this->render('group/list.html.twig', [
            'groups' => $groupRepo->findAll(),
        ]);
    }

    #[Route('/groups/{id}', name: 'group_view', requirements: ['id' => '\d+'])]
    public function view(int $id, ProductsGroupRepository $groupRepo, MinStockManager $minStock): Response
    {
        return $this->renderGroupView($this->findGroup($id, $groupRepo), $minStock, []);
    }

    #[Route('/groups/{id}/min-stock', name: 'group_min_stock', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateMinStock(
        int $id,
        Request $request,
        ProductsGroupRepository $groupRepo,
        MinStockManager $minStock
    ): Response {
        $group = $this->findGroup($id, $groupRepo);

        $errors = $minStock->update(
            $group,
            (string)$request->request->get('min_packages', ''),
            $request->request->has('min_amount') ? (string)$request->request->get('min_amount') : null,
        );

        return $errors
            ? $this->renderGroupView($group, $minStock, $errors, Response::HTTP_UNPROCESSABLE_ENTITY)
            : $this->redirectToRoute('group_view', ['id' => $id]);
    }

    #[Route('/groups/{id}/unit', name: 'group_unit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function updateUnit(
        int $id,
        Request $request,
        ProductsGroupRepository $groupRepo,
        MinStockManager $minStock
    ): Response {
        $group = $this->findGroup($id, $groupRepo);

        $unitInput = (string)$request->request->get('unit', '');
        $unit = Unit::tryFrom($unitInput);

        $errors = $unitInput !== '' && $unit === null
            ? ['Nieznana jednostka.']
            : $minStock->changeGroupUnit($group, $unit);

        return $errors
            ? $this->renderGroupView($group, $minStock, $errors, Response::HTTP_UNPROCESSABLE_ENTITY)
            : $this->redirectToRoute('group_view', ['id' => $id]);
    }

    #[Route('/groups/add', name: 'group_add', methods: ['GET', 'POST'])]
    public function add(Request $request, ProductsGroupRepository $groupRepo): Response
    {
        if ($request->isMethod('POST') && $request->request->get('name')) {
            $group = new ProductsGroup();
            $group->setName(htmlspecialchars($request->request->get('name')));
            $groupRepo->save($group);

            return $this->redirectToRoute('group_view', ['id' => $group->getId()]);
        }

        return $this->render('group/add.html.twig');
    }

    private function findGroup(int $id, ProductsGroupRepository $groupRepo): ProductsGroup
    {
        return $groupRepo->findByIdWithProducts($id)
            ?? throw $this->createNotFoundException('Nie znaleziono grupy');
    }

    /** @param string[] $minStockErrors */
    private function renderGroupView(
        ProductsGroup $group,
        MinStockManager $minStock,
        array $minStockErrors,
        int $status = Response::HTTP_OK
    ): Response {
        return $this->render('group/view.html.twig', [
            'group' => $group,
            'minStock' => $minStock->overview($group),
            'minStockErrors' => $minStockErrors,
            'units' => Unit::cases(),
        ], new Response(status: $status));
    }
}
