<?php

require_once 'multisite.civix.php';

use CRM_Multisite_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function multisite_civicrm_config(&$config) {
  _multisite_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 */
function multisite_civicrm_xmlMenu(&$files) {
  _multisite_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 */
function multisite_civicrm_install() {
  return _multisite_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_uninstall().
 */
function multisite_civicrm_uninstall() {
  return _multisite_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 */
function multisite_civicrm_enable() {
  return _multisite_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 */
function multisite_civicrm_disable() {
  return _multisite_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 */
function multisite_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _multisite_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 */
function multisite_civicrm_managed(&$entities) {
  _multisite_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_container().
 */
function multisite_civicrm_container(\Symfony\Component\DependencyInjection\ContainerBuilder $container) {
  $container->setDefinition("cache.decendantGroups", new Symfony\Component\DependencyInjection\Definition(
    'CRM_Utils_Cache_Interface',
    [
      [
        'name' => 'descendant groups for org',
        'type' => ['*memory*', 'SqlGroup', 'ArrayCache'],
        'withArray' => 'fast',
      ],
    ]
  ))->setFactory('CRM_Utils_Cache::create');
}

/**
 * Implements hook_civicrm_validate_form().
 *
 *  Make parents optional for administrators when
 *  organization id is set
 *
 * @param string $formName - Name of the form being validated, you will typically switch off this value.
 * @param array $fields - Array of name value pairs for all 'POST'ed form values
 * @param array $files - Array of file properties as sent by PHP POST protocol
 * @param object $form - Reference to the civicrm form object. This is useful if you want to retrieve any values that we've constructed in the form
 * @param array $errors - Reference to the errors array. All errors will be added to this array
 * Returns TRUE if form validates successfully, otherwise array with input field names as keys and error message strings as values
 */
function multisite_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ((!isset($fields['organization_id']) && !empty($form->_entityId))) {
    try {
      //$fields['group_organization']
      $fields['group_organization'] = civicrm_api3('group_organization', 'getvalue', ['group_id' => $form->_entityId, 'return' => 'id']);
      $form->setElementError('parents', NULL);
    }
    catch (Exception $e) {
    }
  }
  if (!empty($fields['organization_id']) || !empty($fields['group_organization'])) {
    $form->setElementError('parents', NULL);
  }
}

/**
 * Implements hook civicrm_pre().
 *
 * @param string $op
 * @param string $objectName
 * @param int $id
 * @param array $params
 */
function multisite_civicrm_pre($op, $objectName, $id, &$params) {
  // allow setting of org instead of parent
  if ($objectName == 'Group') {
    if (empty($params['parents'])) {
      // if parents left empty we need to fill organization_id (if not filled)
      // and set no parent. We don't want Civi doing this on our behalf
      // as we assume admin users can make sensible choices on nesting
      // & the default should be the org link
      $params['no_parent'] = 1;
    }
    if (empty($params['organization_id'])) {
      $params['organization_id'] = _multisite_get_domain_organization(TRUE);
    }
  }
}

/**
 * Implements hook_civicrm_post().
 *
 * Current implementation assumes shared user table for all sites -
 * a more sophisticated version will be able to cope with a combination of shared user tables
 * and separate user tables
 *
 * @param string $op
 * @param string $objectName
 * @param int $objectId
 * @param object $objectRef
 */
function multisite_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  if ($op == 'edit' && $objectName == 'UFMatch') {
    static $updating = FALSE;
    if ($updating) {
      // prevent recursion
      return;
    }
    $updating = TRUE;
    $ufs = civicrm_api('uf_match', 'get', [
      'version' => 3,
      'contact_id' => $objectRef->contact_id,
      'uf_id' => $objectRef->uf_id,
      'id' => [
        '!=' => $objectRef->id,
      ],
    ]);
    foreach ($ufs['values'] as $ufMatch) {
      civicrm_api('UFMatch', 'create', [
        'version' => 3,
        'id' => $ufMatch['id'],
        'uf_name' => $objectRef->uf_name,
      ]);
    }
  }
}

/**
 * Implements ACLGroup hook().
 *
 * aclGroup function returns a list of groups which are either children of the
 * domain group id or connected to the same organisation as the domain Group ID
 *
 * @param string $type
 * @param int $contactID
 * @param string $tableName
 * @param array $allGroups
 * @param array $currentGroups
 *
 * @throws \CiviCRM_API3_Exception
 */
function multisite_civicrm_aclGroup($type, $contactID, $tableName, &$allGroups, &$currentGroups) {
  // only process saved search
  if ($tableName != 'civicrm_saved_search') {
    return;
  }
  $isEnabled = civicrm_api('setting', 'getvalue', [
      'version' => 3,
      'name' => 'is_enabled',
      'group' => 'Multi Site Preferences',
    ]
  );
  $groupID = _multisite_get_domain_group();
  // If multisite is not enabled, or if a domain group is not selected, then we default to all groups allowed
  if (!$isEnabled || !$groupID) {
    $currentGroups = array_keys($allGroups);
    return;
  }
  if (!CRM_Core_Permission::check('list all groups in domain') && !_multisite_add_permissions($type)) {
    return;
  }
  $currentGroups = _multisite_get_all_child_groups($groupID, FALSE);
  $currentGroups = array_merge($currentGroups, _multisite_get_domain_groups($groupID));
  $disabledGroups = [];
  $disabled = civicrm_api3('group', 'get', [
    'is_active' => 0,
    'check_permissions' => FALSE,
    'return' => 'id',
    'sequential' => 1,
    'options' => ['limit' => 0],
  ]);
  foreach ($disabled['values'] as $group) {
    $disabledGroups[] = (int) $group['id'];
  }
  if (!empty($allGroups)) {
    //all groups is empty if we really mean all groups but if a filter like 'is_disabled' is already applied
    // it is populated, ajax calls from Manage Groups will leave empty but calls from New Mailing pass in a filtered list
    $originalCurrentGroups = $currentGroups;
    $currentGroups = array_intersect($currentGroups, array_keys($allGroups));
    $currentGroups = array_merge($currentGroups, array_intersect($originalCurrentGroups, $disabledGroups));
  }
}

/**
 * Implements selectWhereClause hook().
 *
 * selectWhereClause restricts group selection to those which are either
 * children of the domain group id or connected to the same organisation as the
 * domain Group ID
 *
 * @param string $entity
 * @param array $clauses
 */
function multisite_civicrm_selectWhereClause($entity, &$clauses) {
  // Only process groups, only without "view all contacts" permission.
  if ($entity !== 'Group' || !(_multisite_is_permission()) || CRM_Core_Permission::check('view all contacts')) {
    return;
  }

  $isEnabled = civicrm_api('setting', 'getvalue', [
    'version' => 3,
    'name' => 'is_enabled',
    'group' => 'Multi Site Preferences',
  ]);
  $groupID = _multisite_get_domain_group();
  // If multisite is not enabled, or if a domain group is not selected, then we default to all groups allowed
  if (!$isEnabled || !$groupID) {
    return;
  }
  if (!CRM_Core_Permission::check('list all groups in domain') && !_multisite_add_permissions($type)) {
    return;
  }
  $currentGroups = _multisite_get_all_child_groups($groupID, FALSE);
  $currentGroups = array_merge($currentGroups, _multisite_get_domain_groups($groupID));

  $groups_list = array_unique($currentGroups);

  // Don't crash if there's no groups....
  if (empty($groups_list)) {
    $groups_list = [0];
  }

  $clauses['id'][] = 'IN (' . implode(',', $groups_list) . ')';
}

/**
 *
 * @param string $type
 * @param array $tables tables to be included in query
 * @param array $whereTables tables required for where portion of query
 * @param int $contactID contact for whom where clause is being composed
 * @param string $where Where clause The completed clause will look like
 *   (multisiteGroupTable.group_id IN ("1,2,3,4,5") AND multisiteGroupTable.status IN ('Added') AND contact_a.is_deleted = 0)
 *   where the child groups are groups the contact is potentially a member of
 *
 */
function multisite_civicrm_aclWhereClause($type, &$tables, &$whereTables, &$contactID, &$where) {
  if (!$contactID) {
    return;
  }
  if (!_multisite_add_permissions($type)) {
    return;
  }
  $groupID = _multisite_get_domain_group();
  if (!$groupID) {
    return;
  }
  $childOrganizations = _multisite_get_all_child_groups($groupID);
  if (!empty($childOrganizations)) {
    $groupTable = 'civicrm_group_contact';
    $groupTableAlias = 'multisiteGroupTable';
    $tables[$groupTableAlias] = $whereTables[$groupTableAlias] = "
      LEFT JOIN {$groupTable} $groupTableAlias ON contact_a.id = {$groupTableAlias}.contact_id
    ";
    if (!empty($where)) {
      $where .= ' AND ';
    }
    $deletedContactClause = CRM_Core_Permission::check('access deleted contacts') ? '' : 'AND contact_a.is_deleted = 0';
    $where .= "(multisiteGroupTable.group_id IN (" . implode(',', $childOrganizations) . ") AND {$groupTableAlias}.status IN ('Added') $deletedContactClause)";
  }
}

/**
 * Add site specific tabs.
 *
 * @param array $tabs
 * @param int $contactID
 *
 * @throws \CiviCRM_API3_Exception
 */
function multisite_civicrm_tabs(&$tabs, $contactID) {
  $enabled = civicrm_api3('setting', 'getvalue', ['group' => 'Multi Site Preferences', 'name' => 'multisite_custom_tabs_restricted']);
  if (!$enabled) {
    return;
  }
  $tabs_visible = civicrm_api3('setting', 'getvalue', ['group' => 'Multi Site Preferences', 'name' => 'multisite_custom_tabs_enabled']);

  foreach ($tabs as $id => $tab) {
    if (stristr($tab['id'], 'custom_')) {
      $tab_id = str_replace('custom_', '', $tab['id']);
      if (!in_array($tab_id, $tabs_visible)) {
        unset($tabs[$id]);
      }
    }
  }
}

/**
 * invoke permissions hook
 * note that permissions hook is now permission hook
 *
 * @param array $permissions
 */
function multisite_civicrm_permissions(&$permissions) {
  multisite_civicrm_permission($permissions);
}

/**
 * invoke permissions hook
 *
 * @param array $permissions
 */
function multisite_civicrm_permission(&$permissions) {
  $permissions += [
    'view all contacts in domain' => E::ts('CiviCRM Multisite: view all contacts in domain'),
    'edit all contacts in domain' => E::ts('CiviCRM Multisite: edit all contacts in domain'),
    'list all groups in domain' => E::ts('CiviCRM Multisite: list all groups in domain'),
  ];
}

/**
 * Implements hook_civicrm_permission_check().
 */
function multisite_civicrm_permission_check($permission, &$granted) {
  $isEnabled = civicrm_api('setting', 'getvalue', [
      'version' => 3,
      'name' => 'is_enabled',
      'group' => 'Multi Site Preferences',
    ]
  );
  if ($isEnabled == 0) {
    // Multisite ACLs are not enabled, so 'view all contacts in domain' cascades to 'view all contacts'
    // and the same is true for 'edit all contacts' - cf. CRM-19256
    if ($permission === 'view all contacts' && CRM_Core_Permission::check('view all contacts in domain')) {
      $granted = TRUE;
    }
    elseif ($permission === 'edit all contacts' && CRM_Core_Permission::check('edit all contacts in domain')) {
      $granted = TRUE;
    }
  }
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 */
function multisite_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _multisite_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Get all groups that are children of the parent group
 * (iterate through all levels)
 *
 * @param int $groupID
 * @param bool $includeParent
 *
 * @return array:child groups
 */
function _multisite_get_all_child_groups($groupID, $includeParent = TRUE) {
  static $_cache = [];

  $groupID = (string) $groupID;
  $cache = Civi::cache('decendantGroups');
  if (!array_key_exists($groupID, $_cache)) {
    $childGroups = $cache->get($groupID);

    if (empty($childGroups)) {
      $childGroups = [];

      $query = "
SELECT children
FROM   civicrm_group
WHERE  children IS NOT NULL
AND    id IN ";

      if (!is_array($groupID)) {
        $groupIDs = [
          $groupID,
        ];
      }

      while (!empty($groupIDs)) {
        $groupIDString = implode(',', $groupIDs);

        $realQuery = $query . " ( $groupIDString )";
        $dao = CRM_Core_DAO::executeQuery($realQuery);
        $groupIDs = [];
        while ($dao->fetch()) {
          if ($dao->children) {
            $childIDs = explode(',', $dao->children);
            foreach ($childIDs as $childID) {
              if (!array_key_exists($childID, $childGroups)) {
                $childGroups[$childID] = 1;
                $groupIDs[] = $childID;
              }
            }
          }
        }
      }

      $cache->set($groupID, $childGroups);
    }
    $_cache[$groupID] = $childGroups;
  }

  if ($includeParent || CRM_Core_Permission::check('administer Multiple Organizations')) {
    return array_keys([
        $groupID => 1,
      ] + $_cache[$groupID]);
  }
  return array_keys($_cache[$groupID]);
}

/**
 * Get groups linked to the domain via the group organization
 * being shared with the domain group
 *
 * @param $groupID
 *
 * @return array
 */
function _multisite_get_domain_groups($groupID) {
  $sql = " SELECT o2.group_id as group_id
           FROM civicrm_group_organization o
           INNER JOIN civicrm_group_organization o2 ON o.organization_id = o2.organization_id
           AND o.group_id = $groupID AND o2.group_id <> $groupID
      ";
  $dao = CRM_Core_DAO::executeQuery($sql);
  $groups = [];
  while ($dao->fetch()) {
    $groups[] = (int) $dao->group_id;
  }
  return $groups;
}

/**
 *
 * @param bool $permission
 *
 * @return NULL|int $groupID
 */
function _multisite_get_domain_group($permission = TRUE) {
  $groupID = CRM_Core_BAO_Domain::getGroupId();
  if (empty($groupID) || !is_numeric($groupID)) {
    /* domain group not defined - we could let people know but
     * it is acceptable for some domains not to be in the multisite
     * so should probably check enabled before we spring an error
     */
    return NULL;
  }
  // We will check for the possibility of the acl_enabled setting being deliberately set to 0
  if ($permission) {
    $aclsEnabled = civicrm_api('setting', 'getvalue', [
        'version' => 3,
        'name' => 'multisite_acl_enabled',
        'group' => 'Multi Site Preferences',
      ]
    );
    if (is_numeric($aclsEnabled) && !$aclsEnabled) {
      return NULL;
    }
  }

  return (int) $groupID;
}

/**
 * Get organization of domain group.
 *
 * @param bool $permission
 *
 * @return bool|int
 */
function _multisite_get_domain_organization($permission = TRUE) {
  $groupID = _multisite_get_domain_group($permission);
  if (!$groupID) {
    return FALSE;
  }
  return (int) civicrm_api('group_organization', 'getvalue', [
    'version' => 3,
    'group_id' => $groupID,
    'return' => 'organization_id',
  ]);
}

/**
 * Should we be adding ACLs in this instance.
 *
 * If we don't add them the user will not be able to see anything.
 * We check if the install has the permissions
 * hook implemented correctly & if so only allow view & edit based on those.
 *
 * Otherwise all users get these permissions added (4.2 vs 4.3 / other CMS issues)
 *
 * @param int $type type of operation
 *
 * @return bool
 */
function _multisite_add_permissions($type) {
  if ($type === 'group') {
    // @fixme only handling we have for this at the moment
    return TRUE;
  }
  // extra check to make sure that hook is properly implemented
  // if not we won't check for it. NB view all contacts in domain is enough checking
  $declaredPermissions = CRM_Core_Permission::basicPermissions();
  if (!array_key_exists('view all contacts in domain', $declaredPermissions)) {
    return TRUE;
  }

  if ($type == CRM_ACL_API::VIEW && CRM_Core_Permission::check('view all contacts in domain')) {
    return TRUE;
  }

  if (($type == CRM_ACL_API::VIEW || $type == CRM_ACL_API::EDIT) && CRM_Core_Permission::check('edit all contacts in domain')) {
    return TRUE;
  }
  return FALSE;
}

/**
 * Implements buildForm hook().
 *
 * http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm
 * @param string $formName
 * @param object $form reference to the form object
 */
function multisite_civicrm_buildForm($formName, &$form) {
  if ($formName == 'CRM_Group_Form_Edit' || $formName == 'CRM_Contact_Form_Task_SaveSearch') {
    _multisite_alter_form_crm_group_form_edit($formName, $form);
  }
}

/**
 * Called from buildForm hook.
 *
 * http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm
 *
 * @param string $formName
 * @param object $form reference to the form object
 */
function _multisite_alter_form_crm_group_form_edit($formName, &$form) {
  if (isset($form->_defaultValues['parents'])) {
    $parentOrgs = civicrm_api('group_organization', 'get', [
      'version' => 3,
      'group_id' => $form->_defaultValues['parents'],
      'return' => 'organization_id',
      'sequential' => 1,
    ]);
    if ($parentOrgs['count'] == 1) {
      $groupOrg = $parentOrgs['values'][0]['organization_id'];
      $defaults['organization_id'] = $groupOrg;
      $defaults['organization'] = civicrm_api('contact', 'getvalue', [
        'version' => 3,
        'id' => $groupOrg,
        'return' => 'display_name',
      ]);
      $defaults['parents'] = "";
      $form->setDefaults($defaults);
    }
  }
  unset($form->_required[2]);
  unset($form->_rules['parents']);
}

/**
 * Implements hook_civicrm_alterAPIPermissions().
 *
 * http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterAPIPermissions
 * @param string $entity
 * @param string $action
 * @param array &$params
 * @param array &$permissions
 */
function multisite_civicrm_alterAPIPermissions($entity, $action, &$params, &$permissions) {
  _multisite_is_permission(TRUE);

  $domain_id = CRM_Core_Config::domainID();
  if ($domain_id !== 1) {
    $entities = [
      'address',
      'email',
      'phone',
      'website',
      'im',
      'loc_block',
      'entity_tag',
      'relationship',
      'group_contact',
    ];

    foreach ($entities as $entity) {
      $permissions[$entity]['default'] = [
        'access CiviCRM',
        'edit all contacts in domain',
      ];

      $permissions[$entity]['get'] = ['access CiviCRM'];
    }

    $permissions['relationship']['delete'] = [
      'access CiviCRM',
      'edit all contacts in domain',
    ];

    $permissions['contact']['update'] = [
      'access CiviCRM',
      'edit all contacts in domain',
    ];

    $permissions['group_contact']['delete'] = [
      'access CiviCRM',
      'edit all contacts in domain',
    ];
  }
}

/**
 * Are we checking if we are in multisite permission
 *
 * @param bool $check
 *
 * @return bool
 */
function _multisite_is_permission($check = NULL) {
  static $checking = FALSE;

  if (isset($check)) {
    $checking = $check;
  }

  return $checking;
}

/**
 * Add in mailing API wrapper
 *
 * @param array $wrappers
 * @param array $apiRequest
 */
function multisite_civicrm_apiWrappers(&$wrappers, $apiRequest) {
  if ($apiRequest['entity'] == 'Mailing' && $apiRequest['action'] == 'getlist') {
    $wrappers[] = new CRM_Multisite_MailingWrapper();
  }
}

/**
 * Implements hook_civicrm_alterEntityRefParams().
 *
 * Alters Entity reference api params for MembershipType to ignore any domain_id filter
 * So that the current behaviour continues
 */
function multisite_civicrm_alterEntityRefParams(&$props = [], $formName) {
  if ($props['entity'] == 'MembershipType') {
    if (!empty($props['api'])) {
      if (!empty($props['api']['params'])) {
        $props['api']['params']['domain_id'] = NULL;
      }
      else {
        $props['api']['params'] = ['domain_id' => NULL];
      }
    }
    else {
      $props['api'] = ['params' => ['domain_id' => NULL]];
    }
  }
}
