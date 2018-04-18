<?php

namespace ScoutEngines\Elasticsearch;

use Elasticsearch\ClientBuilder;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

class ElasticsearchProvider extends ServiceProvider {

    /**
     * Bootstrap the application services.
     */
    public function boot() {
        app(EngineManager::class)
            ->extend('elasticsearch', function() {

                $index             = config('scout.elasticsearch.index', 'laravel');
                $max_result_window = config('scout.elasticsearch.max_result_window', 200000);

                $config = config('scout.elasticsearch', []);
                return new ElasticsearchEngine($config);
            });

    }

    public function register() {
        $this
            ->app
            ->singleton('scout_engines.elasticsearch.client', function() {
                return ClientBuilder::create()
                    ->setHosts(config('scout.elasticsearch.hosts'))
                    ->build();
            });
    }
}
