<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

// The browser Live client (public/js/assistant/live.js) decides what to do from
// the /assistant/session response alone: a valid payload connects, 401/419 means
// "sign in again", 503 switches to Text mode with the message shown as is. These
// tests pin that contract so a backend change cannot silently break reconnects.
class AssistantLiveContractTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['name' => 'Port admin', 'email' => 'admin@example.test', 'password' => 'test-password', 'role' => 'admin', 'is_active' => true]);
    }

    private function assertPlainMessage(string $message): void
    {
        $this->assertNotSame('', trim($message));
        $this->assertDoesNotMatchRegularExpression('/https?:|wss?:|googleapis|exception|sqlstate|curl|\.php|[{}\[\]]|test-live-key/i', $message);
    }

    public function test_session_payload_has_what_the_live_client_validates(): void
    {
        config(['assistant.api_key' => 'test-live-key']);
        Http::fake(['*' => Http::response(['name' => 'auth_tokens/live-ephemeral'])]);
        $json = $this->actingAs($this->admin())->postJson('/assistant/session', ['context' => ['label' => 'Trips']])->assertOk()->json();
        $this->assertIsString($json['token']);
        $this->assertNotSame('', $json['token']);
        $this->assertIsString($json['model']);
        $this->assertNotSame('', $json['model']);
        $this->assertStringStartsWith('wss://', $json['ws_url']);
        $this->assertStringNotContainsString('access_token', $json['ws_url'], 'the client appends the token itself');
        $this->assertIsString($json['system_instruction']);
        $this->assertIsArray($json['tools']);
        $this->assertStringNotContainsString('test-live-key', json_encode($json));
    }

    public function test_every_reconnect_mints_its_own_single_use_token(): void
    {
        config(['assistant.api_key' => 'test-live-key']);
        Http::fake(['*' => Http::sequence()->push(['name' => 'auth_tokens/first'])->push(['name' => 'auth_tokens/second'])]);
        $admin = $this->admin();
        $this->actingAs($admin)->postJson('/assistant/session')->assertOk()->assertJsonPath('token', 'auth_tokens/first');
        $this->actingAs($admin)->postJson('/assistant/session')->assertOk()->assertJsonPath('token', 'auth_tokens/second');
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r['uses'] === 1);
    }

    public function test_session_failures_are_503_with_plain_text_for_text_mode(): void
    {
        $admin = $this->admin();

        config(['assistant.api_key' => null]);
        Http::fake();
        $missing = $this->actingAs($admin)->postJson('/assistant/session')->assertStatus(503)->json('message');
        $this->assertPlainMessage($missing);
        Http::assertNothingSent();

        config(['assistant.api_key' => 'test-live-key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid. https://console.cloud.google.com']], 400)]);
        $rejected = $this->actingAs($admin)->postJson('/assistant/session')->assertStatus(503)->json('message');
        $this->assertPlainMessage($rejected);

        Http::fake(['*' => Http::failedConnection('cURL error 28: https://generativelanguage.googleapis.com timed out')]);
        $unreachable = $this->actingAs($admin)->postJson('/assistant/session')->assertStatus(503)->json('message');
        $this->assertPlainMessage($unreachable);
    }

    public function test_a_signed_out_session_request_is_401_so_live_stops_retrying(): void
    {
        Http::fake();
        $this->postJson('/assistant/session')->assertUnauthorized();
        $this->postJson('/api/assistant/session')->assertUnauthorized();
        Http::assertNothingSent();
    }

    // Read from this checkout's files (not through the app) so the check holds in a
    // git worktree whose vendor/ is shared with another checkout.
    public function test_panel_and_embed_load_the_versioned_live_client(): void
    {
        $root = dirname(__DIR__, 2);
        $embed = file_get_contents($root . '/resources/views/assistant-embed.blade.php');
        $panel = file_get_contents($root . '/resources/views/components/assistant.blade.php');
        $script = file_get_contents($root . '/public/js/assistant.js');
        $this->assertStringContainsString('/js/assistant.js?v=20261110-live"', $embed);
        $this->assertStringContainsString("asset('js/assistant.js') }}?v=20261110-live\"", $panel);
        $this->assertStringContainsString("from './assistant/live.js?v=20261110-live'", $script);
        $this->assertDoesNotMatchRegularExpression('/\?v=(?!20261110-live)/', $script, 'every assistant module import carries the same version');
        $this->get('/assistant/embed')->assertOk()->assertSee('/js/assistant.js?v=', false);
    }
}
