<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 Claus Due <claus@wildside.dk>, Wildside A/S
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

/**
 * FlexForm integration Service
 *
 * Capable of returning instances of DomainObjects or ObjectStorage from
 * FlexForm field values if the type of field is a database relation and the
 * table it uses is one associated with Extbase.
 *
 * @package Flux
 * @subpackage Service
 */
class Tx_Flux_Service_FlexForm extends Tx_Extbase_Service_FlexFormService implements t3lib_Singleton {

	/**
	 * @var string
	 */
	protected $raw;

	/**
	 * @var array
	 */
	protected $contentObjectData;

	/**
	 *
	 * @var Tx_Extbase_Configuration_ConfigurationManagerInterface
	 */
	protected $configurationManager;

	/**
	 * @var Tx_Extbase_Object_ObjectManager
	 */
	protected $objectManager;

	/**
	 * @var Tx_Extbase_Property_Mapper Tx_Extbase_Property_Mapper
	 */
	protected $propertyMapper;

	/**
	 * @var Tx_Extbase_Reflection_Service
	 */
	protected $reflectionService;

	/**
	 * @var Tx_Flux_Service_FluidFlexFormTemplateValidator
	 */
	protected $fluidFlexFormTemplateValidator;

	/**
	 * @param Tx_Extbase_Configuration_ConfigurationManagerInterface $configurationManager
	 * @return void
	 */
	public function injectConfigurationManager(Tx_Extbase_Configuration_ConfigurationManagerInterface $configurationManager) {
		$this->configurationManager = $configurationManager;
	}

	/**
	 * @param Tx_Extbase_Object_ObjectManager $objectManager
	 * @return void
	 */
	public function injectObjectManager(Tx_Extbase_Object_ObjectManager $objectManager) {
		$this->objectManager = $objectManager;
	}

	/**
	 * @param Tx_Extbase_Property_Mapper $propertyMapper
	 * @return void
	 */
	public function injectPropertyMapper(Tx_Extbase_Property_Mapper $propertyMapper) {
		$this->propertyMapper = $propertyMapper;
	}

	/**
	 * @param Tx_Extbase_Reflection_Service $reflectionService
	 * @return void
	 */
	public function injectReflectionService(Tx_Extbase_Reflection_Service $reflectionService) {
		$this->reflectionService = $reflectionService;
	}

	/**
	 * @param Tx_Flux_Service_FluidFlexFormTemplateValidator $fluidFlexFormTemplateValidator
	 * @return void
	 */
	public function injectFluidFlexFormTemplateValidatorService(Tx_Flux_Service_FluidFlexFormTemplateValidator $fluidFlexFormTemplateValidator) {
		$this->fluidFlexFormTemplateValidator = $fluidFlexFormTemplateValidator;
	}

	/**
	 * Initialization
	 * @return void
	 */
	public function initializeObject() {
		$contentObject = $this->configurationManager->getContentObject();
		$this->contentObjectData = $contentObject->data;
		$this->raw = $this->contentObjectData['pi_flexform'];
	}

	/**
	 * @param array $data
	 * @return Tx_Flux_Service_FlexForm
	 */
	public function setContentObjectData($data) {
		if (is_array($data) === FALSE) {
			throw new Exception('Content object data was not an array; please check the type before passing it to the FlexForm Service', 1355433372);
		}
		$this->contentObjectData = $data;
		$this->raw = $this->contentObjectData['pi_flexform'];
		return $this;
	}

	/**
	 * Uses "transform" property on each member of $fieldArrayContainingType to
	 * properly type-cast each value before returning
	 *
	 * @param array $fieldArrayContainingTypes
	 * @return array
	 */
	public function getAllAndTransform($fieldArrayContainingTypes) {
		$all = $this->getAll();
		foreach ($fieldArrayContainingTypes as $fieldConfiguration) {
			$transformType = $fieldConfiguration['transform'];
			if ($transformType) {
				$fieldName = $fieldConfiguration['name'];
				$path = explode('.', $fieldName);
				$current =& $all;
				while ($key = array_shift($path)) {
					$current =& $current[$key];
				}
				$current = $this->digDownTransform($all, explode('.', $fieldName), $transformType);
			}
		}
		return (array) $all;
	}

