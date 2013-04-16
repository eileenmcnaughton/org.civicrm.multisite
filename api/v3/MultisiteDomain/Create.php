<?php

/**
 * Create a new domain - with a domain group
 * This is not fully developed & need to work on creating admin menus etc
 *
 * @param array $params
 *
 * @return array
 * @example DomainCreate.php
 * {@getfields domain_create}
 */
function civicrm_api3_multisite_domain_create($params) {
  $domain = civicrm_api('domain', 'getsingle', array(
    'version' => 3,
    'current_domain' => TRUE,
  ));
  $fullParams = array_merge($domain, $params);
  $fullParams['domain_version'] = $domain['version'];
  $fullParams['version'] = 3;
  unset($fullParams['id']);
  if(empty($params['group_id'])){
    $fullParams['api.group.create'] = array(
      'title' => !empty($params['group_name']) ? $params['group_name'] : $params['name'],
    );
    $domainGroupID = '$value.api.group.create.id';
  }
  else{
    $domainGroupID = $params['group_id'];
  }

  $fullParams['api.setting.create'] = array(
      'is_enabled' => TRUE,
      'domain_group_id' => $domainGroupID,
   );

  return civicrm_api('domain', 'create', $fullParams);
}
/*
 * Adjust Metadata for Create action
 *
 * The metadata is used for setting defaults, documentation & validation
 * @param array $params array or parameters determined by getfields
 */
function _civicrm_api3_multisite_domain_create_spec(&$params) {
  $params['name']['api.required'] = 1;
  $params['group_title'] = array(
    'title' => 'name of group to be created',
    );
  $params['group_id'] = array(
    'title' => 'id of existing group for domain',
    'description' => 'If not populated another will be created using the name'
  );
  $params['contact_id'] = array(
    'title' => 'id of existing contact for domain',
    'description' => 'If not populated another will be created using the name'
  );
}
/**
 * Mechanism for converting a nested group to one that uses the group organization
 * to determine which groups to display
 */
function civicrm_api_multisite_unnest(){
  /*
   * 
   
### 
# ensure contacts are members of parent group
#####
INSERT INTO civicrm_group_contact (contact_id, group_id, `status`)
SELECT child_group_contact.contact_id, domain_group.domain_group_id, 'Added'
FROM civicrm_group_organization go RIGHT JOIN (
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) AS domain_group_id,
value, domain_id
FROM civicrm_setting s
WHERE group_name = 'Multi Site Preferences'
AND name = 'domain_group_id'
AND SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) > 0
) as domain_group
ON domain_group.domain_group_id = go.group_id
LEFT JOIN civicrm_group child_group ON go.group_id = child_group.parents
LEFT JOIN civicrm_group_organization cgo ON child_group.id = cgo.group_id
LEFT JOIN civicrm_group_contact child_group_contact ON child_group_contact.group_id = child_group.id AND child_group_contact.`status` = 'Added'
LEFT JOIN civicrm_group_contact parent_group_contact ON domain_group.domain_group_id = parent_group_contact.group_id
AND child_group_contact.contact_id = parent_group_contact.contact_id
WHERE
child_group.id IS NOT NULL
AND cgo.organization_id IS NULL
AND parent_group_contact.id IS NULL
AND child_group_contact.id IS NOT NULL
;

##
# Set Status on parent group to reflect child group
###
UPDATE
 civicrm_group_organization go RIGHT JOIN (
  SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) AS domain_group_id,
  value, domain_id
  FROM civicrm_setting s
  WHERE group_name = 'Multi Site Preferences'
  AND name = 'domain_group_id'
  AND SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) > 0
  ) as domain_group
ON domain_group.domain_group_id = go.group_id
LEFT JOIN civicrm_group child_group ON go.group_id = child_group.parents
LEFT JOIN civicrm_group_organization cgo ON child_group.id = cgo.group_id
LEFT JOIN civicrm_group_contact child_group_contact ON child_group_contact.group_id = child_group.id AND child_group_contact.`status` = 'Added'
LEFT JOIN civicrm_group_contact parent_group_contact ON domain_group.domain_group_id = parent_group_contact.group_id
AND child_group_contact.contact_id = parent_group_contact.contact_id
SET parent_group_contact.`status` = child_group_contact.`status`
WHERE
child_group.id IS NOT NULL
AND cgo.organization_id IS NULL
AND parent_group_contact.`status` <> 'Added'
AND child_group_contact.id IS NOT NULL

;

    INSERT INTO civicrm_group_organization (group_id, organization_id)
  SELECT  g.id as group_id, cgo.organization_id as organization_id
  FROM civicrm_group_organization go RIGHT JOIN (
    SELECT  SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) AS domain_group_id,
	   value,     domain_id
    FROM civicrm_setting s
    WHERE group_name = 'Multi Site Preferences'
      AND name = 'domain_group_id'
      AND SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) > 0
    ) as se
  ON se.domain_group_id = go.group_id
  LEFT JOIN civicrm_group g ON go.group_id = g.parents
  LEFT JOIN civicrm_group_organization cgo ON g.id = cgo.group_id
  WHERE
    g.id IS NOT NULL
    AND cgo.organization_id IS NULL
;

 DELETE gn FROM civicrm_group_nesting gn RIGHT JOIN (
    SELECT  SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) AS domain_group_id,
	   value,     domain_id
    FROM civicrm_setting s
    WHERE group_name = 'Multi Site Preferences'
      AND name = 'domain_group_id'
      AND SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) > 0
    ) as se
  ON se.domain_group_id = gn.parent_group_id
  WHERE child_group_id IS NOT NULL;

  UPDATE civicrm_group g RIGHT JOIN (
    SELECT  SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) AS domain_group_id,
	   value,     domain_id
    FROM civicrm_setting s
    WHERE group_name = 'Multi Site Preferences'
      AND name = 'domain_group_id'
      AND SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) > 0
    ) as se
  ON se.domain_group_id = g.parents
  SET parents = NULL;

    UPDATE civicrm_group g RIGHT JOIN (
    SELECT  SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) AS domain_group_id,
	   value,     domain_id
    FROM civicrm_setting s
    WHERE group_name = 'Multi Site Preferences'
      AND name = 'domain_group_id'
      AND SUBSTRING_INDEX(SUBSTRING_INDEX(value,'";',1),':"',-1) > 0
    ) as se
  ON se.domain_group_id = g.id
  SET children = NULL;

truncate civicrm_cache;
   */
}
