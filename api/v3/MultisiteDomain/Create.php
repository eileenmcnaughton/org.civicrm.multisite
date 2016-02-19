<?php

/**
 * Create a new domain - with a domain group.
 *
 * This is not fully developed & need to work on creating admin menus etc
 *
 * @param array $params
 *
 * @return array
 * @example DomainCreate.php
 * {@getfields domain_create}
 */
function civicrm_api3_multisite_domain_create($params) {
  $transaction = new CRM_Core_Transaction();
  if (empty($params['contact_id'])) {
    $params['contact_id'] = _civicrm_api3_multisite_domain_create_if_not_exists('Contact', array(
      'organization_name' => $params['name'],
      'contact_type' => 'organization',
    ));
  }
  $domain = civicrm_api('domain', 'getsingle', array(
    'version' => 3,
    'current_domain' => TRUE,
  ));
  $fullParams = array_merge($domain, $params);
  $fullParams['domain_version'] = $domain['version'];
  $fullParams['version'] = 3;
  unset($fullParams['id']);
  if (empty($params['group_id'])) {
    $groupParams = array('title' => !empty($params['group_name']) ? $params['group_name'] : $params['name']);
    $group = civicrm_api3('Group', 'get', array_merge($groupParams, array('options' => array('limit' => 1))));
    if (empty($group['id'])) {
      $group = civicrm_api3('Group', 'create', $groupParams);
    }
    $domainGroupID = $group['id'];
  }
  else {
    $domainGroupID = $params['group_id'];
  }

  _civicrm_api3_multisite_domain_create_if_not_exists('GroupOrganization', array(
    'group_id' => $domainGroupID,
    'organization_id' => $params['contact_id'],
  ));

  $domainID = _civicrm_api3_multisite_domain_create_if_not_exists('domain', $fullParams);
  if (!$domainID || ($domainID != civicrm_api3('Domain', 'getvalue', array('name' => $params['name'], 'return' => 'id')))) {
    throw new CiviCRM_API3_Exception('Failed to create domain', 'unknown');
  }

  $transaction->commit();
  if (!civicrm_api3('Navigation', 'getcount', array('domain_id' => $domainID))) {
    _civicrm_load_navigation($params['name'], $domainID);
  }
  civicrm_api3('Setting', 'create', array(
    'is_enabled' => TRUE,
    'domain_group_id' => $domainGroupID,
    'domain_id' => $domainID,
  ));
  return civicrm_api3_create_success(array($domainID => array('id' => $domainID)));
}

/**
 * Check if entity exists, otherwise create it.
 *
 * @param string $entity
 * @param array $params
 *
 * @return mixed
 * @throws \CiviCRM_API3_Exception
 */
function _civicrm_api3_multisite_domain_create_if_not_exists($entity, $params) {
  $result = civicrm_api3($entity, 'get', array_merge($params, array('options' => array('limit' => 1))));
  if (empty($result['id'])) {
    $result = civicrm_api3($entity, 'create', $params);
  }
  return $result['id'];
}

/**
 * Load the navigation sql for the domain with the given name.
 *
 * @param string $domainName
 * @param int $domainID
 */
function _civicrm_load_navigation($domainName, $domainID) {
  global $civicrm_root;

  $sqlPath = $civicrm_root . DIRECTORY_SEPARATOR . 'sql';
  $config = CRM_Core_Config::singleton();
  $generatedFile = $config->uploadDir . DIRECTORY_SEPARATOR . str_replace(' ', '_', $domainName) . 'nav.mysql';

  //read the entire string
  $str = file_get_contents($sqlPath . DIRECTORY_SEPARATOR . 'civicrm_navigation.mysql');
  $str = str_replace('SELECT @domainID := id FROM civicrm_domain where name = \'Default Domain Name\'', "SELECT @domainID := $domainID", $str);
  file_put_contents($generatedFile, $str);
  CRM_Utils_File::sourceSQLFile($config->dsn,
    $generatedFile, NULL, FALSE
  );
}

/**
 * Adjust Metadata for Create action.
 *
 * The metadata is used for setting defaults, documentation & validation
 *
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_multisite_domain_create_spec(&$params) {
  $params['name']['api.required'] = 1;
  $params['group_title'] = array(
    'title' => 'name of group to be created',
  );
  $params['group_id'] = array(
    'title' => 'id of existing group for domain',
    'description' => 'If not populated another will be created using the name',
  );
  $params['contact_id'] = array(
    'title' => 'id of existing contact for domain',
    'description' => 'If not populated another will be created using the name',
  );
  $params['is_transactional'] = array(
    'api.required' => 1,
    'title' => 'Use transactions (must be FALSE)',
    'description' => 'Set this to 0 or it will fail. Have not managed to do it within the api without the wrapper ignoring',
  );
}
/**
 * Mechanism for converting a nested group to one that uses the group organization
 * to determine which groups to display
 */
function civicrm_api_multisite_unnest(){
  /*
  * @todo how to run denest sql file
  *
  */
}
