<?php
namespace App\Services\Assistant;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Console guide anchors and tours, read from resources/assistant/guides.json.
 * The same file is injected per role into the page for public/js/assistant/guide-engine.js,
 * so tool validation, the system prompt and the browser engine share one list.
 * Roles without anchors (passenger, staff) keep the current-page guide only.
 */
class GuideRegistry
{
    public const MAX_STEPS = 14;
    private static ?array $data = null;
    private static array $roles = [];

    public function data(): array
    {
        if (self::$data === null) {
            $decoded = json_decode((string) @file_get_contents(resource_path('assistant/guides.json')), true);
            self::$data = is_array($decoded) ? $decoded : [];
        }
        return self::$data;
    }

    /** The registry slice for one role: only that role's pages, anchors and presets. */
    public function forRole(string $role): array
    {
        if (isset(self::$roles[$role])) return self::$roles[$role];
        $data = $this->data();
        $anchors = [];
        foreach ($data['anchors'] ?? [] as $id => $a) {
            if (!in_array($role, $a['roles'] ?? [], true)) continue;
            $anchors[$id] = ['label' => (string) $a['label'], 'description' => (string) $a['description'], 'page' => $a['page'] ?? null];
            if (!empty($a['fallback'])) $anchors[$id]['fallback'] = $a['fallback'];
        }
        foreach ($anchors as $id => $a) {
            if (isset($a['fallback']) && !isset($anchors[$a['fallback']])) unset($anchors[$id]['fallback']);
        }
        $pages = [];
        foreach ($data['pages'] ?? [] as $path => $p) {
            if (in_array($role, $p['roles'] ?? [], true)) $pages[$path] = ['label' => $p['label']] + (empty($p['aliases']) ? [] : ['aliases' => $p['aliases']]);
        }
        $tours = [];
        foreach ($data['tours'][$role] ?? [] as $name => $tour) {
            $steps = [];
            foreach ($tour['steps'] ?? [] as $s) {
                $target = $s['target'] ?? '';
                if (isset($anchors[$target])) $steps[] = $this->step($anchors, $target, $s['title'] ?? '', $s['body'] ?? '');
            }
            if ($steps) $tours[$name] = ['title' => $tour['title'], 'steps' => $steps];
        }
        return self::$roles[$role] = ['role' => $role, 'pages' => $pages, 'anchors' => $anchors, 'tours' => $tours];
    }

    /** Page data for the signed-in console user. Never throws: an unknown role gets an empty registry. */
    public function forUser(?Authenticatable $user): array
    {
        try {
            return $user ? $this->forRole(app(Tools::class)->role($user)) : $this->forRole('');
        } catch (\Throwable) {
            return $this->forRole('');
        }
    }

    public function hasGuides(string $role): bool { return (bool) $this->forRole($role)['anchors']; }

