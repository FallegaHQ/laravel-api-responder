<?php
namespace FallegaHQ\ApiResponder\Tests\Feature;

use FallegaHQ\ApiResponder\Attributes\UseDto;
use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;
use FallegaHQ\ApiResponder\DTO\BaseDTO;
use FallegaHQ\ApiResponder\Http\FieldsetParser;
use FallegaHQ\ApiResponder\Http\MetadataBuilder;
use FallegaHQ\ApiResponder\Http\ResponseBuilder;
use FallegaHQ\ApiResponder\Tests\AssertableApiResponse;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

class ModelResponseTest extends TestCase{
    protected ResponseBuilder $builder;

    /**
     * @throws \JsonException
     */
    public function test_returns_single_model_as_json(): void{
        /** @noinspection PhpUndefinedMethodInspection */
        $user     = TestUser::create(
            [
                'name'     => 'John Doe',
                'email'    => 'john@example.com',
                'password' => 'secret',
                'role'     => 'admin',
            ]
        );
        $response = $this->builder->success($user, 'User retrieved');
        $this->assertApiResponse($response)
             ->assertSuccess('User retrieved')
             ->assertHasData();
        $data = $response->getData(true);
        $this->assertEquals('John Doe', $data['data']['name']);
        $this->assertEquals('john@example.com', $data['data']['email']);
    }

    protected function assertApiResponse($response): AssertableApiResponse{
        return new AssertableApiResponse(new TestResponse($response));
    }

