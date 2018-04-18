<?php

namespace ScoutEngines\Elasticsearch\Payloads;

use Exception;
use Illuminate\Database\Eloquent\Model;

class DocumentPayload extends TypePayload {

    /**
     * @param Model $model
     * @param String $index
     *
     * @throws Exception
     */
    public function __construct(Model $model, String $index) {
        if (!$model->getKey()) {
            throw new Exception(sprintf(
                'The key value must be set to construct a payload for the %s instance.',
                get_class($model)
            ));
        }

        parent::__construct($model, $index);

        $this->payload['id']   = $model->getKey();
        $this->protectedKeys[] = 'id';
    }
}