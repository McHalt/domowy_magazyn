<?php

namespace App\Controller\Api\V1;

use App\Api\ApiProblem;
use App\Api\Json;
use App\Api\ListQuery;
use App\Api\Presenter;
use App\Hub\Security\RequireScope;
use App\Hub\Security\Scopes;
use App\Service\StockLookup;
use App\Service\StockMonitor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AlertsController
{
    private const TYPES = ['expiring', 'stock_low'];

    public function __construct(
        private readonly StockMonitor $monitor,
        private readonly StockLookup $lookup,
        private readonly Presenter $presenter,
    ) {}

    #[Route('/api/v1/alerts', name: 'api_v1_alerts', methods: ['GET'])]
    #[RequireScope(Scopes::READ)]
    public function alerts(Request $request): Response
    {
        $query = ListQuery::fromRequest($request);
        $type = $request->query->get('type');
        if ($request->query->has('type') && !in_array($type, self::TYPES, true)) {
            throw ApiProblem::validation([['field' => 'type', 'message' => 'Typ musi być równy expiring albo stock_low']]);
        }

        $rows = [];
        if ($type === null || $type === 'expiring') {
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw')))->format('Y-m-d');
            foreach ($this->monitor->expiring($today) as $alert) {
                $rows[] = [
                    'key' => sprintf('1|%s|%010d', $alert['expirationDate'], $alert['productId']),
                    'item' => ['type' => 'expiring'] + array_diff_key($alert, ['ean' => true]),
                ];
            }
        }
        if ($type === null || $type === 'stock_low') {
            foreach ($this->monitor->lowStock() as $alert) {
                $rows[] = [
                    'key' => sprintf('2|%s|%s|%s', $this->presenter->sortKey($alert['name'], $alert['subjectId']), $alert['subject'], $alert['basis']),
                    'item' => ['type' => 'stock_low'] + array_diff_key($alert, ['key' => true]),
                ];
            }
        }
        usort($rows, fn(array $a, array $b) => strcmp($a['key'], $b['key']));

        return Json::response($query->page($rows));
    }

    #[Route('/api/v1/stocks', name: 'api_v1_stocks', methods: ['GET'])]
    #[RequireScope(Scopes::READ)]
    public function stocks(Request $request): Response
    {
        $name = $request->query->get('name');
        if (!is_string($name) || trim($name) === '' || mb_strlen($name) > 200) {
            throw ApiProblem::validation([['field' => 'name', 'message' => 'Nazwa jest wymagana (najwyżej 200 znaków)']]);
        }

        $result = $this->lookup->find($name, decodeEntities: true);
        $matches = array_map(fn(array $m) => ['id' => $m['id'], 'name' => $m['name'], 'quantity' => $m['qty']], $result['matches']);

        return Json::response([
            'type' => $result['type'],
            'quantity' => array_sum(array_column($matches, 'quantity')),
            'matches' => $matches,
        ]);
    }
}
