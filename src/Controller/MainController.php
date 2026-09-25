<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class MainController extends AbstractController
{
    #[Route('/', name: 'main')]
    public function index(ProductRepository $productRepo): Response
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
                if (!$singleProduct['expirationDate']) {
                    continue;
                }
                $time = strtotime($singleProduct['expirationDate']);
                $name = ($product->getFeaturesMap()['producer'] ?? '') . ' ' . ($product->getFeaturesMap()['name'] ?? '');

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

        return $this->render('main/index.html.twig', [
            'errors' => $errors,
            'warnings' => $warnings,
        ]);
    }
}
