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
    $facets_fields = null
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
  public function __construct(sfLuceneResponse $response, sfLucene $search, $options = array())
  {
    $this->results = $response;
    $this->search = $search;

    if (isset($options['geo_unit']))
    {
      $this->convertGeoDistances($options['geo_unit']);
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

  /*
   * GEO METHODS
   */

  /**
   * For each result, converts geo_distance field value to the specified unit
   * (localsolr internally works in miles)
   *
   * @author  Julien Lirochon <julien@lirochon.net>
   * @param   int $unit
   * @return  void
   */
  protected function convertGeoDistances($unit = sfLuceneGeoCriteria::UNIT_KILOMETERS)
  {
    $ratio = sfLuceneCriteria::getGeoUnitRatio($unit);

    if ($ratio != 1)
    {
      foreach($this->results->response->docs as $index => $doc)
      {
        if (isset($doc->{sfLuceneGeoCriteria::DISTANCE_FIELD}))
        {
          $this->results->response->docs[$index]->{sfLuceneGeoCriteria::DISTANCE_FIELD} = $doc->{sfLuceneGeoCriteria::DISTANCE_FIELD} / $ratio;
        }
      }
    }
  }
}