	/**
	 * Digs down path to transform final member to $dataType
	 *
	 * @param mixed $all
	 * @param array $keysLeft
	 * @param string $transformType
	 * @return mixed
	 */
	protected function digDownTransform($all, $keysLeft, $transformType) {
		$current =& $all;
		while ($key = array_shift($keysLeft)) {
			$current =& $current[$key];
		}
		return $this->transform($current, $transformType);
	}

	/**
	 * Transforms a single value to $dataType
	 *
	 * @param string $value
	 * @param string $dataType
	 * @return mixed
	 */
	protected function transform($value, $dataType) {
		if ($dataType == 'int' || $dataType == 'integer') {
			return intval($value);
		} else if ($dataType == 'float') {
			return floatval($value);
		} else if ($dataType == 'array') {
			return explode(',', $value);
		} else if (strpos($dataType, 'Tx_') === 0) {
			return $this->getObjectOfType($dataType, $value);
		} else {
			return $value;
		}
	}

	/**
	 * Gets a DomainObject or QueryResult of $dataType
	 *
	 * @param string $dataType
	 * @param string $uids
	 * @return mixed
	 */
	protected function getObjectOfType($dataType, $uids) {
		$uids = trim($uids, ',');
		$identifiers = explode(',', $uids);
			// Fast decisions
		if (strpos($dataType, '_Domain_Model_') !== FALSE && strpos($dataType, '<') === FALSE) {
			$repositoryClassName = str_replace('_Model_', '_Repository_', $dataType) . 'Repository';
			if (class_exists($repositoryClassName)) {
				$repository = $this->objectManager->get($repositoryClassName);
				$uid = array_pop($identifiers);
				return $repository->findOneByUid($uid);
			}
		} else if (class_exists($dataType)) {
				// using constructor value to support objects like DateTime
			return $this->objectManager->get($dataType, $uids);
		}
			// slower decisions with support for type-hinted collection objects
		list ($container, $object) = explode('<', trim($dataType, '>'));
		if ($container && $object) {
			if (strpos($object, '_Domain_Model_') !== FALSE && $uids) {
				$repositoryClassName = str_replace('_Model_', '_Repository_', $object) . 'Repository';
				$repository = $this->objectManager->get($repositoryClassName);
				$query = $repository->createQuery();
				$query->matching($query->in('uid', $uids));
				return $query->execute();
			} else {
				$container = $this->objectManager->get($container);
				return $container;
			}
		} else {
				// passthrough; neither object nor type hinted collection object
			return $uids;
		}
	}

	/**
	 * Gets the value of the FlexForm fields.
	 *
	 * @return array
	 * @api
	 */
	public function getAll() {
		return (array) $this->get(NULL);
	}

	/**
	 * Get a single field's value (or all values if no $key given;
	 * getAll() is an alias of get() with no argument)
	 *
	 * @param string $key
	 * @return mixed
	 * @api
	 */
	public function get($key = NULL) {
		if (empty($this->raw) === TRUE) {
			return NULL;
		}
		$languagePointer = 'lDEF';
		$valuePointer = 'vDEF';
		$this->storage = $this->convertFlexFormContentToArray($this->raw, $languagePointer, $valuePointer);
		if ($key === NULL) {
			$arr = $this->storage;
			foreach ($arr as $k => $v) {
				$arr[$k] = $this->get($k);
			}
			return $arr;
		}
		return $this->storage[$key];
	}

	/**
	 * Sets a value back in the flexform. For relational fields supporting
	 * Extbase DomainObjects, the $value may be an ObjectStorage or ModelObject
	 * instance - or the regular, oldschool CSV/UID string value
	 *
	 * @param string $key
	 * @param mixed $value
	 * @return void
	 */
	public function set($key, $value) {
		$this->storage[$key] = $value;
	}

	/**
	 * Write the FlexForm back from whence it came. Returns TRUE/FALSE
	 * on success/failure.
	 *
	 * @return boolean
	 */
	public function save() {
		return FALSE;
	}

