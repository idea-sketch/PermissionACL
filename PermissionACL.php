<?php
 
if( !defined( 'MEDIAWIKI' ) ) {
    echo("This file is an extension to the MediaWiki software and cannot be used standalone.\n");
    die(1);
}
 
$wgExtensionCredits['other'][] = array(
        'name' => 'PermissionACL',
        'author' => 'Jan Vavříček',
        'url' => 'https://mediawiki.org/wiki/Extension:PermissionACL',
        'description' => 'Provides a permissions access control list',
);
 
$wgHooks['userCan'][] = 'PermissionACL_userCan';
 
function PermissionACL_userCan($title, $user, $action, &$result) {
    global $wgPermissionACL, $wgPermissionACL_Superuser, $wgWhitelistRead;
 
    $result = NULL;
 
    if(!isset($wgPermissionACL)) return TRUE; //if not set - grant access
 
    if($title->isCssJsSubpage()) return TRUE;
 
    if($action == 'read' && is_array($wgWhitelistRead)) { //don't continue if - read action on whitelisted pages
        if(in_array($title->getPrefixedText(), @$wgWhitelistRead)) {
            $result = TRUE;
            return TRUE;
        }//if-in_array
    }//if-whitelist
 
    if(isset($wgPermissionACL_Superuser)) { //Superuser can do everything - no need to read ACLs
        if(is_array($wgPermissionACL_Superuser)) {
            if(in_array(strtolower($user->getName()), ArrayToLower($wgPermissionACL_Superuser))) return TRUE;
        }//if-superusers array
        else if(strtolower($user->getName()) == strtolower($wgPermissionACL_Superuser)) return TRUE;
    }//if-superuser
 
    foreach($wgPermissionACL as $rule) { //process ACLs
        if(!PermissionACL_isRuleValid($rule)) continue; //syntax checking
 
        if(PermissionACL_isRuleApplicable($rule, $title, $user, $action)) {
            if(PermissionACL_Permit($rule)) {
                $result = TRUE;
                return TRUE;
            }
            else {
                $result = FALSE;
                return FALSE;
            }
        }//if-applicable
    }//foreach
 
    // If the user's trying to "read"/"edit" a page which doesn't exist; 
    // check for "create" and "createpage" actions
    if (
        (!($title->isKnown())) &&
        (
         ($action == 'read') ||
         ($action == 'edit')
        )
       ) {
      if (
          PermissionACL_userCan($title, $user, 'create', &$result) ||
          PermissionACL_userCan($title, $user, 'createpage', &$result)
         ) {
        return $result;
      }
    }
 
    //implicit end rule - DENY ALL
    $result = FALSE;
    return FALSE;
}//PermissionACL_userCan
 
function PermissionACL_isRuleValid($rule) {
    /* rule parts:
        'group' || 'user'
        'namespace' || 'page' || 'category'
        'action' = (read, edit, create, move, *)
        'operation' = (permit, deny)
    */
    $tmp_actions    = array('read', 'edit', 'create', 'move', 'history', '*');
    $tmp_operations = array('permit', 'deny');
 
    if (isset($rule['group']) ^ isset($rule['user'])) {
        if (isset($rule['namespace']) ^ isset($rule['page']) ^ isset($rule['category'])) {
            if (isset($rule['action'])  && ((is_string($rule['action']) && in_array($rule['action'], $tmp_actions)) || is_array($rule['action']) )) {
                if (isset($rule['operation']) && in_array($rule['operation'], $tmp_operations)) {
                    return TRUE;
                }//if-operation test
            }//if-action test
        }//if-namespace, page, category test
    }//if-user, group test
 
    return FALSE;
}//function PermissionACL_isRuleValid


