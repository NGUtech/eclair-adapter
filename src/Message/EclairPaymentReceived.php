<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/eclair-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Eclair\Message;

use NGUtech\Lightning\Message\LightningInvoiceMessageInterface;
use NGUtech\Lightning\Message\LightningInvoiceMessageTrait;

final class EclairPaymentReceived implements LightningInvoiceMessageInterface
{
    use LightningInvoiceMessageTrait;
}
