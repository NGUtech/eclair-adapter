<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/eclair-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Eclair\Message;

use Daikon\AsyncJob\Worker\WorkerInterface;
use Daikon\Boot\Service\Provisioner\MessageBusProvisioner;
use Daikon\Interop\Assertion;
use Daikon\Interop\RuntimeException;
use Daikon\MessageBus\MessageBusInterface;
use Daikon\RabbitMq3\Connector\RabbitMq3Connector;
use NGUtech\Bitcoin\Service\SatoshiCurrencies;
use NGUtech\Lightning\Message\LightningMessageInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

final class EclairMessageWorker implements WorkerInterface
{
    private const MESSAGE_PAYMENT_RECEIVED = 'eclair.message.payment_received';
    private const MESSAGE_PAYMENT_SENT = 'eclair.message.payment_sent';

    private RabbitMq3Connector $connector;

    private MessageBusInterface $messageBus;

    private LoggerInterface $logger;

    private array $settings;

    public function __construct(
        RabbitMq3Connector $connector,
        MessageBusInterface $messageBus,
        LoggerInterface $logger,
        array $settings = []
    ) {
        $this->connector = $connector;
        $this->messageBus = $messageBus;
        $this->logger = $logger;
        $this->settings = $settings;
    }

    public function run(array $parameters = []): void
    {
        $queue = $parameters['queue'];
        Assertion::notBlank($queue);

        $messageHandler = function (AMQPMessage $amqpMessage): void {
            $this->execute($amqpMessage);
        };

        /** @var AMQPChannel $channel */
        $channel = $this->connector->getConnection()->channel();
        $channel->basic_qos(0, 1, false);
        $channel->basic_consume($queue, '', true, false, false, false, $messageHandler);

        while (count($channel->callbacks)) {
            $channel->wait();
        }
    }

    private function execute(AMQPMessage $amqpMessage): void
    {
        try {
            $message = $this->createMessage($amqpMessage);
            if ($message instanceof LightningMessageInterface) {
                $this->messageBus->publish($message, MessageBusProvisioner::EVENTS_CHANNEL);
            }
            $amqpMessage->ack();
        } catch (RuntimeException $error) {
            $this->logger->error(
                "Error handling lightningd message '{$amqpMessage->getRoutingKey()}'.",
                ['exception' => $error->getTrace()]
            );
            $amqpMessage->nack();
        }
    }

    private function createMessage(AMQPMessage $amqpMessage): ?LightningMessageInterface
    {
        switch ($amqpMessage->getRoutingKey()) {
            case self::MESSAGE_PAYMENT_RECEIVED:
                $message = $this->createPaymentReceivedMessage($amqpMessage);
                break;
            case self::MESSAGE_PAYMENT_SENT:
                $message = $this->createPaymentSentMessage($amqpMessage);
                break;
            default:
                // ignore unknown routing keys
        }

        return $message ?? null;
    }

    private function createPaymentReceivedMessage(AMQPMessage $amqpMessage): EclairPaymentReceived
    {
        $payload = json_decode($amqpMessage->body, true);
        $amountPaid = array_reduce(
            $payload['parts'],
            fn(int $carry, array $part): int => $carry + $part['amount'],
            0
        );

        return EclairPaymentReceived::fromNative([
            'preimageHash' => $payload['paymentHash'],
            'amountPaid' => $amountPaid.SatoshiCurrencies::MSAT,
            'timestamp' => (string)$amqpMessage->get('timestamp')
        ]);
    }

    private function createPaymentSentMessage(AMQPMessage $amqpMessage): EclairPaymentSent
    {
        $payload = json_decode($amqpMessage->body, true);

        return EclairPaymentSent::fromNative([
            'preimage' => $payload['paymentPreimage'],
            'preimageHash' => $payload['paymentHash'],
            'amount' => $payload['recipientAmount'].SatoshiCurrencies::MSAT,
            'amountPaid' => $payload['recipientAmount'].SatoshiCurrencies::MSAT,
            'timestamp' => (string)$amqpMessage->get('timestamp')
        ]);
    }
}
