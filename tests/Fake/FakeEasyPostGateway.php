<?php

namespace App\Tests\Fake;

use App\Services\ShippingService\RateUnavailableException;
use App\Services\ShippingService\ShippingCarrierGatewayInterface;

/**
 * Passerelle EasyPost simulée (lot 2 Livraison, 07/11/2026) : enregistre chaque charge utile envoyée, rend des tarifs fixés par le test
 * ($rates), achète le tarif exact (jamais un autre), suit un colis selon $trackingStatus. Clé « présente » selon $configured.
 */
final class FakeEasyPostGateway implements ShippingCarrierGatewayInterface
{
    /** @var list<array{kind: string, data: array<string, mixed>}> */
    public static array $requests = [];
    /** @var list<array<string, mixed>> tarifs rendus pour chaque colis : carrier, service, rate (dollars, chaîne), currency, carrier_account_id, delivery_days */
    public static array $rates = [];
    public static bool $configured = true;
    public static string $environment = 'test';
    public static ?string $trackingStatus = 'in_transit';
    public static int $labels = 0;

    public static function reset(): void
    {
        self::$requests = [];
        self::$rates = [
            ['carrier' => 'CanadaPost', 'service' => 'ExpeditedParcel', 'rate' => '12.50', 'currency' => 'CAD', 'carrier_account_id' => 'ca_plateforme_postes', 'delivery_days' => 3],
            ['carrier' => 'CanadaPost', 'service' => 'Xpresspost', 'rate' => '19.90', 'currency' => 'CAD', 'carrier_account_id' => 'ca_plateforme_postes', 'delivery_days' => 1],
            ['carrier' => 'USPS', 'service' => 'Priority', 'rate' => '9.10', 'currency' => 'USD', 'carrier_account_id' => 'ca_plateforme_usps', 'delivery_days' => 2],
        ];
        self::$configured = true;
        self::$environment = 'test';
        self::$trackingStatus = 'in_transit';
        self::$labels = 0;
    }

    public function getRates(array $data): array
    {
        self::$requests[] = ['kind' => 'rates', 'data' => $data];

        return array_map(fn (array $r) => (object) ($r + ['id' => 'rate_' . md5(json_encode($r))]), self::$rates);
    }

    public function createShipmentAndBuy(array $data, string $carrierAccountId, string $service): array
    {
        self::$requests[] = ['kind' => 'buy', 'data' => $data, 'carrierAccountId' => $carrierAccountId, 'service' => $service];
        foreach (self::$rates as $r) {
            if ($r['carrier_account_id'] === $carrierAccountId && $r['service'] === $service) {
                self::$labels++;

                return ['label_url' => 'https://easypost.test/labels/' . self::$labels . '.pdf', 'tracking_code' => 'TRK' . str_pad((string) self::$labels, 8, '0', STR_PAD_LEFT), 'price' => $r['rate'], 'currency' => $r['currency'], 'carrier' => $r['carrier'], 'service' => $r['service']];
            }
        }
        throw new RateUnavailableException($service, $carrierAccountId, array_map(fn ($r) => $r['service'] . ' (' . $r['carrier_account_id'] . ')', self::$rates));
    }

    public function track(string $trackingCode, ?string $carrier = null): array
    {
        self::$requests[] = ['kind' => 'track', 'data' => ['tracking_code' => $trackingCode, 'carrier' => $carrier]];

        return ['status' => self::$trackingStatus, 'history' => []];
    }

    public function hasConfiguration(): bool
    {
        return self::$configured;
    }

    public function environment(): string
    {
        return self::$environment;
    }
}
