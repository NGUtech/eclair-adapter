<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/eclair-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Eclair\Service;

use Daikon\Interop\Assertion;
use Daikon\Money\Exception\PaymentServiceFailed;
use Daikon\Money\Exception\PaymentServiceUnavailable;
use Daikon\Money\Service\MoneyServiceInterface;
use Daikon\Money\ValueObject\MoneyInterface;
use Daikon\ValueObject\Timestamp;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use NGUtech\Bitcoin\Service\SatoshiCurrencies;
use NGUtech\Bitcoin\ValueObject\Bitcoin;
use NGUtech\Bitcoin\ValueObject\Hash;
use NGUtech\Eclair\Connector\EclairRpcConnector;
use NGUtech\Lightning\Entity\LightningInvoice;
use NGUtech\Lightning\Entity\LightningPayment;
use NGUtech\Lightning\Service\LightningServiceInterface;
use NGUtech\Lightning\ValueObject\InvoiceState;
use NGUtech\Lightning\ValueObject\PaymentState;
use NGUtech\Lightning\ValueObject\Request;
use Psr\Log\LoggerInterface;

class EclairService implements LightningServiceInterface
{
    public const INVOICE_STATUS_PENDING = 'pending';
    public const INVOICE_STATUS_RECEIVED = 'received';
    public const INVOICE_STATUS_EXPIRED = 'expired';
    public const PAYMENT_STATUS_PENDING = 'pending';
    public const PAYMENT_STATUS_SENT = 'sent';
    public const PAYMENT_STATUS_FAILED = 'failed';

    protected LoggerInterface $logger;

    protected EclairRpcConnector $connector;

    protected MoneyServiceInterface $moneyService;

    protected array $settings;

    public function __construct(
        LoggerInterface $logger,
        EclairRpcConnector $connector,
        MoneyServiceInterface $moneyService,
        array $settings = []
    ) {
        $this->logger = $logger;
        $this->connector = $connector;
        $this->moneyService = $moneyService;
        $this->settings = $settings;
    }

    public function request(LightningInvoice $invoice): LightningInvoice
    {
        Assertion::true($this->canRequest($invoice->getAmount()), 'Eclair service cannot request given amount.');

        $expiry = $invoice->getExpiry()->toNative();
        Assertion::between($expiry, 60, 31536000, 'Invoice expiry is not acceptable.');

        $result = $this->call('createinvoice', [
            'description' => (string)$invoice->getDescription(),
            'paymentPreimage' => (string)$invoice->getPreimage(),
            'amountMsat' => $this->convert((string)$invoice->getAmount())->getAmount(),
            'expireIn' => $expiry
        ]);

        return $invoice->withValues([
            'preimageHash' => $result['paymentHash'],
            'request' => $result['serialized'],
            'expiry' => $result['expiry'],
            'blockHeight' => $this->getInfo()['blockHeight'],
            'createdAt' => Timestamp::now()
        ]);
    }

    public function send(LightningPayment $payment): LightningPayment
    {
        Assertion::true($this->canSend($payment->getAmount()), 'Eclair service cannot send given amount.');

        $result = $this->call('payinvoice', [
            'invoice' => (string)$payment->getRequest(),
            'amountMsat' => $payment->getAmount()->getAmount(),
            'maxAttempts' => $this->settings['send']['max_attempts'] ?? 3,
            'maxFeePct' => $payment->getFeeLimit()->format(6),
            'feeThresholdSat' => $this->convert($this->settings['send']['fee_threshold'] ?? '5SAT')->getAmount()
        ]);

        do {
            sleep(1);
            $parts = $this->call('getsentinfo', ['id' => $result[0]]);
            $pending = array_reduce(
                $parts,
                function (bool $carry, array $part): bool {
                    return $carry || $part['status']['type'] === self::PAYMENT_STATUS_PENDING;
                },
                false
            );
            //@todo add a timeout just in case
        } while($pending);

        //@todo handle failure analysis better. Possibly some parts may fail but the payment succeeds.
        if ($parts[0]['status']['type'] === self::PAYMENT_STATUS_FAILED) {
            throw new PaymentServiceUnavailable($parts[0]['status']['failures'][0]['failureMessage']);
        }

        //calculate total fees paid from all sent parts
        $feeSettled = array_reduce(
            $parts,
            function (Bitcoin $feesPaid, array $part): Bitcoin {
                return $part['status']['type'] === self::PAYMENT_STATUS_SENT
                    ? $feesPaid->add($this->convert($part['status']['feesPaid'].SatoshiCurrencies::MSAT))
                    : $feesPaid;
            },
            Bitcoin::zero()
        );

        return $payment->withValues([
            'preimage' => $parts[0]['status']['paymentPreimage'],
            'preimageHash' => $parts[0]['paymentHash'],
            'feeSettled' => $feeSettled
        ]);
    }

    public function decode(Request $request): LightningInvoice
    {
        $invoice = $this->call('parseinvoice', ['invoice' => (string)$request]);

        return LightningInvoice::fromNative([
            'preimageHash' => $invoice['paymentHash'],
            'request' => $invoice['serialized'],
            'destination' => $invoice['nodeId'],
            'amount' => ($invoice['amount'] ?? 0).SatoshiCurrencies::MSAT,
            'description' => $invoice['description'],
            'expiry' => $invoice['expiry'] ?? 3600,
            'cltvExpiry' => $invoice['minFinalCltvExpiry'],
            'createdAt' => $invoice['timestamp']
        ]);
    }