	/**
	 * Convert a DataStructure array to FlexForm XML
	 *
	 * @param array $dataStructure
	 * @return string
	 */
	public function convertDataStructureToFlexFormContent($dataStructure) {
		$flexFormTool = new t3lib_flexformtools();
		$xml = $flexFormTool->flexArray2Xml($dataStructure);
		return $xml;
	}

	/**
	 * Gets a stored FlexForm configuration and applies any dynamic values to
	 * create a current representation of the FlexForm sheet+fields array
	 *
	 * @param string $templateFile The absolute filename containing the configuration
	 * @param mixed $values Optional values to use when rendering the configuration
	 * @param string|NULL Optional section name containing the configuration
	 * @throws Exception
	 * @return array
	 */
	public function getFlexFormConfigurationFromFile($templateFile, $values, $section = NULL) {
		if (file_exists($templateFile) === FALSE) {
			$templateFile = t3lib_div::getFileAbsFileName($templateFile);
		}
		if (file_exists($templateFile) === FALSE) {
				// Only process this $dataStructArray if the specified template file exists.
			throw new Exception('Tried to get a FlexForm configuration from a file which does not exist (' . $templateFile . ')', 1343264270);
		}
		/**	@var $view Tx_Flux_MVC_View_ExposedStandaloneView */
		$view = $this->objectManager->create('Tx_Flux_MVC_View_ExposedStandaloneView');
		$view->setTemplatePathAndFilename($templateFile);
		$view->assignMultiple($values);

		$this->fluidFlexFormTemplateValidator->validateFluidFlexFormTemplateFile($templateFile);

		$config = $view->getStoredVariable('Tx_Flux_ViewHelpers_FlexformViewHelper', 'storage', $section);
		return $config;
	}

	/**
	 * Updates $dataStructArray by reference, filling it with a proper data structure
	 * based on the selected template file.
	 *
	 * @param string $templateFile
	 * @param array $values
	 * @param array $paths
	 * @param array $dataStructArray
	 * @param string $section
	 * @throws Exception
	 * @return void
	 */
	public function convertFlexFormContentToDataStructure($templateFile, $values, $paths, &$dataStructArray, $section = NULL) {
		unset($paths);
		if ($templateFile === NULL) {
			$config['parameters'] = array(
				'userFunction' => 'Tx_Flux_UserFunction_NoTemplate->renderField'
			);
			$dataStructArray = $this->objectManager->create('Tx_Flux_Provider_Structure_FallbackStructureProvider')->render($config);
			return;
		}
		try {
			$config = $this->getFlexFormConfigurationFromFile($templateFile, $values, $section);
			/** @var $flexFormStructureProvider Tx_Flux_Provider_Structure_FlexFormStructureProvider */
			$flexFormStructureProvider = $this->objectManager->create('Tx_Flux_Provider_Structure_FlexFormStructureProvider');
			$dataStructArray = $flexFormStructureProvider->render($config);
			if ((is_array($dataStructArray['ROOT']['el']) === FALSE && is_array($dataStructArray['sheets']) === FALSE) || (count($dataStructArray['sheets']) < 1 && count($dataStructArray['ROOT']['el']) < 1 && count($dataStructArray['sheets'][key($dataStructArray['sheets'])]) === 0)) {
				$config['parameters'] = array(
					'userFunction' => 'Tx_Flux_UserFunction_NoFields->renderField'
				);
				$dataStructArray = $this->objectManager->create('Tx_Flux_Provider_Structure_FallbackStructureProvider')->render($config);
			}
		} catch (Exception $e) {
			if ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['flux']['setup']['debugMode'] > 0) {
				throw $e;
			} else {
				t3lib_div::sysLog($e->getMessage(), 'flux');
				$config['parameters'] = array(
					'exception' => $e,
					'userFunction' => 'Tx_Flux_UserFunction_ErrorReporter->renderField'
				);
				if (t3lib_extMgm::isLoaded('templavoila') === FALSE) {
					$dataStructArray = $this->objectManager->create('Tx_Flux_Provider_Structure_FallbackStructureProvider')->render($config);
				}
			}
		}
	}

}
