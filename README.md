# CakePHP Collection Library port for PHP 5,7,8

The collection classes provide a set of tools to manipulate arrays or Traversable objects.
If you have ever used underscore.js, you have an idea of what you can expect from the collection classes.

## Usage

Collections can be created using an array or Traversable object.  A simple use of a Collection would be:

```php
use Cake\Collection\Collection;

$items = ['a' => 1, 'b' => 2, 'c' => 3];
$collection = new Collection($items);

// Create a new collection containing elements
// with a value greater than one.
$overOne = $collection->filter(function ($value, $key, $iterator) {
    return $value > 1;
});
```

The `Collection\CollectionTrait` allows you to integrate collection-like features into any Traversable object
you have in your application as well.

## Documentation

Please make sure you check the [official documentation](https://book.cakephp.org/4/en/core-libraries/collections.html)

## How to run tests
Install dependencies
```shell
docker run -v `pwd`:/var/www --rm feitosa/php55-with-composer composer install
```

Run tests
```shell
docker run -v `pwd`:/var/www --rm feitosa/php55-with-composer vendor/bin/phpunit
```
