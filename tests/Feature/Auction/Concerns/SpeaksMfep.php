<?php

declare(strict_types=1);

namespace Tests\Feature\Auction\Concerns;

use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

trait SpeaksMfep
{
    protected function billPull(string $claim, ?string $serviceType = null, ?string $guid = null): TestResponse
    {
        return $this->postMfep(
            '/api/webhooks/payments/efawateercom/bills',
            $this->billPullBody($claim, $serviceType, $guid)
        );
    }

    protected function paymentNotification(array $overrides = [], ?string $guid = null): TestResponse
    {
        return $this->postMfep(
            '/api/webhooks/payments/efawateercom',
            $this->notificationBody($overrides, $guid)
        );
    }

    protected function billPullBody(string $claim, ?string $serviceType = null, ?string $guid = null): array
    {
        return [
            'MFEP' => [
                'MsgHeader' => [
                    'TmStp' => '2026-09-08T10:14:24',
                    'GUID' => $guid ?? (string) Str::uuid(),
                    'TrsInf' => [
                        'SdrCode' => 1,
                        'RcvCode' => 1000,
                        'ReqTyp' => 'BILPULRQ',
                    ],
                ],
                'MsgBody' => [
                    'AcctInfo' => [
                        'BillingNo' => $claim,
                        'BillNo' => $claim,
                    ],
                    'ServiceType' => $serviceType,
                ],
            ],
        ];
    }

    protected function notificationBody(array $overrides = [], ?string $guid = null): array
    {
        $claim = (string) ($overrides['claim'] ?? '');

        $transfer = array_replace([
            'AcctInfo' => [
                'BillingNo' => $claim,
                'BillNo' => $claim,
            ],
            'JOEBPPSTrx' => $overrides['joebppstrx'] ?? 'JO'.Str::ulid(),
            'BankTrxId' => $overrides['bank_trx_id'] ?? 'BNK-000123',
            'BankCode' => 21,
            'PmtStatus' => 'PmtNew',
            'DueAmt' => $overrides['due'] ?? '1.000',
            'PaidAmt' => $overrides['paid'] ?? '1.000',
            'FeesAmt' => $overrides['fees'] ?? '0.150',
            'FeesOnBiller' => true,
            'ProcessDate' => $overrides['process_date'] ?? '2026-09-08T10:43:09',
            'StmtDate' => '2026-09-09',
            'AccessChannel' => 'Mobile',
            'PaymentMethod' => 'ACTDEB',
            'PaymentType' => 'Postpaid',
            'Currency' => $overrides['currency'] ?? 'JOD',
            'ServiceTypeDetails' => [
                'ServiceType' => $overrides['service_type'] ?? 'SOOMBID',
            ],
            'PayerInfo' => [
                'IdType' => 'NAT',
                'Id' => '9999999999',
                'Nation' => 'JO',
                'Name' => 'Payer Name',
                'Email' => 'payer@example.test',
                'Address' => 'Amman',
            ],
        ], $overrides['transfer'] ?? []);

        return [
            'MFEP' => [
                'MsgHeader' => [
                    'TmStp' => '2026-09-08T10:43:09',
                    'GUID' => $guid ?? (string) Str::uuid(),
                    'TrsInf' => [
                        'SdrCode' => 1,
                        'RcvCode' => 1000,
                        'ReqTyp' => 'BLRPMTNTFRQ',
                    ],
                ],
                'MsgBody' => [
                    'Transactions' => [
                        'TrxInf' => $transfer,
                    ],
                ],
            ],
        ];
    }

    protected function postMfep(string $uri, array $body, null|array|false $credentials = null): TestResponse
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];

        if ($credentials !== false) {
            [$username, $password] = $credentials ?? ['ctm-user', 'ctm-secret'];
            $server['PHP_AUTH_USER'] = $username;
            $server['PHP_AUTH_PW'] = $password;
        }

        return $this->call(
            'POST',
            $uri,
            [],
            [],
            [],
            $server,
            json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }
}
