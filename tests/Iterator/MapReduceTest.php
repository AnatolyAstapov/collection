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
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 */
namespace Cake\Test\TestCase\Collection\Iterator;

use ArrayIterator;
use Cake\Collection\Iterator\MapReduce;
use PHPUnit\Framework\TestCase;
use LogicException;

/**
 * Tests MapReduce class
 */
class MapReduceTest extends TestCase
{
    /**
     * Tests the creation of an inversed index of words to documents using
     * MapReduce
     */
    public function testInvertedIndexCreation()
    {
        $data = [
            'document_1' => 'Dogs are the most amazing animal in history',
            'document_2' => 'History is not only amazing but boring',
            'document_3' => 'One thing that is not boring is dogs',
        ];
        $mapper = function ($row, $document, $mr) {
            $words = array_map('strtolower', explode(' ', $row));
            foreach ($words as $word) {
                $mr->emitIntermediate($document, $word);
            }
        };
        $reducer = function ($documents, $word, $mr) {
            $mr->emit(array_unique($documents), $word);
        };
        $results = new MapReduce(new ArrayIterator($data), $mapper, $reducer);
        $expected = [
            'dogs' => ['document_1', 'document_3'],
            'are' => ['document_1'],
            'the' => ['document_1'],
            'most' => ['document_1'],
            'amazing' => ['document_1', 'document_2'],
            'animal' => ['document_1'],
            'in' => ['document_1'],
            'history' => ['document_1', 'document_2'],
            'is' => ['document_2', 'document_3'],
            'not' => ['document_2', 'document_3'],
            'only' => ['document_2'],
            'but' => ['document_2'],
            'boring' => ['document_2', 'document_3'],
            'one' => ['document_3'],
            'thing' => ['document_3'],
            'that' => ['document_3'],
        ];
        $this->assertEquals($expected, iterator_to_array($results));
    }

    /**
     * Tests that it is possible to use the emit function directly in the mapper
     */
    public function testEmitFinalInMapper()
    {
        $data = ['a' => ['one', 'two'], 'b' => ['three', 'four']];
        $mapper = function ($row, $key, $mr) {
            foreach ($row as $number) {
                $mr->emit($number);
            }
        };
        $results = new MapReduce(new ArrayIterator($data), $mapper);
        $expected = ['one', 'two', 'three', 'four'];
        $this->assertEquals($expected, iterator_to_array($results));
    }

    /**
     * Tests that a reducer is required when there are intermediate results
     */
    public function testReducerRequired()
    {
        $this->setExpectedException(LogicException::class);
        $data = ['a' => ['one', 'two'], 'b' => ['three', 'four']];
        $mapper = function ($row, $key, $mr) {
            foreach ($row as $number) {
                $mr->emitIntermediate('a', $number);
            }
        };
        $results = new MapReduce(new ArrayIterator($data), $mapper);
        iterator_to_array($results);
    }
}
