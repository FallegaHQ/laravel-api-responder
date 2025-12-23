<?php
namespace FallegaHQ\ApiResponder\Tests\Unit;

use FallegaHQ\ApiResponder\Http\FieldsetParser;
use FallegaHQ\ApiResponder\Tests\TestCase;
use Illuminate\Http\Request;
use Mockery;

class FieldsetParserTest extends TestCase{
    protected FieldsetParser $parser;

    public function test_parses_fields_parameter(): void{
        $request = Mockery::mock(Request::class);
        $request->allows('has')
                ->with('fields')
                ->andReturns(true);
        $request->allows('get')
                ->with('fields')
                ->andReturns('id,name,email');
        $request->allows('has')
                ->with('include')
                ->andReturns(false);
        $request->allows('has')
                ->with('exclude')
                ->andReturns(false);
        $result = $this->parser->parse($request);
        $this->assertEquals(
            [
                'id',
                'name',
                'email',
            ],
            $result['fields']
        );
    }

    public function test_parses_include_parameter(): void{
        $request = Mockery::mock(Request::class);
        $request->allows('has')
                ->with('fields')
                ->andReturns(false);
        $request->allows('has')
                ->with('include')
                ->andReturns(true);
        $request->allows('get')
                ->with('include')
                ->andReturns('posts,comments');
        $request->allows('has')
                ->with('exclude')
                ->andReturns(false);
        $result = $this->parser->parse($request);
        $this->assertEquals(
            [
                'posts',
                'comments',
            ],
            $result['includes']
        );
    }

    public function test_parses_exclude_parameter(): void{
        $request = Mockery::mock(Request::class);
        $request->allows('has')
                ->with('fields')
                ->andReturns(false);
        $request->allows('has')
                ->with('include')
                ->andReturns(false);
        $request->allows('has')
                ->with('exclude')
                ->andReturns(true);
        $request->allows('get')
                ->with('exclude')
                ->andReturns('password,secret');
        $result = $this->parser->parse($request);
        $this->assertEquals(
            [
                'password',
                'secret',
            ],
            $result['excludes']
        );
    }

    public function test_should_include_field_when_no_filters(): void{
        $request = Mockery::mock(Request::class);
        $request->allows('has')
                ->andReturns(false);
        $this->parser->parse($request);
        $this->assertTrue($this->parser->shouldIncludeField('any_field'));
    }

    public function test_should_include_field_when_in_fields_list(): void{
        $request = Mockery::mock(Request::class);
        $request->allows('has')
                ->with('fields')
                ->andReturns(true);
        $request->allows('get')
                ->with('fields')
                ->andReturns('id,name');
        $request->allows('has')
                ->with('include')
                ->andReturns(false);
        $request->allows('has')
                ->with('exclude')
                ->andReturns(false);
        $this->parser->parse($request);
        $this->assertTrue($this->parser->shouldIncludeField('id'));
        $this->assertTrue($this->parser->shouldIncludeField('name'));
        $this->assertFalse($this->parser->shouldIncludeField('email'));
    }

    public function test_should_exclude_field_when_in_excludes_list(): void{
        $request = Mockery::mock(Request::class);
        $request->allows('has')
                ->with('fields')
                ->andReturns(false);
        $request->allows('has')
                ->with('include')
                ->andReturns(false);
        $request->allows('has')
                ->with('exclude')
                ->andReturns(true);
        $request->allows('get')
                ->with('exclude')
                ->andReturns('password');
        $this->parser->parse($request);
        $this->assertFalse($this->parser->shouldIncludeField('password'));
        $this->assertTrue($this->parser->shouldIncludeField('email'));
    }

    public function test_returns_empty_when_disabled(): void{
        config(['api-responder.sparse_fieldsets.enabled' => false]);
        $parser  = new FieldsetParser();
        $request = Mockery::mock(Request::class);
        $result  = $parser->parse($request);
        $this->assertNull($result['fields']);
        $this->assertEmpty($result['includes']);
        $this->assertEmpty($result['excludes']);
    }

    protected function setUp(): void{
        parent::setUp();
        config(
            [
                'api-responder.sparse_fieldsets' => [
                    'enabled'       => true,
                    'query_param'   => 'fields',
                    'include_param' => 'include',
                    'exclude_param' => 'exclude',
                ],
            ]
        );
        $this->parser = new FieldsetParser();
    }

    protected function tearDown(): void{
        Mockery::close();
        parent::tearDown();
    }
}
