<?php

/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         3.0.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Collection;

use AppendIterator;
use ArrayIterator;
use Cake\Collection\Iterator\BufferedIterator;
use Cake\Collection\Iterator\ExtractIterator;
use Cake\Collection\Iterator\FilterIterator;
use Cake\Collection\Iterator\InsertIterator;
use Cake\Collection\Iterator\MapReduce;
use Cake\Collection\Iterator\NestIterator;
use Cake\Collection\Iterator\ReplaceIterator;
use Cake\Collection\Iterator\SortIterator;
use Cake\Collection\Iterator\StoppableIterator;
use Cake\Collection\Iterator\TreeIterator;
use Cake\Collection\Iterator\UnfoldIterator;
use Cake\Collection\Iterator\UniqueIterator;
use Cake\Collection\Iterator\ZipIterator;
use Countable;
use InvalidArgumentException;
use LimitIterator;
use LogicException;
use OuterIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Traversable;

/**
 * Offers a handful of methods to manipulate iterators
 */
trait CollectionTrait
{
    use ExtractTrait;

    /**
     * @inheritDoc
     */
    public function each(callable $callback)
    {
        foreach ($this->optimizeUnwrap() as $k => $v) {
            $callback($v, $k);
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function filter($callback = null)
    {
        if ($callback === null) {
            $callback = function ($v) {
                return (bool)$v;
            };
        }

        return new FilterIterator($this->unwrap(), $callback);
    }

    /**
     * @inheritDoc
     */
    public function reject(callable $callback)
    {
        return new FilterIterator($this->unwrap(), function ($key, $value, $items) use ($callback) {
            return !$callback($key, $value, $items);
        });
    }

    /**
     * @param $key
     * @return \Cake\Collection\Iterator\FilterIterator
     */
    public function unique($key = null)
    {
        return new UniqueIterator($this->unwrap(), $key);
    }

    /**
     * @inheritDoc
     */
    public function every(callable $callback)
    {
        foreach ($this->optimizeUnwrap() as $key => $value) {
            if (!$callback($value, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function some(callable $callback)
    {
        foreach ($this->optimizeUnwrap() as $key => $value) {
            if ($callback($value, $key) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function contains($value)
    {
        foreach ($this->optimizeUnwrap() as $v) {
            if ($value === $v) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    public function map(callable $callback)
    {
        return new ReplaceIterator($this->unwrap(), $callback);
    }

    /**
     * @inheritDoc
     */
    public function reduce(callable $callback, $initial = null)
    {
        $isFirst = false;
        if (func_num_args() < 2) {
            $isFirst = true;
        }

        $result = $initial;
        foreach ($this->optimizeUnwrap() as $k => $value) {
            if ($isFirst) {
                $result = $value;
                $isFirst = false;
                continue;
            }
            $result = $callback($result, $value, $k);
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function extract($path)
    {
        $extractor = new ExtractIterator($this->unwrap(), $path);
        if (is_string($path) && strpos($path, '{*}') !== false) {
            $extractor = $extractor
                ->filter(function ($data) {
                    return $data !== null && ($data instanceof Traversable || is_array($data));
                })
                ->unfold();
        }

        return $extractor;
    }

    /**
     * @inheritDoc
     */
    public function max($path, $sort = \SORT_NUMERIC)
    {
        return (new SortIterator($this->unwrap(), $path, \SORT_DESC, $sort))->first();
    }

    /**
     * @inheritDoc
     */
    public function min($path, $sort = \SORT_NUMERIC)
    {
        return (new SortIterator($this->unwrap(), $path, \SORT_ASC, $sort))->first();
    }

    /**
     * @inheritDoc
     */
    public function avg($path = null)
    {
        $result = $this;
        if ($path !== null) {
            $result = $result->extract($path);
        }
        $result = $result
            ->reduce(function ($acc, $current) {
                list($count, $sum) = $acc;

                return [$count + 1, $sum + $current];
            }, [0, 0]);

        if ($result[0] === 0) {
            return null;
        }

        return $result[1] / $result[0];
    }

    /**
     * @inheritDoc
     */
    public function median($path = null)
    {
        $items = $this;
        if ($path !== null) {
            $items = $items->extract($path);
        }
        $values = $items->toList();
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        $middle = (int)($count / 2);

        if ($count % 2) {
            return $values[$middle];
        }

        return ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * @inheritDoc
     */
    public function sortBy($path, $order = \SORT_DESC, $sort = \SORT_NUMERIC)
    {
        return new SortIterator($this->unwrap(), $path, $order, $sort);
    }

    /**
     * @inheritDoc
     */
    public function groupBy($path)
    {
        $callback = $this->_propertyExtractor($path);
        $group = [];
        foreach ($this->optimizeUnwrap() as $value) {
            $pathValue = $callback($value);
            if ($pathValue === null) {
                throw new InvalidArgumentException(
                    'Cannot group by path that does not exist or contains a null value. ' .
                    'Use a callback to return a default value for that path.'
                );
            }
            $group[$pathValue][] = $value;
        }

        return new Collection($group);
    }

    /**
     * @inheritDoc
     */
    public function indexBy($path)
    {
        $callback = $this->_propertyExtractor($path);
        $group = [];
        foreach ($this->optimizeUnwrap() as $value) {
            $pathValue = $callback($value);
            if ($pathValue === null) {
                throw new InvalidArgumentException(
                    'Cannot index by path that does not exist or contains a null value. ' .
                    'Use a callback to return a default value for that path.'
                );
            }
            $group[$pathValue] = $value;
        }

        return new Collection($group);
    }

    /**
     * @inheritDoc
     */
    public function countBy($path)
    {
        $callback = $this->_propertyExtractor($path);

        $mapper = function ($value, $key, $mr) use ($callback) {
            /** @var \Cake\Collection\Iterator\MapReduce $mr */
            $mr->emitIntermediate($value, $callback($value));
        };

        $reducer = function ($values, $key, $mr) {
            /** @var \Cake\Collection\Iterator\MapReduce $mr */
            $mr->emit(count($values), $key);
        };

        return new Collection(new MapReduce($this->unwrap(), $mapper, $reducer));
    }

    /**
     * @inheritDoc
     */
    public function sumOf($path = null)
    {
        if ($path === null) {
            return array_sum($this->toList());
        }

        $callback = $this->_propertyExtractor($path);
        $sum = 0;
        foreach ($this->optimizeUnwrap() as $k => $v) {
            $sum += $callback($v, $k);
        }

        return $sum;
    }

    /**
     * @inheritDoc
     */
    public function shuffle()
    {
        $items = $this->toList();
        shuffle($items);

        return new Collection($items);
    }

    /**
     * @inheritDoc
     */
    public function sample($length = 10)
    {
        return new Collection(new LimitIterator($this->shuffle(), 0, $length));
    }

    /**
     * @inheritDoc
     */
    public function take($length = 1, $offset = 0)
    {
        return new Collection(new LimitIterator($this, $offset, $length));
    }

    /**
     * @inheritDoc
     */
    public function skip($length)
    {
        return new Collection(new LimitIterator($this, $length));
    }

    /**
     * @inheritDoc
     */
    public function match(array $conditions)
    {
        return $this->filter($this->_createMatcherFilter($conditions));
    }

    /**
     * @inheritDoc
     */
    public function firstMatch(array $conditions)
    {
        return $this->match($conditions)->first();
    }

    /**
     * @inheritDoc
     */
    public function first()
    {
        $iterator = new LimitIterator($this, 0, 1);
        foreach ($iterator as $result) {
            return $result;
        }
    }

    /**
     * @inheritDoc
     */
    public function last()
    {
        $iterator = $this->optimizeUnwrap();
        if (is_array($iterator)) {
            return array_pop($iterator);
        }

        if ($iterator instanceof Countable) {
            $count = count($iterator);
            if ($count === 0) {
                return null;
            }
            /** @var iterable $iterator */
            $iterator = new LimitIterator($iterator, $count - 1, 1);
        }

        $result = null;
        foreach ($iterator as $result) {
            // No-op
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function takeLast($length)
    {
        if ($length < 1) {
            throw new InvalidArgumentException('The takeLast method requires a number greater than 0.');
        }

        $iterator = $this->optimizeUnwrap();
        if (is_array($iterator)) {
            return new Collection(array_slice($iterator, $length * -1));
        }

        if ($iterator instanceof Countable) {
            $count = count($iterator);

            if ($count === 0) {
                return new Collection([]);
            }

            $iterator = new LimitIterator($iterator, max(0, $count - $length), $length);

            return new Collection($iterator);
        }

        $generator = function ($iterator, $length) {
            $result = [];
            $bucket = 0;
            $offset = 0;

            /**
             * Consider the collection of elements [1, 2, 3, 4, 5, 6, 7, 8, 9], in order
             * to get the last 4 elements, we can keep a buffer of 4 elements and
             * fill it circularly using modulo logic, we use the $bucket variable
             * to track the position to fill next in the buffer. This how the buffer
             * looks like after 4 iterations:
             *
             * 0) 1 2 3 4 -- $bucket now goes back to 0, we have filled 4 elementes
             * 1) 5 2 3 4 -- 5th iteration
             * 2) 5 6 3 4 -- 6th iteration
             * 3) 5 6 7 4 -- 7th iteration
             * 4) 5 6 7 8 -- 8th iteration
             * 5) 9 6 7 8
             *
             *  We can see that at the end of the iterations, the buffer contains all
             *  the last four elements, just in the wrong order. How do we keep the
             *  original order? Well, it turns out that the number of iteration also
             *  give us a clue on what's going on, Let's add a marker for it now:
             *
             * 0) 1 2 3 4
             *    ^ -- The 0) above now becomes the $offset variable
             * 1) 5 2 3 4
             *      ^ -- $offset = 1
             * 2) 5 6 3 4
             *        ^ -- $offset = 2
             * 3) 5 6 7 4
             *          ^ -- $offset = 3
             * 4) 5 6 7 8
             *    ^  -- We use module logic for $offset too
             *          and as you can see each time $offset is 0, then the buffer
             *          is sorted exactly as we need.
             * 5) 9 6 7 8
             *      ^ -- $offset = 1
             *
             * The $offset variable is a marker for splitting the buffer in two,
             * elements to the right for the marker are the head of the final result,
             * whereas the elements at the left are the tail. For example consider step 5)
             * which has an offset of 1:
             *
             * - $head = elements to the right = [6, 7, 8]
             * - $tail = elements to the left =  [9]
             * - $result = $head + $tail = [6, 7, 8, 9]
             *
             * The logic above applies to collections of any size.
             */

            foreach ($iterator as $k => $item) {
                $result[$bucket] = [$k, $item];
                $bucket = (++$bucket) % $length;
                $offset++;
            }

            $offset = $offset % $length;
            $head = array_slice($result, $offset);
            $tail = array_slice($result, 0, $offset);

            foreach ($head as $v) {
                yield $v[0] => $v[1];
            }

            foreach ($tail as $v) {
                yield $v[0] => $v[1];
            }
        };

        return new Collection($generator($iterator, $length));
    }

    /**
     * @inheritDoc
     */
    public function append($items)
    {
        $list = new AppendIterator();
        $list->append($this->unwrap());
        $list->append((new Collection($items))->unwrap());

        return new Collection($list);
    }

    /**
     * @inheritDoc
     */
    public function appendItem($item, $key = null)
    {
        if ($key !== null) {
            $data = [$key => $item];
        } else {
            $data = [$item];
        }

        return $this->append($data);
    }

    /**
     * @inheritDoc
     */
    public function prepend($items)
    {
        return (new Collection($items))->append($this);
    }

    /**
     * @inheritDoc
     */
    public function prependItem($item, $key = null)
    {
        if ($key !== null) {
            $data = [$key => $item];
        } else {
            $data = [$item];
        }

        return $this->prepend($data);
    }

    /**
     * @inheritDoc
     */
    public function combine($keyPath, $valuePath, $groupPath = null)
    {
        $options = [
            'keyPath' => $this->_propertyExtractor($keyPath),
            'valuePath' => $this->_propertyExtractor($valuePath),
            'groupPath' => $groupPath ? $this->_propertyExtractor($groupPath) : null,
        ];

        $mapper = function ($value, $key, MapReduce $mapReduce) use ($options) {
            $rowKey = $options['keyPath'];
            $rowVal = $options['valuePath'];

            if (!$options['groupPath']) {
                $mapReduce->emit($rowVal($value, $key), $rowKey($value, $key));

                return null;
            }

            $key = $options['groupPath']($value, $key);
            $mapReduce->emitIntermediate(
                [$rowKey($value, $key) => $rowVal($value, $key)],
                $key
            );
        };

        $reducer = function ($values, $key, MapReduce $mapReduce) {
            $result = [];
            foreach ($values as $value) {
                $result += $value;
            }
            $mapReduce->emit($result, $key);
        };

        return new Collection(new MapReduce($this->unwrap(), $mapper, $reducer));
    }

    /**
     * @inheritDoc
     */
    public function nest($idPath, $parentPath, $nestingKey = 'children')
    {
        $parents = [];
        $idPath = $this->_propertyExtractor($idPath);
        $parentPath = $this->_propertyExtractor($parentPath);
        $isObject = true;

        $mapper = function ($row, $key, MapReduce $mapReduce) use (&$parents, $idPath, $parentPath, $nestingKey) {
            $row[$nestingKey] = [];
            $id = $idPath($row, $key);
            $parentId = $parentPath($row, $key);
            $parents[$id] = &$row;
            $mapReduce->emitIntermediate($id, $parentId);
        };

        $reducer = function ($values, $key, MapReduce $mapReduce) use (&$parents, &$isObject, $nestingKey) {
            static $foundOutType = false;
            if (!$foundOutType) {
                $isObject = is_object(current($parents));
                $foundOutType = true;
            }
            if (empty($key) || !isset($parents[$key])) {
                foreach ($values as $id) {
                    /** @psalm-suppress PossiblyInvalidArgument */
                    $parents[$id] = $isObject ? $parents[$id] : new ArrayIterator($parents[$id], 1);
                    $mapReduce->emit($parents[$id]);
                }

                return null;
            }

            $children = [];
            foreach ($values as $id) {
                $children[] = &$parents[$id];
            }
            $parents[$key][$nestingKey] = $children;
        };

        return (new Collection(new MapReduce($this->unwrap(), $mapper, $reducer)))
            ->map(function ($value) use (&$isObject) {
                /** @var \ArrayIterator $value */
                return $isObject ? $value : $value->getArrayCopy();
            });
    }

    /**
     * @inheritDoc
     */
    public function insert($path, $values)
    {
        return new InsertIterator($this->unwrap(), $path, $values);
    }

    /**
     * @inheritDoc
     */
    public function toArray($keepKeys = true)
    {
        $iterator = $this->unwrap();
        if ($iterator instanceof ArrayIterator) {
            $items = $iterator->getArrayCopy();

            return $keepKeys ? $items : array_values($items);
        }
        // RecursiveIteratorIterator can return duplicate key values causing
        // data loss when converted into an array
        if ($keepKeys && get_class($iterator) === RecursiveIteratorIterator::class) {
            $keepKeys = false;
        }

        return iterator_to_array($this, $keepKeys);
    }

    /**
     * @inheritDoc
     */
    public function toList()
    {
        return $this->toArray(false);
    }

    /**
     * @inheritDoc
     */
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * @inheritDoc
     */
    public function compile($keepKeys = true)
    {
        return new Collection($this->toArray($keepKeys));
    }

    /**
     * @inheritDoc
     */
    public function lazy()
    {
        $generator = function () {
            foreach ($this->unwrap() as $k => $v) {
                yield $k => $v;
            }
        };

        return new Collection($generator());
    }

    /**
     * @inheritDoc
     */
    public function buffered()
    {
        return new BufferedIterator($this->unwrap());
    }

    /**
     * @inheritDoc
     */
    public function listNested($order = 'desc', $nestingKey = 'children')
    {
        if (is_string($order)) {
            $order = strtolower($order);
            $modes = [
                'desc' => RecursiveIteratorIterator::SELF_FIRST,
                'asc' => RecursiveIteratorIterator::CHILD_FIRST,
                'leaves' => RecursiveIteratorIterator::LEAVES_ONLY,
            ];

            if (!isset($modes[$order])) {
                throw new RuntimeException(sprintf(
                    "Invalid direction `%s` provided. Must be one of: 'desc', 'asc', 'leaves'",
                    $order
                ));
            }
            $order = $modes[$order];
        }

        return new TreeIterator(
            new NestIterator($this, $nestingKey),
            $order
        );
    }

    /**
     * @inheritDoc
     */
    public function stopWhen($condition)
    {
        if (!is_callable($condition)) {
            $condition = $this->_createMatcherFilter($condition);
        }

        return new StoppableIterator($this->unwrap(), $condition);
    }

    /**
     * @inheritDoc
     */
    public function unfold($callback = null)
    {
        if ($callback === null) {
            $callback = function ($item) {
                return $item;
            };
        }

        return new Collection(
            new RecursiveIteratorIterator(
                new UnfoldIterator($this->unwrap(), $callback),
                RecursiveIteratorIterator::LEAVES_ONLY
            )
        );
    }

    /**
     * @inheritDoc
     */
    public function through(callable $callback)
    {
        $result = $callback($this);

        return $result instanceof CollectionInterface ? $result : new Collection($result);
    }

    /**
     * @inheritDoc
     */
    public function zip($items)
    {
        return new ZipIterator(array_merge([$this->unwrap()], func_get_args()));
    }

    /**
     * @inheritDoc
     */
    public function zipWith($items, $callback)
    {
        if (func_num_args() > 2) {
            $items = func_get_args();
            $callback = array_pop($items);
        } else {
            $items = [$items];
        }

        return new ZipIterator(array_merge([$this->unwrap()], $items), $callback);
    }

    /**
     * @inheritDoc
     */
    public function chunk($chunkSize)
    {
        return $this->map(function ($v, $k, $iterator) use ($chunkSize) {
            $values = [$v];
            for ($i = 1; $i < $chunkSize; $i++) {
                $iterator->next();
                if (!$iterator->valid()) {
                    break;
                }
                $values[] = $iterator->current();
            }

            return $values;
        });
    }

    /**
     * @inheritDoc
     */
    public function chunkWithKeys($chunkSize, $keepKeys = true)
    {
        return $this->map(function ($v, $k, $iterator) use ($chunkSize, $keepKeys) {
            $key = 0;
            if ($keepKeys) {
                $key = $k;
            }
            $values = [$key => $v];
            for ($i = 1; $i < $chunkSize; $i++) {
                $iterator->next();
                if (!$iterator->valid()) {
                    break;
                }
                if ($keepKeys) {
                    $values[$iterator->key()] = $iterator->current();
                } else {
                    $values[] = $iterator->current();
                }
            }

            return $values;
        });
    }

    /**
     * @inheritDoc
     */
    public function isEmpty()
    {
        foreach ($this as $el) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function unwrap()
    {
        $iterator = $this;
        while (
            get_class($iterator) === Collection::class
            && $iterator instanceof OuterIterator
        ) {
            $iterator = $iterator->getInnerIterator();
        }

        if ($iterator !== $this && $iterator instanceof CollectionInterface) {
            $iterator = $iterator->unwrap();
        }

        return $iterator;
    }

    /**
     * {@inheritDoc}
     *
     * @param callable|null $operation A callable that allows you to customize the product result.
     * @param callable|null $filter A filtering callback that must return true for a result to be part
     *   of the final results.
     * @return \Cake\Collection\CollectionInterface
     * @throws \LogicException
     */
    public function cartesianProduct($operation = null, $filter = null)
    {
        if ($this->isEmpty()) {
            return new Collection([]);
        }

        $collectionArrays = [];
        $collectionArraysKeys = [];
        $collectionArraysCounts = [];

        foreach ($this->toList() as $value) {
            $valueCount = count($value);
            if ($valueCount !== count($value, COUNT_RECURSIVE)) {
                throw new LogicException('Cannot find the cartesian product of a multidimensional array');
            }

            $collectionArraysKeys[] = array_keys($value);
            $collectionArraysCounts[] = $valueCount;
            $collectionArrays[] = $value;
        }

        $result = [];
        $lastIndex = count($collectionArrays) - 1;
        // holds the indexes of the arrays that generate the current combination
        $currentIndexes = array_fill(0, $lastIndex + 1, 0);

        $changeIndex = $lastIndex;

        while (!($changeIndex === 0 && $currentIndexes[0] === $collectionArraysCounts[0])) {
            $currentCombination = array_map(function ($value, $keys, $index) {
                return $value[$keys[$index]];
            }, $collectionArrays, $collectionArraysKeys, $currentIndexes);

            if ($filter === null || $filter($currentCombination)) {
                $result[] = $operation === null ? $currentCombination : $operation($currentCombination);
            }

            $currentIndexes[$lastIndex]++;

            for (
                $changeIndex = $lastIndex;
                $currentIndexes[$changeIndex] === $collectionArraysCounts[$changeIndex] && $changeIndex > 0;
                $changeIndex--
            ) {
                $currentIndexes[$changeIndex] = 0;
                $currentIndexes[$changeIndex - 1]++;
            }
        }

        return new Collection($result);
    }

    /**
     * {@inheritDoc}
     *
     * @return \Cake\Collection\CollectionInterface
     * @throws \LogicException
     */
    public function transpose()
    {
        $arrayValue = $this->toList();
        $length = count(current($arrayValue));
        $result = [];
        foreach ($arrayValue as $row) {
            if (count($row) !== $length) {
                throw new LogicException('Child arrays do not have even length');
            }
        }

        for ($column = 0; $column < $length; $column++) {
            $result[] = array_column($arrayValue, $column);
        }

        return new Collection($result);
    }

    /**
     * @inheritDoc
     */
    public function count()
    {
        $traversable = $this->optimizeUnwrap();

        if (is_array($traversable)) {
            return count($traversable);
        }

        return iterator_count($traversable);
    }

    /**
     * @inheritDoc
     */
    public function countKeys()
    {
        return count($this->toArray());
    }

    /**
     * Unwraps this iterator and returns the simplest
     * traversable that can be used for getting the data out
     *
     * @return iterable
     */
    protected function optimizeUnwrap()
    {
        /** @var \ArrayObject $iterator */
        $iterator = $this->unwrap();

        if (get_class($iterator) === ArrayIterator::class) {
            $iterator = $iterator->getArrayCopy();
        }

        return $iterator;
    }
}
