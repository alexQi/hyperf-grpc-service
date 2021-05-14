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

namespace Grpc\Service\Packer;

use Hyperf\Contract\PackerInterface;
use Grpc\Service\Parser;

class GrpcPacker implements PackerInterface
{
    public function pack($data): string
    {
        return Parser::serializeMessage($data);
    }

    public function unpack(string $data): string
    {
        return Parser::deserializeMessage($data);
    }
}
