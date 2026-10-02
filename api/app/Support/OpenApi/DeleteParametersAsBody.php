<?php

namespace App\Support\OpenApi;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Parameter;
use Dedoc\Scramble\Support\Generator\RequestBodyObject;
use Dedoc\Scramble\Support\Generator\Schema;

/**
 * DELETE の FormRequest の項目を、クエリではなく JSON の本文として書き直す。
 *
 * Scramble は DELETE を「本文を持たないメソッド」として扱い、FormRequest の項目を
 * すべてクエリパラメータとして書く（RequestBodyExtension::HTTP_METHODS_WITHOUT_REQUEST_BODY）。
 * この API で本文を持つ DELETE は MFA の解除（現在のパスワード）で、クエリとして書かれた
 * ままフロントの型を作ると、パスワードが URL に乗り、アクセスログやブラウザの履歴に残る。
 *
 * DELETE に本文でパスワードを送る形は、Laravel のスターターキット（Breeze のアカウント
 * 削除 DELETE /profile、Jetstream の同機能）と同じ。RFC 9110 は DELETE の本文を、
 * サーバーが受け付けると示した場合に限って認めている。この OpenAPI がその表明になる。
 *
 * この API の DELETE はクエリパラメータを取らないので、DELETE のクエリはすべて移す。
 */
final class DeleteParametersAsBody implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                if ($operation->method === 'delete') {
                    $this->moveQueryToBody($operation);
                }
            }
        }
    }

    private function moveQueryToBody(Operation $operation): void
    {
        $query = array_filter($operation->parameters, fn (Parameter $p): bool => $p->in === 'query');

        if ($query === []) {
            return;
        }

        $operation->parameters = array_values(array_filter(
            $operation->parameters,
            fn (Parameter $p): bool => $p->in !== 'query',
        ));

        $operation->addRequestBodyObject(
            RequestBodyObject::make()
                ->setContent('application/json', Schema::createFromParameters($query))
                ->required(true),
        );
    }
}
