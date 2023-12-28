<?php

namespace Timefactory\Apollo\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Timefactory\Apollo\Client as ApolloClient;

class StartApolloAgent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'apollo:start {--server=} {--appid=} {--namespaces=application} {--daemon=0}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Apollo Config Client';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $saveDir = storage_path('apollo');
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0777, true);
        }
        $server = $this->option('server');
        $appid = $this->option('appid');
        $namespaces = $this->option('namespaces');
        if (!$server || !$appid || !$namespaces) {
            $this->error("server, appid, namespaces must be specified");
            return 1;
        }
        $namespaces = explode(',', $namespaces);

        $apolloClient = new ApolloClient($server, $appid, $namespaces);

        //cache dir
        $apolloClient->saveDir = $saveDir;

        $this->info("start apollo");
        $restart = false; //reload
        $listen = boolval($this->option('daemon', 0));
        do {
            $error = $apolloClient->start($listen, function () use ($saveDir) {
                $list = glob($saveDir . DIRECTORY_SEPARATOR . 'apolloConfig.*');
                $apolloConfig = [];
                foreach ($list as $l) {
                    $config = require $l;
                    if (is_array($config) && isset($config['configurations'])) {
                        $apolloConfig = array_merge($apolloConfig, $config['configurations']);
                    }
                }
                if (!$apolloConfig) {
                    throw new \Exception('Load Apollo Config Failed, no config available');
                }
                $envConfig = '';
                foreach ($apolloConfig as $k => $v) {
                    $envConfig .= "{$k}={$v}\n";
                }
                file_put_contents(base_path('.env'), $envConfig);
            });
            if ($error) {
                $message = 'apollo error:' . $error;
                Log::error($message);
                $this->error($message);
            }
        } while ($error && $restart);
    }
}
