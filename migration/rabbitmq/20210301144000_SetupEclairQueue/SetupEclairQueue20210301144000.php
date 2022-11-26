<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/eclair-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Eclair\Migration\RabbitMq;

use Daikon\RabbitMq3\Migration\RabbitMq3Migration;

final class SetupEclairQueue20210301144000 extends RabbitMq3Migration
{
    public function getDescription(string $direction = self::MIGRATE_UP): string
    {
        return $direction === self::MIGRATE_UP
            ? 'Create RabbitMQ queue for Eclair messages.'
            : 'Delete RabbitMQ queue for Eclair messages.';
    }

    public function isReversible(): bool
    {
        return true;
    }

    protected function up(): void
    {
        $this->declareQueue('eclair.adapter.messages', false, true, false, false);
        $this->bindQueue('eclair.adapter.messages', 'eclair.adapter.exchange', 'eclair.message.#');
    }

    protected function down(): void
    {
        $this->deleteQueue('eclair.adapter.messages');
    }
}
