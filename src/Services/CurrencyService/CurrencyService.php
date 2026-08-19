<?php

namespace App\Services\CurrencyService;

use App\Entity\Currency;
use App\Repository\CurrencyRepository;
use App\Services\TenantEntityManagerProvider;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;

class CurrencyService
{
    private const API_URL = 'https://api.frankfurter.app/latest?from=CAD';

    public function __construct(
        private TenantEntityManagerProvider $emProvider, 
        private HttpClientInterface $client,
        private LoggerInterface $logger
    ) {
    }

    /**
     * @return Currency[]
     */
    public function getCurrenciesOrUpdate(): array
    {
        $repository = $this->emProvider->getEntityManager()->getRepository(Currency::class);
        $currencies = $repository->findAll();

        if (empty($currencies)) {
            return [];
        }

        return $currencies;
    }

    public function refreshRates(): void
    {
        try {
            $em = $this->emProvider->getEntityManager();
            $repository = $em->getRepository(Currency::class);
            $currencies = $repository->findAll();

            if (empty($currencies)) {
                $this->logger->info('CurrencyService: Aucune devise à mettre à jour.');
                return;
            }

            $response = $this->client->request('GET', self::API_URL);
            $data = $response->toArray();
            $now = new \DateTimeImmutable();

            foreach ($currencies as $currency) {
                $code = $currency->getCode();
                if (isset($data['rates'][$code])) {
                    $currency->setExchangeRate((string) $data['rates'][$code]);
                    $currency->setUpdatedAt($now);
                }
            }

            $em->flush();
            $this->logger->info('CurrencyService: Flush effectué avec succès.');

        } catch (\Exception $e) {
            $this->logger->error('CurrencyService Error: ' . $e->getMessage());
        }
    }
}
