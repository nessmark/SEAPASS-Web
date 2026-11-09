<?php
namespace Tests\Feature;

use App\Models\{Passenger, User};
use App\Services\Assistant\{AssistantService, GuideRegistry, Tools};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Http, Route};
use Tests\TestCase;

class AssistantGuideTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User { return User::firstOrCreate(['email' => 'admin@example.test'], ['name' => 'Port admin', 'password' => 'test-password', 'role' => 'admin', 'is_active' => true]); }
    private function staff(): User { return User::firstOrCreate(['email' => 'scanner@example.test'], ['name' => 'Scanner', 'password' => 'test-password', 'role' => 'scanner', 'assigned_port' => 'Surigao City Port', 'is_active' => true]); }
    private function passenger(): Passenger { return Passenger::firstOrCreate(['email' => 'passenger@example.test'], ['name' => 'Passenger', 'password' => 'test-password']); }
    private function tool(User $u, string $name, array $args = [], array $context = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($u)->postJson('/assistant/tool', ['name' => $name, 'args' => $args, 'context' => $context]);
    }
    private function definition(array $defs, string $name): ?array
    {
        foreach ($defs as $d) if ($d['name'] === $name) return $d;
        return null;
    }

    public function test_only_admin_gets_registry_guide_tools_and_others_keep_current_page_guides(): void
    {
        $tools = app(Tools::class);
        $admin = $tools->definitions($this->admin());
        $this->assertNotNull($this->definition($admin, 'list_guide_targets'));
        $tour = $this->definition($admin, 'start_tour');
        $this->assertSame('array', $tour['parameters']['properties']['steps']['type']);
        $this->assertSame(['target'], $tour['parameters']['properties']['steps']['items']['required']);
        $this->assertSame('string', $tour['parameters']['properties']['preset']['type']);

        foreach ([$this->passenger(), $this->staff()] as $user) {
            $defs = $tools->definitions($user);
            $this->assertNull($this->definition($defs, 'list_guide_targets'));
            $this->assertSame(['preset'], array_keys((array) $this->definition($defs, 'start_tour')['parameters']['properties']));
        }
        $this->actingAs($this->passenger(), 'sanctum')->postJson('/api/assistant/tool', ['name' => 'list_guide_targets'])->assertForbidden();
        $this->actingAs($this->staff(), 'api')->postJson('/api/assistant/tool', ['name' => 'list_guide_targets'])->assertForbidden();
        $this->actingAs($this->staff(), 'api')->postJson('/api/assistant/tool', ['name' => 'start_tour', 'args' => ['preset' => 'getting_started']])->assertUnprocessable();
        $this->actingAs($this->staff(), 'api')->postJson('/api/assistant/tool', ['name' => 'start_tour', 'args' => ['preset' => 'current_page']])->assertOk()->assertJsonMissingPath('client_action.tour');
    }

    public function test_list_guide_targets_is_current_page_first_and_filters_by_page(): void
    {
        $admin = $this->admin();
        $rows = $this->tool($admin, 'list_guide_targets', [], ['path' => '/admin/tickets'])->assertOk()->assertJsonPath('result.current_page', 'Ticketing and Booking')->json('result.targets');
        $pages = array_column($rows, 'page');
        $this->assertSame('Ticketing and Booking', $pages[0]);
        $lastTicket = max(array_keys($pages, 'Ticketing and Booking'));
        $firstShell = min(array_keys($pages, 'Every page'));
        $lastShell = max(array_keys($pages, 'Every page'));
        $firstDashboard = min(array_keys($pages, 'Dashboard'));
        $this->assertLessThan($firstShell, $lastTicket);
        $this->assertLessThan($firstDashboard, $lastShell);
        $this->assertContains('getting_started', array_column($this->tool($admin, 'list_guide_targets')->json('result.presets'), 'preset'));

        $fares = array_column($this->tool($admin, 'list_guide_targets', ['page' => 'Fare Management'])->assertOk()->json('result.targets'), 'id');
        $this->assertContains('fares.matrix', $fares);
        $this->assertNotContains('tickets.approve', $fares);
        $this->assertSame($fares, array_column($this->tool($admin, 'list_guide_targets', ['page' => '/admin/fare-management'])->json('result.targets'), 'id'));
        $this->tool($admin, 'list_guide_targets', ['page' => 'Engine room'])->assertUnprocessable();
    }

    public function test_highlight_validates_anchor_ids_with_close_matches_and_keeps_legacy_page_targets(): void
    {
        $admin = $this->admin();
        $this->tool($admin, 'highlight_element', ['target' => 'tickets.approve', 'message' => 'Approve is here — after the ID check.'], ['path' => '/admin/dashboard'])
            ->assertOk()->assertJsonPath('client_action.type', 'highlight_element')->assertJsonPath('client_action.tour.single', true)
            ->assertJsonPath('client_action.tour.steps.0.target', 'tickets.approve')->assertJsonPath('client_action.tour.steps.0.body', 'Approve is here, after the ID check.')
            ->assertJsonPath('starts_on', 'Ticketing and Booking');
        $this->tool($admin, 'highlight_element', ['target' => 'fares.save'], ['path' => '/admin/fare-management'])
            ->assertOk()->assertJsonPath('starts_on', 'this page')->assertJsonPath('client_action.tour.steps.0.body', 'Saves every changed fare in the matrix. Nothing changes until you save.');
        $bad = $this->tool($admin, 'highlight_element', ['target' => 'tickets.aprove', 'message' => 'Here'])->assertUnprocessable();
        $this->assertStringContainsString('tickets.approve', $bad->json('message'));
        $this->tool($admin, 'highlight_element', ['target' => ['tickets.approve']])->assertUnprocessable();
        $this->tool($admin, 'highlight_element', ['target' => 'target-2', 'message' => 'Save'], ['targets' => [['id' => 'target-2', 'label' => 'Save']]])
            ->assertOk()->assertJsonPath('client_action.args.target', 'target-2')->assertJsonMissingPath('client_action.tour');
    }

    public function test_start_tour_presets_custom_tours_and_current_page(): void
    {
        $admin = $this->admin();
        $reg = app(GuideRegistry::class)->forRole('admin');
        foreach (['getting_started', 'create_trip', 'verify_discount', 'publish_advisory'] as $preset) {
            $steps = $this->tool($admin, 'start_tour', ['preset' => $preset])->assertOk()->assertJsonPath('client_action.tour.preset', $preset)->json('client_action.tour.steps');
            foreach ($steps as $s) $this->assertArrayHasKey($s['target'], $reg['anchors']);
        }
        $count = count($reg['tours']['getting_started']['steps']);
        $this->assertTrue($count >= 8 && $count <= 14);
        $this->assertStringContainsString('verify_discount', $this->tool($admin, 'start_tour', ['preset' => 'whole_app'])->assertUnprocessable()->json('message'));

        $custom = $this->tool($admin, 'start_tour', ['title' => 'Fares', 'steps' => [['target' => 'made.up'], ['target' => 'fares.matrix', 'title' => 'Fares', 'body' => 'Set fares here.'], ['target' => 'fares.save']]])->assertOk();
        $custom->assertJsonPath('dropped', ['made.up'])->assertJsonPath('total', 2)->assertJsonPath('client_action.tour.steps.1.title', 'Save All Fares');
        $this->tool($admin, 'start_tour', ['steps' => [['target' => 'fares.save']]])->assertUnprocessable();
        $this->tool($admin, 'start_tour', ['title' => 'Too long', 'steps' => array_fill(0, 15, ['target' => 'fares.save'])])->assertUnprocessable();
        $this->assertStringContainsString('Try:', $this->tool($admin, 'start_tour', ['title' => 'Bad', 'steps' => [['target' => 'nope']]])->assertUnprocessable()->json('message'));

        $page = $this->tool($admin, 'start_tour', ['preset' => 'current_page'], ['path' => '/admin/fare-management/'])->assertOk()->json('client_action.tour.steps');
        $this->assertSame(['fares.matrix', 'fares.save'], array_column($page, 'target'));
        $this->assertSame([], $this->tool($admin, 'start_tour', ['preset' => 'current_page'], ['path' => '/admin/user-management/roles-permissions'])->json('client_action.tour.steps'));
    }

    public function test_prompt_lists_only_the_role_anchors_and_presets(): void
    {
        $service = app(AssistantService::class);
        $admin = $service->prompt($this->admin());
        $this->assertStringContainsString('tickets.approve: Approve [Ticketing and Booking]', $admin);
        $this->assertStringContainsString('getting_started (Getting started)', $admin);
        $this->assertStringContainsString('verify_discount', $admin);
        $this->assertStringNotContainsString('Only current-page tours are implemented', $admin);
        foreach ([$this->passenger(), $this->staff()] as $user) {
            $prompt = $service->prompt($user);
            $this->assertStringContainsString('Only current-page tours are implemented', $prompt);
            $this->assertStringNotContainsString('tickets.approve', $prompt);
            $this->assertStringNotContainsString('getting_started', $prompt);
        }
    }

    public function test_chat_returns_a_validated_tour_effect_without_touching_data(): void
    {
        config(['assistant.api_key' => 'test-key']);
        $count = 0;
        Http::fake(function ($request) use (&$count) {
            $count++;
            $this->assertStringContainsString('dash.attention: Things to do [Dashboard]', $request['systemInstruction']['parts'][0]['text']);
            return Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => $count === 1
                ? [['functionCall' => ['name' => 'start_tour', 'args' => ['title' => 'Bookings', 'steps' => [['target' => 'tickets.tabs', 'title' => 'Tabs', 'body' => 'Pick a tab.'], ['target' => 'invented.part', 'title' => 'X', 'body' => 'Y']]]]]]
                : [['text' => 'The tour is on screen.']]]]]]);
        });
        $this->actingAs($this->admin())->postJson('/assistant/chat', ['message' => 'Show me how bookings work', 'context' => ['path' => '/admin/dashboard']])
            ->assertOk()->assertJsonPath('effects.0.client_action.type', 'start_tour')
            ->assertJsonPath('effects.0.client_action.tour.steps.0.target', 'tickets.tabs')->assertJsonPath('effects.0.dropped', ['invented.part'])
            ->assertJsonPath('effects.0.starts_on', 'Ticketing and Booking');
        $this->assertSame(2, $count);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_registry_routes_fallbacks_and_markup_agree(): void
    {
        $data = app(GuideRegistry::class)->data();
        foreach ($data['pages'] as $path => $page) {
            foreach (array_merge([$path], $page['aliases'] ?? []) as $p) {
                $route = Route::getRoutes()->match(Request::create($p, 'GET'));
                $this->assertContains('role:admin', $route->gatherMiddleware(), $p);
            }
        }
        $views = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $f) if (str_ends_with($f, '.blade.php')) $views .= file_get_contents($f);
        preg_match_all('/data-guide="([a-z][A-Za-z]*\.[A-Za-z.]+)"/', $views, $m);
        $this->assertEqualsCanonicalizing(array_keys($data['anchors']), array_values(array_unique($m[1])));
        foreach ($data['anchors'] as $id => $a) {
            $this->assertNotSame('', trim($a['label']), $id);
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}\x{00B7}]/u', $a['label'].$a['description'], $id);
            if ($a['page'] !== null) $this->assertArrayHasKey($a['page'], $data['pages'], $id);
            if (isset($a['fallback'])) $this->assertArrayHasKey($a['fallback'], $data['anchors'], $id);
        }
        foreach ($data['tours'] as $role => $tours) foreach ($tours as $name => $tour) foreach ($tour['steps'] as $s) {
            $this->assertContains($role, $data['anchors'][$s['target']]['roles'] ?? [], "$name {$s['target']}");
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}\x{00B7}]/u', $s['body']);
        }
    }

    public function test_console_pages_carry_the_role_registry_and_tour_entry_points(): void
    {
        $admin = $this->admin();
        $data = app(GuideRegistry::class)->data();
        $rowAnchors = ['dash.manifest', 'fleet.status', 'trips.seats', 'trips.manifest', 'tickets.details', 'tickets.approve'];
        foreach ($data['pages'] as $path => $page) {
            $html = $this->actingAs($admin)->get($path)->assertOk()->assertSee('id="sp-guide-registry"', false)
                ->assertSee('data-sp-tour="getting_started"', false)->assertSee('js/assistant.js?v=20261110-live', false)->getContent();
            foreach ($data['anchors'] as $id => $a) {
                if (($a['page'] === $path || $a['page'] === null) && !in_array($id, $rowAnchors, true)) $this->assertStringContainsString('data-guide="'.$id.'"', $html, "$path $id");
            }
            preg_match('#<script type="application/json" id="sp-guide-registry">(.*?)</script>#s', $html, $json);
            $registry = json_decode($json[1], true);
            $this->assertSame('admin', $registry['role']);
            $this->assertArrayHasKey('verify_discount', $registry['tours']);
        }
        $this->assertSame([], app(GuideRegistry::class)->forUser($this->staff())['anchors']);
        $this->assertSame([], app(GuideRegistry::class)->forUser(null)['anchors']);
    }
}
