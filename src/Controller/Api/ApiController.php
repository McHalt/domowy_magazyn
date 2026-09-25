<?php

namespace App\Controller\Api;

use App\Entity\Product;
use App\Entity\ProductToFeature;
use App\Repository\FeatureRepository;
use App\Repository\ProductHistoryRepository;
use App\Repository\ProductRepository;
use App\Repository\ProductsGroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API kompatybilne 1:1 ze starym api/index.php?p=PAGE&pwd=HASLO
 *
 * Endpointy:
 *   GET/POST /api/index.php?p=ViewProduct&pwd=X&id=ID
 *   GET/POST /api/index.php?p=EditProduct&pwd=X&ean=EAN&qty=N&cost=N...
 *   GET/POST /api/index.php?p=RemoveProduct&pwd=X&ean=EAN[&date=DATE]
 *   GET      /api/index.php?p=Main&pwd=X
 *   GET      /api/index.php?p=Products&pwd=X
 */
#[Route('/api/index.php', name: 'api_dispatch')]
class ApiController extends AbstractController
{
    public function __construct(
        #[Autowire('%api_password%')]
        private readonly string $apiPassword,
    ) {}

    public function __invoke(
        Request $request,
        ProductRepository $productRepo,
        FeatureRepository $featureRepo,
        ProductsGroupRepository $groupRepo,
        ProductHistoryRepository $historyRepo,
        EntityManagerInterface $em,
    ): Response {
        // Weryfikacja hasła (kompatybilne z api/pwd.php)
        if ($this->apiPassword !== '' && $request->query->get('pwd') !== $this->apiPassword) {
            return new Response('', 200); // stare zachowanie: exit bez treści
        }

        $page = $request->query->get('p', '');

        return match ($page) {
            'ViewProduct'   => $this->viewProduct($request, $productRepo, $featureRepo),
            'EditProduct'   => $this->editProduct($request, $productRepo, $featureRepo, $groupRepo, $historyRepo, $em),
            'RemoveProduct' => $this->removeProduct($request, $productRepo, $em),
            'Main'          => $this->main($productRepo),
            'Products'      => $this->products($productRepo),
            default         => new Response('', 200),
        };
    }

    private function viewProduct(Request $request, ProductRepository $productRepo, FeatureRepository $featureRepo): Response
    {
        $id = $request->query->get('id');
        $ean = $request->query->get('ean');

        $product = $id
            ? $productRepo->findByIdWithRelations((int)$id)
            : ($ean ? $productRepo->findByEanWithRelations($ean) : null);

        if (!$product) {
            return new JsonResponse([]);
        }

        $featuresMap = $product->getFeaturesMap();

        $groups = [];
        foreach ($product->getGroupsMap() as $gId => $gName) {
            $groups[] = ['id' => $gId, 'name' => $gName];
        }

        $data = [
            'id'             => $product->getId(),
            'ean'            => $product->getEan(),
            'qty'            => $product->getQty(),
            'name'           => $featuresMap['name'] ?? null,
            'producer'       => $featuresMap['producer'] ?? null,
            'lastCost'       => $product->getLastCost(),
            'lowestCost'     => $product->getLowestCost(),
            'activeProducts' => $product->getActiveProducts(),
            'groups'         => $groups,
        ];

        // Gdy podano ?id= → zwróć też pełne cechy i możliwe cechy (jak stary kod)
        if ($id) {
            $data['allPossibleFeatures'] = $featureRepo->findAllAsMap();
            $data['features'] = $featuresMap;
        }

        return new JsonResponse($data);
    }

