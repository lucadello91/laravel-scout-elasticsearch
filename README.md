# Laravel Scout Elasticsearch Driver

[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/lucadello91/laravel-scout-elasticsearch.svg?style=flat-square)](https://packagist.org/packages/lucadello91/laravel-scout-elasticsearch)
[![Total Downloads](https://img.shields.io/packagist/dt/lucadello91/laravel-scout-elasticsearch.svg?style=flat-square)](https://packagist.org/packages/lucadello91/laravel-scout-elasticsearch)

This package makes is the [Elasticsearch 6](https://www.elastic.co/products/elasticsearch) driver for Laravel Scout 4.

## Contents

- [Installation](#installation)
- [Usage](#usage)
- [Credits](#credits)
- [License](#license)

## Installation

You can install the package via composer:

``` bash
composer require lucadello91/laravel-scout-elasticsearch
```

If you use laravel <5.5, you must add the Scout service provider and the Elasticsearch service provider in your app.php config:

```php
// config/app.php
'providers' => [
    ...
    Laravel\Scout\ScoutServiceProvider::class,
    ScoutEngines\Elasticsearch\ElasticsearchProvider::class,
],
```

### Setting up Elasticsearch configuration
You must have a Elasticsearch server up and running. The package automatically create the index if it not exist

If you need help with this please refer to the [Elasticsearch documentation](https://www.elastic.co/guide/en/elasticsearch/reference/current/index.html)

After you've published the Laravel Scout package configuration:

```php
// config/scout.php
// Set your driver to elasticsearch
    'driver' => env('SCOUT_DRIVER', 'elasticsearch'),

...
    'elasticsearch' => [
        'index' => env('ELASTICSEARCH_INDEX', 'laravel'),
        'hosts' => [
            env('ELASTICSEARCH_HOST', 'http://localhost'),
        ],
        'max_result_window' => '200000'
    ],
...
```

## Usage

Now you can use Laravel Scout as described in the [official documentation](https://laravel.com/docs/5.3/scout)
## Credits

- [Luca Dell'Orto](https://github.com/lucadello91)
- [All Contributors](../../contributors)

## License

The MIT License (MIT).
