<?php declare(strict_types=1);

namespace Shopware\Storefront\Framework\Cache\ReverseProxy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Symfony\Component\HttpFoundation\Response;
use function sprintf;

class RedisReverseProxyGateway extends AbstractReverseProxyGateway
{
    /**
     * @var array{'method': string, 'headers': array<string, string>}
     */
    protected array $singlePurge;

    /**
     * @var array{'method': string, 'headers': array<string, string>, 'urls': array<string>}
     */
    protected array $entirePurge;

    /**
     * @var array<string>
     */
    private array $hosts;

    private Client $client;

    private int $concurrency;

    /**
     * @var \Redis|\RedisCluster
     */
    private $redis;

    private string $keyScript = <<<LUA
local list = {}

for _, key in ipairs(ARGV) do
    local looped = redis.call('lrange', key, 0, -1)

    for _, url in ipairs(looped) do
        list[url] = true
    end
end

local final = {}

for val, _ in pairs(list) do
    table.insert(final, val);
end

return final
LUA;

    /**
     * @param \Redis|\RedisCluster $redis
     * @param array{'method': string, 'headers': array<string, string>} $singlePurge
     * @param array{'method': string, 'headers': array<string, string>, 'urls': array<string>} $entirePurge
     */
    public function __construct(array $hosts, array $singlePurge, array $entirePurge, int $concurrency, $redis, Client $client)
    {
        $this->hosts = $hosts;
        $this->client = $client;
        $this->concurrency = $concurrency;
        $this->redis = $redis;
        $this->singlePurge = $singlePurge;
        $this->entirePurge = $entirePurge;
    }

    /**
     * @param array<string> $tags
     *
     * @deprecated tag:v6.5.0 - Parameter $response will be required
     */
    public function tag(array $tags, string $url/*, Response $response */): void
    {
        if (\func_num_args() < 3 || !func_get_arg(2) instanceof Response) {
            Feature::triggerDeprecationOrThrow(
                'v6.5.0.0',
                'Method `tag()` in "RedisReverseProxyGateway" expects third parameter of type `Response` in v6.5.0.0.'
            );
        }

        foreach ($tags as $tag) {
            $this->redis->lPush($tag, $url);
        }
    }

    /**
     * @param array<string> $tags
     */
    public function invalidate(array $tags): void
    {
        $urls = $this->redis->eval($this->keyScript, $tags);

        $this->ban($urls);
        $this->redis->del(...$tags);
    }

    public function ban(array $urls): void
    {
        $list = [];

        foreach ($urls as $url) {
            foreach ($this->hosts as $host) {
                $list[] = new Request($this->singlePurge['method'], $host . $url, $this->singlePurge['headers']);
            }
        }

        $pool = new Pool($this->client, $list, [
            'concurrency' => $this->concurrency,
            'rejected' => function (TransferException $reason): void {
                if ($reason instanceof ServerException) {
                    throw new \RuntimeException(sprintf('BAN request failed to %s failed with error: %s', $reason->getRequest()->getUri()->__toString(), $reason->getMessage()), 0, $reason);
                }

                throw $reason;
            },
        ]);

        $pool->promise()->wait();
    }

    public function banAll(): void
    {
        $list = [];

        foreach ($this->entirePurge['urls'] as $url) {
            foreach ($this->hosts as $host) {
                $list[] = new Request($this->entirePurge['method'], $host . $url, $this->entirePurge['headers']);
            }
        }

        $pool = new Pool($this->client, $list, [
            'concurrency' => $this->concurrency,
            'rejected' => function (\Throwable $reason): void {
                if ($reason instanceof ServerException) {
                    throw new \RuntimeException(sprintf('BAN request failed to %s failed with error: %s', $reason->getRequest()->getUri()->__toString(), $reason->getMessage()), 0, $reason);
                }

                throw $reason;
            },
        ]);

        $pool->promise()->wait();
    }

    public function getDecorated(): AbstractReverseProxyGateway
    {
        throw new DecorationPatternException(self::class);
    }
}
