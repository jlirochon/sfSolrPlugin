<?php
/*
 * This file is part of the sfLucenePlugin package
 * (c) 2007 - 2008 Carl Vondrick <carl@carlsoft.net>
 * (c) 2009 - Thomas Rabaix <thomas.rabaix@soleoweb.com>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Symfony friendly wrapper for all the Lucene hits.
 *
 * This implemenets the appropriate interfaces so you can still access it as an array
 * and loop through it.
 *
 * @package    sfLucenePlugin
 * @subpackage Results
 * @author     Carl Vondrick <carl@carlsoft.net>
 * @version SVN: $Id$
 */
class sfLuceneResults implements Iterator, Countable, ArrayAccess
{
  protected
    $results = array(),
    $pointer = 0,
    $search,
    $facets_fields = null,
    $group_results = null
  ;

  /**
   * Constructor
   *
   * @author  Carl Vondrick <carl@carlsoft.net>
   * @author  Julien Lirochon <julien@lirochon.net>
   * @param   sfLuceneResponse $response
   * @param   sfLucene $search
   * @param   array $options
   * @return  void
   */
  public function __construct($response, sfLucene $search, $options = array())
  {
    $this->results = $response;
    $this->search = $search;

    if (isset($options['is_group_result']) && $options['is_group_result'])
    {
      $this->results->response = $this->results->doclist;
    }
  }

  /**
  * Gets a result instance for the result.
  */
  protected function getInstance($result)
  {
    
    return sfLuceneResult::getInstance($result, $this->search);
  }

  /**
   * Hook for sfMixer
   */
  public function __call($method, $arguments)
  {
    $event = $this->search->getEventDispatcher()->notifyUntil(new sfEvent($this, 'sf_lucene_results.method_not_found', array('method' => $method, 'arguments' => $arguments)));

    if (!$event->isProcessed())
    {
      throw new sfLuceneResultsException(sprintf('Call to undefined method %s::%s.', __CLASS__, $method));
    }

    return $event->getReturnValue();
  }

  public function getSearch()
  {
    
    return $this->search;
  }

  public function getRawResult()
  {
    return $this->results;
  }
  
  public function current()
  {

    return $this->getInstance($this->getRawResult()->response->docs[$this->pointer]);
  }

  public function key()
  {
    
    return $this->pointer;
  }

  public function next()
  {
    $this->pointer++;
  }

  public function rewind()
  {
    $this->pointer = 0;
  }

  public function valid()
  {
    
    return isset($this->getRawResult()->response->docs[$this->pointer]);
  }

  public function count()
  {
    if ($this->isGrouped())
    {
      /**
       * returns matches from the first group
       * (all groups appears to have the same number of matches)
       */
      foreach($this->getFixedGroupResults() as $group)
      {
        return $group->matches;
      }
    }

    return $this->getRawResult()->response->numFound;
  }

  public function offsetExists($offset)
  {
    
    return isset($this->getRawResult()->response->docs[$offset]);
  }

  public function offsetGet($offset)
  {
    
    return $this->getInstance($this->getRawResult()->response->docs[$offset]);
  }

  public function offsetSet($offset, $set)
  {
    $this->getRawResult()->response->docs[$offset] = $set;
  }

  public function offsetUnset($offset)
  {
    unset($this->getRawResult()->response->docs[$offset]);
  }

  public function toArray()
  {
    $response = $this->getRawResult()->response;
    
    if(!$response)
    {
      
      return array();
    }
    
    return $this->getRawResult()->response->docs;
  }

  /*
   * FACETS METHODS
   */

  public function getFacetQueries()
  {

    return $this->getFacetsField('facet_queries');
  }

  public function getFacetFields()
  {

    return $this->getFacetsField('facet_fields');
  }

  public function getFacetQuery($name)
  {
    if (!$this->hasFacetQuery($name))
    {

      return null;
    }

    $facets = $this->getFacetQueries();

    return $facets[$name];
  }

  public function getFacetField($name)
  {
    if (!$this->hasFacetField($name))
    {

      return null;
    }

    $facets =  $this->getFacetFields();

    return $facets[$name];
  }

  public function getFacetsField($facet_field_name)
  {
    // The underline library convert the json into a stdClass object
    // There is no other choice for now, sorry for this code ...
    if($this->facets_fields == null)
    {
      $json = json_decode($this->results->getRawResponse(), true);

      $this->facets_fields = $json['facet_counts'];
    }

    if(!array_key_exists($facet_field_name, $this->facets_fields))
    {

      return null;
    }

    return $this->facets_fields[$facet_field_name];
  }

