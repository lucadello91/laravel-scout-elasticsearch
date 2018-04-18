<?php

namespace ScoutEngines\Elasticsearch\Indexers;

use Illuminate\Database\Eloquent\Collection;
use ScoutEngines\Elasticsearch\Facades\ElasticsearchClient;
use ScoutEngines\Elasticsearch\Payloads\RawPayload;
use ScoutEngines\Elasticsearch\Payloads\TypePayload;

class BulkIndexer implements IndexerInterface
{

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
     * @throws \Exception
     */
    public function update(Collection $models)
    {
        $model = $models->first();

        $bulkPayload = new TypePayload($model, $this->index);

        if ($documentRefresh = config('scout.elasticsearch.document_refresh')) {
            $bulkPayload->set('refresh', $documentRefresh);
        }

        $models->each(function ($model) use ($bulkPayload) {
            if ($model->usesSoftDelete() && config('scout.soft_delete', false)) {
                $model->pushSoftDeleteMetadata();
            }

            $modelData = array_merge(
                $model->toSearchableArray(),
                $model->scoutMetadata()
            );

            if (empty($modelData)) {
                return true;
            }

            $actionPayload = (new RawPayload())
                ->set('index._id', $model->getKey());

            $bulkPayload
                ->add('body', $actionPayload->get())
                ->add('body', $modelData);
        });

        ElasticsearchClient::bulk($bulkPayload->get());
    }

    /**
     * @inheritdoc
     * @throws \Exception
     */
    public function delete(Collection $models)
    {
        $model = $models->first();

        $bulkPayload = new TypePayload($model, $this->index);

        $models->each(function ($model) use ($bulkPayload) {
            $actionPayload = (new RawPayload())
                ->set('delete._id', $model->getKey());

            $bulkPayload->add('body', $actionPayload->get());
        });

        ElasticsearchClient::bulk($bulkPayload->get());
    }
    
}