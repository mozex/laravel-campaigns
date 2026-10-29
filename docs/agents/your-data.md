---
title: Giving Agents Your Data
weight: 2
---

Agents know nothing about your app beyond its lists. Two kinds of class let them use what your app knows: named audiences pick people by your own records, and resources hand them read-only data such as your products or a brand guide.

## Named audiences

A named audience is a query on your own records with parameters an agent fills in, such as "people who bought this course". Implement `Mozex\Campaigns\Contracts\Audience`:

```php
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Mozex\Campaigns\Contracts\Audience;

class CourseBuyers implements Audience
{
    public function description(): string
    {
        return 'People who bought a course, by the course key.';
    }

    public function parameters(JsonSchema $schema): array
    {
        return [
            'course' => $schema->string()->description('The course key, such as "intro-to-photography"')->required(),
        ];
    }

    public function rules(): array
    {
        return ['course' => ['required', 'string', 'exists:courses,key']];
    }

    public function query(array $parameters): Builder
    {
        return User::query()->whereHas('purchases.course', fn (Builder $query) => $query->where('key', $parameters['course']));
    }
}
```

Register it by name in `config/campaigns.php`:

```php
'audiences' => [
    'course-buyers' => App\Campaigns\CourseBuyers::class,
],
```

- `parameters()` declares the input the way an MCP tool declares its own. `list_audiences` shows it to agents as a JSON Schema, and a campaign names it as `{"audience": {"name": "course-buyers", "parameters": {"course": "intro-to-photography"}}}`.
- `rules()` validates the parameters before `query()` runs, and `query()` gets only the validated values. A refusal reaches the agent as the validator's message.
- `query()` returns a query on your own model or on `Mozex\Campaigns\Models\Subscriber`. A query on your model reaches the subscribers linked to those records, so the model must feed a [dynamic list](../lists-and-subscribers/dynamic-lists.md). The package uses it as a subquery selecting only the key: leave out limits, and filter with `where()`, `has()` and `whereHas()`.

A named audience only narrows. A campaign still reaches only people subscribed on its target lists, and never anyone opted out, blocked or past the sunset. `Campaigns::audienceSubscribers('course-buyers', ['course' => 'intro-to-photography'])` gives you the same subscriber query in your own code.

## Resources

A resource is read-only data an agent may read to choose an audience or write an email. Implement `Mozex\Campaigns\Contracts\AgentResource`:

```php
use App\Models\Course;
use Illuminate\Contracts\Pagination\Paginator;
use Mozex\Campaigns\Contracts\AgentResource;

class CourseCatalog implements AgentResource
{
    public function description(): string
    {
        return 'Every course on sale: its key, title, price and page.';
    }

    public function read(int $page): Paginator
    {
        return Course::query()
            ->orderBy('id')
            ->simplePaginate(50, page: $page)
            ->through(fn (Course $course): array => [
                'key' => $course->key,
                'title' => $course->title,
                'price' => $course->price,
                'url' => route('courses.show', $course),
            ]);
    }
}
```

```php
'resources' => [
    'courses' => App\Campaigns\CourseCatalog::class,
],
```

- Everything in a page's items is sent, so map each record to the fields an agent needs.
- A page must fit in one answer, 50,000 characters as JSON. A longer one is refused and reported, so pick a page size that leaves room.
- Agents read a resource three ways: `get_guide` with `resource` and `page`, the MCP resource `campaigns://resources/courses` (its first page), and the resource template `campaigns://resources/{name}/pages/{page}`.
- The guide names up to 25 resources with their descriptions, each cut to 300 characters.
- The server lists your resources on every request. One whose class fails to load or whose `description()` throws is reported to your error tracker and left out, so a broken resource never stops an agent from pausing a campaign.
- Name resources with letters, digits and dashes: the name becomes part of an address.
