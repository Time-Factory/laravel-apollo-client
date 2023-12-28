<?php

namespace Timefactory\Apollo;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class Client
{
    /**
     * @var string apollo服务端地址
     */
    protected string $configServer;
    /**
     * @var string apollo配置项目的appid
     */
    protected string $appId;
    /**
     * @var string apollo配置项目的namespace
     */
    protected string $cluster = 'default';
    /**
     * @var string 绑定IP做灰度发布用
     */
    protected string $clientIp = '127.0.0.1';
    /**
     * @var array 接受到的namespace通知列表
     */
    protected array $notifications = [];
    /**
     * @var int 获取某个namespace配置的请求超时时间
     */
    protected int $pullTimeout = 10;
    /**
     * @var int 每次请求获取apollo配置变更时的超时时间
     */
    protected int $intervalTimeout = 65;
    /**
     * @var string 配置保存目录
     */
    public string $saveDir;

    const DEFAULT_SAVE_DIR = './apollo_config/';
    /**
     * @var HttpClient http客户端
     */
    private HttpClient $httpClient;

    /**
     * ApolloClient constructor.
     * @param string $configServer apollo服务端地址
     * @param string $appId apollo配置项目的appid
     * @param array $namespaces apollo配置项目的namespace
     */
    public function __construct(string $configServer, string $appId, array $namespaces, $saveDir = '')
    {
        $this->configServer = $configServer;
        $this->appId = $appId;
        foreach ($namespaces as $namespace) {
            $this->notifications[$namespace] = ['namespaceName' => $namespace, 'notificationId' => -1];
        }
        $this->saveDir = $saveDir ?? self::DEFAULT_SAVE_DIR;
        $this->httpClient = new HttpClient(['base_uri' => rtrim($this->configServer, DIRECTORY_SEPARATOR)]);

        return $this;
    }

    /**
     *  设置apollo服务端地址
     *
     * @param $cluster
     * @return $this
     */
    public function setCluster($cluster): Client
    {
        $this->cluster = $cluster;

        return $this;
    }

    /**
     * 绑定IP做灰度发布用
     *
     * @param $ip
     * @return $this
     */
    public function setClientIp($ip): Client
    {
        $this->clientIp = $ip;

        return $this;
    }

    /**
     * 设置拉取配置超时时间
     *
     * @param $pullTimeout
     * @return $this
     */
    public function setPullTimeout($pullTimeout): Client
    {
        $pullTimeout = intval($pullTimeout);
        if ($pullTimeout < 1 || $pullTimeout > 300) {
            return $this;
        }
        $this->pullTimeout = $pullTimeout;

        return $this;
    }

    /**
     * 设置监听配置变更时的超时时间
     *
     * @param $intervalTimeout
     * @return $this
     */
    public function setIntervalTimeout($intervalTimeout): Client
    {
        $intervalTimeout = intval($intervalTimeout);
        if ($intervalTimeout < 1 || $intervalTimeout > 300) {
            return $this;
        }
        $this->intervalTimeout = $intervalTimeout;
        return $this;
    }

    /**
     * 获取配置的releaseKey
     *
     * @param $configFile
     * @return mixed|string
     */
    private function getReleaseKey($configFile)
    {
        $releaseKey = '';
        if (file_exists($configFile)) {
            $lastConfig = require $configFile;
            is_array($lastConfig) && isset($lastConfig['releaseKey']) && $releaseKey = $lastConfig['releaseKey'];
        }
        return $releaseKey;
    }

    /**
     * 获取单个namespace的配置文件路径
     *
     * @param $namespaceName
     * @return string
     */
    public function getConfigFile($namespaceName): string
    {
        return $this->saveDir . DIRECTORY_SEPARATOR . 'apolloConfig.' . $namespaceName . '.php';
    }