    private function editProduct(
        Request $request,
        ProductRepository $productRepo,
        FeatureRepository $featureRepo,
        ProductsGroupRepository $groupRepo,
        ProductHistoryRepository $historyRepo,
        EntityManagerInterface $em
    ): Response {
        $vars = array_map(
            'htmlspecialchars',
            array_merge($request->query->all(), $request->request->all())
        );

        $id = $vars['id'] ?? null;
        $ean = $vars['ean'] ?? null;
        $qty = $vars['qty'] ?? null;
        $forceSave = !empty($vars['forceSave']);

        if (empty($ean) && empty($id)) {
            return new Response('');
        }

        $product = $id
            ? $productRepo->findByIdWithRelations((int)$id)
            : ($ean ? $productRepo->findByEanWithRelations($ean) : null);

        if ((!is_numeric($vars['cost'] ?? '') || !is_numeric($qty)) && !$forceSave) {
            return new Response('');
        }

        $cost = str_replace(',', '.', $vars['cost'] ?? '0');
        $costGrosze = (int)((float)$cost * 100);
        $expirationDate = $vars['expiration_date'] ?? null;

        if (!$product) {
            $product = new Product();
            $product->setEan($ean ?? '');
            $em->persist($product);
            $em->flush();
        }

        if (!empty($qty)) {
            for ($i = 0; $i < (int)$qty; $i++) {
                $historyRepo->addEntry($product, $costGrosze, $expirationDate ?: null, false);
            }
        }

        // Cechy
        $featuresInput = [];
        foreach ($vars as $key => $value) {
            if (!empty($value) && str_starts_with($key, 'feature_')) {
                $featureName = substr($key, 8);
                $featuresInput[$featureName] = $value;
            }
        }

        if (!empty($featuresInput)) {
            $featuresByName = [];
            foreach ($featureRepo->findAll() as $feature) {
                $featuresByName[$feature->getName()] = $feature;
            }
            foreach ($product->getProductFeatures() as $existing) {
                $em->remove($existing);
            }
            $em->flush();
            foreach ($featuresInput as $featureName => $value) {
                if (!isset($featuresByName[$featureName])) continue;
                $ptf = new ProductToFeature();
                $ptf->setProduct($product);
                $ptf->setFeature($featuresByName[$featureName]);
                $ptf->setValue($value);
                $em->persist($ptf);
            }
        }

        // Grupy
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

        // Stara odpowiedź API
        return new JsonResponse(['status' => 'ok', 'id' => $product->getId()]);
    }

    private function removeProduct(Request $request, ProductRepository $productRepo, EntityManagerInterface $em): Response
    {
        $ean = $request->query->get('ean');

        if (!$ean) {
            return new Response('Not implemented'); // stare zachowanie
        }

        $product = $productRepo->findByEanWithRelations($ean);

        if (!$product || !count($product->getActiveProducts())) {
            return new Response('Not implemented');
        }

        $date = $request->query->get('date');

        if (!$date) {
            $dates = $product->getUniqueExpirationDates();
            if (count($dates) === 1) {
                $date = $dates[0];
            } else {
                return new Response('Not implemented'); // stare zachowanie przy wielu datach
            }
        }

        $product->deactivateByExpirationDate($date);
        $em->flush();

        return new Response('Not implemented'); // stare zachowanie: brak odpowiedzi JSON
    }

    private function main(ProductRepository $productRepo): Response
    {
        $products = $productRepo->findAllWithRelations();

        $errors = [];
        $warnings = [];

        $now = time();
        $time1Day = strtotime('+1 day');
        $time3Days = strtotime('+3 days');
        $time7Days = strtotime('+7 days');

        foreach ($products as $product) {
            foreach ($product->getActiveProducts() as $singleProduct) {
                if (!$singleProduct['expirationDate']) continue;
                $time = strtotime($singleProduct['expirationDate']);
                $featuresMap = $product->getFeaturesMap();
                $name = ($featuresMap['producer'] ?? '') . ' ' . ($featuresMap['name'] ?? '');

                if ($now > $time) {
                    $errors[] = "Produkt $name skończył swoją ważność! ({$singleProduct['expirationDate']})";
                } elseif ($time1Day > $time) {
                    $warnings[] = "Produkt $name kończy ważność za mniej niż 1 dzień! ({$singleProduct['expirationDate']})";
                } elseif ($time3Days > $time) {
                    $warnings[] = "Produkt $name kończy ważność za mniej niż 3 dni! ({$singleProduct['expirationDate']})";
                } elseif ($time7Days > $time) {
                    $warnings[] = "Produkt $name kończy ważność za mniej niż 7 dni! ({$singleProduct['expirationDate']})";
                }
            }
        }

        return new JsonResponse([
            'outdated' => $errors ?: 'none',
            'shortExpirationDates' => $warnings ?: 'none',
        ]);
    }

    private function products(ProductRepository $productRepo): Response
    {
        $products = $productRepo->findAllWithRelations();
        usort($products, fn($a, $b) => $b->getQty() - $a->getQty());

        $result = [];
        foreach ($products as $product) {
            $featuresMap = $product->getFeaturesMap();
            $result[] = [
                'id'       => $product->getId(),
                'ean'      => $product->getEan(),
                'qty'      => $product->getQty(),
                'name'     => $featuresMap['name'] ?? null,
                'producer' => $featuresMap['producer'] ?? null,
            ];
        }

        return new JsonResponse($result);
    }

}
