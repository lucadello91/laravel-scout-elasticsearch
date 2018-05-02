<?php

namespace ScoutEngines\Elasticsearch;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;
use ScoutEngines\Elasticsearch\Builders\SearchBuilder;
use ScoutEngines\Elasticsearch\Facades\ElasticsearchClient;
use ScoutEngines\Elasticsearch\Payloads\TypePayload;
use stdClass;

class ElasticsearchEngine extends Engine {

    static protected $updatedMappings = [];
    /**
     * Index where the models will be saved.
     *
     * @var string
     */
    protected $index;
    protected $indexer;
    protected $updateMapping;

    /**
     * Create a new engine instance.
     *
     * @param $config
     */
    public function __construct($config) {
        $this->index  = config('scout.prefix') . array_get($config, 'index', 'laravel');
        $indexerClass = '\\ScoutEngines\\Elasticsearch\\Indexers\\'.ucfirst(array_get($config, 'indexer', 'single')) . "Indexer";

        $this->indexer = new $indexerClass($this->index);

        try {
            $max_result_window = (int)array_get($config, 'max_result_window', 200000);
        } catch (\Exception $ex) {
            $max_result_window = 200000;
        }

        $this->updateMapping = array_get($config, 'update_mapping', TRUE);

        $indexParams['index'] = $this->index;

        $params = [
            'index' => $this->index,
            'body'  => [
                'settings' => [
                    'max_result_window' => $max_result_window,
                ],
            ],
        ];

        if (!ElasticsearchClient::indices()->exists($indexParams)) {
            ElasticsearchClient::indices()->create($params);
        }

        $response = ElasticsearchClient::indices()->getSettings($indexParams);

        if (!isset($response[$this->index]['settings']['index']['max_result_window']) || $response[$this->index]['settings']['index']['max_result_window'] < $max_result_window) {
            ElasticsearchClient::indices()->putSettings($params);
        }

    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection $models
     *
     * @return void
     */
    public function delete($models) {
        if ($models->isEmpty()) {
            return;
        }

        $this->indexer->delete($models);
    }

    /**
     * @param Builder $builder
     *
     * @return int
     * @throws \Exception
     */
    public function count(Builder $builder) {
        $count = 0;

        $this
            ->buildSearchQueryPayloadCollection($builder)
            ->each(function($payload) use (&$count) {
                $result = ElasticsearchClient::count($payload);

                $count = $result['count'];

                if ($count > 0) {
                    return FALSE;
                }
            });

        return $count;
    }

    /**
     * @param Builder $builder
     * @param array $options
     *
     * @return array
     * @throws \Exception
     */
    private function buildSearchQueryPayloadCollection(Builder $builder, array $options = []) {
        $payloadCollection = collect();

        if ($builder instanceof SearchBuilder) {
            $searchRules = $builder->rules ? : $builder->model->getSearchRules();

            foreach ($searchRules as $rule) {
                $payload = new TypePayload($builder->model, $this->index);

                if (is_callable($rule)) {
                    $payload->setIfNotEmpty('body.query.bool', call_user_func($rule, $builder));
                } else {
                    /** @var SearchRule $ruleEntity */
                    $ruleEntity = new $rule($builder);

                    if ($ruleEntity->isApplicable()) {
                        $payload->setIfNotEmpty('body.query.bool', $ruleEntity->buildQueryPayload());
                        $payload->setIfNotEmpty('body.highlight', $ruleEntity->buildHighlightPayload());
                    } else {
                        continue;
                    }
                }

                $payloadCollection->push($payload);
            }
        } else {
            $payload = (new TypePayload($builder->model, $this->index))
                ->setIfNotEmpty('body.query.bool.must.match_all', new stdClass());

            $payloadCollection->push($payload);
        }

        return $payloadCollection->map(function(TypePayload $payload) use ($builder, $options) {
            $payload
                ->setIfNotEmpty('body._source', $builder->select)
                ->setIfNotEmpty('body.collapse.field', $builder->collapse)
                ->setIfNotEmpty('body.sort', $builder->orders)
                ->setIfNotEmpty('body.explain', $options['explain'] ?? NULL)
                ->setIfNotEmpty('body.profile', $options['profile'] ?? NULL)
                ->setIfNotNull('body.from', $builder->offset)
                ->setIfNotNull('body.size', $builder->limit);

            foreach ($builder->wheres as $clause => $filters) {
                $clauseKey = 'body.query.bool.filter.bool.' . $clause;

                $clauseValue = array_merge(
                    $payload->get($clauseKey, []),
                    $filters
                );

                $payload->setIfNotEmpty($clauseKey, $clauseValue);
            }

            return $payload->get();
        });
    }

    /**
     * @param Builder $builder
     *
     * @return array
     * @throws \Exception
     */
    public function explain(Builder $builder) {
        return $this->performSearch($builder, [
            'explain' => TRUE,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder $builder
     * @param  array $options
     *
     * @return mixed
     * @throws \Exception
     */
    protected function performSearch(Builder $builder, array $options = []) {

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                ElasticsearchClient::getFacadeRoot(),
                $builder->query,
                $options
            );
        }

        $results = [];

        $this
            ->buildSearchQueryPayloadCollection($builder, $options)
            ->each(function($payload) use (&$results) {
                $results = ElasticsearchClient::search($payload);

                $results['_payload'] = $payload;

                if ($this->getTotalCount($results) > 0) {
                    return FALSE;
                }
            });

        return $results;
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed $results
     *
     * @return int
     */
    public function getTotalCount($results) {
        return $results['hits']['total'];
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed $results
     * @param  \Illuminate\Database\Eloquent\Model $model
     *
     * @return Collection
     */
    public function map($results, $model) {
        if ($this->getTotalCount($results) == 0) {
            return Collection::make();
        }

        $primaryKey = $model->getKeyName();

        $columns = array_get($results, '_payload.body._source');

        if (is_null($columns)) {
            $columns = ['*'];
        } else {
            $columns[] = $primaryKey;
        }

        $ids = $this->mapIds($results);

        $builder = $model->usesSoftDelete() ? $model->withTrashed() : $model->newQuery();

        $models = $builder
            ->whereIn($primaryKey, $ids)
            ->get($columns)
            ->keyBy($primaryKey);

        return Collection::make($results['hits']['hits'])
            ->map(function($hit) use ($models) {
                $id = $hit['_id'];

                if (isset($models[$id])) {
                    $model = $models[$id];

                    if (isset($hit['highlight'])) {
                        $model->highlight = new Highlight($hit['highlight']);
                    }

                    return $model;
                }
            })
            ->filter()
            ->values();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed $results
     *
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results) {
        return array_pluck($results['hits']['hits'], '_id');
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder $builder
     * @param  int $perPage
     * @param  int $page
     *
     * @return mixed
     * @throws \Exception
     */
    public function paginate(Builder $builder, $perPage, $page) {
        $builder
            ->from(($page - 1) * $perPage)
            ->take($perPage);

        return $this->performSearch($builder);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder $builder
     *
     * @return mixed
     * @throws \Exception
     */
    public function search(Builder $builder) {
        return $this->performSearch($builder);
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection $models
     *
     * @return void
     */
    public function update($models) {
        if ($models->isEmpty()) {
            return;
        }

        if ($this->updateMapping) {
            $self = $this;

            $models->each(function($model) use ($self) {
                $modelClass = get_class($model);

                if (in_array($modelClass, $self::$updatedMappings)) {
                    return TRUE;
                }

                $this->updateMapping(new $modelClass);

                $self::$updatedMappings[] = $modelClass;
            });
        }

        $this->indexer->update($models);
    }

    /**
     * @param Model $model
     *
     * @throws \Exception
     */
    private function updateMapping(Model $model) {

        $mapping = array_merge_recursive(
            $model->getMapping()
        );

        if (empty($mapping)) {
            return;
        }

        $payload = (new TypePayload($model, $this->index))
            ->set('body.' . $model->searchableAs(), $mapping)
            ->get();

        ElasticsearchClient::indices()
            ->putMapping($payload);
    }

    /**
     * @param Builder $builder
     *
     * @return array
     * @throws \Exception
     */
    public function profile(Builder $builder) {
        return $this->performSearch($builder, [
            'profile' => TRUE,
        ]);
    }

    /**
     * @param Model $model
     * @param array $query
     *
     * @return array
     * @throws \Exception
     */
    public function searchRaw(Model $model, $query) {
        $payload = (new TypePayload($model, $this->index))
            ->setIfNotEmpty('body', $query)
            ->get();

        return ElasticsearchClient::search($payload);
    }
}
