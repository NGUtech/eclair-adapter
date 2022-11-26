<?php declare(strict_types=1);
/**
 * This file is part of the ngutech/eclair-adapter project.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace NGUtech\Eclair\Connector;

use Daikon\Dbal\Connector\ConnectorInterface;
use Daikon\Dbal\Connector\ProvidesConnector;
use GuzzleHttp\Client;

final class EclairRpcConnector implements ConnectorInterface
{
    use ProvidesConnector;

    protected function connect(): Client
    {
        $clientOptions = [
            'base_uri' => sprintf(
                '%s://%s:%s',
                $this->settings['scheme'],
                $this->settings['host'],
                $this->settings['port']
            ),
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded'
            ]
        ];

        if (isset($this->settings['password']) && !empty($this->settings['password'])) {
            $clientOptions['auth'] = [
                '',
                $this->settings['password'],
                $this->settings['authentication'] ?? 'basic'
            ];
        }

        return new Client($clientOptions);
    }
}