function PermissionACL_findWildcardInArray($haystack, $needle) {
        if (!is_array($haystack))
                return FALSE;
        foreach($haystack as $item) {
                if (strrchr($item,'*') == '*') {
                        if (strpos($needle, rtrim($item, '*')) === 0 )
                                return TRUE;
                }
        }
        return FALSE;
}

 
function PermissionACL_isRuleApplicable($rule, $title, $user, $action) {
    //group|user rule
    if(isset($rule['group'])) { //group rule
        if(is_array($rule['group']))
            $tmp = ArrayToLower($rule['group']);
        else
            $tmp = strtolower($rule['group']);
 
        $groups = ArrayToLower($user->getEffectiveGroups());
        if(!( (is_string($tmp) && in_array($tmp, $groups)) ||
              (is_array($tmp) && count(array_intersect($tmp, $groups))>0)
            )) return FALSE;
    }
    else { // user rule
        if(is_array($rule['user']))
            $tmp = ArrayToLower($rule['user']);
        else
            $tmp = strtolower($rule['user']);
        $tmp2 = strtolower($user->getName());
 
        if(!( (is_string($tmp) && $tmp=='*') ||
              (is_string($tmp) && $tmp==$tmp2) ||
              (is_array($tmp) && in_array($tmp2, $tmp))
            )) return FALSE;
    }
 
    //namespace|page|category rule
    if(isset($rule['namespace'])) { //namespace rule
        $tmp = $rule['namespace'];
        $tmp2 = $title->getNamespace();
 
        if(!( (is_int($tmp) &&  $tmp==$tmp2) ||
              (is_string($tmp) && $tmp=='*') ||
              (is_array($tmp) && in_array($tmp2, $tmp))
            )) return FALSE;
    }
    else if(isset($rule['page'])){ //page rule
        $tmp = $rule['page'];
        $tmp2 = $title->getPrefixedText();
 
        if(!( (is_string($tmp) && $tmp==$tmp2) ||
              (is_string($tmp) && $tmp=='*') ||
              (is_array($tmp) && in_array($tmp2, $tmp)) ||
              // allow simple right truncate wildcard search for single page names like 'Special:Log*'
              ( (is_string($tmp) && strrchr($tmp,'*') == '*' ) && (strpos($tmp2, rtrim($tmp, '*')) === 0 ) ) ||
              // ...and arrays of page names
              ( is_array($tmp) && PermissionACL_findWildcardInArray($tmp, $tmp2) )
            )) return FALSE;
    }
    else { //category rule
        $tmp = $rule['category'];
        $tmp2 = $title->getParentCategories();
        $categs = array();
 
        if(is_array($tmp2)) {
            global $wgContLang;
            $tmp_pos = strrpos($wgContLang->getNSText(NS_CATEGORY), ':');
 
            foreach($tmp2 as $cat => $page) {
                if($tmp_pos === FALSE) {
                    $categs[] = substr($cat, strpos($cat, ':')+1);
                }
                else {
                    $tmp_categ = substr($cat, $tmp_pos+1);
                    $categs[] = substr($tmp_categ, strpos($tmp_categ, ':')+1);
                }
            }
        }
 
        if(!( (is_string($tmp) && is_array($tmp2) && in_array($tmp, $categs)) ||
              (is_string($tmp) && $tmp=='*') ||
              (is_array($tmp)  && is_array($tmp2) && count(array_intersect($tmp, $categs))>0)
            )) return FALSE;
    }
 
    //action rule
    if(is_array($rule['action']))
        $tmp = ArrayToLower($rule['action']);
    else
        $tmp = strtolower($rule['action']);
 
    return ($tmp == $action) ||
          (is_string($tmp) && $tmp=='*') ||
          (is_array($tmp) && in_array($action, $tmp));
}//function PermissionACL_isRuleApplicable
 
function PermissionACL_Permit($rule) {
    return $rule['operation'] == 'permit';
}//function PermissionACL_Permit
 
function ArrayToLower($ar) {
    $tmp = array();
    foreach($ar as $index => $value)
        $tmp[$index] = strtolower($value);
    return $tmp;
}//function ArrayToLower