  public function hasFacetQuery($name)
  {
    $facets = $this->getFacetQueries();

    if(!$facets || !isset($facets[$name]))
    {

      return false;
    }

    return true;
  }

  public function hasFacetField($name)
  {
    $facets =  $this->getFacetFields();

    if(!$facets || !isset($facets[$name]))
    {

      return false;
    }

    return true;
  }

  /**
   * Returns whether the response contains a facet date result for the given field
   *
   * @author  Mathieu Dumoutier
   * @param   string  $name   date field name
   * @return  bool
   */
  public function hasFacetDate($name)
  {
    $facets = $this->getFacetDates();

    if(!$facets || !isset($facets[$name]))
    {
      return false;
    }

    return true;
  }
  
  /**
   * GROUP METHODS
   */

  /**
   * Returns whether the response contains grouped results or not
   *
   * @author  Julien Lirochon <julien@kolana-studio.com>
   * @return  boolean 
   */
  public function isGrouped()
  {
    return isset($this->getRawResult()->grouped);
  }

  /**
   * Returns a list of group names contained in the response. The list contains groups of both type
   * (group.field and group.query)
   *
   * @author  Julien Lirochon <julien@kolana-studio.com>
   * @return  array
   */
  public function getGroups()
  {
    if ($this->isGrouped())
    {
      return array_keys($this->getFixedGroupResults());
    }

    return array();
  }

  /**
   * Fix a problem in Solr when it does not evaluate {!key=mykey} as it does for facets
   *   "{!key=mykey}some:query" will be fixed as "mykey"
   *   "some:query" will be kept as "some:query"
   *
   * @author  Julien Lirochon <julien@kolana-studio.com>
   * @since   2011-02-22
   * @param   string $groupName
   * @return  string
   */
  protected function fixGroupName($groupName)
  {
    if (preg_match('/\{![^}]*key=([a-zA-Z0-9_]+)[^}]*\}/', $groupName, $matches))
    {
      $groupName = $matches[1];
    }

    return $groupName;
  }

  public function getFixedGroupResults()
  {
    if (null === $this->group_results)
    {
      $this->group_results = array();
      foreach($this->getRawResult()->grouped as $name => $result)
      {
        $this->group_results[$this->fixGroupName($name)] = $result;
      }
    }

    return $this->group_results;
  }

  /**
   * Depending of group type (group.query or group.field), returns a sfLuceneResults object,
   * or an array of sfLuceneResults objects, respectively.
   *
   * @author  Julien Lirochon <julien@kolana-studio.com>
   * @param   string $groupName
   * @return  array|bool|sfLuceneResults
   */
  public function getGroupResults($groupName)
  {
    if (!$this->hasGroupResults($groupName))
    {
      return false;
    }

    // group.query ?
    $group_results = $this->getFixedGroupResults();
    if (!isset($group_results[$groupName]->groups))
    {
      return new sfLuceneResults($group_results[$groupName], $this->search, array(
        'is_group_result' => true
      ));
    }

    // group.field ?
    $resultsByValue = array();
    foreach($group_results[$groupName]->groups as $group)
    {
      $resultsByValue[$group->groupValue] = new sfLuceneResults($group, $this->search, array(
        'is_group_result' => true
      ));
    }

    return $resultsByValue;
  }

  /**
   * Returns whether the response contains the given $groupName or not
   *
   * @author  Julien Lirochon <julien@kolana-studio.com>
   * @param   string $groupName
   * @return  bool
   */
  public function hasGroupResults($groupName)
  {
    $groups = $this->getGroups();

    if(!$groups || !in_array($groupName, $groups))
    {
      return false;
    }

    return true;
  }

  /**
   * Returns total number of matches for the group
   *
   * @author  Julien Lirochon <julien@kolana-studio.com>
   * @param   string $groupName
   * @return  int
   */
  public function countGroupMatches($groupName)
  {
    if (!$this->hasGroupResults($groupName))
    {
      return 0;
    }

    $results = $this->getGroupResults($groupName);

    // @todo: how can we handle this case ?
    if (is_array($results))
    {
      return 0;
    }

    return $results->results->doclist->numFound;
  }

  /**
   * Retrieves all facet date results
   *
   * @author  Mathieu Dumoutier
   *
   */
  public function getFacetDates()
  {
    return $this->getFacetsField('facet_dates');
  }

  /**
   * Retrieves number of matches for $
   *
   * @author  Mathieu Dumoutier
   * @param   string  $name   date field name
   * @return  null
   */
  public function getFacetDate($name)
  {
    if (!$this->hasFacetDate($name))
    {
      return null;
    }

    $facets = $this->getFacetDates();

    return $facets[$name];
  }
}
