<?php

namespace ScoutEngines\Elasticsearch;

use Elasticsearch\Client as Elastic;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine;

class ElasticsearchEngine extends Engine
{

    /**
     * Index where the models will be saved.
     *
     * @var string
     */
    protected $index;

    /**
     * Elastic where the instance of Elastic|\Elasticsearch\Client is stored.
     *
     * @var object
     */
    protected $elastic;

    /**
     * Create a new engine instance.
     *
     * @param  \Elasticsearch\Client $elastic
     *
     * @param $index
     */
    public function __construct(Elastic $elastic, $index, int $max_result_window)
    {
        $this->elastic = $elastic;
        $this->index   = $index;

        $indexParams['index'] = $this->index;

        $params = [
            'index' => $this->index,
            'body'  => [
                'settings' => [
                    'max_result_window' => $max_result_window,
                ],
            ],
        ];

        if (!$this->elastic->indices()->exists($indexParams)) {
            $this->elastic->indices()->create($params);
        }

        $response = $this->elastic->indices()->getSettings($indexParams);

        if (!isset($response[$this->index]['settings']['index']['max_result_window']) || $response[$this->index]['settings']['index']['max_result_window'] < $max_result_window) {
            $this->elastic->indices()->putSettings($params);
        }

    }

    /**
     * Remove the given model from the index.
     *
     * @param  Collection $models
     *
     * @return void
     */
    public function delete($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $params['body'] = [];
        $params['type'] = $models->first()->searchableAs();

        $models->each(function($model) use (&$params) {
            $params['body'][] = [
                'delete' => [
                    '_id'    => $model->getKey(),
                    '_index' => $this->index,
                    //                    '_type' => $model->searchableAs(),
                ],
            ];
        });

        $this->elastic->bulk($params);
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed $results
     *
     * @return int
     */
    public function getTotalCount($results)
    {
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
    public function map($results, $model)
    {
        if ($results['hits']['total'] === 0) {
            return Collection::make();
        }

        $keys = collect($results['hits']['hits'])
            ->pluck('_id')->values()->all();

        $models = $model->whereIn(
            $model->getKeyName(), $keys
        )->get()->keyBy($model->getKeyName());

        return collect($results['hits']['hits'])->map(function($hit) use ($model, $models) {
            return isset($models[$hit['_id']]) ? $models[$hit['_id']] : NULL;
        })->filter()->values();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed $results
     *
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits']['hits'])->pluck('_id')->values();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder $builder
     * @param  int $perPage
     * @param  int $page
     *
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        $result = $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'from'           => (($page * $perPage) - $perPage),
            'size'           => $perPage,
        ]);

        $result['nbPages'] = $result['hits']['total'] / $perPage;

        return $result;
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder $builder
     * @param  array $options
     *
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $params = [
            'index' => $this->index,
            'type'  => $builder->index ? : $builder->model->searchableAs(),
            'body'  => [
                'query' => [
                    'bool' => [
                        'must' => [['query_string' => ['query' => "*{$builder->query}*"]]],
                    ],
                ],
            ],
        ];

        if ($sort = $this->sort($builder)) {
            $params['body']['sort'] = $sort;
        }

        if (isset($options['from'])) {
            $params['body']['from'] = $options['from'];
        }

        if (isset($options['size'])) {
            $params['body']['size'] = $options['size'];
        }

        if (isset($options['numericFilters']) && count($options['numericFilters'])) {
            $params['body']['query']['bool']['must'] = array_merge($params['body']['query']['bool']['must'],
                $options['numericFilters']);
        }

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $this->elastic,
                $builder->query,
                $params
            );
        }

        return $this->elastic->search($params);
    }

    /**
     * Generates the sort if theres any.
     *
     * @param  Builder $builder
     *
     * @return array|null
     */
    protected function sort($builder)
    {
        if (count($builder->orders) == 0) {
            return NULL;
        }

        return collect($builder->orders)->map(function($order) {
            return [$order['column'] => $order['direction']];
        })->toArray();
    }

    /**
     * Get the filter array for the query.
     *
     * @param  Builder $builder
     *
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function($value, $key) {
            if (is_array($value)) {
                return ['match' => [$key => implode(' ', $value)]];
            }

            return ['match_phrase' => [$key => $value]];
        })->values()->all();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  Builder $builder
     *
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'size'           => $builder->limit,
        ]));
    }

    /**
     * Update the given model in the index.
     *
     * @param  Collection $models
     *
     * @return void
     */
    public function update($models)
    {
        if ($models->isEmpty()) {
            return;
        }

        $params['body'] = [];
        $params['type'] = $models->first()->searchableAs();

        $models->each(function($model) use (&$params) {
            $params['body'][] = [
                'update' => [
                    '_id'    => $model->getKey(),
                    '_index' => $this->index,
                    //                    '_type' => $model->searchableAs(),
                ],
            ];
            $params['body'][] = [
                'doc'           => $model->toSearchableArray(),
                'doc_as_upsert' => TRUE,
            ];
        });

        $this->elastic->bulk($params);
    }
}
