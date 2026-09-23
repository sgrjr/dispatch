<?php

namespace Sgrjr\Dispatch\Support;

/**
 * One action a task kind offers (TASK-1188): what the button says, how it
 * looks, what it asks for first, and whether an agent may run it.
 *
 * An input: {key, label, type: text|textarea|select, required?: bool,
 * options?: [{value, label}], placeholder?: string}. A `required` input is
 * checked by the action service before the kind runs, so a kind can rely on it.
 *
 * A LINK action (`url` set, TASK-1190) is a pointer, not something the task
 * runs: when the work has its own tool (a request's review form), the task
 * links to it instead of reinventing it. Surfaces render it as a link, and
 * TaskActions::perform() refuses it.
 */
final class TaskAction
{
    public const STYLE_PRIMARY = 'primary';

    public const STYLE_DANGER = 'danger';

    public const STYLE_SECONDARY = 'secondary';

    /**
     * @param  array<int, array<string, mixed>>  $inputs
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $style = self::STYLE_SECONDARY,
        public readonly array $inputs = [],
        public readonly ?string $confirm = null,
        public readonly bool $agentAllowed = false,
        public readonly ?string $url = null,
    ) {}

    /** A link to the work's own tool, never performed (TASK-1190). */
    public static function link(string $key, string $label, string $url, string $style = self::STYLE_PRIMARY): self
    {
        return new self($key, $label, $style, url: $url);
    }

    public function isLink(): bool
    {
        return $this->url !== null;
    }

    /** @return array<int, string> the keys of the inputs that must be filled */
    public function requiredInputs(): array
    {
        return array_values(array_map(
            fn (array $i) => (string) $i['key'],
            array_filter($this->inputs, fn (array $i) => (bool) ($i['required'] ?? false)),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'style' => $this->style,
            'inputs' => $this->inputs,
            'confirm' => $this->confirm,
            'agent_allowed' => $this->agentAllowed,
            'url' => $this->url,
        ];
    }
}
