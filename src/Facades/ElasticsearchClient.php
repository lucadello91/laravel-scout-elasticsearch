<?php

namespace ScoutEngines\Elasticsearch\Facades;

use Illuminate\Support\Facades\Facade;

class ElasticsearchClient extends Facade
{
    /**
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'scout_engines.elasticsearch.client';
    }
}