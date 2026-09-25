<?php

namespace App\Command;

use App\Repository\ProductRepository;
use GuzzleHttp\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:send-notifications',
    description: 'Wysyła powiadomienia push o produktach z kończącą się datą ważności (OneSignal)',
)]
class SendNotificationsCommand extends Command
{
    public function __construct(
        private readonly ProductRepository $productRepo,
        #[Autowire('%env(ONESIGNAL_APP_ID)%')]
        private readonly string $oneSignalAppId,
        #[Autowire('%env(ONESIGNAL_REST_API_KEY)%')]
        private readonly string $oneSignalRestApiKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $products = $this->productRepo->findAllWithRelations();

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

        if (empty($this->oneSignalAppId) || empty($this->oneSignalRestApiKey)) {
            $output->writeln('<error>Brak konfiguracji OneSignal (ONESIGNAL_APP_ID, ONESIGNAL_REST_API_KEY)</error>');
            return Command::FAILURE;
        }

        $client = new Client();
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Basic ' . $this->oneSignalRestApiKey,
            'Content-Type' => 'application/json',
        ];

        if (!empty($warnings)) {
            $body = [
                'app_id' => $this->oneSignalAppId,
                'included_segments' => ['All'],
                'headings' => ['en' => 'Zbliża się koniec ważności produktów'],
                'contents' => ['en' => implode("\n", $warnings)],
            ];
            $client->request('POST', 'https://onesignal.com/api/v1/notifications', [
                'body' => json_encode($body),
                'headers' => $headers,
            ]);
            $output->writeln(sprintf('Wysłano ostrzeżenia: %d', count($warnings)));
        }

        if (!empty($errors)) {
            $body = [
                'app_id' => $this->oneSignalAppId,
                'included_segments' => ['All'],
                'headings' => ['en' => 'Produkty utraciły swoją ważność'],
                'contents' => ['en' => implode("\n", $errors)],
            ];
            $client->request('POST', 'https://onesignal.com/api/v1/notifications', [
                'body' => json_encode($body),
                'headers' => $headers,
            ]);
            $output->writeln(sprintf('Wysłano błędy: %d', count($errors)));
        }

        if (empty($warnings) && empty($errors)) {
            $output->writeln('Brak produktów z kończącą się datą ważności.');
        }

        return Command::SUCCESS;
    }
}
