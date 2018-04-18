<?php

namespace ScoutEngines\Elasticsearch\Indexers;

use Illuminate\Database\Eloquent\Collection;

interface IndexerInterface {

    /**
     * IndexerInterface constructor.
     *
     * @param String $index
     */
    public function __construct(String $index);

    /**
     * @param Collection $models
     *
     * @return array
     */
    public function update(Collection $models);

    /**
     * @param Collection $models
     *
     * @return array
     */
    public function delete(Collection $models);
}