    /**
     * @throws \JsonException
     */
    public function test_returns_model_collection_as_json(): void{
        /** @noinspection PhpUndefinedMethodInspection */
        TestUser::create(
            [
                'name'     => 'User 1',
                'email'    => 'user1@example.com',
                'password' => 'secret',
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestUser::create(
            [
                'name'     => 'User 2',
                'email'    => 'user2@example.com',
                'password' => 'secret',
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestUser::create(
            [
                'name'     => 'User 3',
                'email'    => 'user3@example.com',
                'password' => 'secret',
            ]
        );
        $users    = TestUser::all();
        $response = $this->builder->success($users);
        $this->assertApiResponse($response)
             ->assertSuccess()
             ->assertHasData();
        $data = $response->getData(true);
        $this->assertCount(3, $data['data']);
        $this->assertEquals('User 1', $data['data'][0]['name']);
        $this->assertEquals('User 2', $data['data'][1]['name']);
        $this->assertEquals('User 3', $data['data'][2]['name']);
    }

    /**
     * @throws \JsonException
     */
    public function test_returns_paginated_models(): void{
        for($i = 1; $i <= 25; $i++){
            /** @noinspection PhpUndefinedMethodInspection */
            TestUser::create(
                [
                    'name'     => "User $i",
                    'email'    => "user$i@example.com",
                    'password' => 'secret',
                ]
            );
        }
        /** @noinspection PhpUndefinedMethodInspection */
        $users    = TestUser::paginate(10);
        $response = $this->builder->success($users);
        $this->assertApiResponse($response)
             ->assertSuccess()
             ->assertHasData()
             ->assertHasPagination();
        $data = $response->getData(true);
        $this->assertCount(10, $data['data']);
        $this->assertEquals(1, $data['meta']['current_page']);
        $this->assertEquals(3, $data['meta']['last_page']);
        $this->assertEquals(10, $data['meta']['per_page']);
        $this->assertEquals(25, $data['meta']['total']);
    }

    /**
     * @throws \JsonException
     */
    public function test_returns_model_with_relationships(): void{
        /** @noinspection PhpUndefinedMethodInspection */
        $user = TestUser::create(
            [
                'name'     => 'Author',
                'email'    => 'author@example.com',
                'password' => 'secret',
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        $post         = TestPost::create(
            [
                'user_id'   => $user->id,
                'title'     => 'Test Post',
                'content'   => 'This is test content',
                'published' => true,
            ]
        );
        $postWithUser = TestPost::with('user')
                                ->find($post->id);
        $response     = $this->builder->success($postWithUser);
        $this->assertApiResponse($response)
             ->assertSuccess()
             ->assertHasData();
        $data = $response->getData(true);
        $this->assertEquals('Test Post', $data['data']['title']);
        $this->assertArrayHasKey('user', $data['data']);
        $this->assertEquals('Author', $data['data']['user']['name']);
    }

    /**
     * @throws \JsonException
     */
    public function test_created_response_with_model(): void{
        /** @noinspection PhpUndefinedMethodInspection */
        $user     = TestUser::create(
            [
                'name'     => 'New User',
                'email'    => 'new@example.com',
                'password' => 'secret',
            ]
        );
        $response = $this->builder->created($user, 'User created successfully');
        $this->assertApiResponse($response)
             ->assertSuccess('User created successfully')
             ->assertHasData()
             ->assertStatus(201);
        $data = $response->getData(true);
        $this->assertEquals('New User', $data['data']['name']);
    }

    /**
     * @throws \JsonException
     */
    public function test_filters_model_attributes(): void{
        /** @noinspection PhpUndefinedMethodInspection */
        /** @var \FallegaHQ\ApiResponder\Tests\Feature\TestUser $user */
        $user     = TestUser::create(
            [
                'name'     => 'Test User',
                'email'    => 'test@example.com',
                'password' => 'secret_password',
                'role'     => 'admin',
            ]
        );
        $response = $this->builder->success($user->makeHidden(['email']));
        $data     = $response->getData(true);
        $this->assertArrayNotHasKey('password', $data['data']);
        $this->assertArrayHasKey('name', $data['data']);
        $this->assertArrayNotHasKey('email', $data['data']);
    }

    /**
     * @throws \JsonException
     */
    public function test_model_collection_with_custom_transformation(): void{
        /** @noinspection PhpUndefinedMethodInspection */
        TestUser::create(
            [
                'name'     => 'Admin',
                'email'    => 'admin@example.com',
                'password' => 'secret',
                'role'     => 'admin',
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestUser::create(
            [
                'name'     => 'User',
                'email'    => 'user@example.com',
                'password' => 'secret',
                'role'     => 'user',
            ]
        );
        $users    = TestUser::all()
                            ->map(
                                function($user){
                                    return [
                                        'id'       => $user->id,
                                        'name'     => $user->name,
                                        'email'    => $user->email,
                                        'is_admin' => $user->role === 'admin',
                                    ];
                                }
                            );
        $response = $this->builder->success($users);
        $this->assertApiResponse($response)
             ->assertSuccess()
             ->assertHasData();
        $data = $response->getData(true);
        $this->assertCount(2, $data['data']);
        $this->assertTrue($data['data'][0]['is_admin']);
        $this->assertFalse($data['data'][1]['is_admin']);
    }

    /**
     * @throws \JsonException
     */
    public function test_model_with_computed_fields(): void{
        /** @noinspection PhpUndefinedMethodInspection */
        $user = TestUser::create(
            [
                'name'     => 'John Doe',
                'email'    => 'john@example.com',
                'password' => 'secret',
                'role'     => 'admin',
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestPost::create(
            [
                'user_id'   => $user->id,
                'title'     => 'First Post',
                'content'   => 'Content',
                'published' => true,
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestPost::create(
            [
                'user_id'   => $user->id,
                'title'     => 'Second Post',
                'content'   => 'Content',
                'published' => false,
            ]
        );
        $user->refresh();
        $response = $this->builder->success($user);
        $this->assertApiResponse($response)
             ->assertSuccess()
             ->assertHasData();
        $data = $response->getData(true);
        $this->assertEquals('John Doe', $data['data']['name']);
        $this->assertEquals('JOHN DOE', $data['data']['name_uppercase']);
        $this->assertEquals('john@example.com', $data['data']['email']);
        $this->assertEquals('j***@example.com', $data['data']['masked_email']);
        $this->assertTrue($data['data']['is_admin']);
        $this->assertEquals(2, $data['data']['posts_count']);
    }

    /**
     * @throws \JsonException
     */
    public function test_model_collection_with_computed_fields(): void{
        /** @noinspection PhpUndefinedMethodInspection */
        $user1 = TestUser::create(
            [
                'name'     => 'Alice',
                'email'    => 'alice@example.com',
                'password' => 'secret',
                'role'     => 'admin',
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        $user2 = TestUser::create(
            [
                'name'     => 'Bob',
                'email'    => 'bob@example.com',
                'password' => 'secret',
                'role'     => 'user',
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestPost::create(
            [
                'user_id'   => $user1->id,
                'title'     => 'Post 1',
                'content'   => 'Content',
                'published' => true,
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestPost::create(
            [
                'user_id'   => $user1->id,
                'title'     => 'Post 2',
                'content'   => 'Content',
                'published' => true,
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestPost::create(
            [
                'user_id'   => $user1->id,
                'title'     => 'Post 3',
                'content'   => 'Content',
                'published' => true,
            ]
        );
        /** @noinspection PhpUndefinedMethodInspection */
        TestPost::create(
            [
                'user_id'   => $user2->id,
                'title'     => 'Post 4',
                'content'   => 'Content',
                'published' => true,
            ]
        );
        $users    = TestUser::all();
        $response = $this->builder->success($users);
        $this->assertApiResponse($response)
             ->assertSuccess()
             ->assertHasData();
        $data = $response->getData(true);
        $this->assertCount(2, $data['data']);
        $this->assertEquals('ALICE', $data['data'][0]['name_uppercase']);
        $this->assertTrue($data['data'][0]['is_admin']);
        $this->assertEquals(3, $data['data'][0]['posts_count']);
        $this->assertEquals('BOB', $data['data'][1]['name_uppercase']);
        $this->assertFalse($data['data'][1]['is_admin']);
        $this->assertEquals(1, $data['data'][1]['posts_count']);
    }

    protected function setUp(): void{
        parent::setUp();
        $metadataBuilder = new MetadataBuilder();
        $fieldsetParser  = new FieldsetParser();
        $this->builder   = new ResponseBuilder($metadataBuilder, $fieldsetParser);
        $this->setUpDatabase();
    }

    protected function setUpDatabase(): void{
        Schema::create(
            'users',
            static function(Blueprint $table){
                $table->id();
                $table->string('name');
                $table->string('email')
                      ->unique();
                $table->string('password');
                $table->string('role')
                      ->default('user');
                $table->timestamps();
            }
        );
        Schema::create(
            'posts',
            static function(Blueprint $table){
                $table->id();
                $table->foreignId('user_id')
                      ->constrained()
                      ->onDelete('cascade');
                $table->string('title');
                $table->text('content');
                $table->boolean('published')
                      ->default(false);
                $table->timestamps();
            }
        );
    }

    protected function tearDown(): void{
        Schema::dropIfExists('posts');
        Schema::dropIfExists('users');
        parent::tearDown();
    }
}

#[UseDto(TestUserDTO::class)]
class TestUser extends Model{
    protected $table    = 'users';
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];
    protected $hidden   = ['password'];

    /** @noinspection PhpUnused */
    public function posts(): HasMany{
        return $this->hasMany(TestPost::class, 'user_id');
    }
}

class TestPost extends Model{
    protected $table    = 'posts';
    protected $fillable = [
        'user_id',
        'title',
        'content',
        'published',
    ];

    public function user(): BelongsTo{
        return $this->belongsTo(TestUser::class, 'user_id');
    }
}

class TestUserDTO extends BaseDTO{
    /** @noinspection PhpUnused */
    #[ComputedField(name: 'name_uppercase')]
    public function nameUppercase(): string{
        return strtoupper($this->source->name);
    }

    /** @noinspection PhpUnused */
    #[ComputedField(name: 'masked_email')]
    public function maskedEmail(): string{
        $parts = explode('@', $this->source->email);

        return $parts[0][0] . '***@' . $parts[1];
    }

    /** @noinspection PhpUnused */
    #[ComputedField(name: 'is_admin')]
    public function isAdmin(): bool{
        return $this->source->role === 'admin';
    }

    /** @noinspection PhpUnused */
    #[ComputedField(name: 'posts_count')]
    public function postsCount(): int{
        return $this->source->posts()
                            ->count();
    }

    protected function getHiddenFields(): array{
        return [
            'password',
            'updated_at',
        ];
    }
}
