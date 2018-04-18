<?php

namespace ScoutEngines\Elasticsearch\Indexers;

use Illuminate\Database\Eloquent\Collection;
use ScoutEngines\Elasticsearch\Facades\ElasticsearchClient;
use ScoutEngines\Elasticsearch\Payloads\DocumentPayload;

class SingleIndexer implements IndexerInterface {

    private $index;

    /**
     * IndexerInterface constructor.
     *
     * @param String $index
     */
    public function __construct(String $index) {
        $this->index = $index;
    }

    /**
     * @inheritdoc
     */
    public function delete(Collection $models) {
        $models->each(function($model) {
            $payload = (new DocumentPayload($model, $this->index))
                ->get();

            ElasticsearchClient::delete($payload);
        });
    }

    /**
     * @inheritdoc
     */
    public function update(Collection $models) {

        $models->each(function($model) {
            if ($model->usesSoftDelete() && config('scout.soft_delete', FALSE)) {
                $model->pushSoftDeleteMetadata();
            }

            $modelData = array_merge(
                $model->toSearchableArray(),
                $model->scoutMetadata()
            );

            if (empty($modelData)) {
                return TRUE;
            }

            $payload = (new DocumentPayload($model, $this->index))
                ->set('body', $modelData);

            if ($documentRefresh = config('scout.elasticsearch.document_refresh')) {
                $payload->set('refresh', $documentRefresh);
            }

            ElasticsearchClient::index($payload->get());
        });
    }

}