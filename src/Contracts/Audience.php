<?php

declare(strict_types=1);

namespace Mozex\Campaigns\Contracts;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;

/**
 * A named audience the app defines in code, such as "bought a course", which
 * an agent picks by name with its parameters. Listed in campaigns.audiences.
 * The audience only targets: who may be mailed at all, and through which
 * lists, the package decides.
 */
interface Audience
{
    /**
     * What the audience holds and when to use it, for an agent choosing one.
     */
    public function description(): string;

    /**
     * The parameters, as an MCP tool declares its input:
     * ['course' => $schema->string()->description('The course key')->required()].
     *
     * @return array<string, Type>
     */
    public function parameters(JsonSchema $schema): array;

    /**
     * Laravel validation rules for the parameters, checked before query()
     * runs; query() receives only the validated values.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * The people in the audience: a query on the app's own records, whose
     * subscribers form the audience, or a query on Subscriber. The package
     * uses it as a subquery that selects only the key, so leave out a limit
     * and filter with where(), has() and whereHas(), never having() on a
     * column you select.
     *
     * @param  array<string, mixed>  $parameters
     * @return Builder<covariant Model>
     */
    public function query(array $parameters): Builder;
}
