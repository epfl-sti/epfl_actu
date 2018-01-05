<?php

namespace EPFL\Persons;

/* Only included from Person.php, so assume function_exists("___") */

class LDAPException extends \Exception { }
class LDAPUnsupportedException extends \Exception {
    function as_text () {
        return ___("This PHP doesn't support LDAP");
    }
}

/**
 * LDAP client for the EPFL directory
 */

class LDAPClient 
{
    const LDAP_SERVER = "ldap.epfl.ch";
    const BASE_DN     = "o=epfl,c=ch";
    const SEARCH_PERSON_BY_SCIPER = "(&(objectClass=person)(uniqueIdentifier=%d))";

    static function query_by_sciper ($sciper) {
        self::_require_ldap();
        $ld = @ldap_connect(self::LDAP_SERVER);
        if (! ($ld && @ldap_bind($ld))) {
            throw new LDAPException(
                sprintf(___('Unable to bind to LDAP server %s (error: %s)'),
                        self::LDAP_SERVER,
                        ldap_error($ld)));
        }

        $results = @ldap_search($ld, self::BASE_DN,
                               sprintf(self::SEARCH_PERSON_BY_SCIPER, $sciper));
        if (! $results) {
            throw new LDAPException(sprintf(
                ___("ldap_search error: %s"), ldap_error($ld)));
        }
        $entries = @ldap_get_entries($ld, $results);
        if (! $entries) {
            throw new LDAPException(sprintf(
                ___("ldap_get_entries error: %s"), ldap_error($ld)));
        }
        return $entries;
    }

    static private function _require_ldap () {
        if (! function_exists("ldap_connect")) {
            throw new LDAPUnsupportedException();
        }
    }
}
