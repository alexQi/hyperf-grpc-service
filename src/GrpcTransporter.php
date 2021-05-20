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

use Hyperf\GrpcClient\BaseClient;
use Hyperf\GrpcClient\Request;
use Hyperf\LoadBalancer\LoadBalancerInterface;
use Hyperf\LoadBalancer\Node;
use Hyperf\Rpc\Contract\TransporterInterface;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\ChannelPool;
use Hyperf\Utils\Context;
use RuntimeException;

class GrpcTransporter implements TransporterInterface
{
    use RecvTrait;

    /**
     * @var null|LoadBalancerInterface
     */
    private $loadBalancer;

    /**
     * If $loadBalancer is null, will select a node in $nodes to request,
     * otherwise, use the nodes in $loadBalancer.
     *
     * @var Node[]
     */
    private $nodes = [];

    /**
     * @var float
     */
    private $connectTimeout = 5;

    /**
     * @var float
     */
    private $recvTimeout = 5;

    /**
     * @var array
     */
    private $config = [];

    public function __construct(array $config = [])
    {
        $this->config = array_replace_recursive($this->config, $config);

        $this->recvTimeout    = $this->config['recv_timeout'] ?? 5.0;
        $this->connectTimeout = $this->config['connect_timeout'] ?? 5.0;
    }

    public function send($data)
    {
        throw new RuntimeException(__CLASS__ . ' does not support send method.');
    }

    public function sendGrpc($data, $method, $deserialize)
    {
        $headers = [
            'Service' => 'Hyperf service'
        ];
        $request = new Request($method, $data, $headers);

        $streamId = $this->getClient()->send($request);
        if ($streamId <= 0) {
            throw new GrpcClientException('Failed to send the request to server', StatusCode::INTERNAL);
        }
        return Parser::parseResponse($this->getClient()->recv($streamId), $deserialize);
    }

    public function recv()
    {
        throw new RuntimeException(__CLASS__ . ' does not support rev method.');
    }

    public function getClient(): BaseClient
    {
        $class = spl_object_hash($this) . '.Connection';
        if (Context::has($class)) {
            return Context::get($class);
        }

        return Context::set(
            $class,
            retry(
                2,
                function () {
                    $node = $this->getNode();
                    return new BaseClient($node->host . ":" . $node->port, $this->config);
                }
            )
        );
    }

    public function getLoadBalancer(): ?LoadBalancerInterface
    {
        return $this->loadBalancer;
    }

    public function setLoadBalancer(LoadBalancerInterface $loadBalancer): TransporterInterface
    {
        $this->loadBalancer = $loadBalancer;
        return $this;
    }

    /**
     * @param \Hyperf\LoadBalancer\Node[] $nodes
     */
    public function setNodes(array $nodes): self
    {
        $this->nodes = $nodes;
        return $this;
    }

    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * If the load balancer is exists, then the node will select by the load balancer,
     * otherwise will get a random node.
     */
    private function getNode(): Node
    {
        if ($this->loadBalancer instanceof LoadBalancerInterface) {
            return $this->loadBalancer->select();
        }
        return $this->nodes[array_rand($this->nodes)];
    }
}
