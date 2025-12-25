<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Attributes\UseDto;
use FallegaHQ\ApiResponder\DTO\Attributes\ComputedField;
use FallegaHQ\ApiResponder\DTO\BaseDTO;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NestedDtoTest extends TestCase{
    /**
     * @throws \ReflectionException
     */
    public function test_automatically_includes_loaded_relationships(): void{
        $author       = new TestAuthor();
        $author->id   = 1;
        $author->name = 'John Doe';
        $post        = new TestPost();
        $post->id    = 1;
        $post->title = 'Test Post';
        $post->setRelation('author', $author);
        $dto    = new TestPostDTO($post);
        $result = $dto->toArray();
        $this->assertArrayHasKey('author', $result);
        $this->assertIsArray($result['author']);
        $this->assertEquals('John Doe', $result['author']['name']);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_excludes_unloaded_relationships_by_default(): void{
        $post        = new TestPost();
        $post->id    = 1;
        $post->title = 'Test Post';
        $dto    = new TestPostDTO($post);
        $result = $dto->toArray();
        $this->assertArrayNotHasKey('author', $result);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_handles_collection_relationships(): void{
        $post1        = new TestPost();
        $post1->id    = 1;
        $post1->title = 'Post 1';
        $post2        = new TestPost();
        $post2->id    = 2;
        $post2->title = 'Post 2';
        $author       = new TestAuthor();
        $author->id   = 1;
        $author->name = 'John Doe';
        $author->setRelation(
            'posts',
            collect(
                [
                    $post1,
                    $post2,
                ]
            )
        );
        $dto    = new TestAuthorDTO($author);
        $result = $dto->toArray();
        $this->assertArrayHasKey('posts', $result);
        $this->assertIsArray($result['posts']);
        $this->assertCount(2, $result['posts']);
        $this->assertEquals('Post 1', $result['posts'][0]['title']);
        $this->assertEquals('Post 2', $result['posts'][1]['title']);
    }

    /**
     * @throws \ReflectionException
     */
    public function test_prevents_infinite_circular_references(): void{
        // The system should prevent infinite loops from circular references
        $author       = new TestAuthor();
        $author->id   = 1;
        $author->name = 'John Doe';
        $post        = new TestPost();
        $post->id    = 1;
        $post->title = 'Test Post';
        $post->setRelation('author', $author);
        // Create circular reference
        $author->setRelation('posts', collect([$post]));
        // This should not cause infinite recursion
        $dto    = new TestAuthorDTO($author);
        $result = $dto->toArray();
        // Should successfully transform without infinite loop
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('posts', $result);
        $this->assertIsArray($result['posts']);
        // The nested post should have the author relationship
        // but it won't recurse infinitely due to depth limiting
        $this->assertArrayHasKey('title', $result['posts'][0]);
    }
}

#[UseDto(TestAuthorDTO::class)]
class TestAuthor extends Model{
    protected $fillable = ['name'];

    public function posts(): HasMany{
        return $this->hasMany(TestPost::class);
    }
}

#[UseDto(TestPostDTO::class)]
class TestPost extends Model{
    protected $fillable = ['title'];

    public function author(): BelongsTo{
        return $this->belongsTo(TestAuthor::class);
    }
}

class TestAuthorDTO extends BaseDTO{
    #[ComputedField(name: 'name')]
    public function getName(): string{
        return $this->source->name ?? '';
    }
}

class TestPostDTO extends BaseDTO{
    #[ComputedField(name: 'title')]
    public function getTitle(): string{
        return $this->source->title ?? '';
    }
}
