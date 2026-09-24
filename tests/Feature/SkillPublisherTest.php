<?php

use Illuminate\Support\Facades\Artisan;
use Sgrjr\Dispatch\Support\SkillPublisher;

/*
 * TASK-1237 — the shipped skills are TEMPLATES a host renders with its own
 * config + overlays (`dispatch:skills:publish`), instead of hand-edited forks.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/dispatch-skills-'.uniqid();
    mkdir($this->dir.'/skills', 0777, true);
    mkdir($this->dir.'/overlays', 0777, true);
    config([
        'dispatch.skills.target' => $this->dir.'/skills',
        'dispatch.skills.overlays' => $this->dir.'/overlays',
        'dispatch.skills.vars' => [],
        'dispatch.agent.lane' => null,
    ]);
});

afterEach(function () {
    \Illuminate\Support\Facades\File::deleteDirectory($this->dir);
});

test('renderString: variables, if/else blocks, and a template note that never reaches the skill', function () {
    $template = "<!-- dispatch:template\n  for template editors\n-->\n\nHi {{ app_name }}.\n<!-- dispatch:if code_lane -->\nFile code to {{code_lane}}.\n<!-- dispatch:else -->\nNo lanes here.\n<!-- dispatch:endif -->\nBye.\n";
    $publisher = app(SkillPublisher::class);

    expect($publisher->renderString($template, ['app_name' => 'Acme', 'code_lane' => 'eng:dev']))->toBe("Hi Acme.\nFile code to eng:dev.\nBye.\n")
        ->and($publisher->renderString($template, ['app_name' => 'Acme', 'code_lane' => '']))->toBe("Hi Acme.\nNo lanes here.\nBye.\n");
});

test('renderString refuses an unknown variable or an unbalanced block, naming the problem', function () {
    $publisher = app(SkillPublisher::class);

    expect(fn () => $publisher->renderString("{{ nope }}\n", []))->toThrow(InvalidArgumentException::class, 'nope')
        ->and(fn () => $publisher->renderString("<!-- dispatch:if x -->\nopen\n", ['x' => '1']))->toThrow(InvalidArgumentException::class, 'unbalanced');
});

test('a slot takes the host overlay file, or disappears when there is none', function () {
    mkdir($this->dir.'/overlays/demo', 0777, true);
    file_put_contents($this->dir.'/overlays/demo/extra.md', "- host-only rule\r\n");
    $publisher = app(SkillPublisher::class);

    expect($publisher->renderString("A\n<!-- dispatch:slot extra -->\nB\n", [], 'demo'))->toBe("A\n- host-only rule\nB\n")
        ->and($publisher->renderString("A\n<!-- dispatch:slot other -->\nB\n", [], 'demo'))->toBe("A\nB\n");
});

test('every shipped template renders cleanly with the package defaults', function () {
    $publisher = app(SkillPublisher::class);

    foreach (SkillPublisher::SKILLS as $skill) {
        $out = $publisher->render($skill);
        expect($out)->toStartWith("---\nname: {$skill}\n")
            ->and($out)->not->toContain('{{')
            ->and($out)->not->toContain('<!-- dispatch:')
            ->and($out)->toContain('fingerprint:');
    }
});

test('code_lane: set, the skills tell agents to file code work there; unset, they say nothing about a developer lane', function () {
    $publisher = app(SkillPublisher::class);
    expect($publisher->render('dispatch-track'))->not->toContain('Code work →');

    config(['dispatch.skills.vars' => ['code_lane' => 'eng:dev']]);
    expect($publisher->render('dispatch-track'))->toContain('--lane=eng:dev')
        ->and($publisher->render('dispatch-agent-session'))->toContain('Code work goes to `--lane=eng:dev`')
        ->and($publisher->render('dispatch-batch-migrate'))->toContain('"lane": "eng:dev"');
});

test('code_lane defaults to agent.lane, the lane a commissioned session serves', function () {
    config(['dispatch.agent.lane' => 'ops:dev']);

    expect(app(SkillPublisher::class)->vars())->toMatchArray(['code_lane' => 'ops:dev', 'code_lane_department' => 'ops']);
});

test('the loud priorities are named from notifications.email_priorities', function () {
    config(['dispatch.notifications.email_priorities' => ['blocker', 'high']]);

    expect(app(SkillPublisher::class)->render('dispatch-track'))->toContain('`blocker`, `high` are **loud**');
});

test('publish → check → hand-edit → refuse → force: the lifecycle', function () {
    config(['dispatch.skills.vars' => ['code_lane' => 'eng:dev']]);

    expect(Artisan::call('dispatch:skills:publish', ['--check' => true]))->toBe(1);   // missing
    expect(Artisan::call('dispatch:skills:publish'))->toBe(0);
    expect(Artisan::call('dispatch:skills:publish', ['--check' => true]))->toBe(0);   // up to date

    // Config moved → stale, and a plain publish refreshes it.
    config(['dispatch.skills.vars' => ['code_lane' => 'eng:web']]);
    expect(app(SkillPublisher::class)->status('dispatch-track')['state'])->toBe(SkillPublisher::STALE);
    expect(Artisan::call('dispatch:skills:publish'))->toBe(0);
    expect(file_get_contents($this->dir.'/skills/dispatch-track/SKILL.md'))->toContain('--lane=eng:web');

    // A hand edit is never silently overwritten.
    $path = $this->dir.'/skills/dispatch-track/SKILL.md';
    file_put_contents($path, file_get_contents($path)."\nmy local tweak\n");
    expect(app(SkillPublisher::class)->status('dispatch-track')['state'])->toBe(SkillPublisher::HAND_EDITED);
    expect(Artisan::call('dispatch:skills:publish', ['--skill' => ['dispatch-track']]))->toBe(1);
    expect(file_get_contents($path))->toContain('my local tweak');

    expect(Artisan::call('dispatch:skills:publish', ['--skill' => ['dispatch-track'], '--force' => true]))->toBe(0);
    expect(file_get_contents($path))->not->toContain('my local tweak');
});

test('a hand copy that was never published is UNMANAGED, and needs --force the first time', function () {
    mkdir($this->dir.'/skills/dispatch-track', 0777, true);
    file_put_contents($this->dir.'/skills/dispatch-track/SKILL.md', "---\nname: dispatch-track\n---\nforked by hand\n");

    expect(app(SkillPublisher::class)->status('dispatch-track')['state'])->toBe(SkillPublisher::UNMANAGED)
        ->and(Artisan::call('dispatch:skills:publish', ['--skill' => ['dispatch-track']]))->toBe(1);
});

test('dispatch:doctor reports a stale skill', function () {
    Artisan::call('dispatch:skills:publish');
    config(['dispatch.skills.vars' => ['code_lane' => 'eng:moved']]);

    Artisan::call('dispatch:doctor', ['--json' => true]);
    $findings = collect(json_decode(Artisan::output(), true)['findings'] ?? []);

    expect($findings->firstWhere('check', 'skills.dispatch-track')['level'] ?? null)->toBe('warn');
});
