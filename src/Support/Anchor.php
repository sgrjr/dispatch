<?php

namespace Sgrjr\Dispatch\Support;

use Sgrjr\Dispatch\Contracts\OriginResolver;
use Sgrjr\Dispatch\Contracts\TopicResolver;

/**
 * ONE anchor value object behind BOTH `$task->topic` and `$task->origin`
 * (TASK-995, ruling R10) — callers never parse a `"<type>:<id>"` wire string
 * or touch a resolver directly. Only Task's two accessors build one of these
 * (from the already-validated `topic_type`/`topic_id` or
 * `origin_type`/`origin_id` columns), so only those two places change if an
 * id format ever changes.
 *
 * Construction is cheap — resolution (model()/label()/url()) is LAZY and
 * memoized per instance, so `$task->topic` costs nothing beyond holding
 * type/id/resolver until something actually reads one of those three.
 */
class Anchor
{
    private bool $modelResolved = false;

    private mixed $resolvedModel = null;

    private bool $labelResolved = false;

    private ?string $resolvedLabel = null;

    private bool $urlResolved = false;

    private ?string $resolvedUrl = null;

    public function __construct(
        public readonly string $type,
        public readonly ?string $id,
        private readonly TopicResolver|OriginResolver $resolver,
    ) {}

    /**
     * Split a wire anchor string on the FIRST colon only — an id may itself
     * contain colons. `"<type>"` alone (no colon) is that type with a null
     * id, for channel-only anchors (`phone`, `in_person`). The ONE parser
     * every CLI flag, agent API param, and batch shorthand string goes
     * through.
     *
     * @return array{0:string,1:?string}
     *
     * @throws \InvalidArgumentException when the type segment doesn't match
     *                                   `^[a-z][a-z0-9_]{0,31}$`.
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        $pos = strpos($raw, ':');

        [$type, $id] = $pos === false
            ? [$raw, null]
            : [substr($raw, 0, $pos), substr($raw, $pos + 1)];

        if (! preg_match('/^[a-z][a-z0-9_]{0,31}$/', $type)) {
            throw new \InvalidArgumentException(
                "Invalid anchor type `{$type}` — must match ^[a-z][a-z0-9_]{0,31}\$ (e.g. `account`, `plan`, `phone`)."
            );
        }

        return [$type, $id];
    }

    /**
     * The resolved model/object, or null when the resolver can't find it.
     * TopicResolver's `resolve()` takes a non-nullable id — a topic with a
     * null id (a bare type, no natural key) resolves with `''` rather than
     * risk a TypeError; OriginResolver's id is nullable throughout (channel
     * origins genuinely have none).
     */
    public function model(): mixed
    {
        if (! $this->modelResolved) {
            $this->resolvedModel = $this->resolver instanceof TopicResolver
                ? $this->resolver->resolve($this->type, (string) $this->id)
                : $this->resolver->resolve($this->type, $this->id);
            $this->modelResolved = true;
        }

        return $this->resolvedModel;
    }

    public function label(): string
    {
        if (! $this->labelResolved) {
            $this->resolvedLabel = $this->resolver instanceof TopicResolver
                ? $this->resolver->label($this->type, (string) $this->id)
                : $this->resolver->label($this->type, $this->id);
            $this->labelResolved = true;
        }

        return $this->resolvedLabel;
    }

    public function url(): ?string
    {
        if (! $this->urlResolved) {
            $this->resolvedUrl = $this->resolver instanceof TopicResolver
                ? $this->resolver->url($this->type, (string) $this->id)
                : $this->resolver->url($this->type, $this->id);
            $this->urlResolved = true;
        }

        return $this->resolvedUrl;
    }
}
