<?php
namespace FallegaHQ\ApiResponder\Policies;

use FallegaHQ\ApiResponder\Contracts\VisibilityResolverInterface;

class VisibilityResolver implements VisibilityResolverInterface{
    protected array $config;

    public function __construct(){
        $this->config = config('api-responder.visibility');
    }

    public function canSeeField(string $field, array $allowedRoles, $user = null): bool{
        if(!$this->config['enabled']){
            return true;
        }
        if(empty($allowedRoles)){
            return true;
        }
        $userRoles = $this->getUserRoles($user);

        return !empty(array_intersect($allowedRoles, $userRoles));
    }

    public function getUserRoles($user): array{
        if($user === null){
            return [$this->config['guest_role']];
        }
        if(method_exists($user, 'getRoles')){
            return $user->getRoles();
        }
        if(method_exists($user, 'roles')){
            return $user->roles()
                        ->pluck('name')
                        ->toArray();
        }
        if(isset($user->role)){
            return [$user->role];
        }
        if(isset($user->roles)){
            return is_array($user->roles) ? $user->roles : [$user->roles];
        }

        return [$this->config['guest_role']];
    }
}