    public function estimateFee(LightningPayment $payment): Bitcoin
    {
        $route = $this->call('findroute', [
            'invoice' => (string)$payment->getRequest(),
            'amountMsat' => $payment->getAmount()->getAmount()
        ]);

        if (count($route) < 2) {
            throw new PaymentServiceUnavailable('No route found.');
        }

        return count($route) === 2
            ? Bitcoin::zero() // zero fee for direct connection
            : $payment->getAmount()->percentage($payment->getFeeLimit()->toNative(), Bitcoin::ROUND_UP);
    }

    public function getInvoice(Hash $preimageHash): ?LightningInvoice
    {
        $invoice = $this->call('getreceivedinfo', ['paymentHash' => (string)$preimageHash]);

        return LightningInvoice::fromNative([
            'preimage' => $invoice['paymentPreimage'],
            'preimageHash' => $invoice['paymentRequest']['paymentHash'],
            'request' => $invoice['paymentRequest']['serialized'],
            'amount' => $invoice['paymentRequest']['amount'].SatoshiCurrencies::MSAT,
            'amountPaid' => ($invoice['status']['amount'] ?? 0).SatoshiCurrencies::MSAT,
            'description' => $invoice['paymentRequest']['description'],
            'state' => (string)$this->mapInvoiceState($invoice['status']['type']),
            'createdAt' => $invoice['paymentRequest']['timestamp']
        ]);
    }

    public function getPayment(Hash $preimageHash): ?LightningPayment
    {
        $parts = $this->call('getsentinfo', ['paymentHash' => (string)$preimageHash]);
        if (empty($parts)) {
            return null;
        }

        //@todo impl Eclair invoice/payment VOs
        $amountPaid = array_reduce(
            $parts,
            function (Bitcoin $amountPaid, array $part): Bitcoin {
                return $part['status']['type'] === self::PAYMENT_STATUS_SENT
                    ? $amountPaid->add($this->convert($part['amount'].SatoshiCurrencies::MSAT))
                    : $amountPaid;
            },
            Bitcoin::zero()
        );

        $feeSettled = array_reduce(
            $parts,
            function (Bitcoin $feesPaid, array $part): Bitcoin {
                return $part['status']['type'] === self::PAYMENT_STATUS_SENT
                    ? $feesPaid->add($this->convert($part['status']['feesPaid'].SatoshiCurrencies::MSAT))
                    : $feesPaid;
            },
            Bitcoin::zero()
        );

        return LightningPayment::fromNative([
            'preimage' => $parts[0]['status']['paymentPreimage'],
            'preimageHash' => $parts[0]['paymentHash'],
            'request' => $parts[0]['paymentRequest']['serialized'],
            'destination' => $parts[0]['paymentRequest']['nodeId'],
            'amount' => $parts[0]['recipientAmount'],
            'amountPaid' => (string)$amountPaid,
            'feeSettled' => (string)$feeSettled,
            'label' => $parts[0]['id'],
            //@todo determine state from all parts
            'state' => (string)$this->mapPaymentState($parts[0]['status']['type']),
            'createdAt' => $parts[0]['createdAt']
        ]);
    }

    public function getInfo(): array
    {
        return $this->call('getinfo');
    }

    public function canRequest(MoneyInterface $amount): bool
    {
        return ($this->settings['request']['enabled'] ?? true)
            && $amount->isGreaterThanOrEqual(
                $this->convert(($this->settings['request']['minimum'] ?? LightningInvoice::AMOUNT_MIN))
            ) && $amount->isLessThanOrEqual(
                $this->convert(($this->settings['request']['maximum'] ?? LightningInvoice::AMOUNT_MAX))
            );
    }

    public function canSend(MoneyInterface $amount): bool
    {
        return ($this->settings['send']['enabled'] ?? true)
            && $amount->isGreaterThanOrEqual(
                $this->convert(($this->settings['send']['minimum'] ?? LightningInvoice::AMOUNT_MIN))
            ) && $amount->isLessThanOrEqual(
                $this->convert(($this->settings['send']['maximum'] ?? LightningInvoice::AMOUNT_MAX))
            );
    }

    protected function call(string $endpoint, array $params = []): array
    {
        /** @var Client $client */
        $client = $this->connector->getConnection();

        try {
            $response = $client->post($endpoint, ['form_params' => $params]);
        } catch (BadResponseException $error) {
            $this->logger->error($error->getMessage());
            throw new PaymentServiceFailed($error->getMessage());
        }

        return (array)json_decode($response->getBody()->getContents(), true);
    }

    protected function convert(string $amount, string $currency = SatoshiCurrencies::MSAT): Bitcoin
    {
        return $this->moneyService->convert($this->moneyService->parse($amount), $currency);
    }

    protected function mapInvoiceState(string $state): InvoiceState
    {
        $invoiceState = null;
        switch ($state) {
            case self::INVOICE_STATUS_PENDING:
                $invoiceState = InvoiceState::PENDING;
                break;
            case self::INVOICE_STATUS_RECEIVED:
                $invoiceState = InvoiceState::SETTLED;
                break;
            case self::INVOICE_STATUS_EXPIRED:
                $invoiceState = InvoiceState::CANCELLED;
                break;
            default:
                throw new PaymentServiceFailed("Unknown invoice state '$state'.");
        }
        return InvoiceState::fromNative($invoiceState);
    }

    protected function mapPaymentState(string $state): PaymentState
    {
        $paymentState = null;
        switch ($state) {
            case self::PAYMENT_STATUS_PENDING:
                $paymentState = PaymentState::PENDING;
                break;
            case self::PAYMENT_STATUS_SENT:
                $paymentState = PaymentState::COMPLETED;
                break;
            case self::PAYMENT_STATUS_FAILED:
                $paymentState = PaymentState::FAILED;
                break;
            default:
                throw new PaymentServiceFailed("Unknown payment state '$state'.");
        }
        return PaymentState::fromNative($paymentState);
    }
}
