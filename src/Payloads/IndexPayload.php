<?php

namespace ScoutEngines\Elasticsearch\Payloads;

use ScoutEngines\Elasticsearch\Payloads\Features\HasProtectedKeys;

class IndexPayload extends RawPayload {

    use HasProtectedKeys;

    /**
     * @var array
     */
    protected $protectedKeys = [
        'index',
    ];

    protected $index;

    /**
     * @param String $index
     */
    public function __construct(String $index) {
        $this->index            = $index;
        $this->payload['index'] = $this->index;
    }

}