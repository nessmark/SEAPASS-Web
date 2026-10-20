<?php
namespace Tests\Feature;
use App\Models\SmsMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class TextLinkWebhookTest extends TestCase {
 use RefreshDatabase;
 public function test_failed_callbacks_require_secret_and_match_recipient():void {
  config(['services.textlink.webhook_secret'=>'callback-secret']);
  $message=SmsMessage::create(['deduplication_key'=>'test','to'=>'+639123456789','body'=>'Hello','status'=>'queued']);
  $this->postJson('/api/textlink/failed',['custom_id'=>(string)$message->id,'phone_number'=>$message->to])->assertUnauthorized();
  $this->postJson('/api/textlink/failed',['secret'=>'callback-secret','custom_id'=>(string)$message->id,'phone_number'=>'+639999999999'])->assertOk();
  $this->assertEquals('queued',$message->fresh()->status);
  $payload=['secret'=>'callback-secret','custom_id'=>(string)$message->id,'phone_number'=>$message->to,'textlink_id'=>123];
  $this->postJson('/api/textlink/failed',$payload)->assertOk();$this->postJson('/api/textlink/failed',$payload)->assertOk();
  $this->assertEquals('failed',$message->fresh()->status);$this->assertEquals('123',$message->fresh()->provider_id);
 }
}
