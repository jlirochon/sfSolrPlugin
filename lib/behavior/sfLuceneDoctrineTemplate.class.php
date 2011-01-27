<?php
/*
 * This file is part of the sfLucenePlugin package
 * (c) 2007 Carl Vondrick <carlv@carlsoft.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Responsible for handling Doctrine's behaviors.
 * @package    sfLucenePlugin
 * @subpackage Behavior
 * @author     Carl Vondrick <carlv@carlsoft.net>
 */
class sfLuceneDoctrineTemplate extends Doctrine_Template
{
  private $_listener;

  /**
   * setTableDefinition
   *
   * @return void
   */
  public function setTableDefinition()
  {
    $this->_listener = new sfLuceneDoctrineListener();
    $this->addListener($this->_listener);
  }

  /**
   * Saves index by deleting and inserting.
   */
  public function saveIndex($node)
  {
    $this->_listener->saveIndex($node);
  }
}