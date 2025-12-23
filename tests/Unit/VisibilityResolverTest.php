<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Policies\VisibilityResolver;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Mockery;

class VisibilityResolverTest extends TestCase{
    protected VisibilityResolver $resolver;

    public function test_allows_field_when_visibility_disabled(): void{
        config(['api-responder.visibility.enabled' => false]);
        $resolver = new VisibilityResolver();
        $this->assertTrue($resolver->canSeeField('field', ['admin']));
    }

    public function test_allows_field_when_no_roles_required(): void{
        $this->assertTrue($this->resolver->canSeeField('field', []));
    }

    public function test_allows_field_for_user_with_correct_role(): void{
        $user       = Mockery::mock();
        $user->role = 'admin';
        $this->assertTrue($this->resolver->canSeeField('field', ['admin'], $user));
    }

    public function test_denies_field_for_user_without_correct_role(): void{
        $user       = Mockery::mock();
        $user->role = 'user';
        $this->assertFalse($this->resolver->canSeeField('field', ['admin'], $user));
    }

    public function test_allows_field_for_user_with_one_of_multiple_roles(): void{
        $user       = Mockery::mock();
        $user->role = 'manager';
        $this->assertTrue(
            $this->resolver->canSeeField(
                'field',
                [
                    'admin',
                    'manager',
                ],
                $user
            )
        );
    }

    public function test_guest_role_for_null_user(): void{
        $roles = $this->resolver->getUserRoles(null);
        $this->assertEquals(['guest'], $roles);
    }

    public function test_gets_role_from_user_property(): void{
        $user       = Mockery::mock();
        $user->role = 'admin';
        $roles      = $this->resolver->getUserRoles($user);
        $this->assertEquals(['admin'], $roles);
    }

    public function test_gets_roles_from_array_property(): void{
        $user        = Mockery::mock();
        $user->roles = [
            'admin',
            'manager',
        ];
        $roles       = $this->resolver->getUserRoles($user);
        $this->assertEquals(
            [
                'admin',
                'manager',
            ],
            $roles
        );
    }

    public function test_gets_roles_from_get_roles_method(): void{
        $user  = new class{
            public function getRoles(): array{
                return [
                    'admin',
                    'editor',
                ];
            }
        };
        $roles = $this->resolver->getUserRoles($user);
        $this->assertEquals(
            [
                'admin',
                'editor',
            ],
            $roles
        );
    }

    protected function setUp(): void{
        parent::setUp();
        config(
            [
                'api-responder.visibility' => [
                    'enabled'      => true,
                    'guest_role'   => 'guest',
                    'resolve_user' => fn() => null,
                ],
            ]
        );
        $this->resolver = new VisibilityResolver();
    }

    protected function tearDown(): void{
        Mockery::close();
        parent::tearDown();
    }
}
