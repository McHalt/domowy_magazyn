<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\ProductToFeature;
use App\Repository\FeatureRepository;
use App\Repository\ProductHistoryRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductsGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProductController extends AbstractController
{
    #[Route('/products', name: 'products_list')]
    public function list(ProductRepository $productRepo): Response
    {
        $products = $productRepo->findAllWithRelations();

        // Sortuj malejąco po ilości (jak stara logika w Products.php)
        usort($products, fn($a, $b) => $b->getQty() - $a->getQty());

        return $this->render('product/list.html.twig', [
            'products' => $products,
        ]);
    }

    #[Route('/products/{id}', name: 'product_view', requirements: ['id' => '\d+'])]
    public function view(int $id, ProductRepository $productRepo): Response
    {
        $product = $productRepo->findByIdWithRelations($id);

        if (!$product) {
            throw $this->createNotFoundException('Nie znaleziono produktu');
        }

        return $this->render('product/view.html.twig', [
            'product' => $product,
        ]);
    }

    #[Route('/products/edit', name: 'product_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ProductRepository $productRepo,
        FeatureRepository $featureRepo,
        ProductsGroupRepository $groupRepo,
        ProductHistoryRepository $historyRepo,
        EntityManagerInterface $em
    ): Response {
        $id = $request->query->get('id');
        $ean = $request->query->get('ean') ?? $request->request->get('ean');

        // Krok 1: brak EAN i brak ID → formularz wpisania EAN
        if (!$id && !$ean) {
            return $this->render('product/insert_ean.html.twig');
        }

        // Wczytaj produkt
        $product = null;
        if ($id) {
            $product = $productRepo->findByIdWithRelations((int)$id);
        } elseif ($ean) {
            $product = $productRepo->findByEanWithRelations($ean);
        }

        $allPossibleFeatures = $featureRepo->findAllAsMap();
        $allGroups = [];
        foreach ($groupRepo->findAll() as $g) {
            $allGroups[$g->getId()] = ['id' => $g->getId(), 'name' => $g->getName()];
        }
        $groupsJson = json_encode($allGroups);

        $qty = $request->request->get('qty') ?? $request->query->get('qty');
        $forceSave = $request->query->getBoolean('forceSave');

        // Krok 2: EAN istnieje ale nie zapisujemy jeszcze (brak qty)
        if (!$qty && !$forceSave && !$id) {
            if ($product && $product->getId()) {
                // Produkt o tym EAN już istnieje — pokaż formularz "dodajesz znany produkt"
                return $this->render('product/ean_exists.html.twig', [
                    'product' => $product,
                    'allPossibleFeatures' => $allPossibleFeatures,
                    'groupsJson' => $groupsJson,
                ]);
            }
            // Nowy produkt
            if (!$product) {
                $product = new Product();
                $product->setEan($ean);
            }
            return $this->render('product/edit.html.twig', [
                'product' => $product,
                'allPossibleFeatures' => $allPossibleFeatures,
                'allGroups' => $allGroups,
            ]);
        }

        // Krok 3: Edycja istniejącego (ID podane) → formularz edycji
        if ($id && !$qty && !$forceSave) {
            return $this->render('product/edit_existing.html.twig', [
                'product' => $product,
                'allPossibleFeatures' => $allPossibleFeatures,
                'allGroups' => $allGroups,
                'groupsJson' => $groupsJson,
            ]);
        }

        // Krok 4: Zapis
        $cost = str_replace(',', '.', $request->request->get('cost') ?? $request->query->get('cost') ?? '');
        $expirationDate = $request->request->get('expiration_date') ?? $request->query->get('expiration_date') ?? '';

        if ((!is_numeric($cost) || !is_numeric($qty)) && !$forceSave) {
            return new Response('', 400);
        }

        $costGrosze = (int)((float)$cost * 100);

        // Utwórz produkt jeśli nowy
        if (!$product) {
            $product = new Product();
            $product->setEan($ean);
            $em->persist($product);
            $em->flush();
        }

        // Zapisz historię (dodaj sztuki)
        if ($qty > 0) {
            for ($i = 0; $i < (int)$qty; $i++) {
                $historyRepo->addEntry($product, $costGrosze, $expirationDate ?: null, false);
            }
        }

        // Zapisz cechy (features_)
        $featuresInput = [];
        foreach (array_merge($request->query->all(), $request->request->all()) as $key => $value) {
            if (!empty($value) && str_starts_with($key, 'feature_')) {
                $featureName = substr($key, 8);
                $featuresInput[$featureName] = htmlspecialchars($value);
            }
        }

        if (!empty($featuresInput)) {
            // Znajdź ID cech po name
            $featuresByName = [];
            foreach ($featureRepo->findAll() as $feature) {
                $featuresByName[$feature->getName()] = $feature;
            }

            // Usuń stare cechy produktu
            foreach ($product->getProductFeatures() as $existing) {
                $em->remove($existing);
            }
            $em->flush();

            // Dodaj nowe
            foreach ($featuresInput as $featureName => $value) {
                if (!isset($featuresByName[$featureName])) continue;
                $ptf = new ProductToFeature();
                $ptf->setProduct($product);
                $ptf->setFeature($featuresByName[$featureName]);
                $ptf->setValue($value);
                $em->persist($ptf);
            }
        }

        // Zapisz grupy
        $postGroups = $request->request->all('groups');
        if (!empty($postGroups)) {
            foreach ($product->getGroups() as $g) {
                $product->getGroups()->removeElement($g);
            }
            foreach ($postGroups as $groupId) {
                $group = $groupRepo->find((int)$groupId);
                if ($group) {
                    $product->getGroups()->add($group);
                }
            }
        }

        $em->flush();

        return $this->redirectToRoute('main');
    }

    #[Route('/products/remove', name: 'product_remove', methods: ['GET', 'POST'])]
    public function remove(
        Request $request,
        ProductRepository $productRepo,
        EntityManagerInterface $em
    ): Response {
        $ean = $request->query->get('ean');

        if (!$ean) {
            return $this->render('product/remove_insert_ean.html.twig');
        }

        $product = $productRepo->findByEanWithRelations($ean);

        if (!$product) {
            return $this->render('product/remove.html.twig', [
                'errors' => ['Nie ma takiego produktu'],
                'warnings' => [],
            ]);
        }

        if (!count($product->getActiveProducts())) {
            return $this->render('product/remove.html.twig', [
                'errors' => ['Nie ma takiego produktu na stanie'],
                'warnings' => [],
            ]);
        }

        $date = $request->query->get('date');

        if (!$date) {
            // Sprawdź unikalne daty ważności
            $expirationDates = $product->getUniqueExpirationDates();

            if (count($expirationDates) === 1) {
                // Jedna data → automatycznie usuń
                $date = $expirationDates[0];
            } else {
                // Wiele dat → pokaż wybór
                return $this->render('product/remove.html.twig', [
                    'errors' => [],
                    'warnings' => [],
                    'expirationDates' => $expirationDates,
                    'product' => $product,
                ]);
            }
        }

        $product->deactivateByExpirationDate($date);
        $em->flush();

        return $this->redirectToRoute('main');
    }
}