    /**
     * 获取单个namespace的配置-无缓存的方式
     *
     * @param $namespaceName
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function pullConfig($namespaceName)
    {
        $path = '/configs/' . $this->appId . '/' . $this->cluster . '/' . $namespaceName;

        $response = $this->httpClient->get($path, [
            'headers' => [
                'Content-Type' => 'application/json;charset=UTF-8',
            ],
            'timeout' => $this->pullTimeout,
            'query' => [
                'ip' => $this->clientIp,
                'releaseKey' => $this->getReleaseKey($this->getConfigFile($namespaceName)),
            ],
        ]);
        if ($response->getStatusCode() == 200) {
            $result = json_decode($response->getBody()->getContents(), true);
            $content = '<?php return ' . var_export($result, true) . ';';
            file_put_contents($this->getConfigFile($namespaceName), $content);
        } elseif ($response->getStatusCode() != 304) {
            echo $response->getBody()->getContents();
            return false;
        }
    }

    /**
     * 获取多个namespace的配置-无缓存的方式
     *
     * @param array $namespaceNames
     * @return array
     */
    public function pullConfigBatch(array $namespaceNames)
    {
        // 获取结果
        $responseList = [];
        if (empty($namespaceNames)) {
            return $responseList;
        }

        $uri = rtrim($this->configServer, '/') . '/configs/' . $this->appId . '/' . $this->cluster . '/';
        $requests = function ($total) use ($namespaceNames, $uri) {
            foreach ($namespaceNames as $namespaceName) {
                yield new Request('GET', $uri . $namespaceName, [
                    'query' => [
                        'ip' => $this->clientIp,
                        'releaseKey' => $this->getReleaseKey($this->getConfigFile($namespaceName)),
                    ]
                ]);
            }
        };

        $pool = new Pool($this->httpClient, $requests(count($namespaceNames)), [
            'concurrency' => 5,
            'fulfilled' => function (Response $response, $index) use ($namespaceNames, &$responseList) {
                $result = json_decode($response->getBody()->getContents(), true);
                $content = '<?php return ' . var_export($result, true) . ';';
                if ($response->getStatusCode() == 200) {
                    $responseList[$result['namespaceName']] = true;
                    file_put_contents($this->getConfigFile($result['namespaceName']), $content);
                } elseif ($response->getStatusCode() != 304) {
                    echo 'pull config of namespace[' . $namespaceNames[$index] . '] error:' . ($result ?: '') . "\n";
                    $responseList[$namespaceNames[$index]] = false;
                }
            },
            'rejected' => function (RequestException $reason, $index) use ($namespaceNames, &$responseList) {
                $responseList[$namespaceNames[$index]] = false;
            },
        ]);
        $promise = $pool->promise();
        $promise->wait();

        return $responseList;
    }

    protected function listenChange($listen = true, $callback = null)
    {
        $path = '/notifications/v2?';
        $params = [];
        $params['appId'] = $this->appId;
        $params['cluster'] = $this->cluster;
        do {
            $params['notifications'] = json_encode(array_values($this->notifications));

            $response = $this->httpClient->get($path, [
                'headers' => [
                    'Content-Type' => 'application/json;charset=UTF-8',
                ],
                'curl' => [
                    CURLOPT_TIMEOUT => $this->intervalTimeout,
                    CURLOPT_HEADER => false,
                    CURLOPT_RETURNTRANSFER => 1,
                ],
                'query' => $params,
            ]);
            $httpCode = $response->getStatusCode();
            $bodyContent = $response->getBody()->getContents();

            if ($httpCode == 200) {
                $res = json_decode($bodyContent, true);
                $changeList = [];
                foreach ($res as $r) {
                    if ($r['notificationId'] != $this->notifications[$r['namespaceName']]['notificationId']) {
                        $changeList[$r['namespaceName']] = $r['notificationId'];
                    }
                }
                $responseList = $this->pullConfigBatch(array_keys($changeList));
                foreach ($responseList as $namespaceName => $result) {
                    $result && ($this->notifications[$namespaceName]['notificationId'] = $changeList[$namespaceName]);
                }
                //如果定义了配置变更的回调，比如重新整合配置，则执行回调
                ($callback instanceof \Closure) && call_user_func($callback);
            } elseif ($httpCode != 304) {
                throw new \Exception($bodyContent ?: 'apollo error');
            }
        } while ($listen);
    }

    /**
     * 监听到配置变更时的回调处理
     *
     * @param $callback
     * @return mixed
     */
    public function start($listen = true, $callback = null)
    {
        try {
            $this->listenChange($listen, $callback);
        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }
}
