<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Grpc\Service\Listener;

use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Grpc\Service\DataFormatter;
use Grpc\Service\GrpcHttpTransporter;
use Grpc\Service\Packer\GrpcPacker;
use Grpc\Service\Packer\GrpcLengthPacker;
use Grpc\Service\PathGenerator;
use Hyperf\Rpc\ProtocolManager;

class RegisterProtocolListener implements ListenerInterface
{
    /**
     * @var ProtocolManager
     */
    private $protocolManager;

    public function __construct(ProtocolManager $protocolManager)
    {
        $this->protocolManager = $protocolManager;
    }

    public function listen(): array
    {
        return [
            BootApplication::class,
        ];
    }

    /**
     * All official rpc protocols should register in here,
     * and the others non-official protocols should register in their own component via listener.
     */
    public function process(object $event)
    {
        $this->protocolManager->register(
            'grpc',
            [
                'packer'         => GrpcPacker::class,
                'transporter'    => GrpcHttpTransporter::class,
                'path-generator' => PathGenerator::class,
                'data-formatter' => DataFormatter::class,
            ]
        );

        $this->protocolManager->register(
            'grpc-tcp-length-check',
            [
                'packer'         => GrpcPacker::class,
                'transporter'    => GrpcHttpTransporter::class,
                'path-generator' => PathGenerator::class,
                'data-formatter' => DataFormatter::class,
            ]
        );
    }
}
