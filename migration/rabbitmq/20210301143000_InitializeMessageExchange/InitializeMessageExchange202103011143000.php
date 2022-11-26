<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/eclair-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Eclair\Migration\RabbitMq;

use Daikon\RabbitMq3\Migration\RabbitMq3Migration;
use PhpAmqpLib\Exchange\AMQPExchangeType;

final class InitializeMessageExchange20210301143000 extends RabbitMq3Migration
{
    public function getDescription(string $direction = self::MIGRATE_UP): string
    {
        return $direction === self::MIGRATE_UP
            ? 'Create a RabbitMQ message exchange for the Eclair-Adapter context.'
            : 'Delete the RabbitMQ message message exchange for the Eclair-Adapter context.';
    }

    public function isReversible(): bool
    {
        return true;
    }

    protected function up(): void
    {
        $this->createMigrationList('eclair.adapter.migration_list');
        $this->declareExchange(
            'eclair.adapter.exchange',
            'x-delayed-message',
            false,
            true,
            false,
            false,
            false,
            ['x-delayed-type' => AMQPExchangeType::TOPIC]
        );
    }

    protected function down(): void
    {
        $this->deleteExchange('eclair.adapter.exchange');
        $this->deleteExchange('eclair.adapter.migration_list');
    }
}
