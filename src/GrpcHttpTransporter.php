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

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Hyperf\Guzzle\ClientFactory;
use Hyperf\LoadBalancer\LoadBalancerInterface;
use Hyperf\LoadBalancer\Node;
use Hyperf\Rpc\Contract\TransporterInterface;

class GrpcHttpTransporter implements TransporterInterface
{
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
     * @var \Hyperf\Guzzle\ClientFactory
     */
    private $clientFactory;

    /**
     * @var array
     */
    private $clientOptions;

    public function __construct(ClientFactory $clientFactory, array $config = [])
    {
        $this->clientFactory = $clientFactory;
        if (!isset($config['recv_timeout'])) {
            $config['recv_timeout'] = $this->recvTimeout;
        }
        if (!isset($config['connect_timeout'])) {
            $config['connect_timeout'] = $this->connectTimeout;
        }
        $this->clientOptions = $config;
    }

    public function send(string $data)
    {
        return '';
        $node     = $this->getNode();
        $uri      = $node->host . ':' . $node->port;
        $schema   = value(
            function () use ($node) {
                $schema = 'http';
                if (property_exists($node, 'schema')) {
                    $schema = $node->schema;
                }
                if (!in_array($schema, ['http', 'https'])) {
                    $schema = 'http';
                }
                $schema .= '://';
                return $schema;
            }
        );
        $url      = $schema . $uri;
        $response = $this->getClient()->post(
            $url,
            [
                RequestOptions::HEADERS     => [
                    'Content-Type' => 'application/json',
                ],
                RequestOptions::HTTP_ERRORS => false,
                RequestOptions::BODY        => $data,
            ]
        );
        if ($response->getStatusCode() === 200) {
            return (string)$response->getBody();
        }
        $this->loadBalancer->removeNode($node);

        return '';
    }

    public function recv()
    {
        throw new \RuntimeException(__CLASS__ . ' does not support recv method.');
    }

    public function getClient(): Client
    {
        $clientOptions = $this->clientOptions;
        // Swoole HTTP Client cannot set recv_timeout and connect_timeout options, use timeout.
        $clientOptions['timeout'] = $clientOptions['recv_timeout'] + $clientOptions['connect_timeout'];
        unset($clientOptions['recv_timeout'], $clientOptions['connect_timeout']);

        return $this->clientFactory->create($clientOptions);
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

    public function getClientOptions(): array
    {
        return $this->clientOptions;
    }

    private function getEof()
    {
        return "\r\n";
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
