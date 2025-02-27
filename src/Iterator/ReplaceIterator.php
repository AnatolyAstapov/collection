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
namespace Cake\Collection\Iterator;

use ArrayIterator;
use Cake\Collection\Collection;
use Cake\Collection\CollectionInterface;
use Traversable;

/**
 * Creates an iterator from another iterator that will modify each of the values
 * by converting them using a callback function.
 */
class ReplaceIterator extends Collection
{
    /**
     * The callback function to be used to transform values
     *
     * @var callable
     */
    protected $_callback;

    /**
     * A reference to the internal iterator this object is wrapping.
     *
     * @var \Traversable
     */
    protected $_innerIterator;

    /**
     * Creates an iterator from another iterator that will modify each of the values
     * by converting them using a callback function.
     *
     * Each time the callback is executed it will receive the value of the element
     * in the current iteration, the key of the element and the passed $items iterator
     * as arguments, in that order.
     *
     * @param iterable $items The items to be filtered.
     * @param callable $callback Callback.
     */
    public function __construct($items, callable $callback)
    {
        $this->_callback = $callback;
        parent::__construct($items);
        $this->_innerIterator = $this->getInnerIterator();
    }

    /**
     * Returns the value returned by the callback after passing the current value in
     * the iteration
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function current()
    {
        $callback = $this->_callback;

        return $callback(parent::current(), $this->key(), $this->_innerIterator);
    }

    /**
     * @inheritDoc
     */
    public function unwrap()
    {
        $iterator = $this->_innerIterator;

        if ($iterator instanceof CollectionInterface) {
            $iterator = $iterator->unwrap();
        }

        if (get_class($iterator) !== ArrayIterator::class) {
            return $this;
        }

        // ArrayIterator can be traversed strictly.
        // Let's do that for performance gains

        $callback = $this->_callback;
        $res = [];

        foreach ($iterator as $k => $v) {
            $res[$k] = $callback($v, $k, $iterator);
        }

        return new ArrayIterator($res);
    }
}
