<?php

namespace App\Controller;

use App\Entity\ProductsGroup;
use App\Repository\ProductsGroupRepository;
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
    public function view(int $id, ProductsGroupRepository $groupRepo): Response
    {
        $group = $groupRepo->findByIdWithProducts($id);

        if (!$group) {
            throw $this->createNotFoundException('Nie znaleziono grupy');
        }

        return $this->render('group/view.html.twig', [
            'group' => $group,
        ]);
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
}
