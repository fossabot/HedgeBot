<?php
namespace HedgeBot\Core\Tikal\Endpoint;

use HedgeBot\Core\API\Security;
use HedgeBot\Core\Security\SecurityRole;
use stdClass;

class SecurityEndpoint
{
    /**
     * Gets the currently available roles into the security system.
     * 
     * @return array The list of currently registered roles, with all their settings and rights.
     */
    public function getRoles()
    {
        $roleList = Security::getRoleList();
        foreach($roleList as &$role)
            $role = $role->toArray();
        
        return $roleList;
    }
    
    /** 
     * Gets information about a specific role. It will also return the users that have this role.
     * 
     * @param string $roleId The ID of the role.
     * 
     * @return array|null The role data, or null if the role hasn't been found.
     */
    public function getRole($roleId)
    {
        $role = Security::getRole($roleId);
        if(!$role)
            return null;
        
        $inheritedRights = $role->getInheritedRights();

        $role = $role->toArray();
        $role['users'] = Security::getRoleUsers($roleId);
        $role['inheritedRights'] = $inheritedRights;

        return $role;
    }

    /**
     * Saves a role into the security system.
     * 
     * @param string $roleId   The ID of the role to save.
     * @param object $roleData The data of the role. Refer to the output of the getRoles() method to see the
     *                         expected format.
     * 
     * @return bool True if the role has been successfully saved, false if an error occured.
     *              Usual errors can be: Parent role not found when specified, parent role is invalid.
     */
    public function saveRole($roleId, stdClass $roleData)
    {
        // Try to get the role object, and if it doesn't exist, create it.
        $role = Security::getRole($roleId);
        
        if(empty($role))
        {
            $role = new SecurityRole($roleId);
            Security::addRole($role);
        }

        // Save the parent role if found. if not found, return false.
        if(!empty($roleData->parent))
        {
            $parentRole = Security::getRole($roleData->parent);
            if(empty($parentRole))
                return false;
            
            if(Security::rolesHaveRelation($role, $parentRole))
                return false;
            
            $role->setParent($parentRole);
        }

        // Save the name
        if(!empty($roleData->name))
            $role->setName($roleData->name);
        
        // Save the default status
        if(isset($roleData->default))
            $role->setDefault($roleData->default);
        
        if(!empty($roleData->rights))
            $role->replaceRights((array) $roleData->rights);
        
        Security::saveToStorage();
    }

    /**
     * Gets the tree hierarchy of the roles registered into the bot.
     * 
     * @return array The role tree as a recursive array. Each leaf has 2 keys:
     *               - role: The role ID
     *               - children: The role children
     */
    public function getRoleTree()
    {
        $roleTree = Security::getRoleTree();
        $roleTreeOut = [];

        $simplifyRoleTree = function(&$level) use(&$simplifyRoleTree)
        {
            foreach($level as &$role)
            {
                $role['role'] = $role['role']->getId();
                if(!empty($role['children']))
                    $simplifyRoleTree($role['children']);
            }
        };

        $simplifyRoleTree($roleTree);

        return $roleTree;
    }

    /**
     * Gets the registered users in the bot.
     * 
     * @return array The user list as an array with the username as key, and its roles as the value.
     */
    public function getUsers()
    {
        return Security::getUserList();
    }
}