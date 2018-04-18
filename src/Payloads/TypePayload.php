<?php

namespace ScoutEngines\Elasticsearch\Payloads;

use Exception;
use Illuminate\Database\Eloquent\Model;
use ScoutEngines\Elasticsearch\Searchable;

class TypePayload extends IndexPayload {

    /**
     * @var Model
     */
    protected $model;

    /**
     * @param Model $model
     *
     *
     * @param String $index
     *
     * @throws Exception
     */
    public function __construct(Model $model, String $index) {
        if (!in_array(Searchable::class, class_uses_recursive($model))) {
            throw new Exception(sprintf(
                'The %s model must use the %s trait.',
                get_class($model),
                Searchable::class
            ));
        }

        $this->model = $model;

        parent::__construct($index);

        $this->payload['type'] = $model->searchableAs();
        $this->protectedKeys[] = 'type';
    }
}