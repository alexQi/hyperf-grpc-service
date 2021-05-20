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

namespace GrpcService;

use Psr\Http\Message\ResponseInterface;
use Google\Protobuf\Internal\Message;
use GrpcService\Parser;
use Hyperf\HttpMessage\Stream\SwooleStream;
use Hyperf\Rpc\Context;
use Hyperf\Rpc\Contract\DataFormatterInterface;

/**
 * Class DataFormatter
 *
 * @package GrpcService
 */
class DataFormatter implements DataFormatterInterface
{
    /**
     * @var Context
     */
    protected $context;

    public function __construct(Context $context)
    {
        $this->context = $context;
    }

    public function formatRequest($data)
    {
        [$path, $params, $id] = $data;
        return [
            'method' => $path,
            'params' => $params,
            'id'     => $id,
        ];
    }

    public function formatResponse($data)
    {
        return [
            'result' => $data
        ];
    }

    public function formatErrorResponse($data)
    {
        return $data;
    }
}
