<?php
/*
 * This file is part of the sfLucenePlugin package
 * (c) 2009 - Thomas Rabaix <thomas.rabaix@soleoweb.com>
 * (c) 2010 - Julien Lirochon <julien@lirochon.net>
 * 
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 *
 * This class extends some original method from the parent class in order
 * to be more flexible
 *
 * @package     sfLucenePlugin
 * @subpackage  Utilities
 * @author      Thomas Rabaix <thomas.rabaix@soleoweb.com>
 * @author      Julien Lirochon <julien@lirochon.net>
 * @version     SVN: $Id$
 */
class sfLuceneResponse extends Apache_Solr_Response
{
  protected
    $document_class = 'sfLuceneDocument';

  /**
   * Converts raw solr response to sfLuceneDocuments
   *
   * @author  Julien Lirochon <julien@lirochon.net>
   * @param   $originalDocuments
   * @return  array of sfLuceneDocument
   */
  protected function _createDocuments($originalDocuments)
  {
    $documents = array();

    foreach ($originalDocuments as $originalDocument)
    {
      if ($this->_createDocuments)
      {
        $class = $this->document_class;
        $document = new $class;
      }
      else
      {
        $document = $originalDocument;
      }

      foreach ($originalDocument as $key => $value)
      {
        //If a result is an array with only a single
        //value then its nice to be able to access
        //it as if it were always a single value
        if ($this->_collapseSingleValueArrays && is_array($value) && count($value) <= 1)
        {
          $value = array_shift($value);
        }

        $document->$key = $value;
      }

      $documents[] = $document;
    }

    return $documents;
  }

  /**
   * Parses the raw response into the parsed_data array for access
   *
   * @author  Thomas Rabaix <thomas.rabaix@soleoweb.com>
   * @author  Julien Lirochon <julien@lirochon.net>  
   */
  protected function _parseData()
  {
    //An alternative would be to use Zend_Json::decode(...)
    $data = json_decode($this->_rawResponse);

    // check that we receive a valid JSON response - we should never receive a null
    if ($data === null)
    {
      throw new Exception('Solr response does not appear to be valid JSON, please examine the raw response with getRawResposne() method');
    }

    //if we're configured to collapse single valued arrays or to convert them to Apache_Solr_Document objects
    //and we have response documents, then try to collapse the values and / or convert them now
    if (($this->_createDocuments || $this->_collapseSingleValueArrays) && isset($data->response) && is_array($data->response->docs))
    {
      $data->response->docs = $this->_createDocuments($data->response->docs);
    }

    if (($this->_createDocuments || $this->_collapseSingleValueArrays) && isset($data->grouped))
    {
      foreach($data->grouped as $name => $group)
      {
        if (isset($group->groups) && is_array($group->groups) && sizeof($group->groups) > 0)
        {
          foreach($group->groups as $index => $subGroup)
          {
            $data->grouped->$name->groups[$index]->doclist->docs = $this->_createDocuments($subGroup->doclist->docs);
          }
        }
        elseif(isset($group->doclist))
        {
          $data->grouped->$name->doclist->docs = $this->_createDocuments($group->doclist->docs);
        }
      }
    }

    $this->_parsedData = $data;
  }
}