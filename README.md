=== Multisite extension for CiviCRM
=====================

This extension adds the ACLS that allow a contact to only see contacts and groups associated with the domain (by
virtue of them being in the domain group - either directly or via a group connected to the same organisation).
The domain group is configured through the administration menu within CiviCRM under System Settings/Multisite Settings. 

Note that previous versions of the multisite extension made heavy use of Group Nesting. This is no longer recommended
for performance reasons. Avoid group nesting where possible.

=== Adding new domains
To add new domains use the MultisiteDomain.create api e.g

drush cvapi MultisiteDomain.create debug=1 sequential=1 name="Bobita"

You also need to ensure that the right domain ID is defined - e.g you could put something like this in your civicrm.settings.php

 switch ($url) {
    case 'http://site1.org':
      define( 'CIVICRM_DOMAIN_ID', 1 );
      break;
    case 'http://site2.org' :
      define( 'CIVICRM_DOMAIN_ID', 2 );
      break;
    case 'http://site3.org':
      define( 'CIVICRM_DOMAIN_ID', 4 );
      break;
    case 'http://site4.org':
      define( 'CIVICRM_DOMAIN_ID', 5 );
      break;
    default:
      echo "The world just fell apart";
   }

