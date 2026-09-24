<?php

namespace Sgrjr\Dispatch\Support;

use Illuminate\Support\Facades\Route;

/**
 * Host-configured links for the Dispatch layout's main navigation
 * (`dispatch.nav.links`) — the seam that lets a host stitch Dispatch into the
 * rest of its app ("Back to Chat", "Dashboard", …) without publishing and
 * forking the layout view.
 *
 * Each entry is an array:
 *   label       (required) link text
 *   url         a literal URL or path ('/chat?tasks=with_me'), OR
 *   route       a route name, with optional `params` — skipped when the route
 *               is not registered, the same existence check the built-in
 *               Focuses / Labels / Agent Sessions links use
 *   staff_only  show only to DispatchGate::isStaff() users (default false)
 *   new_tab     open in a new tab (default false)
 *   title       optional tooltip
 *
 * A malformed entry (no label, or neither url nor a registered route) is
 * dropped rather than rendered as a dead link.
 */
class NavLinks
{
    /**
     * @return list<array{label: string, href: string, new_tab: bool, title: ?string}>
     */
    public static function resolve(bool $isStaff): array
    {
        $links = [];

        foreach ((array) config('dispatch.nav.links', []) as $link) {
            if (! is_array($link)) {
                continue;
            }

            $label = trim((string) ($link['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            if (! empty($link['staff_only']) && ! $isStaff) {
                continue;
            }

            $href = null;
            if (! empty($link['route'])) {
                if (Route::has($link['route'])) {
                    $href = route($link['route'], $link['params'] ?? []);
                }
            } elseif (! empty($link['url'])) {
                $href = (string) $link['url'];
            }

            if ($href === null) {
                continue;
            }

            $links[] = [
                'label' => $label,
                'href' => $href,
                'new_tab' => (bool) ($link['new_tab'] ?? false),
                'title' => isset($link['title']) ? (string) $link['title'] : null,
            ];
        }

        return $links;
    }
}
