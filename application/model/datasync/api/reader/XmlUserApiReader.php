<?php

ClassLoader::import('application.model.datasync.api.reader.ApiReader');

/**
 * User model API XML format request parsing (reading/routing)
 *
 * @package application.model.datasync
 * @author Integry Systems <http://integry.com>
 */

class XmlUserApiReader extends ApiReader
{
	protected $xmlKeyToApiActionMapping = array
	(
		'list' => 'filter'
	);

	public static function canParse(Request $request)
	{
		$get = $request->getRawGet();
		if(array_key_exists('xml',$get))
		{
			$xml = self::getSanitizedSimpleXml($get['xml']);
			if($xml != null)
			{
				if(count($xml->xpath('/request/customer')) == 1)
				{
					$request->set(ApiReader::API_PARSER_DATA ,$xml);
					$request->set(ApiReader::API_PARSER_CLASS_NAME, __CLASS__);
					return true;
				}
			}
		}
		return false;
	}

	public function populate($updater, $profile)
	{
		parent::populate($updater, $profile, $this->xml, 
			'/request/customer/[[API_ACTION_NAME]]/[[API_FIELD_NAME]]', array('ID','email'));
	}
	
	public function getARSelectFilter()
	{
		$ormClassName = 'User';
		$ormFieldNames = $this->getApiFieldNames();
		foreach($ormFieldNames as $fieldName)
		{
			$list[$fieldName] = array(
				self::AR_FIELD_HANDLE => new ARFieldHandle($ormClassName, $fieldName),
				self::AR_CONDITION => 'LikeCond'
			);
		}
		$list = array_merge($list, $this->getExtraFilteringMapping());
		$arsf = new ARSelectFilter();
		$filterKeys = array_keys($list);
		foreach($filterKeys as $key)
		{
			$data = $this->xml->xpath('//filter/'.$key);
			while(count($data) > 0)
			{
				$value = (string)array_shift($data);
				$arsf->mergeCondition(
					new $list[$key][self::AR_CONDITION](
						$list[$key][self::AR_FIELD_HANDLE],						
						$this->sanitizeFilterField($key, $value)
					)
				);
			}
		}
		return $arsf;
	}
	
	public function getExtraFilteringMapping()
	{
		if(count($this->extraFilteringMapping) == 0)
		{
			$this->extraFilteringMapping = array(
				'id' => array(self::AR_FIELD_HANDLE=>f('User.ID'), self::AR_CONDITION=>'EqualsCond'),
				'name' => array(
					self::AR_FIELD_HANDLE => new ARExpressionHandle("CONCAT(User.firstName,' ',User.lastName)"),
					self::AR_CONDITION => 'LikeCond'),
				'created' => array(self::AR_FIELD_HANDLE => f('User.dateCreated'), self::AR_CONDITION => 'EqualsCond'),
				'enabled' => array(self::AR_FIELD_HANDLE => f('User.isEnabled'), self::AR_CONDITION => 'EqualsCond')
			);
		}
		return $this->extraFilteringMapping;
	}

	public function sanitizeFilterField($name, &$value)
	{
		switch($name)
		{
			case 'enabled':
				$value = in_array(strtolower($value), array('y','t','yes','true','1')) ? true : false;
				break;
		}
		return $value;
	}

	protected function findApiActionName($xml)
	{
		return parent::findApiActionNameFromXml($xml, '/request/customer');
	}

	public function loadDataInRequest($request)
	{
		$apiActionName = $this->getApiActionName();
		$shortFormatActions = array('get','delete'); // like <customer><delete>[customer id]</delete></customer>
		if(in_array($apiActionName, $shortFormatActions))
		{
			$request = parent::loadDataInRequest($request, '//', $shortFormatActions);
			$request->set('ID',$request->get($apiActionName));
			$request->remove($apiActionName);
		} else {
			$request = parent::loadDataInRequest($request, '/request/customer//', $this->getApiFieldNames());
		}
		return $request;
	}
}
