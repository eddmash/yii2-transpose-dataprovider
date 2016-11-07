yii2-transposedataprovider
=================================

A Yii2 data provider that transpose data into a table format


## Installation

The preferred way to install this extension is through [composer](http://getcomposer.org/download/).

> Read this [web tip /wiki](http://webtips.krajee.com/setting-composer-minimum-stability-application/) on setting
the `minimum-stability` settings for your application's composer.json.

Either run

```
$ php composer.phar require eddmash/transposedataprovider "@dev"
```

or add

```
"eddmash/transposedataprovider": "@dev"
```

to the ```require``` section of your `composer.json` file.


## Usage
```php
use Eddmash\TransposeDataProvider;

$dataProvider = new TransposeDataProvider([
    'query'=>$dataQuery,
    'groupField'=>'rowid',
    'columnsField'=>'columns.column_name',
    'valuesField'=>'data',
    'extraFields'=>['call_data_id', 'instance.name'],
    'pagination'=>[
        'pageSize'=>4
    ]
]);
```

Learn more [yii2-transpose-dataprovider documentation](https://eddmash.github.io/yii2-transpose-dataprovider/docs/v1_0_0/classes/Eddmash.TransposeDataProvider.TransposeDataProvider.html)

