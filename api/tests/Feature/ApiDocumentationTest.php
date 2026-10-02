<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * OpenAPI（Scramble）の書き出し方と、ドキュメントの公開範囲を固定する。
 *
 * 生成された OpenAPI はフロントの型の元になる。ここで崩れると、フロントは誤った形の
 * リクエストを型どおりに送ることになる。Scramble の版を上げたときに気づけるよう、
 * AppServiceProvider::configureApiDocs() で直した点をここで確かめる。
 */
class ApiDocumentationTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $document;

    protected function setUp(): void
    {
        parent::setUp();

        $path = storage_path('framework/testing/openapi.json');
        File::ensureDirectoryExists(dirname($path));

        Artisan::call('scramble:export', ['--path' => $path]);
        $this->document = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        File::delete($path);
    }

    /**
     * ドキュメントは開発環境（APP_ENV=local）でしか開けない。テストの環境は testing なので
     * 403 になるのが正しい。本番で開くには viewApiDocs のゲートを明示的に定義する必要がある。
     */
    public function test_docs_are_not_served_outside_the_local_environment(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    /**
     * パスワードが URL のクエリとして書かれると、フロントの型がそのとおりに送り、
     * アクセスログやブラウザの履歴に残る。DELETE の項目は本文として書く
     * （DeleteParametersAsBody）。
     */
    public function test_no_operation_takes_a_password_in_the_query(): void
    {
        foreach ($this->operations() as [$method, $path, $operation]) {
            foreach ($operation['parameters'] ?? [] as $parameter) {
                $this->assertFalse(
                    $parameter['in'] === 'query' && str_contains($parameter['name'], 'password'),
                    "{$method} {$path} takes {$parameter['name']} in the query",
                );
            }
        }

        $disable = $this->document['paths']['/auth/two-factor']['delete'];
        $this->assertArrayHasKey(
            'password',
            $disable['requestBody']['content']['application/json']['schema']['properties'],
        );
    }

    /** 更新は「送った項目だけを変える」ので PATCH。apiResource の PUT は書かない */
    public function test_updates_are_documented_as_patch(): void
    {
        $methods = collect($this->operations())->map(fn (array $op): string => $op[0]);

        $this->assertNotContains('put', $methods);
        $this->assertArrayHasKey('patch', $this->document['paths']['/projects/{project}/tasks/{task}']);
    }

    /** APP_URL に依存しない。依存すると、開発環境と CI で書き出し結果が変わる */
    public function test_the_server_url_does_not_depend_on_the_environment(): void
    {
        $this->assertSame([['url' => '/api']], $this->document['servers']);
    }

    /**
     * Bearer トークンは auth:sanctum の付いたルートにだけ必須。ログインなどの公開ルートは
     * security: [] で「認証不要」と書く。
     */
    public function test_only_authenticated_routes_require_a_bearer_token(): void
    {
        $this->assertSame('bearer', collect($this->document['components']['securitySchemes'])->first()['scheme']);

        foreach (['/auth/register', '/auth/login', '/auth/two-factor-challenge'] as $path) {
            $this->assertSame([], $this->document['paths'][$path]['post']['security'], $path);
        }

        $this->assertArrayNotHasKey('security', $this->document['paths']['/auth/me']['get']);
    }

    /**
     * @return list<array{string, string, array<string, mixed>}>
     */
    private function operations(): array
    {
        $operations = [];

        foreach ($this->document['paths'] as $path => $item) {
            foreach ($item as $method => $operation) {
                $operations[] = [$method, $path, $operation];
            }
        }

        return $operations;
    }
}