    public static function clean(mixed $value, int $max): string
    {
        if (!is_string($value)) return '';
        $value = preg_replace('/\s*[\x{2013}\x{2014}\x{00B7}]\s*/u', ', ', $value) ?? '';
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $value) ?? ''), 0, $max);
    }

    public static function normalizePath(?string $path): string
    {
        $p = rtrim(strtok((string) $path, '?#') ?: '', '/');
        return strtolower($p === '' ? '/' : $p);
    }

    /** Canonical registry path for a browser path, or null when the page has no guide. */
    public function pageOf(string $role, ?string $path): ?string
    {
        $wanted = self::normalizePath($path);
        foreach ($this->forRole($role)['pages'] as $page => $p) {
            foreach (array_merge([$page], $p['aliases'] ?? []) as $candidate) {
                if (self::normalizePath($candidate) === $wanted) return $page;
            }
        }
        return null;
    }

    /** Same ranking as closeMatches() in guide-engine.js. */
    public function closeMatches(string $role, mixed $target, int $limit = 5): array
    {
        $anchors = $this->forRole($role)['anchors'];
        $text = strtolower(is_scalar($target) ? (string) $target : '');
        $words = array_values(array_filter(preg_split('/[ ._-]+/', $text) ?: [], fn($w) => $w !== ''));
        $rows = [];
        foreach ($anchors as $id => $a) {
            $score = 0;
            foreach ($words as $w) if (str_contains(strtolower($id), $w) || str_contains(strtolower($a['label']), $w)) $score++;
            $rows[] = ['id' => $id, 'score' => $score, 'd' => levenshtein(substr($text, 0, 255), strtolower($id))];
        }
        usort($rows, fn($a, $b) => [$b['score'], $a['d'], $a['id']] <=> [$a['score'], $b['d'], $b['id']]);
        return array_column(array_slice($rows, 0, $limit), 'id');
    }

    public function targetError(string $role, mixed $target): string
    {
        $matches = $this->closeMatches($role, $target);
        return 'Unknown guide target: '.mb_substr(is_scalar($target) ? (string) $target : '', 0, 80).'.'.($matches ? ' Try: '.implode(', ', $matches).'.' : '');
    }

    private function step(array $anchors, string $target, mixed $title, mixed $body): array
    {
        return [
            'target' => $target,
            'title' => self::clean($title, 100) ?: self::clean($anchors[$target]['label'], 100),
            'body' => self::clean($body, 600) ?: self::clean($anchors[$target]['description'], 600),
        ];
    }

    /** Validates highlight_element args. Throws a 422 with close matches for an unknown target. */
    public function highlight(string $role, array $args): array
    {
        $reg = $this->forRole($role);
        $target = $args['target'] ?? null;
        abort_unless(is_string($target) && isset($reg['anchors'][$target]), 422, $this->targetError($role, $target));
        $step = $this->step($reg['anchors'], $target, '', $args['message'] ?? '');
        return ['title' => $step['title'], 'single' => true, 'preset' => null, 'dropped' => [], 'steps' => [$step]];
    }

    /** Validates start_tour args: a preset, current_page, or a short custom tour. Unknown custom targets are dropped. */
    public function tour(string $role, array $args, ?string $currentPath = null): array
    {
        $reg = $this->forRole($role);
        $preset = $args['preset'] ?? null;
        if (is_string($preset) && $preset !== '') {
            if ($preset === 'current_page') {
                // Same as currentPageTour() in guide-engine.js. Empty on pages without anchors: the app uses its older page guide there.
                $page = $this->pageOf($role, $currentPath);
                $steps = [];
                foreach ($reg['anchors'] as $id => $a) if ($page !== null && $a['page'] === $page && count($steps) < 8) $steps[] = $this->step($reg['anchors'], $id, '', '');
                return ['title' => 'This page', 'single' => false, 'preset' => 'current_page', 'dropped' => [], 'steps' => $steps];
            }
            abort_unless(isset($reg['tours'][$preset]), 422, 'Unknown tour. Choose: '.implode(', ', array_merge(['current_page'], array_keys($reg['tours']))).'.');
            $tour = $reg['tours'][$preset];
            return ['title' => $tour['title'], 'single' => false, 'preset' => $preset, 'dropped' => [], 'steps' => $tour['steps']];
        }
        $steps = $args['steps'] ?? null;
        abort_unless(is_array($steps) && array_is_list($steps) && count($steps) >= 1 && count($steps) <= self::MAX_STEPS, 422, 'Give 1 to '.self::MAX_STEPS.' steps, or a preset tour.');
        $title = self::clean($args['title'] ?? '', 100);
        abort_if($title === '', 422, 'Give the tour a short title.');
        $kept = [];
        $dropped = [];
        foreach ($steps as $s) {
            $target = is_array($s) ? ($s['target'] ?? null) : null;
            if (!is_string($target) || !isset($reg['anchors'][$target])) { $dropped[] = mb_substr(is_scalar($target) ? (string) $target : '', 0, 80); continue; }
            $kept[] = $this->step($reg['anchors'], $target, $s['title'] ?? '', $s['body'] ?? '');
        }
        abort_unless($kept, 422, $this->targetError($role, $dropped[0] ?? ''));
        return ['title' => $title, 'single' => false, 'preset' => null, 'dropped' => $dropped, 'steps' => $kept];
    }

    /** list_guide_targets: this role's anchors, current page first, optionally one page only. */
    public function targets(string $role, ?string $page = null, ?string $currentPath = null): array
    {
        $reg = $this->forRole($role);
        $current = $this->pageOf($role, $currentPath);
        $only = null;
        if (is_string($page) && $page !== '') {
            $only = $this->pageOf($role, $page);
            if ($only === null) {
                foreach ($reg['pages'] as $path => $p) if (strcasecmp($p['label'], $page) === 0) $only = $path;
            }
            abort_if($only === null, 422, 'Unknown page. Choose: '.implode(', ', array_map(fn($p) => $p['label'], $reg['pages'])).'.');
        }
        $rows = [];
        foreach ($reg['anchors'] as $id => $a) {
            if ($only !== null && $a['page'] !== null && $a['page'] !== $only) continue;
            $rows[] = ['id' => $id, 'label' => $a['label'], 'description' => $a['description'], 'page' => $a['page'] ? $reg['pages'][$a['page']]['label'] ?? $a['page'] : 'Every page', 'on_current_page' => $a['page'] === null || $a['page'] === $current];
        }
        $rank = fn(array $r) => ($current !== null && ($reg['anchors'][$r['id']]['page'] ?? null) === $current) ? 2 : (($reg['anchors'][$r['id']]['page'] ?? null) === null ? 1 : 0);
        usort($rows, fn($x, $y) => ($rank($y) <=> $rank($x)));
        return [
            'current_page' => $current ? $reg['pages'][$current]['label'] : null,
            'targets' => $rows,
            'presets' => array_map(fn($name, $t) => ['preset' => $name, 'title' => $t['title'], 'steps' => count($t['steps'])], array_keys($reg['tours']), array_values($reg['tours'])),
        ];
    }

    /** System prompt lines. Only the given role's anchors and presets appear. */
    public function promptLines(string $role): array
    {
        $reg = $this->forRole($role);
        if (!$reg['anchors']) {
            return ['Guides: for where is X call highlight_element with an actual target ID from the page context. For walk me through this page call start_tour preset current_page. Stay on this page unless explicitly asked to navigate. Only current-page tours are implemented. Do not invent targets.'];
        }
        $presets = [];
        foreach ($reg['tours'] as $name => $t) $presets[] = $name.' ('.$t['title'].')';
        $anchors = [];
        foreach ($reg['anchors'] as $id => $a) $anchors[] = $id.': '.$a['label'].' ['.($a['page'] ? ($reg['pages'][$a['page']]['label'] ?? $a['page']) : 'every page').']';
        return [
            'Guides: the console has read-only guided tours that point at real parts of the screen. A where is X question uses highlight_element with an anchor id below and a one or two sentence message. A walk me through this page request uses start_tour preset current_page and stays on this page. Show me around, getting started or a whole console tour uses start_tour preset getting_started. A how do I question about a task uses its preset, or a short custom tour (start_tour with title and 2 to 6 steps of target, title, body) built only from the anchor ids below. Unknown ids are refused or dropped, so never invent one. Call list_guide_targets for descriptions or one page. A target on another page shows an Open page button; the user moves between pages. Tours never save, submit or change data, and they work without this chat. Do not narrate every step; the card shows the text.',
            'Guide presets for this account: current_page, '.implode(', ', $presets).'.',
            'Guide anchors for this account (id: label [page]): '.implode('; ', $anchors).'.',
        ];
    }
}
